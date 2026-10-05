<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineScenario;
use OCA\ProjectCreatorAIO\Db\TimelineScenarioMapper;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Named What-If scenarios saved on a project. Every member can see and load them; the
 * person who saved one, or the project owner, can overwrite, rename or delete it.
 */
class TimelineScenarioLibraryService
{
	public const MAX_SCENARIOS_PER_PROJECT = 50;
	private const MAX_NAME_LENGTH = 120;

	public function __construct(
		private readonly TimelineScenarioMapper $mapper,
		private readonly TimelineScenarioService $scenarioService,
		private readonly IUserManager $userManager,
	) {
	}

	/**
	 * Saved scenarios with their headline numbers, next to the live plan's.
	 *
	 * @return array{livePlan: array<string, mixed>, scenarios: array<int, array<string, mixed>>}
	 */
	public function list(Project $project, ?IUser $user): array
	{
		$saved = $this->mapper->findByProject((int)$project->getId());
		$changeLists = [];
		foreach ($saved as $scenario) {
			$changeLists[$scenario->getId()] = $scenario->getChangeList();
		}
		$comparison = $this->scenarioService->compare($project, $changeLists);

		$scenarios = [];
		foreach ($saved as $scenario) {
			$scenarios[] = $this->present($project, $scenario, $user) + $comparison['scenarios'][$scenario->getId()];
		}
		return ['livePlan' => $comparison['livePlan'], 'scenarios' => $scenarios];
	}

	/**
	 * @param array<int, mixed> $changes
	 * @return array<string, mixed>
	 */
	public function save(Project $project, IUser $user, mixed $name, array $changes): array
	{
		$projectId = (int)$project->getId();
		if ($this->mapper->countByProject($projectId) >= self::MAX_SCENARIOS_PER_PROJECT) {
			throw new \InvalidArgumentException('A project can keep at most ' . self::MAX_SCENARIOS_PER_PROJECT . ' saved scenarios. Delete one first.');
		}

		$scenario = new TimelineScenario();
		$scenario->setProjectId($projectId);
		$scenario->setName($this->requireName($name));
		$scenario->setChanges($this->encodeChanges($project, $changes));
		$scenario->setCreatedBy($user->getUID());
		$now = new DateTime();
		$scenario->setCreatedAt($now);
		$scenario->setUpdatedAt($now);

		return $this->present($project, $this->mapper->insert($scenario), $user);
	}

	/**
	 * @param array<int, mixed>|null $changes
	 * @return array<string, mixed>
	 */
	public function update(Project $project, IUser $user, int $id, mixed $name, ?array $changes): array
	{
		$scenario = $this->requireEditable($project, $user, $id);
		if ($name !== null) {
			$scenario->setName($this->requireName($name));
		}
		if ($changes !== null) {
			$scenario->setChanges($this->encodeChanges($project, $changes));
		}
		$scenario->setUpdatedAt(new DateTime());

		return $this->present($project, $this->mapper->update($scenario), $user);
	}

	public function delete(Project $project, IUser $user, int $id): void
	{
		$this->mapper->delete($this->requireEditable($project, $user, $id));
	}

	private function requireEditable(Project $project, IUser $user, int $id): TimelineScenario
	{
		$scenario = $this->mapper->findInProject((int)$project->getId(), $id);
		if ($scenario === null) {
			throw new OCSNotFoundException('Scenario not found');
		}
		if (!$this->canEdit($project, $scenario, $user)) {
			throw new OCSForbiddenException('Only the person who saved this scenario or the project owner can change it');
		}
		return $scenario;
	}

	private function canEdit(Project $project, TimelineScenario $scenario, ?IUser $user): bool
	{
		return $user !== null && in_array($user->getUID(), [$scenario->getCreatedBy(), (string)$project->getOwnerId()], true);
	}

	private function requireName(mixed $name): string
	{
		$name = is_string($name) ? trim($name) : '';
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new \InvalidArgumentException('Give the scenario a name of at most ' . self::MAX_NAME_LENGTH . ' characters');
		}
		return $name;
	}

	/**
	 * Saves the changes as the server understands them, after checking them against the plan.
	 *
	 * @param array<int, mixed> $changes
	 */
	private function encodeChanges(Project $project, array $changes): string
	{
		if ($changes === []) {
			throw new \InvalidArgumentException('An empty scenario is the live plan; add a change before saving it');
		}
		$normalized = $this->scenarioService->simulate($project, $changes)['changes'];
		$stored = array_map(static function (array $change): array {
			unset($change['label'], $change['predecessorLabel'], $change['successorLabel']);
			return $change;
		}, $normalized);
		return json_encode($stored, JSON_THROW_ON_ERROR);
	}

	/** @return array<string, mixed> */
	private function present(Project $project, TimelineScenario $scenario, ?IUser $user): array
	{
		$creator = $this->userManager->getDisplayName($scenario->getCreatedBy());
		return $scenario->jsonSerialize() + [
			'createdByDisplayName' => $creator ?? $scenario->getCreatedBy(),
			'canEdit' => $this->canEdit($project, $scenario, $user),
		];
	}
}
