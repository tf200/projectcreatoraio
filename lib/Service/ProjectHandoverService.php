<?php

namespace OCA\ProjectCreatorAIO\Service;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserManager;
use Throwable;

class ProjectHandoverService
{
    public function __construct(
        private readonly ProjectMapper $projectMapper,
        private readonly OrganizationUserMapper $organizationUserMapper,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly IRootFolder $rootFolder,
    ) {
    }

    /**
     * @return array{
     *   projectsOwnedTransferred: int,
     *   projectMembershipsAdded: int,
     *   projectMembershipsRemoved: int,
     *   privateFoldersProvisioned: int
     * }
     */
    public function handoverUserInOrganization(string $sourceUserId, string $targetUserId, int $organizationId, bool $removeSourceFromGroups = false): array
    {
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
        $processedGroups = [];

        foreach ($projects as $project) {
            $groupGid = trim((string) ($project->getProjectGroupGid() ?? ''));
            if ($groupGid !== '' && !isset($processedGroups[$groupGid])) {
                if (!$this->groupManager->isInGroup($targetUserId, $groupGid)) {
                    $group = $this->groupManager->get($groupGid);
                    if ($group === null) {
                        throw new OCSException(sprintf('Project group "%s" was not found.', $groupGid), 404);
                    }

                    $group->addUser($targetUser);
                    $projectMembershipsAdded++;
                }

                if ($removeSourceFromGroups && $this->groupManager->isInGroup($sourceUserId, $groupGid)) {
                    $group = $this->groupManager->get($groupGid);
                    if ($group !== null) {
                        $group->removeUser($sourceUser);
                        $projectMembershipsRemoved++;
                    }
                }

                $processedGroups[$groupGid] = true;
            }

            $projectId = (int) ($project->getId() ?? 0);
            if ($projectId > 0 && $this->projectMapper->findPrivateFolderForUser($projectId, $targetUserId) === null) {
                $this->provisionPrivateFolderForUser($project, $targetUserId);
                $privateFoldersProvisioned++;
            }
        }

        $projectsOwnedTransferred = $this->projectMapper->transferOwnershipByOrg(
            $sourceUserId,
            $targetUserId,
            $organizationId,
        );

        return [
            'projectsOwnedTransferred' => $projectsOwnedTransferred,
            'projectMembershipsAdded' => $projectMembershipsAdded,
            'projectMembershipsRemoved' => $projectMembershipsRemoved,
            'privateFoldersProvisioned' => $privateFoldersProvisioned,
        ];
    }

    private function assertUserBelongsToOrganization(string $userId, int $organizationId, string $label): void
    {
        $membership = $this->organizationUserMapper->getOrganizationMembership($userId);
        if ($membership === null || (int) ($membership['organization_id'] ?? 0) !== $organizationId) {
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
