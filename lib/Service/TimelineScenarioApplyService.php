<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCP\IDBConnection;
use OCP\IUser;

/**
 * Makes a What-If scenario the plan: Deck card dates, saved overlaps and start limits,
 * and planning settings, so that the reloaded timeline shows exactly what was simulated.
 */
class TimelineScenarioApplyService
{
	public function __construct(
		private readonly TimelineScenarioService $scenarioService,
		private readonly TimelinePhaseService $phaseService,
		private readonly DeckCardScheduleService $deckCardScheduleService,
		private readonly TimelineItemMapper $itemMapper,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectActivityService $activityService,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * @param array<int, mixed> $changes
	 * @param array<int, mixed>|null $expectedDeckCardUpdates The card updates the user reviewed; refused when the plan has moved since
	 * @return array<string, mixed>
	 */
	public function apply(Project $project, array $changes, ?array $expectedDeckCardUpdates, ?IUser $user): array
	{
		if ($changes === []) {
			throw new \InvalidArgumentException('The scenario has no changes to apply');
		}

		$baseline = $this->phaseService->getProjectPhaseHierarchy($project);
		$baselineTasks = [];
		foreach ($baseline['phases'] as $phase) {
			foreach ($phase['tasks'] as $task) {
				$baselineTasks[(string)$task['id']] = $task;
			}
		}
		$normalized = $this->scenarioService->normalizeChanges($changes, $baselineTasks, $baseline['dependencies']);
		$scenario = $this->scenarioService->simulate($project, $changes);
		$cardUpdates = $scenario['impact']['deckCardUpdates'];

		if ($expectedDeckCardUpdates !== null && $this->fingerprint($expectedDeckCardUpdates) !== $this->fingerprint($cardUpdates)) {
			throw new TimelineScenarioConflictException('The plan changed since this scenario was previewed. Review the scenario again before applying it.');
		}
		foreach ($cardUpdates as $update) {
			$this->deckCardScheduleService->assertCardCanBeRescheduled($project, (int)$update['cardId']);
		}

		$projectId = (int)$project->getId();
		$this->db->beginTransaction();
		try {
			if ($normalized['planning'] !== []) {
				if (array_key_exists('desiredStartDate', $normalized['planning'])) {
					$desired = $normalized['planning']['desiredStartDate'];
					$project->setDesiredStartDate($desired === null ? null : new DateTime($desired));
				}
				if (array_key_exists('requiredPreparationWeeks', $normalized['planning'])) {
					$project->setRequiredPreparationWeeks($normalized['planning']['requiredPreparationWeeks']);
				}
				$this->projectMapper->updateProjectDetails($project);
			}

			foreach ($normalized['dependencyOverlaps'] as $key => $days) {
				$this->saveOverride($projectId, TimelinePhaseService::OVERLAP_KEY_PREFIX . sha1($key), $key, $days > 0 ? ['durationDays' => $days] : null);
			}
			foreach ($normalized['taskOverrides'] as $taskId => $override) {
				if (array_key_exists('startNotBefore', $override)) {
					$date = $override['startNotBefore'];
					$this->saveOverride($projectId, TimelinePhaseService::START_LIMIT_KEY_PREFIX . sha1((string)$taskId), (string)$taskId, $date === null ? null : ['startDate' => $date]);
				}
			}

			foreach ($cardUpdates as $update) {
				$this->deckCardScheduleService->updateCardSchedule(
					$project,
					(int)$update['cardId'],
					new DateTime($update['toStartDate']),
					new DateTime($update['toEndDate']),
				);
			}
			// Tasks without a Deck card keep their new length as a schedule override.
			foreach ($scenario['impact']['tasks'] as $task) {
				if (empty($task['deckCardId']) && !$task['isDone'] && $task['durationChangeDays'] !== 0) {
					$this->saveOverride($projectId, 'schedule_override:' . sha1((string)$task['id']), (string)$task['id'], ['durationDays' => $task['durationDays']]);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$after = $this->phaseService->getProjectPhaseHierarchy($project);
		$mismatches = $this->findMismatches($scenario['phases'], $after['phases']);

		if ($user !== null) {
			$this->activityService->record(
				$project,
				ProjectActivityService::EVENT_TIMELINE_ITEM_UPDATED,
				ProjectActivityService::SOURCE_INTERNAL,
				$user,
				[
					'scenario' => $normalized['changes'],
					'message' => sprintf(
						'What-If scenario applied by %s: %d %s, %d Deck %s rescheduled',
						$user->getDisplayName(),
						count($normalized['changes']),
						count($normalized['changes']) === 1 ? 'change' : 'changes',
						count($cardUpdates),
						count($cardUpdates) === 1 ? 'card' : 'cards',
					),
				],
			);
		}

		return [
			'applied' => true,
			'changes' => $normalized['changes'],
			'deckCardsUpdated' => count($cardUpdates),
			'planningChanged' => $normalized['planning'] !== [],
			'mismatches' => $mismatches,
			'hierarchy' => $after,
		];
	}

	/**
	 * @param array<string, mixed>|null $values Fields to save, or null to remove the override
	 */
	private function saveOverride(int $projectId, string $systemKey, string $label, ?array $values): void
	{
		$item = $this->itemMapper->findByProjectAndSystemKey($projectId, $systemKey);
		if ($values === null) {
			if ($item !== null) {
				$this->itemMapper->delete($item);
			}
			return;
		}

		$item ??= $this->itemMapper->createItem(
			$projectId,
			$label,
			null,
			null,
			'#64748b',
			$this->itemMapper->getNextOrderIndex($projectId),
			$systemKey,
			'schedule_override',
		);
		if (isset($values['startDate'])) {
			$item->setStartDate(new DateTime((string)$values['startDate']));
		}
		if (isset($values['durationDays'])) {
			$item->setDurationDays((int)$values['durationDays']);
		}
		$this->itemMapper->updateItem($item);
	}

	/**
	 * @param array<int, mixed> $updates
	 */
	private function fingerprint(array $updates): string
	{
		$rows = [];
		foreach ($updates as $update) {
			if (!is_array($update)) {
				return 'invalid';
			}
			$rows[] = implode('|', [(int)($update['cardId'] ?? 0), (string)($update['toStartDate'] ?? ''), (string)($update['toEndDate'] ?? '')]);
		}
		sort($rows);
		return implode(';', $rows);
	}

	/**
	 * Tasks whose reloaded dates differ from the simulated ones.
	 *
	 * @param array<int, array<string, mixed>> $expectedPhases
	 * @param array<int, array<string, mixed>> $actualPhases
	 * @return array<int, array<string, mixed>>
	 */
	private function findMismatches(array $expectedPhases, array $actualPhases): array
	{
		$actual = [];
		foreach ($actualPhases as $phase) {
			foreach ($phase['tasks'] as $task) {
				$actual[(string)$task['id']] = $task;
			}
		}

		$mismatches = [];
		foreach ($expectedPhases as $phase) {
			foreach ($phase['tasks'] as $task) {
				$found = $actual[(string)$task['id']] ?? null;
				if ($found === null || $found['startDate'] !== $task['startDate'] || $found['endDate'] !== $task['endDate']) {
					$mismatches[] = [
						'id' => $task['id'],
						'label' => $task['label'],
						'expectedStartDate' => $task['startDate'],
						'expectedEndDate' => $task['endDate'],
						'startDate' => $found['startDate'] ?? null,
						'endDate' => $found['endDate'] ?? null,
					];
				}
			}
		}
		return $mismatches;
	}
}
