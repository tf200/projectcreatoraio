<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\Project;

/**
 * Which timeline tasks are running late. What-If scenarios and their fixes live in
 * TimelineScenarioService.
 */
class TimelineImpactService
{
	public function __construct(
		private readonly TimelinePhaseService $phaseService,
	) {
	}

	/**
	 * @param array<int, array<string, mixed>>|null $phases The project's phase hierarchy when already loaded
	 * @return array{hasActiveDelays: bool, delayedTasks: array<int, array<string, mixed>>}
	 */
	public function analyzeProjectDelays(Project $project, ?array $phases = null): array
	{
		$phases ??= $this->phaseService->getProjectPhaseHierarchy($project)['phases'];
		$delayedTasks = [];

		foreach ($phases as $phase) {
			foreach ($phase['tasks'] as $task) {
				if (!empty($task['isDelayed']) || $task['status'] === 'behind_at_risk') {
					$delayedTasks[] = array_merge($task, [
						'phaseCategory' => $phase['category'],
						'phaseName' => $phase['name'],
					]);
				}
			}
		}

		return [
			'hasActiveDelays' => !empty($delayedTasks),
			'delayedTasks' => $delayedTasks,
		];
	}
}
