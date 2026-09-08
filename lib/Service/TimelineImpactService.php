<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;

class TimelineImpactService
{
	public function __construct(
		private readonly TimelinePhaseService $phaseService,
		private readonly TimelinePlanningService $planningService,
		private readonly TimelineItemMapper $itemMapper,
		private readonly ProjectActivityService $activityService,
		private readonly IUserSession $userSession,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Analyze current project state for any delayed tasks or candidate simulation targets.
	 *
	 * @return array{
	 *     hasActiveDelays: bool,
	 *     delayedTasks: array<int, array<string, mixed>>,
	 *     defaultAnalysis: array<string, mixed>
	 * }
	 */
	public function analyzeProjectDelays(Project $project): array
	{
		$hierarchy = $this->phaseService->getProjectPhaseHierarchy($project);
		$delayedTasks = [];

		foreach ($hierarchy['phases'] as $phase) {
			foreach ($phase['tasks'] as $task) {
				if (!empty($task['isDelayed']) || $task['status'] === 'behind_at_risk') {
					$delayedTasks[] = array_merge($task, [
						'phaseCategory' => $phase['category'],
						'phaseName' => $phase['name'],
					]);
				}
			}
		}

		// Find default candidate task for simulation / analysis
		$candidateTask = null;
		if (!empty($delayedTasks)) {
			$candidateTask = $delayedTasks[0];
		} else {
			// Find "Permits" or first task in Preparation Phase matching the mockup
			foreach ($hierarchy['phases'] as $phase) {
				if ($phase['category'] === 'preparation' && !empty($phase['tasks'])) {
					foreach ($phase['tasks'] as $t) {
						if (stripos($t['label'], 'permit') !== false) {
							$candidateTask = array_merge($t, [
								'phaseCategory' => $phase['category'],
								'phaseName' => $phase['name'],
							]);
							break 2;
						}
					}
					$candidateTask = array_merge($phase['tasks'][0], [
						'phaseCategory' => $phase['category'],
						'phaseName' => $phase['name'],
					]);
					break;
				}
			}
		}

		// Default fallback if no preparation task exists
		if ($candidateTask === null && !empty($hierarchy['phases'][0]['tasks'])) {
			$candidateTask = array_merge($hierarchy['phases'][0]['tasks'][0], [
				'phaseCategory' => $hierarchy['phases'][0]['category'],
				'phaseName' => $hierarchy['phases'][0]['name'],
			]);
		}

		$delayDays = !empty($candidateTask['delayDays']) ? (int)$candidateTask['delayDays'] : 28; // Default 4 weeks example matching mockup
		$defaultAnalysis = $candidateTask ? $this->calculateTaskImpact($project, $candidateTask['id'], $delayDays) : [];

		return [
			'hasActiveDelays' => !empty($delayedTasks),
			'delayedTasks' => $delayedTasks,
			'defaultAnalysis' => $defaultAnalysis,
		];
	}

	/**
	 * Calculate downstream impact for a task delay and provide the 6 recovery options.
	 *
	 * @return array<string, mixed>
	 */
	public function calculateTaskImpact(
		Project $project,
		int|string $taskId,
		int $delayDays,
		?string $newExpectedEndDate = null
	): array {
		$baseline = $this->phaseService->getProjectPhaseHierarchy($project);

		// Build baseline lookup map
		$baselineTasks = [];
		foreach ($baseline['phases'] as $phase) {
			foreach ($phase['tasks'] as $task) {
				$baselineTasks[(string)$task['id']] = array_merge($task, [
					'phaseCategory' => $phase['category'],
					'phaseName' => $phase['name'],
				]);
			}
		}

		$targetTask = $baselineTasks[(string)$taskId] ?? null;
		if (!$targetTask) {
			// Search by label substring
			foreach ($baselineTasks as $id => $task) {
				if (stripos($task['label'], (string)$taskId) !== false) {
					$targetTask = $task;
					$taskId = $task['id'];
					break;
				}
			}
		}

		if (!$targetTask) {
			$targetTask = reset($baselineTasks) ?: [
				'id' => $taskId,
				'label' => 'Task',
				'startDate' => (new DateTime())->format('Y-m-d'),
				'endDate' => (new DateTime('+14 days'))->format('Y-m-d'),
				'plannedEndDate' => (new DateTime('+14 days'))->format('Y-m-d'),
				'durationDays' => 14,
				'phaseCategory' => 'preparation',
				'phaseName' => 'Preparation Phase',
			];
			$taskId = $targetTask['id'];
		}

		// Calculate delayDays from newExpectedEndDate if provided
		if ($newExpectedEndDate !== null) {
			$baseEnd = new DateTime($targetTask['plannedEndDate'] ?? $targetTask['endDate']);
			$newEnd = new DateTime($newExpectedEndDate);
			if ($newEnd > $baseEnd) {
				$delayDays = max(0, (int)$baseEnd->diff($newEnd)->days);
			}
		}

		$delayDays = max(1, $delayDays);
		$delayWeeks = (int)round($delayDays / 7);

		// Forward pass with delay on target task
		$delayed = $this->phaseService->getProjectPhaseHierarchy($project, [
			'taskOverrides' => [
				(string)$taskId => ['delayDays' => $delayDays],
			],
		]);

		$delayedTasks = [];
		foreach ($delayed['phases'] as $phase) {
			foreach ($phase['tasks'] as $task) {
				$delayedTasks[(string)$task['id']] = $task;
			}
		}

		// Compute affected downstream tasks
		$impactedTasks = [];
		foreach ($delayedTasks as $id => $delTask) {
			if ($id === (string)$taskId) {
				continue;
			}
			$baseTask = $baselineTasks[$id] ?? null;
			if (!$baseTask) {
				continue;
			}
			$baseStart = new DateTime($baseTask['startDate']);
			$delStart = new DateTime($delTask['startDate']);
			if ($delStart > $baseStart) {
				$shiftDays = (int)$baseStart->diff($delStart)->days;
				$impactedTasks[] = [
					'id' => $id,
					'label' => $baseTask['label'],
					'phaseCategory' => $baseTask['phaseCategory'],
					'baselineStart' => $baseTask['startDate'],
					'projectedStart' => $delTask['startDate'],
					'shiftDays' => $shiftDays,
					'shiftWeeks' => (int)round($shiftDays / 7),
				];
			}
		}

		// Milestone impacts
		$planning = $this->planningService->buildSummary($project);
		$floatDays = max(0, (int)($planning['systemPlanning']['float']['days'] ?? 0));
		$desiredStartDate = $planning['systemPlanning']['desiredStart']['date'] ?? null;
		$minimumStartDate = $planning['systemPlanning']['minimumStart']['date'] ?? null;

		$startConstructionImpactWeeks = $delayWeeks;
		$floatConsumedDays = min($floatDays, $delayDays);
		$residualDelayDays = max(0, $delayDays - $floatConsumedDays);
		$desiredStartAchievable = $desiredStartDate ? ($residualDelayDays <= 0) : true;

		// Severity
		$severity = 'low';
		if ($delayDays >= 21 || ($residualDelayDays > 0 && !$desiredStartAchievable)) {
			$severity = 'high';
		} elseif ($delayDays > 7) {
			$severity = 'medium';
		}

		// Format impacts list matching mockup
		$impactBullets = [];
		if (!empty($impactedTasks)) {
			$firstImpacted = $impactedTasks[0];
			$impactBullets[] = "{$firstImpacted['label']}: +{$firstImpacted['shiftWeeks']} weeks";
		}
		$impactBullets[] = "Start construction: +{$delayWeeks} weeks";
		if ($desiredStartDate) {
			$impactBullets[] = "Desired start (" . (new DateTime($desiredStartDate))->format('d/m/Y') . "): " . ($desiredStartAchievable ? "achievable" : "no longer achievable");
		}
		$floatLabel = (int)round($floatDays / 7) . " week";
		$impactBullets[] = "Available float: {$floatLabel} (" . ($floatConsumedDays > 0 ? "consumed by delay" : "preserved") . ")";

		$newExpectedDate = (new DateTime($targetTask['endDate']))->modify("+{$delayDays} days")->format('Y-m-d');

		// Build 6 Standard Recovery Options (A through F)
		$recoveryOptions = [
			[
				'id' => 'shift_everything',
				'letter' => 'A',
				'title' => 'Shift everything',
				'description' => 'All dependent tasks move the same amount. Simple and safe.',
				'tag' => 'Safe',
				'recommended' => false,
				'deltaWeeks' => $delayWeeks,
				'summary' => "Start construction delayed by +{$delayWeeks} weeks",
			],
			[
				'id' => 'use_float',
				'letter' => 'B',
				'title' => 'Use available float (if possible)',
				'description' => 'Available float is used first before tasks move.',
				'tag' => 'Float Buffer',
				'recommended' => $floatDays > 0,
				'available' => $floatDays > 0,
				'deltaWeeks' => (int)round($residualDelayDays / 7),
				'summary' => $residualDelayDays > 0
					? "Start construction delayed by +" . round($residualDelayDays / 7) . " weeks"
					: "Start construction on track (absorbed by float)",
			],
			[
				'id' => 'execute_in_parallel',
				'letter' => 'C',
				'title' => 'Execute in parallel (partial overlap)',
				'description' => 'Start a successor task partially before the predecessor is fully completed.',
				'tag' => 'Fast-track',
				'recommended' => false,
				'overlapDays' => 14,
				'deltaWeeks' => max(0, $delayWeeks - 2),
				'summary' => "Reduces downstream delay by up to 2 weeks via 14-day overlap",
			],
			[
				'id' => 'accelerate',
				'letter' => 'D',
				'title' => 'Accelerate (shorten task / add capacity)',
				'description' => 'Reduce duration of one or more tasks by adding extra capacity, teams, resources, etc.',
				'tag' => 'Crash',
				'recommended' => true,
				'shortenDays' => 14,
				'deltaWeeks' => max(0, $delayWeeks - 2),
				'summary' => "Accelerate subsequent task by 2 weeks, reducing net impact",
			],
			[
				'id' => 'keep_date',
				'letter' => 'E',
				'title' => 'Keep date, accept risk',
				'description' => 'Keep the end date. Accept the increased risk of not meeting the target.',
				'tag' => 'Accept Risk',
				'recommended' => false,
				'deltaWeeks' => 0,
				'summary' => "Target dates unchanged, elevated schedule risk flagged",
			],
			[
				'id' => 'new_baseline',
				'letter' => 'F',
				'title' => 'Create new baseline (re-baseline)',
				'description' => 'Establish a new official baseline for the project.',
				'tag' => 'Re-baseline',
				'recommended' => false,
				'deltaWeeks' => 0,
				'summary' => "Current projected schedule becomes the new official baseline",
			],
		];

		return [
			'task' => [
				'id' => $targetTask['id'],
				'label' => $targetTask['label'],
				'phaseCategory' => $targetTask['phaseCategory'],
				'phaseName' => $targetTask['phaseName'],
				'plannedEndDate' => $targetTask['plannedEndDate'] ?? $targetTask['endDate'],
				'newExpectedEndDate' => $newExpectedDate,
				'delayDays' => $delayDays,
				'delayWeeks' => $delayWeeks,
			],
			'severity' => $severity,
			'unmitigatedImpacts' => $impactBullets,
			'impactedTasks' => $impactedTasks,
			'floatRemainingWeeks' => (int)round(max(0, $floatDays - $delayDays) / 7),
			'recoveryOptions' => $recoveryOptions,
		];
	}

	/**
	 * Run an in-memory What-If scenario simulation combining a delay with a recovery strategy.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function simulateScenario(Project $project, array $params): array
	{
		$rootTaskId = $params['rootTaskId'] ?? null;
		$delayDays = (int)($params['delayDays'] ?? 28);
		$strategy = (string)($params['strategy'] ?? 'accelerate');
		$accelerateDays = (int)($params['accelerateDays'] ?? 14); // 2 weeks default acceleration

		$delayWeeks = (int)round($delayDays / 7);
		$taskOverrides = [];
		$baseline = $this->phaseService->getProjectPhaseHierarchy($project);
		$baselineTasks = $this->indexTasks($baseline['phases']);
		if ($rootTaskId === null || !isset($baselineTasks[(string)$rootTaskId])) {
			$requestedTask = (string)($rootTaskId ?? '');
			$rootTaskId = null;
			foreach ($baselineTasks as $task) {
				if ($requestedTask !== '' && stripos((string)$task['label'], $requestedTask) !== false) {
					$rootTaskId = $task['id'];
					break;
				}
			}
			$rootTaskId ??= array_key_first($baselineTasks);
		}

		if ($rootTaskId !== null) {
			$taskOverrides[(string)$rootTaskId] = ['delayDays' => $delayDays];
		}

		$scenarioDescription = "Delay +{$delayWeeks} weeks";
		$scenarioResultText = "Start construction: +{$delayWeeks} weeks";
		$scenarioStatusText = "Delayed by {$delayWeeks} weeks";
		$isAchievable = false;

		switch ($strategy) {
		case 'accelerate':
			$accelTask = $this->findRecoveryTask($baseline['phases'], $rootTaskId, true);
			$accelTaskId = $accelTask !== null ? (string)$accelTask['id'] : null;
			$accelTaskLabel = $accelTask['label'] ?? 'subsequent task';
			$actualAccelerateDays = 0;

			if ($accelTaskId) {
				$baseDuration = (int)($accelTask['durationDays'] ?? 28);
				$newDuration = max(7, $baseDuration - $accelerateDays);
				$actualAccelerateDays = $baseDuration - $newDuration;
				$taskOverrides[$accelTaskId] = [
					'durationDays' => $newDuration,
				];
			}

			$netDelayWeeks = max(0, $delayWeeks - (int)round($actualAccelerateDays / 7));
			$scenarioDescription = "Accelerate {$accelTaskLabel} by " . round($actualAccelerateDays / 7) . " weeks";
			$scenarioResultText = "Start construction: +{$netDelayWeeks} " . ($netDelayWeeks === 1 ? 'week' : 'weeks');
			$scenarioStatusText = $netDelayWeeks === 0 ? "On track" : "Still {$netDelayWeeks} " . ($netDelayWeeks === 1 ? 'week' : 'weeks') . " delayed";
			$isAchievable = $netDelayWeeks === 0;
			break;

		case 'execute_in_parallel':
			$overlapTask = $this->findRecoveryTask($baseline['phases'], $rootTaskId, false);
			if ($overlapTask !== null) {
				$taskOverrides[(string)$overlapTask['id']] = ['overlapDays' => 14];
			}
			$scenarioDescription = "Execute Work preparation in parallel (14-day overlap)";
			$netDelayWeeks = max(0, $delayWeeks - 2);
			$scenarioResultText = "Start construction: +{$netDelayWeeks} weeks";
			$scenarioStatusText = $netDelayWeeks === 0 ? "On track" : "Still {$netDelayWeeks} " . ($netDelayWeeks === 1 ? 'week' : 'weeks') . " delayed";
			$isAchievable = $netDelayWeeks === 0;
			break;

		case 'use_float':
			$planning = $this->planningService->buildSummary($project);
			$floatDays = max(0, (int)($planning['systemPlanning']['float']['days'] ?? 0));
			$residualDelayDays = max(0, $delayDays - $floatDays);
			if ($rootTaskId !== null) {
				$taskOverrides[(string)$rootTaskId] = ['delayDays' => $residualDelayDays];
			}
			$scenarioDescription = "Absorb delay using project float buffer";
			$netDelayWeeks = (int)round($residualDelayDays / 7);
			$scenarioResultText = "Start construction: +{$netDelayWeeks} weeks";
			$scenarioStatusText = $netDelayWeeks === 0 ? "On track" : "Still {$netDelayWeeks} weeks delayed";
			$isAchievable = $netDelayWeeks === 0;
			break;

		case 'new_baseline':
			$scenarioDescription = "Accept delayed dates as official schedule baseline";
			$scenarioResultText = "Baseline rescheduled to new completion date";
			$scenarioStatusText = "New baseline active";
			$isAchievable = true;
			break;

		case 'keep_date':
			if ($rootTaskId !== null) {
				$taskOverrides[(string)$rootTaskId] = ['status' => 'behind_at_risk'];
			}
			$scenarioDescription = "Maintain deadline, accept schedule compression risk";
			$scenarioResultText = "Target date preserved with high risk flag";
			$scenarioStatusText = "At risk";
			$isAchievable = false;
			break;

		case 'shift_everything':
		default:
			$scenarioDescription = "Shift all downstream tasks by +{$delayWeeks} weeks";
			$scenarioResultText = "Start construction: +{$delayWeeks} weeks";
			$scenarioStatusText = "Delayed by {$delayWeeks} weeks";
			$isAchievable = false;
			break;
		}

		// Re-run forward pass with all simulated overrides
		$simulated = $this->phaseService->getProjectPhaseHierarchy($project, [
			'taskOverrides' => $taskOverrides,
		]);
		$simulatedTasks = $this->indexTasks($simulated['phases']);
		$durableOverrides = [];
		foreach ($taskOverrides as $taskId => $override) {
			$baseTask = $baselineTasks[(string)$taskId] ?? null;
			$simulatedTask = $simulatedTasks[(string)$taskId] ?? null;
			if ($baseTask === null || $simulatedTask === null) {
				continue;
			}
			if (array_key_exists('delayDays', $override) || array_key_exists('durationDays', $override)) {
				$durableOverrides[(string)$taskId]['durationDays'] = (int)$simulatedTask['durationDays'];
			}
			if (array_key_exists('overlapDays', $override)) {
				$durableOverrides[(string)$taskId]['startDate'] = (string)$simulatedTask['startDate'];
			}
			if (isset($override['status'])) {
				$durableOverrides[(string)$taskId]['status'] = (string)$override['status'];
			}
		}
		if ($strategy === 'new_baseline') {
			foreach ($simulatedTasks as $taskId => $task) {
				$durableOverrides[$taskId]['durationDays'] = (int)$task['durationDays'];
				$durableOverrides[$taskId]['plannedEndDate'] = (string)$task['endDate'];
				$durableOverrides[$taskId]['status'] = 'on_track';
			}
		}

		return [
			'scenario' => [
				'strategy' => $strategy,
				'description' => $scenarioDescription,
				'currentImpact' => "Start construction: +{$delayWeeks} weeks",
				'scenarioResult' => $scenarioResultText,
				'scenarioStatus' => $scenarioStatusText,
				'isAchievable' => $isAchievable,
			],
			'simulatedPhases' => $simulated['phases'],
			'simulatedDependencies' => $simulated['dependencies'],
			'application' => [
				'rootTaskId' => $rootTaskId,
				'delayDays' => $delayDays,
				'accelerateDays' => $accelerateDays,
				'overrides' => $durableOverrides,
			],
		];
	}

	/**
	 * Apply chosen recovery strategy permanently and write audit log.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function applyRecoveryStrategy(Project $project, string $strategy, array $params, ?IUser $user = null): array
	{
		$projectId = (int)$project->getId();
		$allowedStrategies = ['shift_everything', 'use_float', 'execute_in_parallel', 'accelerate', 'keep_date', 'new_baseline'];
		if (!in_array($strategy, $allowedStrategies, true)) {
			throw new \InvalidArgumentException('Unknown recovery strategy');
		}

		$simulation = $this->simulateScenario($project, array_merge($params, ['strategy' => $strategy]));
		$overrides = $simulation['application']['overrides'] ?? [];
		if ($overrides === []) {
			throw new \InvalidArgumentException('The recovery strategy has no applicable timeline tasks');
		}
		$this->db->beginTransaction();
		try {
			foreach ($overrides as $taskId => $override) {
				$this->persistScheduleOverride($projectId, (string)$taskId, $override);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Audit logging
		$userName = $user ? $user->getDisplayName() : 'Project Administrator';
		$strategyLabels = [
			'shift_everything' => 'Shift everything',
			'use_float' => 'Use available float',
			'execute_in_parallel' => 'Execute in parallel',
			'accelerate' => 'Accelerate / Crash subsequent tasks',
			'keep_date' => 'Keep date and accept risk',
			'new_baseline' => 'Establish new official baseline',
		];
		$stratLabel = $strategyLabels[$strategy] ?? $strategy;

		if ($user) {
			$this->activityService->record(
				$project,
				ProjectActivityService::EVENT_TIMELINE_ITEM_UPDATED,
				ProjectActivityService::SOURCE_INTERNAL,
				$user,
				[
					'strategy' => $strategy,
					'message' => "Timeline recovery strategy applied: {$stratLabel} by {$userName}",
				]
			);
		}

		return [
			'success' => true,
			'strategy' => $strategy,
			'message' => "Successfully applied recovery strategy: {$stratLabel}",
			'summary' => $this->planningService->buildSummary($project),
			'hierarchy' => $this->phaseService->getProjectPhaseHierarchy($project),
		];
	}

	/** @param array<int, array<string, mixed>> $phases */
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

	/** @param array<int, array<string, mixed>> $phases */
	private function findRecoveryTask(array $phases, int|string|null $rootTaskId, bool $preferEarthworks): ?array
	{
		$fallback = null;
		$rootSeen = $rootTaskId === null;
		foreach ($phases as $phase) {
			foreach ($phase['tasks'] as $task) {
				if ($rootTaskId !== null && (string)$task['id'] === (string)$rootTaskId) {
					$rootSeen = true;
					continue;
				}
				if (!$rootSeen) {
					continue;
				}
				if ($preferEarthworks && stripos((string)$task['label'], 'earthworks') !== false) {
					return $task;
				}
				if ($fallback === null && !empty($task['predecessorIds'])) {
					$fallback = $task;
				}
			}
		}
		return $fallback;
	}

	/** @param array<string, mixed> $override */
	private function persistScheduleOverride(int $projectId, string $taskId, array $override): void
	{
		$systemKey = 'schedule_override:' . sha1($taskId);
		$item = $this->itemMapper->findByProjectAndSystemKey($projectId, $systemKey);
		if ($item === null) {
			$item = $this->itemMapper->createItem(
				$projectId,
				$taskId,
				null,
				null,
				'#64748b',
				$this->itemMapper->getNextOrderIndex($projectId),
				$systemKey,
				'schedule_override',
			);
		}
		if (!isset($override['status'])) {
			$item->setStatus('');
		}

		if (isset($override['startDate'])) {
			$item->setStartDate(new DateTime((string)$override['startDate']));
		}
		if (isset($override['durationDays'])) {
			$item->setDurationDays(max(1, (int)$override['durationDays']));
		}
		if (isset($override['plannedEndDate'])) {
			$item->setPlannedEndDate(new DateTime((string)$override['plannedEndDate']));
		}
		if (isset($override['status'])) {
			$item->setStatus((string)$override['status']);
		}
		$this->itemMapper->updateItem($item);
	}
}
