<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;
use Throwable;

final class ProjectAdministratorAccessService
{
    public const GLOBAL_ADMIN_GROUP = 'admin';
    private const ORGANIZATION_ADMIN_GROUP_PREFIX = 'organization-project-admins-';

    public function __construct(
        private ProjectMapper $projectMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IRootFolder $rootFolder,
        private IShareManager $shareManager,
        private IDBConnection $db,
        private ?object $folderManager,
        private ?object $changeHelper,
        private ?object $organizationUserMapper,
        private LoggerInterface $logger,
    ) {
    }

    public static function organizationAdminGroupId(int $organizationId): string
    {
        return self::ORGANIZATION_ADMIN_GROUP_PREFIX . $organizationId;
    }

    /** @return array{processed:int,failed:int} */
    public function reconcileAll(): array
    {
        $processed = 0;
        $failed = 0;
        foreach ($this->projectMapper->list() as $project) {
            try {
                $this->syncProject($project);
                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $this->logger->error('Failed to synchronize project administrator access', [
                    'projectId' => (int) ($project->getId() ?? 0),
                    'exception' => $e,
                ]);
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    public function syncProject(Project $project): void
    {
        $organizationId = (int) ($project->getOrganizationId() ?? 0);
        if ($organizationId <= 0) {
            return;
        }

        $organizationGroupId = self::organizationAdminGroupId($organizationId);
        $this->syncOrganizationAdminGroup($organizationId, $organizationGroupId);
        $administratorGroups = [self::GLOBAL_ADMIN_GROUP, $organizationGroupId];

        $this->syncGroupFolder((int) ($project->getGroupFolderId() ?? 0), $administratorGroups);
        $this->syncPrivateFolderShares($project, $administratorGroups);
        $this->syncDeckAcl((int) ($project->getBoardId() ?? 0), $administratorGroups);
    }

    private function syncOrganizationAdminGroup(int $organizationId, string $groupId): void
    {
        $group = $this->groupManager->get($groupId) ?? $this->groupManager->createGroup($groupId);
        if ($group === null || $this->organizationUserMapper === null) {
            return;
        }

        $expected = [];
        foreach ($this->organizationUserMapper->getOrganizationMembers($organizationId) as $member) {
            if (($member['role'] ?? '') !== 'admin') {
                continue;
            }
            $user = $this->userManager->get((string) $member['user_uid']);
            if ($user !== null) {
                $expected[$user->getUID()] = true;
                $group->addUser($user);
            }
        }

        foreach ($group->getUsers() as $user) {
            if (!isset($expected[$user->getUID()])) {
                $group->removeUser($user);
            }
        }
    }

    /** @param string[] $groupIds */
    private function syncGroupFolder(int $folderId, array $groupIds): void
    {
        if ($folderId <= 0 || $this->folderManager === null) {
            return;
        }
        foreach ($groupIds as $groupId) {
            if (!$this->groupManager->groupExists($groupId)) {
                continue;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->select('folder_id')->from('group_folders_groups')
                ->where($qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId)))
                ->andWhere($qb->expr()->eq('group_id', $qb->createNamedParameter($groupId)))
                ->setMaxResults(1);
            if ($qb->executeQuery()->fetchOne() === false) {
                $this->folderManager->addApplicableGroup($folderId, $groupId);
            }
            $this->folderManager->setGroupPermissions($folderId, $groupId, Constants::PERMISSION_ALL);
        }
    }

    /** @param string[] $groupIds */
    private function syncPrivateFolderShares(Project $project, array $groupIds): void
    {
        foreach ($this->projectMapper->findAllPrivateFoldersByProject((int) $project->getId()) as $link) {
            $ownerId = (string) $link->getUserId();
            try {
                $ownerFolder = $this->rootFolder->getUserFolder($ownerId);
                $node = $ownerFolder->get(basename((string) $link->getFolderPath()));
                if (!$node instanceof Folder) {
                    continue;
                }
                $shares = $this->shareManager->getSharesBy($ownerId, IShare::TYPE_GROUP, $node, false, -1, 0);
                foreach ($groupIds as $groupId) {
                    if (!$this->groupManager->groupExists($groupId)) {
                        continue;
                    }
                    $existing = null;
                    foreach ($shares as $share) {
                        if ($share->getSharedWith() === $groupId) {
                            $existing = $share;
                            break;
                        }
                    }
                    if ($existing !== null) {
                        if ($existing->getPermissions() !== Constants::PERMISSION_ALL) {
                            $existing->setPermissions(Constants::PERMISSION_ALL);
                            $this->shareManager->updateShare($existing);
                        }
                        continue;
                    }
                    $share = $this->shareManager->newShare();
                    $share->setNodeId($node->getId())
                        ->setNode($node)
                        ->setShareTime(new DateTime())
                        ->setSharedBy($ownerId)
                        ->setShareType(IShare::TYPE_GROUP)
                        ->setSharedWith($groupId)
                        ->setPermissions(Constants::PERMISSION_ALL);
                    $this->shareManager->createShare($share);
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to share a private project folder with administrators', [
                    'projectId' => (int) $project->getId(),
                    'ownerId' => $ownerId,
                    'folderId' => (int) $link->getFolderId(),
                    'exception' => $e,
                ]);
            }
        }
    }

    /** @param string[] $groupIds */
    private function syncDeckAcl(int $boardId, array $groupIds): void
    {
        if ($boardId <= 0 || $this->changeHelper === null) {
            return;
        }
        foreach ($groupIds as $groupId) {
            if (!$this->groupManager->groupExists($groupId)) {
                continue;
            }
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')->from('deck_board_acl')
                ->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId)))
                ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(IShare::TYPE_GROUP)))
                ->andWhere($qb->expr()->eq('participant', $qb->createNamedParameter($groupId)))
                ->setMaxResults(1);
            $id = $qb->executeQuery()->fetchOne();

            $write = $this->db->getQueryBuilder();
            if ($id === false) {
                $write->insert('deck_board_acl')->values([
                    'board_id' => $write->createNamedParameter($boardId),
                    'type' => $write->createNamedParameter(IShare::TYPE_GROUP),
                    'participant' => $write->createNamedParameter($groupId),
                    'permission_edit' => $write->createNamedParameter(1),
                    'permission_share' => $write->createNamedParameter(1),
                    'permission_manage' => $write->createNamedParameter(1),
                ])->executeStatement();
            } else {
                $write->update('deck_board_acl')
                    ->set('permission_edit', $write->createNamedParameter(1))
                    ->set('permission_share', $write->createNamedParameter(1))
                    ->set('permission_manage', $write->createNamedParameter(1))
                    ->where($write->expr()->eq('id', $write->createNamedParameter((int) $id)))
                    ->executeStatement();
            }
        }
        $this->changeHelper->boardChanged($boardId);
    }
}
