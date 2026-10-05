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
	private const MAX_SUGGESTIONS = 5;
	private const MAX_COMBINED_FIXES = 3;

	/** @var array<string, string> Task labels of the plan last loaded, for describing suggestions */
	private array $taskLabels = [];

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
		return $this->run($project, $changes, $this->loadBaseline($project));
	}

	/**
	 * Fixes for the slip a scenario causes in the earliest construction start, each one
	 * checked by running it through the engine: shortening critical cards, overlapping
	 * critical dependencies, cutting preparation, and a combination when no single fix is enough.
	 *
	 * @param array<int, mixed> $changes
	 * @return array{slipDays: int, suggestions: array<int, array<string, mixed>>}
	 */
	public function suggestFixes(Project $project, array $changes): array
	{
		$baseline = $this->loadBaseline($project);
		$current = $this->run($project, $changes, $baseline);
		$slip = (int)$current['impact']['planning']['minimumStartShiftDays'];
		if ($slip <= 0) {
			return ['slipDays' => $slip, 'suggestions' => []];
		}

		$levers = $this->findLevers($current);
		$evaluate = function (array $extra) use ($project, $current, $baseline, $slip): array {
			$result = $this->run($project, array_merge($current['changes'], $extra), $baseline);
			$planning = $result['impact']['planning'];
			return [
				'changes' => $extra,
				'slipDays' => (int)$planning['minimumStartShiftDays'],
				'recoveredDays' => $slip - (int)$planning['minimumStartShiftDays'],
				'minimumStartDate' => $planning['minimumStartDate'],
				'floatDays' => $planning['floatDays'],
				'desiredStartAchievable' => $planning['desiredStartAchievable'],
			];
		};

		$singles = [];
		foreach ($levers as $lever) {
			$change = $lever['build']($slip);
			if ($change === null) {
				continue;
			}
			$outcome = $evaluate([$change]);
			if ($outcome['recoveredDays'] > 0) {
				$singles[] = $outcome + ['lever' => $lever['id']];
			}
		}
		usort($singles, fn (array $a, array $b): int => $this->compareOutcomes($a, $b));
		// Lead with the best fix of each kind, so one kind of lever does not crowd out the others.
		$leaders = [];
		foreach ($singles as $index => $outcome) {
			$leaders[strstr($outcome['lever'], ':', true) ?: $outcome['lever']] ??= $index;
		}
		$singles = array_merge(
			array_values(array_intersect_key($singles, array_flip($leaders))),
			array_values(array_diff_key($singles, array_flip($leaders))),
		);

		$suggestions = array_map(fn (array $outcome): array => $this->describe($outcome, $slip), array_slice($singles, 0, self::MAX_SUGGESTIONS));

		// No single fix recovers everything: stack the best ones, each sized to what is still missing.
		if ($singles !== [] && $singles[0]['slipDays'] > 0) {
			$best = ['changes' => [], 'slipDays' => $slip];
			$used = [];
			for ($round = 0; $round < self::MAX_COMBINED_FIXES && $best['slipDays'] > 0; $round++) {
				$roundBest = null;
				foreach ($levers as $lever) {
					if (isset($used[$lever['id']]) || ($change = $lever['build']($best['slipDays'])) === null) {
						continue;
					}
					$outcome = $evaluate(array_merge($best['changes'], [$change]));
					if ($outcome['slipDays'] < $best['slipDays'] && ($roundBest === null || $this->compareOutcomes($outcome, $roundBest) < 0)) {
						$roundBest = $outcome + ['lever' => $lever['id']];
					}
				}
				if ($roundBest === null) {
					break;
				}
				$used[$roundBest['lever']] = true;
				$best = $roundBest;
			}
			if (count($best['changes']) > 1) {
				array_unshift($suggestions, $this->describe($best, $slip));
			}
		}

		return ['slipDays' => $slip, 'suggestions' => $suggestions];
	}

	/**
	 * @return array{hierarchy: array<string, mixed>, tasks: array<string, array<string, mixed>>, summary: array<string, mixed>, analysis: array<string, array<string, mixed>>}
	 */
	private function loadBaseline(Project $project): array
	{
		$hierarchy = $this->phaseService->getProjectPhaseHierarchy($project);
		$tasks = $this->indexTasks($hierarchy['phases']);
		$this->taskLabels = array_map(static fn (array $task): string => (string)$task['label'], $tasks);
		$summary = $this->planningService->buildSummary($project, $hierarchy['phases']);
		return [
			'hierarchy' => $hierarchy,
			'tasks' => $tasks,
			'summary' => $summary,
			'analysis' => $this->criticalPath->analyze($tasks, $this->overlapsFrom($hierarchy['dependencies']), $this->deadline($summary)),
		];
	}

	/**
	 * @param array<int, mixed> $changes
	 * @param array<string, mixed> $base From loadBaseline()
	 * @return array<string, mixed>
	 */
	private function run(Project $project, array $changes, array $base): array
	{
		$baseline = $base['hierarchy'];
		$baselineTasks = $base['tasks'];
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

		$baselineSummary = $base['summary'];
		$scenarioSummary = $normalized['changes'] === []
			? $baselineSummary
			: $this->planningService->buildSummary($scenarioProject, $scenario['phases']);

		$baselineAnalysis = $base['analysis'];
		$scenarioAnalysis = $normalized['changes'] === []
			? $baselineAnalysis
			: $this->criticalPath->analyze(
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
			'summary' => $scenarioSummary,
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
					// null clears the limit, including one saved on the live plan.
					$override['startNotBefore'] = $date;
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
	 * What can be pulled to win time back: every open card on the critical path can be
	 * shortened, every critical dependency can overlap, and preparation can be cut.
	 * Shortening and overlapping are capped at half the card's length.
	 *
	 * @param array<string, mixed> $scenario
	 * @return array<int, array{id: string, build: callable(int): ?array}>
	 */
	private function findLevers(array $scenario): array
	{
		$tasks = [];
		foreach ($scenario['phases'] as $phase) {
			foreach ($phase['tasks'] as $task) {
				$tasks[(string)$task['id']] = $task;
			}
		}
		$isOpenCritical = static fn (?array $task): bool => $task !== null && empty($task['isDone']) && $task['whatIf']['isCritical'];

		$levers = [];
		foreach ($tasks as $id => $task) {
			$maxCut = intdiv((int)$task['durationDays'], 2);
			if (!$isOpenCritical($task) || $maxCut < 1) {
				continue;
			}
			$levers[] = [
				'id' => 'shorten:' . $id,
				'build' => static fn (int $needed): array => ['type' => 'delay', 'taskId' => $task['id'], 'days' => -min($needed, $maxCut)],
			];
		}

		foreach ($scenario['dependencies'] as $dependency) {
			$predecessor = $tasks[(string)$dependency['predecessorId']] ?? null;
			$successor = $tasks[(string)$dependency['successorId']] ?? null;
			if (!$isOpenCritical($predecessor) || !$isOpenCritical($successor)) {
				continue;
			}
			$current = (int)($dependency['overlapDays'] ?? 0);
			$room = intdiv((int)$predecessor['durationDays'], 2) - $current;
			if ($room < 1) {
				continue;
			}
			$levers[] = [
				'id' => 'overlap:' . TimelinePhaseService::dependencyKey($predecessor['id'], $successor['id']),
				'build' => static fn (int $needed): array => [
					'type' => 'overlap',
					'predecessorId' => $predecessor['id'],
					'successorId' => $successor['id'],
					'days' => $current + min($needed, $room),
				],
			];
		}

		$weeks = (int)$scenario['impact']['planning']['preparationWeeks'];
		if ($weeks > 0) {
			$levers[] = [
				'id' => 'preparation',
				'build' => static fn (int $needed): array => [
					'type' => 'planning',
					'requiredPreparationWeeks' => max(0, $weeks - (int)ceil($needed / 7)),
				],
			];
		}

		return $levers;
	}

	/**
	 * Full recoveries first, then fewer changes, then the most days won back.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	private function compareOutcomes(array $a, array $b): int
	{
		return [$a['slipDays'] > 0, count($a['changes']), -$a['recoveredDays']]
			<=> [$b['slipDays'] > 0, count($b['changes']), -$b['recoveredDays']];
	}

	/**
	 * @param array<string, mixed> $outcome
	 * @return array<string, mixed>
	 */
	private function describe(array $outcome, int $slip): array
	{
		$changes = array_map(fn (array $change): array => $this->labelChange($change), $outcome['changes']);
		return [
			'kind' => count($changes) > 1 ? 'combined' : $changes[0]['kind'],
			'title' => implode(' + ', array_column($changes, 'title')),
			'changes' => array_map(static function (array $change): array {
				unset($change['kind'], $change['title']);
				return $change;
			}, $changes),
			'recoveredDays' => $outcome['recoveredDays'],
			'remainingSlipDays' => $outcome['slipDays'],
			'recoversFully' => $outcome['slipDays'] <= 0,
			'minimumStartDate' => $outcome['minimumStartDate'],
			'floatDays' => $outcome['floatDays'],
			'desiredStartAchievable' => $outcome['desiredStartAchievable'],
		];
	}

	/**
	 * @param array<string, mixed> $change
	 * @return array<string, mixed>
	 */
	private function labelChange(array $change): array
	{
		$labels = $this->taskLabels;
		return match ($change['type']) {
			'delay' => $change + [
				'kind' => 'shorten',
				'title' => sprintf('Shorten "%s" by %s', $labels[(string)$change['taskId']] ?? $change['taskId'], $this->days(-$change['days'])),
			],
			'overlap' => $change + [
				'kind' => 'overlap',
				'title' => sprintf(
					'Start "%s" %s before "%s" ends',
					$labels[(string)$change['successorId']] ?? $change['successorId'],
					$this->days($change['days']),
					$labels[(string)$change['predecessorId']] ?? $change['predecessorId'],
				),
			],
			'planning' => $change + [
				'kind' => 'preparation',
				'title' => sprintf('Cut preparation to %d %s', $change['requiredPreparationWeeks'], $change['requiredPreparationWeeks'] === 1 ? 'week' : 'weeks'),
			],
		};
	}

	private function days(int $days): string
	{
		return $days === 1 ? '1 day' : "{$days} days";
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
