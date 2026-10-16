<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use DateTimeZone;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\TeamService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The handover step: after the process steps the project goes to the people
 * who carry it out. It records the handover date and can add people, set
 * the execution team, change the owner and set how long externals keep
 * access. Nobody leaves the project here; removing someone stays a separate,
 * explicit action.
 *
 * Everything is checked before anything changes. The steps then run in an
 * order that never takes access away in between: people first, then the
 * team, the owner, the externals and finally the date. A step that fails is
 * reported and the others still run.
 */
class ProjectHandoverStepService {
	public const TEAM_KEEP = 'keep';
	public const TEAM_SET = 'set';
	public const TEAM_NONE = 'none';
	public const EXTERNAL_UNTIL_END = 'untilEnd';
	public const EXTERNAL_AT_HANDOVER = 'atHandover';
	public const EXTERNAL_DATE = 'date';

	private const OPEN_GRANT_STATUSES = ['pending', 'active'];

	public function __construct(
		private ProjectMapper $projectMapper,
		private ProjectService $projectService,
		private ProjectAccessService $access,
		private ProjectOwnershipService $ownership,
		private ProjectMemberSourceMapper $memberSourceMapper,
		private TimelinePlanningService $planningService,
		private ProjectActivityService $activityService,
		private IUserManager $userManager,
		private LoggerInterface $logger,
		private ?TeamService $teams = null,
		private ?ExternalCollaboratorService $externals = null,
	) {
	}

	/**
	 * What the handover dialog starts from.
	 *
	 * @return array<string,mixed>
	 */
	public function preview(Project $project): array {
		$projectId = (int)$project->getId();
		$organizationId = (int)$project->getOrganizationId();
		$summary = $this->planningService->buildSummary($project);

		$members = [];
		foreach ($this->projectService->getProjectMembers($projectId) as $member) {
			$uid = (string)$member['id'];
			$sources = $this->memberSourceMapper->findSources($projectId, $uid);
			$members[] = [
				'id' => $uid,
				'displayName' => (string)$member['displayName'],
				'isOwner' => (bool)$member['isOwner'],
				'isExternal' => $this->access->isExternal($uid),
				'teamIds' => array_values(array_filter($sources, static fn (int $teamId): bool => $teamId !== ProjectMemberSourceMapper::MANUAL)),
				'addedByHand' => in_array(ProjectMemberSourceMapper::MANUAL, $sources, true),
				'drascivsRoles' => $member['drascivsRoles'] ?? [],
			];
		}

		return [
			'handoverDate' => $summary['actualHandoverDate'] ?? null,
			'plannedEndDate' => $summary['plannedEndDate'] ?? null,
			'ownerId' => trim((string)$project->getOwnerId()),
			'members' => $members,
			'functionalRoles' => $this->projectService->getProjectFunctionalRoles($projectId),
			'team' => $this->currentTeam($organizationId, $projectId),
			'teams' => $this->teams === null ? [] : array_map(static fn (array $team): array => [
				'id' => (int)$team['id'],
				'name' => (string)$team['name'],
				'members' => array_map(static fn (array $m): array => ['id' => (string)$m['uid'], 'displayName' => (string)$m['displayName']], $team['members'] ?? []),
			], $this->teams->list($organizationId)),
			'externals' => array_values(array_map(static fn (array $grant): array => [
				'id' => (string)$grant['userId'],
				'displayName' => (string)($grant['displayName'] ?? $grant['userId']),
				'company' => $grant['company'] ?? null,
				'status' => (string)$grant['status'],
				'expiresAt' => $grant['expiresAt'] ?? null,
			], $this->openGrants($projectId))),
			'teamsAvailable' => $this->teams !== null,
		];
	}

	/**
	 * @param array<string,mixed> $request handoverDate, ownerUid?, team?, addMembers?, externals?
	 * @return array{handoverDate: string, steps: list<array{step: string, status: string, message?: string}>}
	 */
	public function apply(Project $project, array $request, IUser $actor): array {
		$plan = $this->validate($project, $request);
		$projectId = (int)$project->getId();
		$organizationId = (int)$project->getOrganizationId();
		$steps = [];

		foreach ($plan['addMembers'] as $member) {
			$steps[] = $this->run('member:' . $member['uid'], function () use ($projectId, $member): void {
				$this->projectService->addMemberToProject($projectId, $member['uid'], $member['drascivsRoles'], $member['functionalRoleKeys'] === [] ? null : $member['functionalRoleKeys']);
			});
		}

		if ($plan['team']['mode'] !== self::TEAM_KEEP) {
			$teamId = $plan['team']['mode'] === self::TEAM_SET ? $plan['team']['teamId'] : null;
			// The organization app tells this app the team changed; the new
			// team's people join and the previous team's people stay.
			$steps[] = $this->run('team', function () use ($organizationId, $projectId, $teamId, $actor): void {
				$this->teamService()->assignProjectTeam($organizationId, $projectId, $teamId, $actor->getUID());
			});
		}

		$ownerUid = $plan['ownerUid'];
		if ($ownerUid !== null) {
			$steps[] = $this->run('owner', function () use ($projectId, $ownerUid): ?string {
				$fresh = $this->projectMapper->find($projectId) ?? throw new OCSException('Project not found', 404);
				$result = $this->ownership->transfer($fresh, $ownerUid);
				$notes = array_keys(array_filter(['board' => $result['board'], 'conversation' => $result['talk']], static fn (string $s): bool => $s === ProjectOwnershipService::STEP_FAILED));
				return $notes === [] ? null : 'Owner changed, but the ' . implode(' and the ', $notes) . ' could not follow.';
			});
		}

		foreach ($plan['externals'] as $external) {
			$steps[] = $this->run('external:' . $external['uid'], function () use ($organizationId, $projectId, $external, $actor): ?string {
				if ($external['endsAt'] === null) {
					return 'No end of work on site is planned yet, so the end date is unchanged.';
				}
				if ($external['endsAt'] <= new DateTime('now', new DateTimeZone('UTC'))) {
					$this->externalService()->revoke($organizationId, $projectId, $external['uid'], $actor->getUID());
					return 'Access ended now: the handover is not in the future.';
				}
				$this->externalService()->changeEndDate($organizationId, $projectId, $external['uid'], $external['endsAt'], $actor->getUID());
				return null;
			});
		}

		$steps[] = $this->run('handoverDate', function () use ($projectId, $plan): void {
			$fresh = $this->projectMapper->find($projectId) ?? throw new OCSException('Project not found', 404);
			$fresh->setActualHandoverDate(DateTime::createFromFormat('!Y-m-d', $plan['handoverDate']));
			$this->projectMapper->updateProjectDetails($fresh);
		});

		try {
			$this->activityService->recordHandedOver($this->projectMapper->find($projectId) ?? $project, $actor, [
				'handoverDate' => $plan['handoverDate'],
				'addedMembers' => array_column($plan['addMembers'], 'uid'),
				'team' => $plan['team'],
				'ownerUid' => $plan['ownerUid'],
				'externals' => array_map(static fn (array $e): array => ['uid' => $e['uid'], 'endsAt' => $e['endsAt']?->format(DATE_ATOM)], $plan['externals']),
				'failedSteps' => array_column(array_filter($steps, static fn (array $s): bool => $s['status'] === 'failed'), 'step'),
			]);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to record the project handover', ['projectId' => $projectId, 'exception' => $e]);
		}

		return ['handoverDate' => $plan['handoverDate'], 'steps' => $steps];
	}

	/**
	 * @return array{handoverDate: string, ownerUid: ?string, team: array{mode: string, teamId: ?int}, addMembers: list<array{uid: string, drascivsRoles: string[], functionalRoleKeys: string[]}>, externals: list<array{uid: string, endsAt: ?DateTime}>}
	 */
	private function validate(Project $project, array $request): array {
		$projectId = (int)$project->getId();
		$organizationId = (int)$project->getOrganizationId();

		$handoverDate = trim((string)($request['handoverDate'] ?? ''));
		$parsed = DateTime::createFromFormat('!Y-m-d', $handoverDate);
		if ($parsed === false || $parsed->format('Y-m-d') !== $handoverDate) {
			throw new OCSException('The handover date must be a date like 2026-10-28.', 400);
		}

		$addMembers = [];
		$adding = [];
		foreach ((array)($request['addMembers'] ?? []) as $entry) {
			$uid = trim((string)($entry['uid'] ?? ''));
			if ($uid === '' || $this->userManager->get($uid) === null) {
				throw new OCSException('One of the people to add does not exist.', 400);
			}
			if ($this->projectService->isProjectMember($project, $uid) || isset($adding[$uid])) {
				continue;
			}
			$membership = $this->access->getOrganizationMembership($uid);
			if ($membership === null || $membership['organization_id'] !== $organizationId) {
				throw new OCSException('Only members of the organization can be added here; invite externals from the Members tab.', 400);
			}
			$roles = $this->projectService->validateMemberRoles($project, (array)($entry['drascivsRoles'] ?? []), (array)($entry['functionalRoleKeys'] ?? []));
			$addMembers[] = ['uid' => $uid, 'drascivsRoles' => $roles['drasciRoles'], 'functionalRoleKeys' => $roles['functionalRoleKeys']];
			$adding[$uid] = true;
		}

		$mode = (string)($request['team']['mode'] ?? self::TEAM_KEEP);
		$teamId = isset($request['team']['teamId']) ? (int)$request['team']['teamId'] : null;
		if (!in_array($mode, [self::TEAM_KEEP, self::TEAM_SET, self::TEAM_NONE], true)) {
			throw new OCSException('Choose to keep, set or clear the team.', 400);
		}
		if ($mode !== self::TEAM_KEEP && $this->teams === null) {
			throw new OCSException('Teams are not available.', 400);
		}
		if ($mode === self::TEAM_SET) {
			$known = array_map(static fn (array $team): int => (int)$team['id'], $this->teams->list($organizationId));
			if ($teamId === null || !in_array($teamId, $known, true)) {
				throw new OCSException('Choose a team of this organization.', 400);
			}
			if (($this->currentTeam($organizationId, $projectId)['id'] ?? null) === $teamId) {
				$mode = self::TEAM_KEEP;
			}
		}
		if ($mode === self::TEAM_NONE && $this->currentTeam($organizationId, $projectId) === null) {
			$mode = self::TEAM_KEEP;
		}

		$ownerUid = trim((string)($request['ownerUid'] ?? ''));
		if ($ownerUid === '' || $ownerUid === trim((string)$project->getOwnerId())) {
			$ownerUid = null;
		} else {
			$teamMembers = [];
			if ($mode === self::TEAM_SET) {
				foreach ($this->teams->list($organizationId) as $team) {
					if ((int)$team['id'] === $teamId) {
						$teamMembers = array_column($team['members'] ?? [], 'uid');
					}
				}
			}
			$joins = isset($adding[$ownerUid]) || in_array($ownerUid, $teamMembers, true);
			if (!$joins && !$this->projectService->isProjectMember($project, $ownerUid)) {
				throw new OCSException('The new owner must be on the project, or be added in this handover.', 400);
			}
			$membership = $this->access->getOrganizationMembership($ownerUid);
			if ($membership === null || $membership['organization_id'] !== $organizationId) {
				throw new OCSException('Only members of the project\'s organization can own it, not external collaborators.', 400);
			}
		}

		$externals = [];
		$requested = (array)($request['externals'] ?? []);
		if ($requested !== []) {
			$open = [];
			foreach ($this->openGrants($projectId) as $grant) {
				$open[(string)$grant['userId']] = true;
			}
			$summary = $this->planningService->buildSummary($project);
			foreach ($requested as $entry) {
				$uid = trim((string)($entry['uid'] ?? ''));
				if (!isset($open[$uid])) {
					throw new OCSException('One of the externals is not invited to this project.', 400);
				}
				$externals[] = ['uid' => $uid, 'endsAt' => $this->externalEnd((string)($entry['mode'] ?? ''), $entry['date'] ?? null, $handoverDate, $summary['plannedEndDate'] ?? null)];
			}
		}

		return [
			'handoverDate' => $handoverDate,
			'ownerUid' => $ownerUid,
			'team' => ['mode' => $mode, 'teamId' => $mode === self::TEAM_SET ? $teamId : null],
			'addMembers' => $addMembers,
			'externals' => $externals,
		];
	}

	/** An end date ends at the end of that day (UTC), as the Members tab sets it. */
	private function externalEnd(string $mode, mixed $date, string $handoverDate, ?string $plannedEndDate): ?DateTime {
		$day = match ($mode) {
			self::EXTERNAL_UNTIL_END => $plannedEndDate,
			self::EXTERNAL_AT_HANDOVER => $handoverDate,
			self::EXTERNAL_DATE => is_string($date) ? trim($date) : '',
			default => throw new OCSException('Choose how long each external keeps access.', 400),
		};
		if ($day === null) {
			return null;
		}
		$parsed = DateTime::createFromFormat('!Y-m-d', substr($day, 0, 10), new DateTimeZone('UTC'));
		if ($parsed === false || $parsed->format('Y-m-d') !== substr($day, 0, 10)) {
			throw new OCSException('An external\'s end date must be a date like 2026-12-31.', 400);
		}
		return $parsed->setTime(23, 59, 59);
	}

	/**
	 * @param callable(): ?string $action Returns a note for a step done with a remark
	 * @return array{step: string, status: string, message?: string}
	 */
	private function run(string $step, callable $action): array {
		try {
			$note = $action();
			$result = ['step' => $step, 'status' => 'done'];
			if (is_string($note) && $note !== '') {
				$result['message'] = $note;
			}
			return $result;
		} catch (Throwable $e) {
			$this->logger->error('A handover step failed', ['step' => $step, 'exception' => $e]);
			return ['step' => $step, 'status' => 'failed', 'message' => $e instanceof OCSException ? $e->getMessage() : 'Something went wrong.'];
		}
	}

	/** @return ?array{id: int, name: string} */
	private function currentTeam(int $organizationId, int $projectId): ?array {
		if ($this->teams === null) {
			return null;
		}
		foreach ($this->teams->listProjectTeams($organizationId) as $row) {
			if ((int)$row['projectId'] === $projectId) {
				return $row['team'];
			}
		}
		return null;
	}

	/** @return list<array<string,mixed>> */
	private function openGrants(int $projectId): array {
		if ($this->externals === null) {
			return [];
		}
		return array_values(array_filter(
			$this->externals->listForProject($projectId),
			static fn (array $grant): bool => in_array($grant['status'] ?? '', self::OPEN_GRANT_STATUSES, true),
		));
	}

	private function teamService(): TeamService {
		return $this->teams ?? throw new OCSException('Teams are not available.', 400);
	}

	private function externalService(): ExternalCollaboratorService {
		return $this->externals ?? throw new OCSException('External collaborators are not available.', 400);
	}
}
