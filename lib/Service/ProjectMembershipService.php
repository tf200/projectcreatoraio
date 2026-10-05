<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Removes project members and keeps team-driven membership in sync.
 *
 * Removing a member revokes everything a project grants: the project group
 * (Deck board, Team Folder), DRASCIVS and functional roles, the Talk
 * conversation and the private folder, which is handed to the project owner.
 */
class ProjectMembershipService {
	public const PRIVATE_FOLDER_NONE = 'none';
	public const PRIVATE_FOLDER_MOVED = 'moved';
	public const PRIVATE_FOLDER_DELETED = 'deleted';
	public const PRIVATE_FOLDER_FAILED = 'failed';

	private const FORMER_MEMBERS_FOLDER = 'Former members';

	public function __construct(
		private ProjectMapper $projectMapper,
		private ProjectService $projectService,
		private ProjectMemberRoleMapper $memberRoleMapper,
		private ProjectMemberSourceMapper $memberSourceMapper,
		private BoardPolicyRoleMapper $policyRoleMapper,
		private BoardPolicyMembershipMapper $policyMembershipMapper,
		private ProjectTalkIntegrationService $talkIntegrationService,
		private ProjectActivityService $activityService,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{removed: bool, userId: string, privateFolder: string}
	 */
	public function removeMember(int $projectId, string $userId): array {
		$userId = trim($userId);
		if ($userId === '') {
			throw new OCSException('A user ID is required.', 400);
		}

		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSException("Project with ID $projectId not found", 404);
		}

		if (trim((string)$project->getOwnerId()) === $userId) {
			throw new OCSException('The project owner cannot be removed. Hand the project over to someone else first.', 409);
		}

		$groupGid = trim((string)($project->getProjectGroupGid() ?? ''));
		$inGroup = $groupGid !== '' && $this->groupManager->isInGroup($userId, $groupGid);
		if (!$inGroup && !$this->memberSourceMapper->hasAnySource($projectId, $userId)) {
			throw new OCSException('User is not a project member.', 404);
		}

		$user = $this->userManager->get($userId);
		if ($inGroup && $user !== null) {
			$this->groupManager->get($groupGid)?->removeUser($user);
		}

		$this->db->beginTransaction();
		try {
			$this->memberRoleMapper->deleteByProjectAndUser($projectId, $userId);
			$this->removeFunctionalRoleMemberships($project, $userId);
			$this->memberSourceMapper->deleteByProjectAndUser($projectId, $userId);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		if ($user !== null) {
			$this->talkIntegrationService->removeUserFromConversation((string)($project->getTalkConversationToken() ?? ''), $user);
		}

		$privateFolder = $this->handOverPrivateFolder($project, $userId, $user?->getDisplayName() ?: $userId);

		try {
			$this->activityService->recordMemberRemoved($project, $userId, $user?->getDisplayName(), $this->userSession->getUser());
		} catch (Throwable $e) {
			$this->logger->warning('Failed to record project member removal', ['projectId' => $projectId, 'exception' => $e]);
		}

		return ['removed' => true, 'userId' => $userId, 'privateFolder' => $privateFolder];
	}

	/**
	 * Drops one team origin of a member and removes the member once no
	 * origin remains. Manually added members are never removed here.
	 */
	public function releaseTeamSource(int $projectId, string $userId, int $teamId): bool {
		$this->memberSourceMapper->removeSource($projectId, $userId, $teamId);
		if ($this->memberSourceMapper->hasAnySource($projectId, $userId)) {
			return false;
		}

		$project = $this->projectMapper->find($projectId);
		if ($project === null || trim((string)$project->getOwnerId()) === $userId) {
			return false;
		}

		$groupGid = trim((string)($project->getProjectGroupGid() ?? ''));
		if ($groupGid === '' || !$this->groupManager->isInGroup($userId, $groupGid)) {
			return false;
		}

		$this->removeMember($projectId, $userId);
		return true;
	}

	/**
	 * A project's team was assigned, switched or unassigned.
	 */
	public function syncProjectTeam(int $projectId, ?int $previousTeamId, ?int $teamId): void {
		if ($teamId !== null && $teamId > 0 && !$this->addTeamMembers($projectId, $teamId, null)) {
			// The new team's members were not recorded, so releasing the old team
			// would also drop people who are in both teams. Keep the old access.
			$this->logger->error('Kept the previous team on the project because the new team could not be added', [
				'projectId' => $projectId,
				'previousTeamId' => $previousTeamId,
				'teamId' => $teamId,
			]);
			return;
		}

		if ($previousTeamId === null || $previousTeamId <= 0 || $previousTeamId === $teamId) {
			return;
		}

		foreach ($this->memberSourceMapper->findUserIdsByProjectAndTeam($projectId, $previousTeamId) as $userId) {
			$this->releaseSafely($projectId, $userId, $previousTeamId);
		}
	}

	public function addTeamMember(int $teamId, string $userId): void {
		foreach ($this->findProjectIdsForTeam($teamId) as $projectId) {
			$this->addTeamMembers($projectId, $teamId, [$userId]);
		}
	}

	public function removeTeamMember(int $teamId, string $userId): void {
		foreach ($this->memberSourceMapper->findByTeam($teamId, $userId) as $row) {
			$this->releaseSafely($row['projectId'], $row['userId'], $teamId);
		}
	}

	public function removeTeam(int $teamId): void {
		foreach ($this->memberSourceMapper->findByTeam($teamId) as $row) {
			$this->releaseSafely($row['projectId'], $row['userId'], $teamId);
		}
	}

	/**
	 * The user left the organization: revoke every project of that
	 * organization, whatever the origin of the membership.
	 */
	public function removeFromOrganizationProjects(int $organizationId, string $userId): void {
		foreach ($this->projectMapper->findByUserIdAndOrganizationId($userId, $organizationId) as $project) {
			$projectId = (int)$project->getId();
			if (trim((string)$project->getOwnerId()) === $userId) {
				$this->logger->warning('Organization member left while still owning a project; ownership must be handed over', [
					'projectId' => $projectId,
					'userId' => $userId,
				]);
				continue;
			}

			try {
				$this->removeMember($projectId, $userId);
			} catch (Throwable $e) {
				$this->logger->error('Failed to revoke project access for a removed organization member', [
					'projectId' => $projectId,
					'userId' => $userId,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * An external collaborator's grant became active: give them the project
	 * group, private folder, conversation and the roles chosen at invitation.
	 *
	 * @param string[] $drasciRoles
	 * @param string[] $functionalRoleKeys
	 */
	public function addExternalCollaborator(int $projectId, string $userId, array $drasciRoles, array $functionalRoleKeys): void {
		try {
			$this->projectService->addMemberToProject(
				$projectId,
				$userId,
				$drasciRoles,
				$functionalRoleKeys === [] ? null : $functionalRoleKeys,
			);
		} catch (Throwable $e) {
			$this->logger->error('Failed to add an external collaborator to a project', [
				'projectId' => $projectId,
				'userId' => $userId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * An external collaborator's grant was revoked or expired.
	 */
	public function removeExternalCollaborator(int $projectId, string $userId): void {
		try {
			$this->removeMember($projectId, $userId);
		} catch (Throwable $e) {
			$this->logger->error('Failed to remove an external collaborator from a project', [
				'projectId' => $projectId,
				'userId' => $userId,
				'exception' => $e,
			]);
		}
	}

	/** @param ?string[] $onlyUserIds */
	private function addTeamMembers(int $projectId, int $teamId, ?array $onlyUserIds): bool {
		try {
			$result = $this->projectService->addMembersToProjectBulk($projectId, [], [], null, $teamId, $onlyUserIds);
			if ($result['rejected'] !== []) {
				$this->logger->warning('Some team members could not be added to a project', [
					'projectId' => $projectId,
					'teamId' => $teamId,
					'rejected' => $result['rejected'],
				]);
			}
			return true;
		} catch (Throwable $e) {
			$this->logger->error('Failed to add team members to a project', [
				'projectId' => $projectId,
				'teamId' => $teamId,
				'exception' => $e,
			]);
			return false;
		}
	}

	private function releaseSafely(int $projectId, string $userId, int $teamId): void {
		try {
			$this->releaseTeamSource($projectId, $userId, $teamId);
		} catch (Throwable $e) {
			$this->logger->error('Failed to revoke team-based project access', [
				'projectId' => $projectId,
				'userId' => $userId,
				'teamId' => $teamId,
				'exception' => $e,
			]);
		}
	}

	/** @return int[] */
	private function findProjectIdsForTeam(int $teamId): array {
		if (!$this->db->tableExists('organization_project_teams')) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('project_id')
			->from('organization_project_teams')
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$projectIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $projectIds;
	}

	private function removeFunctionalRoleMemberships(Project $project, string $userId): void {
		$boardId = (int)($project->getBoardId() ?? 0);
		if ($boardId <= 0) {
			return;
		}

		foreach ($this->policyRoleMapper->findByBoard($boardId) as $role) {
			$membership = $this->policyMembershipMapper->findUnique((int)$role->getId(), 'user', $userId);
			if ($membership !== null) {
				$this->policyMembershipMapper->delete($membership);
			}
		}
	}

	/**
	 * Moves the departing member's private project folder into the owner's
	 * private folder (under "Former members"), so the work stays with the
	 * project. Empty folders are deleted instead.
	 */
	private function handOverPrivateFolder(Project $project, string $userId, string $displayName): string {
		$projectId = (int)$project->getId();
		$link = $this->projectMapper->findPrivateFolderForUser($projectId, $userId);
		if ($link === null) {
			return self::PRIVATE_FOLDER_NONE;
		}

		try {
			$folder = $this->rootFolder->getUserFolder($userId)->getFirstNodeById((int)$link->getFolderId());
			if (!$folder instanceof Folder) {
				$this->projectMapper->deletePrivateFolderLink($projectId, $userId);
				return self::PRIVATE_FOLDER_NONE;
			}

			foreach ([IShare::TYPE_GROUP, IShare::TYPE_USER] as $shareType) {
				foreach ($this->shareManager->getSharesBy($userId, $shareType, $folder, false, -1, 0) as $share) {
					$this->shareManager->deleteShare($share);
				}
			}

			if ($folder->getDirectoryListing() === []) {
				$folder->delete();
				$this->projectMapper->deletePrivateFolderLink($projectId, $userId);
				return self::PRIVATE_FOLDER_DELETED;
			}

			$destination = $this->formerMembersFolder($project);
			$name = $this->uniqueName($destination, sprintf('%s (%s)', $displayName, $userId));
			$folder->move($destination->getPath() . '/' . $name);
			$this->projectMapper->deletePrivateFolderLink($projectId, $userId);
			return self::PRIVATE_FOLDER_MOVED;
		} catch (Throwable $e) {
			$this->logger->error('Failed to hand over a removed member\'s private project folder', [
				'projectId' => $projectId,
				'userId' => $userId,
				'exception' => $e,
			]);
			return self::PRIVATE_FOLDER_FAILED;
		}
	}

	private function formerMembersFolder(Project $project): Folder {
		$ownerId = trim((string)$project->getOwnerId());
		$ownerFolder = $this->rootFolder->getUserFolder($ownerId);

		$ownerLink = $this->projectMapper->findPrivateFolderForUser((int)$project->getId(), $ownerId);
		$parent = $ownerLink !== null ? $ownerFolder->getFirstNodeById((int)$ownerLink->getFolderId()) : null;
		if (!$parent instanceof Folder) {
			$projectName = trim((string)($project->getName() ?? '')) ?: 'Project';
			$name = sprintf('%s - %s', $projectName, self::FORMER_MEMBERS_FOLDER);
			return $ownerFolder->nodeExists($name) ? $ownerFolder->get($name) : $ownerFolder->newFolder($name);
		}

		return $parent->nodeExists(self::FORMER_MEMBERS_FOLDER)
			? $parent->get(self::FORMER_MEMBERS_FOLDER)
			: $parent->newFolder(self::FORMER_MEMBERS_FOLDER);
	}

	private function uniqueName(Folder $parent, string $name): string {
		$candidate = $name;
		$counter = 2;
		while ($parent->nodeExists($candidate)) {
			$candidate = sprintf('%s %d', $name, $counter++);
		}
		return $candidate;
	}
}
