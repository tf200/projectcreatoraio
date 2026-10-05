<?php

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRole;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use Psr\Log\LoggerInterface;
use Throwable;

class ProjectHandoverService
{
    private const DECK_ACL_TYPE_USER = 0;

    public function __construct(
        private readonly ProjectMapper $projectMapper,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly IRootFolder $rootFolder,
        private readonly ProjectMemberRoleMapper $memberRoleMapper,
        private readonly ProjectMemberSourceMapper $memberSourceMapper,
        private readonly BoardPolicyRoleMapper $policyRoleMapper,
        private readonly BoardPolicyMembershipMapper $policyMembershipMapper,
        private readonly ProjectMembershipService $membershipService,
        private readonly ProjectTalkIntegrationService $talkIntegrationService,
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
        private readonly ?ProjectAdministratorAccessService $administratorAccessService = null,
        private readonly ?ProjectAccessService $access = null,
    ) {
    }

    /**
     * Gives the target user everything the source user has in the organization's
     * projects: membership, DRASCIVS and functional roles, project and Deck board
     * ownership. With $removeSourceFromGroups the source user then loses all
     * access to those projects.
     *
     * @return array{
     *   projectsOwnedTransferred: int,
     *   deckBoardsTransferred: int,
     *   projectMembershipsAdded: int,
     *   projectMembershipsRemoved: int,
     *   privateFoldersProvisioned: int
     * }
     */
    public function handoverUserInOrganization(
        string $sourceUserId,
        string $targetUserId,
        int $organizationId,
        bool $removeSourceFromGroups = false,
        bool $remapDeckContent = false,
    ): array {
        $sourceUserId = trim($sourceUserId);
        $targetUserId = trim($targetUserId);

        if ($sourceUserId === '' || $targetUserId === '') {
            throw new OCSException('Source and target user IDs are required.', 400);
        }

        if ($sourceUserId === $targetUserId) {
            throw new OCSException('Source and target user must be different users.', 400);
        }

        $sourceUser = $this->userManager->get($sourceUserId);
        if ($sourceUser === null) {
            throw new OCSException(sprintf('Source user "%s" does not exist.', $sourceUserId), 404);
        }

        $targetUser = $this->userManager->get($targetUserId);
        if ($targetUser === null) {
            throw new OCSException(sprintf('Target user "%s" does not exist.', $targetUserId), 404);
        }

        $this->assertUserBelongsToOrganization($sourceUserId, $organizationId, 'Source user');
        $this->assertUserBelongsToOrganization($targetUserId, $organizationId, 'Target user');

        $ownedProjects = $this->projectMapper->findOwnedByUserAndOrganization($sourceUserId, $organizationId);
        $memberProjects = $this->projectMapper->findByUserIdAndOrganizationId($sourceUserId, $organizationId);

        $projects = [];
        foreach (array_merge($ownedProjects, $memberProjects) as $project) {
            $projectId = (int) ($project->getId() ?? 0);
            if ($projectId <= 0 || isset($projects[$projectId])) {
                continue;
            }
            $projects[$projectId] = $project;
        }

        $projectMembershipsAdded = 0;
        $projectMembershipsRemoved = 0;
        $privateFoldersProvisioned = 0;
        $deckBoardsTransferred = 0;

        foreach ($projects as $projectId => $project) {
            $groupGid = trim((string) ($project->getProjectGroupGid() ?? ''));
            if ($groupGid !== '' && !$this->groupManager->isInGroup($targetUserId, $groupGid)) {
                $group = $this->groupManager->get($groupGid);
                if ($group === null) {
                    throw new OCSException(sprintf('Project group "%s" was not found.', $groupGid), 404);
                }

                $group->addUser($targetUser);
                $this->talkIntegrationService->addUserToConversation((string) ($project->getTalkConversationToken() ?? ''), $targetUser);
                $projectMembershipsAdded++;
            }

            if ($this->projectMapper->findPrivateFolderForUser($projectId, $targetUserId) === null) {
                $this->provisionPrivateFolderForUser($project, $targetUserId);
                $this->administratorAccessService?->syncProject($project);
                $privateFoldersProvisioned++;
            }

            $this->copyMemberAccess($project, $sourceUserId, $targetUserId);

            if ($this->transferDeckBoard((int) ($project->getBoardId() ?? 0), $sourceUserId, $targetUserId, $remapDeckContent)) {
                $deckBoardsTransferred++;
            }
        }

        $projectsOwnedTransferred = $this->projectMapper->transferOwnershipByOrg(
            $sourceUserId,
            $targetUserId,
            $organizationId,
        );

        if ($removeSourceFromGroups) {
            foreach (array_keys($projects) as $projectId) {
                try {
                    $this->membershipService->removeMember($projectId, $sourceUserId);
                    $projectMembershipsRemoved++;
                } catch (OCSException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                }
            }
        }

        return [
            'projectsOwnedTransferred' => $projectsOwnedTransferred,
            'deckBoardsTransferred' => $deckBoardsTransferred,
            'projectMembershipsAdded' => $projectMembershipsAdded,
            'projectMembershipsRemoved' => $projectMembershipsRemoved,
            'privateFoldersProvisioned' => $privateFoldersProvisioned,
        ];
    }

    /**
     * The target inherits the source's DRASCIVS roles, functional (card policy)
     * roles and membership origins on top of what they already have.
     */
    private function copyMemberAccess(Project $project, string $sourceUserId, string $targetUserId): void
    {
        $projectId = (int) $project->getId();

        $this->db->beginTransaction();
        try {
            $roles = static fn (array $rows): array => array_map(
                static fn (ProjectMemberRole $role): string => (string) $role->getDrasciRole(),
                $rows,
            );
            $targetRoles = $roles($this->memberRoleMapper->findByProjectAndUser($projectId, $targetUserId));
            $mergedRoles = array_values(array_unique(array_merge(
                $targetRoles,
                $roles($this->memberRoleMapper->findByProjectAndUser($projectId, $sourceUserId)),
            )));
            if ($mergedRoles !== [] && count($mergedRoles) !== count($targetRoles)) {
                $this->memberRoleMapper->replaceRoles($projectId, $targetUserId, $mergedRoles);
            }

            $boardId = (int) ($project->getBoardId() ?? 0);
            if ($boardId > 0) {
                foreach ($this->policyRoleMapper->findByBoard($boardId) as $role) {
                    $roleId = (int) $role->getId();
                    if ($this->policyMembershipMapper->findUnique($roleId, 'user', $sourceUserId) === null
                        || $this->policyMembershipMapper->findUnique($roleId, 'user', $targetUserId) !== null) {
                        continue;
                    }

                    $membership = new BoardPolicyMembership();
                    $membership->setRoleId($roleId);
                    $membership->setParticipantType('user');
                    $membership->setParticipantId($targetUserId);
                    $this->policyMembershipMapper->insert($membership);
                }
            }

            $sources = $this->memberSourceMapper->findSources($projectId, $sourceUserId);
            foreach ($sources === [] ? [ProjectMemberSourceMapper::MANUAL] : $sources as $teamId) {
                $this->memberSourceMapper->addSource($projectId, $targetUserId, $teamId);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Moves Deck board ownership like Deck's own transfer, minus its
     * permission check (handover runs as a background job) and without
     * granting the previous owner a personal ACL: if they stay on the
     * project, the project group still gives them access.
     */
    private function transferDeckBoard(int $boardId, string $sourceUserId, string $targetUserId, bool $remapDeckContent): bool
    {
        if ($boardId <= 0 || !class_exists('OCA\Deck\Db\BoardMapper')) {
            return false;
        }

        $boardMapper = Server::get('OCA\Deck\Db\BoardMapper');
        try {
            $board = $boardMapper->find($boardId);
        } catch (Throwable) {
            return false;
        }
        if ($board->getOwner() !== $sourceUserId) {
            return false;
        }

        $aclMapper = Server::get('OCA\Deck\Db\AclMapper');
        $this->db->beginTransaction();
        try {
            $aclMapper->deleteParticipantFromBoard($boardId, self::DECK_ACL_TYPE_USER, $targetUserId);
            $aclMapper->deleteParticipantFromBoard($boardId, self::DECK_ACL_TYPE_USER, $sourceUserId);
            $boardMapper->transferOwnership($sourceUserId, $targetUserId, $boardId);
            if ($remapDeckContent) {
                Server::get('OCA\Deck\Db\AssignmentMapper')->remapAssignedUser($boardId, $sourceUserId, $targetUserId);
                Server::get('OCA\Deck\Db\CardMapper')->remapCardOwner($boardId, $sourceUserId, $targetUserId);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $boardMapper->flushCache($boardId);
        try {
            Server::get('OCA\Deck\Db\ChangeHelper')->boardChanged($boardId);
        } catch (Throwable $e) {
            $this->logger->debug('Could not invalidate Deck board cache after handover', ['exception' => $e]);
        }

        return true;
    }

    private function assertUserBelongsToOrganization(string $userId, int $organizationId, string $label): void
    {
        if ($this->access === null || !$this->access->hasOrganizations()) {
            throw new OCSException('Organization service is not available.', 500);
        }
        if (!$this->access->belongsToOrganization($userId, $organizationId)) {
            throw new OCSException(sprintf('%s does not belong to organization %d.', $label, $organizationId), 403);
        }
    }

    private function provisionPrivateFolderForUser(Project $project, string $userId): void
    {
        $projectId = (int) ($project->getId() ?? 0);
        if ($projectId <= 0) {
            throw new OCSException('Invalid project while creating private folder.', 500);
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
            $projectName = trim((string) ($project->getName() ?? ''));
            if ($projectName === '') {
                $projectName = 'Project';
            }

            $privateFolderName = $this->getUniqueFolderName($projectName, 'Private Files', $userFolder);
            $privateFolder = $userFolder->newFolder($privateFolderName);

            $this->projectMapper->createPrivateFolderLink(
                $projectId,
                $userId,
                (int) $privateFolder->getId(),
                $privateFolder->getPath(),
            );
        } catch (Throwable $e) {
            throw new OCSException('Unable to provision private files for target user: ' . $e->getMessage(), 500, $e);
        }
    }

    private function getUniqueFolderName(string $projectName, string $suffix, Folder $folder): string
    {
        $folderName = sprintf('%s - %s', $projectName, $suffix);

        if (!$folder->nodeExists($folderName)) {
            return $folderName;
        }

        $counter = 2;
        while (true) {
            $folderName = sprintf('%s (%d) - %s', $projectName, $counter, $suffix);
            if (!$folder->nodeExists($folderName)) {
                return $folderName;
            }

            $counter++;
        }
    }
}
