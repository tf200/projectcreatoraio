<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;

/**
 * What-if scenarios: a list of changes laid over the live plan, recalculated by the
 * same scheduling engine as the real timeline and compared against it. Nothing is saved.
 */
class TimelineScenarioService
{
	public const MAX_CHANGES = 200;
	private const MAX_DAYS = 3650;
	private const MAX_PREPARATION_WEEKS = 520;
	private const TASK_CHANGE_TYPES = ['delay', 'duration', 'endDate', 'startNotBefore'];

	public function __construct(
		private readonly TimelinePhaseService $phaseService,
		private readonly TimelinePlanningService $planningService,
		private readonly TimelineCriticalPath $criticalPath,
	) {
	}

	/**
	 * @param array<int, mixed> $changes
	 * @return array<string, mixed>
	 */
	public function simulate(Project $project, array $changes): array
	{
		$baseline = $this->phaseService->getProjectPhaseHierarchy($project);
		$baselineTasks = $this->indexTasks($baseline['phases']);
		$normalized = $this->normalizeChanges($changes, $baselineTasks, $baseline['dependencies']);

		$scenarioProject = clone $project;
		if (array_key_exists('desiredStartDate', $normalized['planning'])) {
			$desired = $normalized['planning']['desiredStartDate'];
			$scenarioProject->setDesiredStartDate($desired === null ? null : new DateTime($desired));
		}
		if (array_key_exists('requiredPreparationWeeks', $normalized['planning'])) {
			$scenarioProject->setRequiredPreparationWeeks($normalized['planning']['requiredPreparationWeeks']);
		}

		$scenario = $normalized['changes'] === []
			? $baseline
			: $this->phaseService->getProjectPhaseHierarchy($project, [
				'taskOverrides' => $this->keepBaselineDurations($normalized['taskOverrides'], $baselineTasks),
				'dependencyOverlaps' => $normalized['dependencyOverlaps'],
			]);
		$scenarioTasks = $this->indexTasks($scenario['phases']);

		$baselineSummary = $this->planningService->buildSummary($project, $baseline['phases']);
		$scenarioSummary = $normalized['changes'] === []
			? $baselineSummary
			: $this->planningService->buildSummary($scenarioProject, $scenario['phases']);

		$baselineAnalysis = $this->criticalPath->analyze(
			$baselineTasks,
			$this->overlapsFrom($baseline['dependencies']),
			$this->deadline($baselineSummary),
		);
		$scenarioAnalysis = $this->criticalPath->analyze(
			$scenarioTasks,
			$this->overlapsFrom($scenario['dependencies']),
			$this->deadline($scenarioSummary),
		);

		$changedTaskIds = [];
		foreach ($normalized['changes'] as $change) {
			if (isset($change['taskId'])) {
				$changedTaskIds[(string)$change['taskId']] = true;
			}
			if (isset($change['successorId'])) {
				$changedTaskIds[(string)$change['successorId']] = true;
			}
		}

		$phases = [];
		$taskImpacts = [];
		$deckCardUpdates = [];
		foreach ($scenario['phases'] as $phase) {
			foreach ($phase['tasks'] as $index => $task) {
				$id = (string)$task['id'];
				$before = $baselineTasks[$id] ?? $task;
				$beforeAnalysis = $baselineAnalysis[$id] ?? null;
				$afterAnalysis = $scenarioAnalysis[$id];
				$impact = [
					'id' => $task['id'],
					'label' => $task['label'],
					'phaseCategory' => $phase['category'],
					'deckCardId' => $task['deckCardId'] ?? null,
					'isDone' => !empty($task['isDone']),
					'changedDirectly' => isset($changedTaskIds[$id]),
					'baselineStartDate' => $before['startDate'],
					'baselineEndDate' => $before['endDate'],
					'baselineDurationDays' => (int)$before['durationDays'],
					'startDate' => $task['startDate'],
					'endDate' => $task['endDate'],
					'durationDays' => (int)$task['durationDays'],
					'startShiftDays' => $this->signedDays($before['startDate'], $task['startDate']),
					'endShiftDays' => $this->signedDays($before['endDate'], $task['endDate']),
					'durationChangeDays' => (int)$task['durationDays'] - (int)$before['durationDays'],
					'baselineFloatDays' => $beforeAnalysis['floatDays'] ?? null,
					'floatDays' => $afterAnalysis['floatDays'],
					'wasCritical' => $beforeAnalysis['isCritical'] ?? false,
					'isCritical' => $afterAnalysis['isCritical'],
					'lateStartDate' => $afterAnalysis['lateStart'],
					'lateFinishDate' => $afterAnalysis['lateFinish'],
				];
				$phase['tasks'][$index]['whatIf'] = $impact;

				$moved = $impact['startShiftDays'] !== 0 || $impact['endShiftDays'] !== 0;
				if ($moved || $impact['changedDirectly'] || $impact['wasCritical'] !== $impact['isCritical']) {
					$taskImpacts[] = $impact;
				}
				if ($moved && !empty($task['deckCardId']) && !$impact['isDone']) {
					$deckCardUpdates[] = [
						'cardId' => (int)$task['deckCardId'],
						'label' => $task['label'],
						'fromStartDate' => $before['startDate'],
						'fromEndDate' => $before['endDate'],
						'toStartDate' => $task['startDate'],
						'toEndDate' => $task['endDate'],
					];
				}
			}
			$phases[] = $phase;
		}

		$movedCount = count(array_filter(
			$taskImpacts,
			static fn (array $impact): bool => $impact['startShiftDays'] !== 0 || $impact['endShiftDays'] !== 0,
		));

		return [
			'changes' => $normalized['changes'],
			'phases' => $phases,
			'dependencies' => $scenario['dependencies'],
			'impact' => [
				'planning' => $this->comparePlanning($baselineSummary, $scenarioSummary),
				'milestones' => $this->compareMilestones($baseline['phases'], $scenario['phases']),
				'tasks' => $taskImpacts,
				'movedTaskCount' => $movedCount,
				'criticalPath' => array_values(array_map(
					'strval',
					array_keys(array_filter($scenarioAnalysis, static fn (array $a): bool => $a['isCritical'])),
				)),
				'deckCardUpdates' => $deckCardUpdates,
			],
		];
	}

	/**
	 * Validate the requested changes against the live plan and turn them into engine overrides.
	 * Changes are applied in order: a duration or end date resets a task's length, and delays add to it.
	 *
	 * @param array<int, mixed> $changes
	 * @param array<string, array<string, mixed>> $tasks
	 * @param array<int, array<string, mixed>> $dependencies
	 * @return array{changes: array<int, array<string, mixed>>, taskOverrides: array<string, array<string, mixed>>, dependencyOverlaps: array<string, int>, planning: array<string, mixed>}
	 */
	public function normalizeChanges(array $changes, array $tasks, array $dependencies): array
	{
		if (!array_is_list($changes)) {
			throw new \InvalidArgumentException('Scenario changes must be a list');
		}
		if (count($changes) > self::MAX_CHANGES) {
			throw new \InvalidArgumentException('A scenario can hold at most ' . self::MAX_CHANGES . ' changes');
		}

		$dependencyKeys = [];
		foreach ($dependencies as $dependency) {
			$dependencyKeys[TimelinePhaseService::dependencyKey($dependency['predecessorId'], $dependency['successorId'])] = true;
		}

		$normalized = [];
		$taskOverrides = [];
		$dependencyOverlaps = [];
		$planning = [];
		foreach ($changes as $position => $change) {
			if (!is_array($change)) {
				throw new \InvalidArgumentException("Change #{$position} is not an object");
			}
			$type = (string)($change['type'] ?? '');

			if (in_array($type, self::TASK_CHANGE_TYPES, true)) {
				$task = $this->requireTask($tasks, $change['taskId'] ?? null, $position);
				$taskId = (string)$task['id'];
				$override = $taskOverrides[$taskId] ?? [];
				$entry = ['type' => $type, 'taskId' => $task['id'], 'label' => $task['label']];

				switch ($type) {
				case 'delay':
					$days = $this->requireDays($change['days'] ?? null, -self::MAX_DAYS, self::MAX_DAYS, $position);
					if ($days === 0) {
						throw new \InvalidArgumentException("Change #{$position}: a delay of 0 days changes nothing");
					}
					$override['adjustDays'] = ($override['adjustDays'] ?? 0) + $days;
					$entry['days'] = $days;
					break;
				case 'duration':
					$days = $this->requireDays($change['days'] ?? null, 1, self::MAX_DAYS, $position);
					$override['durationDays'] = $days;
					unset($override['endDate'], $override['adjustDays']);
					$entry['days'] = $days;
					break;
				case 'endDate':
					$date = $this->requireDate($change['date'] ?? null, $position);
					$override['endDate'] = $date;
					unset($override['durationDays'], $override['adjustDays']);
					$entry['date'] = $date;
					break;
				case 'startNotBefore':
					$date = ($change['date'] ?? null) === null ? null : $this->requireDate($change['date'], $position);
					if ($date === null) {
						unset($override['startNotBefore']);
					} else {
						$override['startNotBefore'] = $date;
					}
					$entry['date'] = $date;
					break;
				}

				$taskOverrides[$taskId] = $override;
				$normalized[] = $entry;
				continue;
			}

			if ($type === 'overlap') {
				$predecessor = $this->requireTask($tasks, $change['predecessorId'] ?? null, $position);
				$successor = $this->requireTask($tasks, $change['successorId'] ?? null, $position);
				$key = TimelinePhaseService::dependencyKey($predecessor['id'], $successor['id']);
				if (!isset($dependencyKeys[$key])) {
					throw new \InvalidArgumentException("Change #{$position}: \"{$successor['label']}\" does not depend on \"{$predecessor['label']}\"");
				}
				$days = $this->requireDays($change['days'] ?? null, 0, self::MAX_DAYS, $position);
				$dependencyOverlaps[$key] = $days;
				$normalized[] = [
					'type' => 'overlap',
					'predecessorId' => $predecessor['id'],
					'successorId' => $successor['id'],
					'predecessorLabel' => $predecessor['label'],
					'successorLabel' => $successor['label'],
					'days' => $days,
				];
				continue;
			}

			if ($type === 'planning') {
				$entry = ['type' => 'planning'];
				if (array_key_exists('desiredStartDate', $change)) {
					$date = $change['desiredStartDate'] === null ? null : $this->requireDate($change['desiredStartDate'], $position);
					$planning['desiredStartDate'] = $entry['desiredStartDate'] = $date;
				}
				if (array_key_exists('requiredPreparationWeeks', $change)) {
					$weeks = $this->requireDays($change['requiredPreparationWeeks'], 0, self::MAX_PREPARATION_WEEKS, $position);
					$planning['requiredPreparationWeeks'] = $entry['requiredPreparationWeeks'] = $weeks;
				}
				if (count($entry) === 1) {
					throw new \InvalidArgumentException("Change #{$position}: a planning change needs a desired start date or preparation weeks");
				}
				$normalized[] = $entry;
				continue;
			}

			throw new \InvalidArgumentException("Change #{$position}: unknown change type \"{$type}\"");
		}

		return [
			'changes' => $normalized,
			'taskOverrides' => array_filter($taskOverrides),
			'dependencyOverlaps' => $dependencyOverlaps,
			'planning' => $planning,
		];
	}

	/**
	 * Cards without Deck dates last "three months from their start", which is a different
	 * number of days depending on where they land. A card that only moves keeps its length.
	 *
	 * @param array<string, array<string, mixed>> $taskOverrides
	 * @param array<string, array<string, mixed>> $baselineTasks
	 * @return array<string, array<string, mixed>>
	 */
	private function keepBaselineDurations(array $taskOverrides, array $baselineTasks): array
	{
		foreach ($baselineTasks as $id => $task) {
			$override = $taskOverrides[$id] ?? [];
			if (!empty($task['isDone']) || isset($override['durationDays']) || isset($override['endDate'])) {
				continue;
			}
			$taskOverrides[$id] = ['durationDays' => (int)$task['durationDays']] + $override;
		}
		return $taskOverrides;
	}

	/**
	 * @param array<string, array<string, mixed>> $tasks
	 * @return array<string, mixed>
	 */
	private function requireTask(array $tasks, mixed $taskId, int $position): array
	{
		if ((!is_int($taskId) && !is_string($taskId)) || !isset($tasks[(string)$taskId])) {
			throw new \InvalidArgumentException("Change #{$position}: unknown timeline task");
		}
		$task = $tasks[(string)$taskId];
		if (!empty($task['isDone'])) {
			throw new \InvalidArgumentException("Change #{$position}: \"{$task['label']}\" is already completed and cannot be changed");
		}
		return $task;
	}

	private function requireDays(mixed $value, int $min, int $max, int $position): int
	{
		if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
			$value = (int)$value;
		}
		if (!is_int($value) || $value < $min || $value > $max) {
			throw new \InvalidArgumentException("Change #{$position}: expected a whole number between {$min} and {$max}");
		}
		return $value;
	}

	private function requireDate(mixed $value, int $position): string
	{
		$date = is_string($value) ? DateTime::createFromFormat('!Y-m-d', $value) : false;
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new \InvalidArgumentException("Change #{$position}: expected a date as YYYY-MM-DD");
		}
		return $value;
	}

	/**
	 * Tasks feeding "Start construction" must finish by the desired start minus the preparation weeks.
	 *
	 * @param array<string, mixed> $summary
	 */
	private function deadline(array $summary): ?DateTime
	{
		$desired = $summary['desiredStartDate'] ?? null;
		if (!is_string($desired) || $desired === '') {
			return null;
		}
		$weeks = max(0, (int)($summary['requiredPreparationWeeks'] ?? 0));
		return (new DateTime($desired))->setTime(0, 0)->modify('-' . (7 * $weeks) . ' days');
	}

	/**
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return array<string, mixed>
	 */
	private function comparePlanning(array $before, array $after): array
	{
		$floatBefore = $before['overallFloatDays'] ?? null;
		$floatAfter = $after['overallFloatDays'] ?? null;
		$minimumBefore = (string)($before['minimumStartDate'] ?? '');
		$minimumAfter = (string)($after['minimumStartDate'] ?? '');

		return [
			'baselineMinimumStartDate' => $minimumBefore ?: null,
			'minimumStartDate' => $minimumAfter ?: null,
			'minimumStartShiftDays' => $minimumBefore !== '' && $minimumAfter !== '' ? $this->signedDays($minimumBefore, $minimumAfter) : 0,
			'baselineDesiredStartDate' => $before['desiredStartDate'] ?? null,
			'desiredStartDate' => $after['desiredStartDate'] ?? null,
			'baselinePreparationWeeks' => (int)($before['requiredPreparationWeeks'] ?? 0),
			'preparationWeeks' => (int)($after['requiredPreparationWeeks'] ?? 0),
			'baselineFloatDays' => $floatBefore === null ? null : (int)$floatBefore,
			'floatDays' => $floatAfter === null ? null : (int)$floatAfter,
			'baselineDesiredStartAchievable' => $floatBefore === null ? null : (int)$floatBefore >= 0,
			'desiredStartAchievable' => $floatAfter === null ? null : (int)$floatAfter >= 0,
		];
	}

	/**
	 * @param array<int, array<string, mixed>> $before
	 * @param array<int, array<string, mixed>> $after
	 * @return array<int, array<string, mixed>>
	 */
	private function compareMilestones(array $before, array $after): array
	{
		$baselineDates = [];
		foreach ($before as $phase) {
			$baselineDates[$phase['category']] = $phase['milestone']['date'] ?? null;
		}

		$milestones = [];
		foreach ($after as $phase) {
			if (empty($phase['milestone'])) {
				continue;
			}
			$from = $baselineDates[$phase['category']] ?? null;
			$to = $phase['milestone']['date'];
			$milestones[] = [
				'phaseCategory' => $phase['category'],
				'label' => $phase['milestone']['label'],
				'baselineDate' => $from,
				'date' => $to,
				'shiftDays' => $from === null ? 0 : $this->signedDays($from, $to),
			];
		}
		return $milestones;
	}

	/**
	 * @param array<int, array<string, mixed>> $dependencies
	 * @return array<string, int>
	 */
	private function overlapsFrom(array $dependencies): array
	{
		$overlaps = [];
		foreach ($dependencies as $dependency) {
			if (!empty($dependency['overlapDays'])) {
				$overlaps[TimelinePhaseService::dependencyKey($dependency['predecessorId'], $dependency['successorId'])] = (int)$dependency['overlapDays'];
			}
		}
		return $overlaps;
	}

	/**
	 * @param array<int, array<string, mixed>> $phases
	 * @return array<string, array<string, mixed>>
	 */
	private function indexTasks(array $phases): array
	{
		$tasks = [];
		foreach ($phases as $phase) {
			foreach ($phase['tasks'] as $task) {
				$tasks[(string)$task['id']] = $task;
			}
		}
		return $tasks;
	}

	private function signedDays(string $from, string $to): int
	{
		$start = (new DateTime($from))->setTime(0, 0);
		$end = (new DateTime($to))->setTime(0, 0);
		$days = (int)$start->diff($end)->days;
		return $end < $start ? -$days : $days;
	}
}
