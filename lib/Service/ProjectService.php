<?php

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OC\Files\SetupManager;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRole;
use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\NoteMapper as DeckNoteMapper;
use OCA\Deck\Service\BoardService;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Constants;
use OCP\IUserSession;
use OCP\Files\Folder;
use OCP\IUser;
use OCA\Deck\Db\Board;
use OCA\ProjectCreatorAIO\Service\DeckDefaultCardsService;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCA\ProjectCreatorAIO\ProjectStatus;
use Throwable;
use Exception;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\Organization;
use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\FolderStorageManager;
use OCA\Organization\Db\Plan;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Files\File;
use OCA\Deck\Db\ChangeHelper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use Psr\Log\LoggerInterface;

class ProjectService
{
    private const CV_FIELD_OBJECT_OWNERSHIP = 'cv_object_ownership';
    private const CV_FIELD_TRACE_OWNERSHIP = 'cv_trace_ownership';
    private const CV_FIELD_BUILDING_TYPE = 'cv_building_type';
    private const CV_FIELD_AVP_LOCATION = 'cv_avp_location';

    public const DRASCI_ROLES = [
        'driver'       => 'Driver',
        'responsible'  => 'Responsible',
        'accountable'  => 'Accountable',
        'supportive'   => 'Supportive',
        'consulted'    => 'Consulted',
        'informed'     => 'Informed',
        'verifier'     => 'Verifier',
        'signer'       => 'Signer',
    ];

	// Card-visibility helpers live in CardVisibility.

    public function __construct(
        protected IUserSession $userSession,
        protected IShareManager $shareManager,
        protected ?object $boardService,
        private readonly ?object $deckDefaultCardsService,
        protected IRootFolder $rootFolder,
        protected ProjectMapper $projectMapper,
        protected ProjectNoteMapper $noteMapper,
        protected FileTreeService $fileTreeService,
        protected ?object $organizationMapper,
        protected ?object $organizationUserMapper,
        protected ?object $subscriptionMapper,
        protected ?object $planMapper,
        protected IGroupManager $groupManager,
        protected ?object $folderManager,
        protected IDBConnection $db,
        protected IUserManager $userManager,
        private readonly ?object $folderStorageManager,
        private readonly ?object $changeHelper,
        private readonly ProjectNotificationService $projectNotificationService,
        private readonly ProjectActivityService $projectActivityService,
        private readonly ProjectDeckActivityService $projectDeckActivityService,
        private readonly ProjectTalkIntegrationService $projectTalkIntegrationService,
        private readonly ?object $cardMapper,
        private readonly ?object $stackService,
        private readonly ?object $deckPermissionService,
        private readonly LoggerInterface $logger,
        private readonly ProjectMemberRoleMapper $memberRoleMapper,
        private readonly BoardPolicyRoleMapper $policyRoleMapper,
        private readonly BoardPolicyMembershipMapper $policyMembershipMapper,
        private readonly CardPolicyService $cardPolicyService,
        private readonly OrganizationPdfService $organizationPdfService,
    ) {
    }

    /**
     * The main public method to create a complete project.
     * It orchestrates all the necessary steps and handles rollbacks.
     */
    public function createProject(
        string $name,
        string $number,
        int $type,
        array $members,
        string $description,
        ?int $organizationId = null,
        ?string $clientName = null,
        ?string $clientRole = null,
        ?string $clientPhone = null,
        ?string $clientEmail = null,
        ?string $clientAddress = null,
        ?string $locStreet = null,
        ?string $locCity = null,
        ?string $locZip = null,
        ?int $requiredPreparationWeeks = null,
    ): Project {

        $createdBoard = null;
        $createdGroup = null;
        $createdFolders = [];
        $createdConversationToken = null;
        $createdProject = null;

        try {
            $owner = $this->userSession->getUser();
            if ($owner === null) {
                throw new OCSException('You must be logged in to create a project.');
            }
            if ($this->folderManager === null || $this->folderStorageManager === null) {
                throw new OCSException('Team Folders must be enabled to create quota-managed projects.', 503);
            }

            $organization = null;
            $plan = null;
            if ($this->organizationMapper !== null && $this->subscriptionMapper !== null && $this->planMapper !== null) {
                $organization = $this->resolveOrganizationForCurrentUser($owner->getUID(), $organizationId, false);
                $this->assertUsersBelongToOrganization(array_merge($members, [$owner->getUID()]), $organization->getId());

                $subscription = $this->subscriptionMapper->findByOrganizationId($organization->getId());
                $plan = $this->planMapper->find($subscription->getPlanId());
                $count = $this->organizationMapper->getProjectsCount($organization->getId());

                if ($count >= $plan->getMaxProjects()) {
                    throw new OCSException(sprintf(
                        "The maximum number of projects allowed for this plan (%d) has been reached. " .
                        "You currently have %d projects. Please upgrade your plan to create additional projects.",
                        $plan->getMaxProjects(),
                        $count
                    ));
                }
            }

            $group = $this->createGroupForMembers(
                array_merge($members, [$owner->getUID()])
            );
            $createdGroup = $group;

            $boardId = null;
            if ($this->boardService !== null) {
                $createdBoard = $this->createBoardForProject(
                    $name,
                    $owner,
                    $group->getGID(),
                    $type,
                );
                $boardId = $createdBoard->getId();
            }

            $createdFolders = $this->createFoldersForProject(
                $name,
                $members,
                $owner,
                $group,
                $plan
            );

            $createdWhiteBoardId = $this->createWhiteboardFile(
                $createdFolders['shared']['name'],
                $name,
                $createdFolders['shared']['group_folder_id'] ?? null,
                $createdFolders['shared']['folder'] ?? null
            );

            if ($createdWhiteBoardId <= 0) {
                throw new OCSException('Whiteboard file creation failed.');
            }

            $resolvedOrgId = $organization !== null ? $organization->getId() : $organizationId;
            $this->createDefaultPdfFile(
                $createdFolders['shared']['name'],
                $resolvedOrgId,
                $createdFolders['shared']['group_folder_id'] ?? null,
                $createdFolders['shared']['folder'] ?? null
            );
            $this->refreshTeamFolderSize($createdFolders['shared']['group_folder_id']);

            $whiteBoardId = (string) $createdWhiteBoardId;
            $memberIds = array_values(array_unique(array_merge($members, [$owner->getUID()])));
            if ($this->projectTalkIntegrationService->isAvailable()) {
                $conversation = $this->projectTalkIntegrationService->createProjectConversation(
                    $name,
                    $owner,
                    $memberIds,
                );
                $createdConversationToken = $conversation['token'];
            }

            $project = $this->projectMapper->createProject(
                $organization,
                $name,
                $number,
                $type,
                $description,
                $owner->getUID(),
                $boardId,
                $group->getGID(),
                $createdConversationToken,
                $createdFolders['shared']['id'],
                $createdFolders['shared']['group_folder_id'],
                $createdFolders['shared']['name'],
                $createdFolders['private'],
                $whiteBoardId,
                $requiredPreparationWeeks,
                $clientName,
                $clientRole,
                $clientPhone,
                $clientEmail,
                $clientAddress,
                $locStreet,
                $locCity,
                $locZip,
            );
            $createdProject = $project;
            $this->memberRoleMapper->replaceRoles((int)$project->getId(), $owner->getUID(), ['accountable']);

            $seededCards = [];
            if ($this->deckDefaultCardsService !== null && $createdBoard !== null) {
                $seededCards = $this->deckDefaultCardsService->seedForProjectType(
                    $type,
                    $createdBoard,
                    $owner,
                );
            }

            if ($createdBoard !== null) {
                $stacks = $this->stackService !== null ? $this->stackService->findAll((int)$createdBoard->getId()) : [];
                $this->cardPolicyService->seedDefaultPolicies(
                    (int)$createdBoard->getId(),
                    $stacks,
                    $owner,
                    $seededCards,
                );
            }

            // On creation, conditional sets start hidden by default until the
            // questionnaire is explicitly saved.
            if ($this->boardService !== null) {
                $this->applyCardVisibilityToDeckCards(
                    $project,
                    $this->extractCardVisibilityAnswers($project),
                );
            }

            $this->projectActivityService->recordProjectCreated(
                $project,
                $owner,
            );

            if ($createdConversationToken !== null && $createdConversationToken !== '') {
                try {
                    $this->refreshFilesystemMountsForUser($owner);
                    $this->projectTalkIntegrationService->shareFileInConversation(
                        $createdConversationToken,
                        $createdWhiteBoardId,
                        $owner,
                        $project->getFolderPath(),
                        $project->getName(),
                        (int) ($project->getId() ?? 0),
                        (int) ($project->getFolderId() ?? 0),
                    );
                } catch (Throwable $e) {
                    $this->logger->warning('Failed inline project whiteboard share in Talk conversation', [
                        'projectId' => (int) ($project->getId() ?? 0),
                        'whiteboardFileId' => $createdWhiteBoardId,
                        'conversationToken' => $createdConversationToken,
                        'actorUserId' => $owner->getUID(),
                        'projectFolderId' => (int) ($project->getFolderId() ?? 0),
                        'projectFolderPath' => $project->getFolderPath(),
                        'projectName' => $project->getName(),
                        'exception' => $e,
                    ]);
                }
            }

            return $project;

        } catch (Throwable $e) {
            if ($createdConversationToken !== null && $createdConversationToken !== '') {
                $this->projectTalkIntegrationService->deleteConversation($createdConversationToken);
            }

            if ($createdProject !== null) {
                try {
                    $this->memberRoleMapper->deleteByProject((int)$createdProject->getId());
                    $this->projectMapper->deleteProject($createdProject);
                } catch (Throwable $cleanupError) {
                    $this->logger->error('Failed to remove an incomplete project record', [
                        'projectId' => (int) ($createdProject->getId() ?? 0),
                        'exception' => $cleanupError,
                    ]);
                }
            }

            $this->cleanupResources(
                $createdBoard,
                $createdGroup,
                $createdFolders['all'] ?? [],
                $createdFolders['shared']['group_folder_id'] ?? null,
            );

            throw $e;
        }
    }

    /**
     * Search users constrained to one organization.
     * Admins can specify any organization ID, non-admins are restricted to their own organization.
     *
     * @return array<int, array{id: string, user: string, label: string, displayName: string, subname: string}>
     */
    public function searchUsers(
        string $search,
        ?int $organizationId = null,
        int $limit = 25,
        int $offset = 0,
    ): array {
        $search = trim($search);
        if ($search == '') {
            return [];
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new OCSException('You must be logged in to search users.');
        }

        if ($this->organizationMapper === null) {
            // Fallback: Search all Nextcloud users
            $nextcloudUsers = $this->userManager->find($search, max(1, $limit), max(0, $offset));
            $users = [];
            foreach ($nextcloudUsers as $ncUser) {
                $uid = $ncUser->getUID();
                $displayName = $ncUser->getDisplayName() ?: $uid;
                $email = $ncUser->getEMailAddress() ?: '';
                $users[] = [
                    'id' => $uid,
                    'user' => $uid,
                    'label' => $displayName,
                    'displayName' => $displayName,
                    'subname' => $email,
                ];
            }
            return $users;
        }

        $organization = $this->resolveOrganizationForCurrentUser($user->getUID(), $organizationId, false);

        $qb = $this->db->getQueryBuilder();
        $qb->select('user_uid')
            ->from('organization_members')
            ->where(
                $qb->expr()->eq('organization_id', $qb->createNamedParameter($organization->getId(), \PDO::PARAM_INT))
            )
            ->andWhere(
                $qb->expr()->iLike('user_uid', $qb->createNamedParameter('%' . $search . '%'))
            )
            ->orderBy('user_uid', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $users = [];
        foreach ($rows as $row) {
            $uid = (string) ($row['user_uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $nextcloudUser = $this->userManager->get($uid);
            if ($nextcloudUser === null) {
                continue;
            }

            $displayName = $nextcloudUser->getDisplayName() ?: $uid;
            $email = $nextcloudUser->getEMailAddress() ?: '';

            $users[] = [
                'id' => $uid,
                'user' => $uid,
                'label' => $displayName,
                'displayName' => $displayName,
                'subname' => $email,
            ];
        }

        return $users;
    }

    /**
     * @return array<int, array{id: string, displayName: string, email: string, isOwner: bool}>
     */
    public function getProjectMembers(int $projectId): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $ownerId = trim((string) ($project->getOwnerId() ?? ''));
        $groupGid = trim((string) ($project->getProjectGroupGid() ?? ''));

        $memberIds = $groupGid !== ''
            ? $this->getMemberUserIdsByGroup($groupGid)
            : [];

        if ($ownerId !== '' && !in_array($ownerId, $memberIds, true)) {
            $memberIds[] = $ownerId;
        }

        $roleRows = $this->memberRoleMapper->findByProject($projectId);
        $rolesByUser = [];
        foreach ($roleRows as $roleRow) {
            $rolesByUser[$roleRow->getUserId()][] = $roleRow->getDrasciRole();
        }

        $functionalRolesByUser = $this->getFunctionalRoleKeysByUser($project, $memberIds);

        $members = [];
        foreach ($memberIds as $memberId) {
            $user = $this->userManager->get($memberId);
            if ($user === null) {
                continue;
            }

            $roles = $rolesByUser[$memberId] ?? [];
            $members[] = $this->formatProjectMember($user, $ownerId, $roles, $functionalRolesByUser[$memberId] ?? []);
        }

        usort($members, static function (array $a, array $b): int {
            if ($a['isOwner'] !== $b['isOwner']) {
                return $a['isOwner'] ? -1 : 1;
            }

            return strcasecmp($a['displayName'], $b['displayName']);
        });

        return $members;
    }

    /**
     * @return array<int, array{key: string, name: string}>
     */
    public function getProjectFunctionalRoles(int $projectId): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $boardId = (int) ($project->getBoardId() ?? 0);
        if ($boardId <= 0) {
            return [];
        }

        return array_map(static fn (BoardPolicyRole $role): array => [
            'key' => (string) $role->getRoleKey(),
            'name' => (string) $role->getRoleName(),
        ], $this->policyRoleMapper->findByBoard($boardId));
    }

    /**
     * Build a read-only overview of effective Deck access for project members.
     *
     * @return array<string, mixed>
     */
    public function getDeckAccessSummary(int $projectId, string $viewerId, bool $teamScope): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $members = $this->getProjectMembers($projectId);
        if (!$teamScope) {
            $members = array_values(array_filter(
                $members,
                static fn (array $member): bool => $member['id'] === $viewerId,
            ));
        }

        $functionalRoleLabels = [];
        foreach ($this->getProjectFunctionalRoles($projectId) as $role) {
            $functionalRoleLabels[$role['key']] = $role['name'];
        }

        $boardId = (int) ($project->getBoardId() ?? 0);
        if ($boardId <= 0) {
            return $this->formatDeckAccessSummary(null, [], $members, $functionalRoleLabels, $teamScope);
        }

        if ($this->cardMapper === null
            || !method_exists($this->cardMapper, 'findAllByBoardId')
            || $this->deckPermissionService === null
            || !method_exists($this->deckPermissionService, 'checkPermission')) {
            throw new OCSException('Deck access information is unavailable because Deck is not enabled.', 503);
        }

        try {
            $cards = $this->cardMapper->findAllByBoardId($boardId);
        } catch (Throwable $e) {
            throw new OCSException('The Deck board for this project is unavailable.', 503, $e);
        }

        return $this->formatDeckAccessSummary($boardId, $cards, $members, $functionalRoleLabels, $teamScope);
    }

    /**
     * @param object[] $cards
     * @param array<int, array<string, mixed>> $members
     * @param array<string, string> $functionalRoleLabels
     * @return array<string, mixed>
     */
    private function formatDeckAccessSummary(
        ?int $boardId,
        array $cards,
        array $members,
        array $functionalRoleLabels,
        bool $teamScope,
    ): array {
        $totalCards = count($cards);
        $summaries = [];

        foreach ($members as $member) {
            $userId = (string) $member['id'];
            $canRead = $boardId !== null && $this->hasNativeDeckPermission($boardId, Acl::PERMISSION_READ, $userId);
            $canEdit = $boardId !== null && $this->hasNativeDeckPermission($boardId, Acl::PERMISSION_EDIT, $userId);
            $actionCards = [
                'view' => [],
                'move' => [],
                'verify' => [],
                'sign' => [],
            ];

            foreach ($cards as $card) {
                $canView = $canRead && $this->cardPolicyService->assertActionLogic($card, $boardId, 'view', $userId);
                if ($canView) {
                    $actionCards['view'][] = $card;
                }

                if (!$canEdit || !$canView) {
                    continue;
                }

                foreach (['move', 'verify', 'sign'] as $action) {
                    if ($this->cardPolicyService->assertActionLogic($card, $boardId, $action, $userId)) {
                        $actionCards[$action][] = $card;
                    }
                }
            }

            $actions = [];
            foreach ($actionCards as $action => $allowedCards) {
                $allowedCount = count($allowedCards);
                $actions[$action] = [
                    'allowed' => $allowedCount,
                    'total' => $totalCards,
                    'status' => $this->deckAccessStatus($allowedCount, $totalCards),
                    'allowedCards' => array_map(static fn (object $card): array => [
                        'id' => (int) $card->getId(),
                        'title' => (string) $card->getTitle(),
                    ], $allowedCards),
                ];
            }

            $roleKeys = array_values($member['functionalRoleKeys'] ?? []);
            $summaries[] = [
                'id' => $userId,
                'displayName' => (string) $member['displayName'],
                'isOwner' => (bool) $member['isOwner'],
                'drascivsRoles' => array_values($member['drascivsRoles'] ?? $member['drasciRoles'] ?? []),
                'drascivsRoleLabels' => array_values($member['drascivsRoleLabels'] ?? $member['drasciRoleLabels'] ?? []),
                'drasciRoles' => array_values($member['drasciRoles']),
                'drasciRoleLabels' => array_values($member['drasciRoleLabels']),
                'drasciRole' => $member['drasciRole'],
                'drasciRoleLabel' => (string) $member['drasciRoleLabel'],
                'functionalRoleKeys' => $roleKeys,
                'functionalRoleLabels' => array_values(array_map(
                    static fn (string $roleKey): string => $functionalRoleLabels[$roleKey] ?? $roleKey,
                    $roleKeys,
                )),
                'boardAccess' => $canEdit ? 'edit' : ($canRead ? 'read' : 'none'),
                'actions' => $actions,
            ];
        }

        return [
            'boardId' => $boardId,
            'totalCards' => $totalCards,
            'scope' => $teamScope ? 'team' : 'self',
            'members' => $summaries,
        ];
    }

    private function hasNativeDeckPermission(int $boardId, int $permission, string $userId): bool
    {
        if ($this->deckPermissionService === null || !method_exists($this->deckPermissionService, 'checkPermission')) {
            return false;
        }

        try {
            $this->deckPermissionService->checkPermission(null, $boardId, $permission, $userId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function deckAccessStatus(int $allowed, int $total): string
    {
        if ($allowed === 0 || $total === 0) {
            return 'none';
        }

        return $allowed === $total ? 'all' : 'some';
    }

    /**
     * Adds an organization member to the project group and provisions a private folder link.
     *
     * @param string[] $drasciRoles
     */
    public function addMemberToProject(
        int $projectId,
        string $userId,
        array $drasciRoles,
        ?array $functionalRoleKeys = null,
    ): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new OCSException('A user ID is required to add a project member.', 400);
        }

        $drasciRoles = $this->normalizeDrasciRoles($drasciRoles);

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $functionalRoles = $functionalRoleKeys === null
            ? null
            : $this->resolveFunctionalRoles($project, $functionalRoleKeys, true);

        $groupGid = trim((string) ($project->getProjectGroupGid() ?? ''));
        if ($groupGid === '') {
            throw new OCSException('This project cannot accept members because the member group is not configured.', 500);
        }

        if ($this->organizationUserMapper !== null) {
            $memberOrganization = $this->organizationUserMapper->getOrganizationMembership($userId);
            if ($memberOrganization === null || (int) $memberOrganization['organization_id'] !== (int) $project->getOrganizationId()) {
                throw new OCSException('User does not belong to this organization.', 403);
            }
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            throw new OCSException(sprintf('User "%s" does not exist.', $userId), 404);
        }

        $alreadyMember = $this->groupManager->isInGroup($userId, $groupGid);
        $group = null;
        $addedToGroup = false;
        $privateFolderProvisioning = ['created' => false, 'folder' => null];

        if (!$alreadyMember) {
            $group = $this->groupManager->get($groupGid);
            if ($group === null) {
                throw new OCSException('Project member group not found.', 404);
            }

            $group->addUser($user);
            $addedToGroup = true;
        }

        try {
            $privateFolderProvisioning = $this->ensurePrivateFolderForMember($project, $userId);
        } catch (Throwable $e) {
            $this->rollbackPrivateFolderProvisioning($project, $userId, $privateFolderProvisioning);
            if ($addedToGroup && $group !== null) {
                $group->removeUser($user);
            }

            throw $e;
        }

        $ownerId = trim((string) ($project->getOwnerId() ?? ''));

        $this->db->beginTransaction();
        try {
            $this->memberRoleMapper->replaceRoles($projectId, $userId, $drasciRoles);
            if ($functionalRoles !== null) {
                $this->replaceFunctionalRoleMemberships($project, $userId, $functionalRoles);
            } else {
                $this->cardPolicyService->syncLegacyProjectMemberRole((int) ($project->getBoardId() ?? 0), $userId, $drasciRoles);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            $this->rollbackPrivateFolderProvisioning($project, $userId, $privateFolderProvisioning);
            if ($addedToGroup && $group !== null) {
                $group->removeUser($user);
            }
            throw $e;
        }

        if ($addedToGroup) {
            $conversationToken = trim((string) ($project->getTalkConversationToken() ?? ''));
            if ($conversationToken !== '') {
                $this->projectTalkIntegrationService->addUserToConversation(
                    $conversationToken,
                    $user,
                    $this->userSession->getUser(),
                );
            }
            $this->projectNotificationService->notifyMemberAdded(
                $project,
                $user,
                $this->userSession->getUser(),
            );
            $this->projectActivityService->recordMemberAdded(
                $project,
                $user,
                $this->userSession->getUser(),
            );
        }

        return [
            'added' => !$alreadyMember,
            'alreadyMember' => $alreadyMember,
            'member' => $this->formatProjectMember(
                $user,
                $ownerId,
                $drasciRoles,
                $functionalRoles === null
                    ? ($this->getFunctionalRoleKeysByUser($project, [$userId])[$userId] ?? [])
                    : array_keys($functionalRoles),
            ),
        ];
    }

    /**
     * Manually assign or update DRASCIVS roles for an existing project member.
     *
     * @param ?string[] $drasciRoles
     */
    public function updateProjectMemberRoles(
        int $projectId,
        string $userId,
        ?array $drasciRoles = null,
        ?array $functionalRoleKeys = null,
    ): array
    {
        $userId = trim($userId);
        if ($userId === '') {
            throw new OCSException('A user ID is required.', 400);
        }
        if ($drasciRoles === null && $functionalRoleKeys === null) {
            throw new OCSException('At least one role dimension must be provided.', 400);
        }
        if ($drasciRoles !== null) {
            $drasciRoles = $this->normalizeDrasciRoles($drasciRoles);
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $functionalRoles = $functionalRoleKeys === null
            ? null
            : $this->resolveFunctionalRoles($project, $functionalRoleKeys, false);

        $groupGid = trim((string) ($project->getProjectGroupGid() ?? ''));
        $ownerId = trim((string) ($project->getOwnerId() ?? ''));

        $isInGroup = $groupGid !== '' && $this->groupManager->isInGroup($userId, $groupGid);
        if (!$isInGroup && $userId !== $ownerId) {
            throw new OCSException('User is not a project member.', 404);
        }

        if ($this->organizationUserMapper !== null) {
            $memberOrganization = $this->organizationUserMapper->getOrganizationMembership($userId);
            if ($memberOrganization === null || (int) $memberOrganization['organization_id'] !== (int) $project->getOrganizationId()) {
                throw new OCSException('User does not belong to this organization.', 403);
            }
        }

        $user = $this->userManager->get($userId);
        if ($user === null) {
            throw new OCSException(sprintf('User "%s" does not exist.', $userId), 404);
        }

        $this->db->beginTransaction();
        try {
            if ($drasciRoles !== null) {
                $this->memberRoleMapper->replaceRoles($projectId, $userId, $drasciRoles);
            }
            if ($functionalRoles !== null) {
                $this->replaceFunctionalRoleMemberships($project, $userId, $functionalRoles);
            } elseif ($drasciRoles !== null) {
                $this->cardPolicyService->syncLegacyProjectMemberRole((int) ($project->getBoardId() ?? 0), $userId, $drasciRoles);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $effectiveFunctionalRoleKeys = $functionalRoles !== null
            ? array_keys($functionalRoles)
            : ($this->getFunctionalRoleKeysByUser($project, [$userId])[$userId] ?? []);
        $effectiveDrasciRoles = $drasciRoles;
        if ($effectiveDrasciRoles === null) {
            $effectiveDrasciRoles = array_map(
                static fn (ProjectMemberRole $role): string => (string) $role->getDrasciRole(),
                $this->memberRoleMapper->findByProjectAndUser($projectId, $userId),
            );
        }

        return [
            'member' => $this->formatProjectMember($user, $ownerId, $effectiveDrasciRoles, $effectiveFunctionalRoleKeys),
        ];
    }

    /**
     * @param string[] $projectMemberIds
     * @return array<string, string[]>
     */
    private function getFunctionalRoleKeysByUser(Project $project, array $projectMemberIds): array
    {
        $boardId = (int) ($project->getBoardId() ?? 0);
        if ($boardId <= 0) {
            return [];
        }

        $projectMembers = [];
        foreach ($projectMemberIds as $projectMemberId) {
            $projectMemberId = trim((string) $projectMemberId);
            if ($projectMemberId !== '') {
                $projectMembers[$projectMemberId] = true;
            }
        }
        if ($projectMembers === []) {
            return [];
        }

        $roles = $this->policyRoleMapper->findByBoard($boardId);
        $rolesById = [];
        foreach ($roles as $role) {
            $rolesById[(int) $role->getId()] = (string) $role->getRoleKey();
        }

        $memberships = $this->policyMembershipMapper->findByRoles(array_keys($rolesById));
        $roleKeysByUser = [];
        $groupMembers = [];
        foreach ($memberships as $membership) {
            $roleKey = $rolesById[(int) $membership->getRoleId()] ?? null;
            if ($roleKey === null) {
                continue;
            }

            $participantId = trim((string) $membership->getParticipantId());
            if ($participantId === '') {
                continue;
            }

            if ($membership->getParticipantType() === 'user') {
                if (isset($projectMembers[$participantId])) {
                    $roleKeysByUser[$participantId][$roleKey] = true;
                }
                continue;
            }

            if ($membership->getParticipantType() !== 'group') {
                continue;
            }

            if (!array_key_exists($participantId, $groupMembers)) {
                $groupMembers[$participantId] = [];
                $group = $this->groupManager->get($participantId);
                foreach ($group?->getUsers() ?? [] as $user) {
                    $userId = trim((string) $user->getUID());
                    if (isset($projectMembers[$userId])) {
                        $groupMembers[$participantId][$userId] = true;
                    }
                }
            }

            foreach ($groupMembers[$participantId] as $userId => $_) {
                $roleKeysByUser[$userId][$roleKey] = true;
            }
        }

        foreach ($roleKeysByUser as &$roleKeys) {
            $roleKeys = array_keys($roleKeys);
            sort($roleKeys);
        }
        unset($roleKeys);

        return $roleKeysByUser;
    }

    /**
     * @param mixed[] $roleKeys
     * @return array<string, BoardPolicyRole>
     */
    private function resolveFunctionalRoles(Project $project, array $roleKeys, bool $requireOne): array
    {
        $normalizedKeys = [];
        foreach ($roleKeys as $roleKey) {
            if (!is_string($roleKey) || trim($roleKey) === '') {
                throw new OCSException('Functional role keys must be non-empty strings.', 400);
            }
            $normalizedKeys[trim($roleKey)] = true;
        }

        if ($requireOne && $normalizedKeys === []) {
            throw new OCSException('At least one functional project role is required.', 400);
        }

        $boardId = (int) ($project->getBoardId() ?? 0);
        if ($boardId <= 0) {
            throw new OCSException('This project has no Deck board for functional role assignment.', 409);
        }

        $roles = [];
        foreach ($this->policyRoleMapper->findByBoard($boardId) as $role) {
            $roleKey = (string) $role->getRoleKey();
            if (isset($normalizedKeys[$roleKey])) {
                $roles[$roleKey] = $role;
            }
        }

        $unknownKeys = array_diff(array_keys($normalizedKeys), array_keys($roles));
        if ($unknownKeys !== []) {
            throw new OCSException('Unknown functional project roles: ' . implode(', ', $unknownKeys), 400);
        }

        ksort($roles);
        return $roles;
    }

    /**
     * @param array<string, BoardPolicyRole> $selectedRoles
     */
    private function replaceFunctionalRoleMemberships(Project $project, string $userId, array $selectedRoles): void
    {
        $boardId = (int) ($project->getBoardId() ?? 0);
        $boardRoles = $this->policyRoleMapper->findByBoard($boardId);
        $selectedRoleIds = array_map(static fn (BoardPolicyRole $role): int => (int) $role->getId(), $selectedRoles);

        foreach ($boardRoles as $role) {
            $roleId = (int) $role->getId();
            $membership = $this->policyMembershipMapper->findUnique($roleId, 'user', $userId);
            $shouldExist = in_array($roleId, $selectedRoleIds, true);

            if ($membership !== null && !$shouldExist) {
                $this->policyMembershipMapper->delete($membership);
                continue;
            }
            if ($membership !== null || !$shouldExist) {
                continue;
            }

            $membership = new BoardPolicyMembership();
            $membership->setRoleId($roleId);
            $membership->setParticipantType('user');
            $membership->setParticipantId($userId);
            $this->policyMembershipMapper->insert($membership);
        }
    }

    /**
     * @return string[]
     */
    private function getMemberUserIdsByGroup(string $groupGid): array
    {
        if ($groupGid === '') {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('uid')
            ->from('group_user')
            ->where(
                $qb->expr()->eq('gid', $qb->createNamedParameter($groupGid))
            )
            ->orderBy('uid', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $memberIds = [];
        $seen = [];
        foreach ($rows as $row) {
            $uid = (string) ($row['uid'] ?? '');
            if ($uid !== '' && !isset($seen[$uid])) {
                $seen[$uid] = true;
                $memberIds[] = $uid;
            }
        }

        return $memberIds;
    }

    /**
     * @return array{created: bool, folder: ?Folder}
     */
    private function ensurePrivateFolderForMember(Project $project, string $userId): array
    {
        $projectId = (int) ($project->getId() ?? 0);
        if ($projectId <= 0) {
            throw new OCSException('Invalid project while creating private folder.', 500);
        }

        $existingLink = $this->projectMapper->findPrivateFolderForUser($projectId, $userId);
        if ($existingLink !== null) {
            return ['created' => false, 'folder' => null];
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
            return ['created' => true, 'folder' => $privateFolder];
        } catch (Throwable $e) {
            $this->logger->error('Failed to ensure private folder for member', [
                'projectId' => $projectId,
                'userId' => $userId,
                'exception' => $e,
            ]);
            throw new OCSException('Unable to provision private files for invited member: ' . $e->getMessage(), 500, $e);
        }
    }

    /**
     * @param array{created: bool, folder: ?Folder} $privateFolderProvisioning
     */
    private function rollbackPrivateFolderProvisioning(Project $project, string $userId, array $privateFolderProvisioning): void
    {
        if (($privateFolderProvisioning['created'] ?? false) !== true) {
            return;
        }

        $projectId = (int) ($project->getId() ?? 0);
        if ($projectId > 0) {
            $this->projectMapper->deletePrivateFolderLink($projectId, $userId);
        }

        $folder = $privateFolderProvisioning['folder'] ?? null;
        if ($folder instanceof Folder && $folder->isDeletable()) {
            $folder->delete();
        }
    }

    /**
     * @param string[] $functionalRoleKeys
     * @param string[] $drasciRoles
     */
    private function formatProjectMember(
        IUser $user,
        string $ownerId,
        array $drasciRoles = [],
        array $functionalRoleKeys = [],
    ): array
    {
        $userId = $user->getUID();
        $drasciRoles = $this->sortDrasciRoles($drasciRoles);
        $drasciRoleLabels = array_map(
            static fn (string $role): string => self::DRASCI_ROLES[$role] ?? $role,
            $drasciRoles,
        );
        $legacyRole = $drasciRoles[0] ?? null;

        return [
            'id' => $userId,
            'displayName' => $user->getDisplayName() ?: $userId,
            'email' => $user->getEMailAddress() ?: '',
            'isOwner' => $ownerId !== '' && $userId === $ownerId,
            'drascivsRoles' => $drasciRoles,
            'drascivsRoleLabels' => $drasciRoleLabels,
            'drasciRoles' => $drasciRoles,
            'drasciRoleLabels' => $drasciRoleLabels,
            'drasciRole' => $legacyRole,
            'drasciRoleLabel' => $drasciRoleLabels[0] ?? 'Unassigned',
            'functionalRoleKeys' => array_values($functionalRoleKeys),
        ];
    }

    /** @param mixed[] $drasciRoles */
    private function normalizeDrasciRoles(array $drasciRoles): array
    {
        $normalized = [];
        foreach ($drasciRoles as $role) {
            if (!is_string($role)) {
                throw new OCSException('Every DRASCIVS role must be a string.', 400);
            }
            $role = trim($role);
            if (!array_key_exists($role, self::DRASCI_ROLES)) {
                throw new OCSException('A valid DRASCIVS role is required. Allowed: ' . implode(', ', array_keys(self::DRASCI_ROLES)), 400);
            }
            $normalized[$role] = true;
        }

        if ($normalized === []) {
            throw new OCSException('At least one DRASCIVS role is required.', 400);
        }

        return $this->sortDrasciRoles(array_keys($normalized));
    }

    /** @param string[] $drasciRoles */
    private function sortDrasciRoles(array $drasciRoles): array
    {
        $selected = array_fill_keys($drasciRoles, true);
        return array_values(array_filter(
            array_keys(self::DRASCI_ROLES),
            static fn (string $role): bool => isset($selected[$role]),
        ));
    }

    public function buildProjectPayload(Project $project): array
    {
        $payload = $project->jsonSerialize();
        $payload['talk_url'] = $this->projectTalkIntegrationService->buildConversationUrl(
            $project->getTalkConversationToken(),
        );

        return $payload;
    }

    /**
     * @param Project[] $projects
     * @return array<int, array<string, mixed>>
     */
    public function buildProjectPayloads(array $projects): array
    {
        return array_map(fn (Project $project): array => $this->buildProjectPayload($project), $projects);
    }

    private function resolveOrganizationForCurrentUser(
        string $userId,
        ?int $organizationId = null,
        bool $mustBeOrgAdmin = true,
    ): ?object
    {
        if ($this->organizationMapper === null || $this->organizationUserMapper === null) {
            return null;
        }
        $isAdmin = $this->groupManager->isInGroup($userId, 'admin');

        if ($isAdmin) {
            if ($organizationId === null) {
                throw new OCSException('An organization ID is required for admins.');
            }

            $organization = $this->organizationMapper->find($organizationId);
            if ($organization === null) {
                throw new OCSException('The selected organization does not exist.');
            }

            return $organization;
        }

        $membership = $this->organizationUserMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            throw new OCSException('No organization is assigned to your user account.');
        }

        if ($mustBeOrgAdmin && $membership['role'] !== 'admin') {
            throw new OCSException('Only organization admins can create projects.');
        }

        $resolvedOrganizationId = (int) $membership['organization_id'];

        if ($organizationId !== null && $organizationId !== $resolvedOrganizationId) {
            throw new OCSException('You can only manage projects for your own organization.');
        }

        $organization = $this->organizationMapper->find($resolvedOrganizationId);
        if ($organization === null) {
            throw new OCSException('No organization is assigned to your user account.');
        }

        return $organization;
    }

    /**
     * @param string[] $userIds
     */
    private function assertUsersBelongToOrganization(array $userIds, int $organizationId): void
    {
        foreach ($userIds as $userId) {
            $membership = $this->organizationUserMapper->getOrganizationMembership((string) $userId);
            if ($membership === null || (int) $membership['organization_id'] !== $organizationId) {
                throw new OCSException(sprintf(
                    'User "%s" does not belong to the selected organization.',
                    (string) $userId,
                ));
            }
        }
    }

    private function createGroupForMembers(array $members): IGroup
    {
        $projectGroupName = $this->generateProjectGroupId();

        // Create the group
        $createdGroup = $this->groupManager->createGroup($projectGroupName);

        if ($createdGroup === null) {
            throw new Exception("Failed to create project group '$projectGroupName'.");
        }

        $this->logger->debug('Adding project members to project group through Nextcloud group API', [
            'projectGroupGid' => $createdGroup->getGID(),
            'memberIds' => array_values(array_unique(array_map('strval', $members))),
        ]);

        foreach (array_values(array_unique(array_map('strval', $members))) as $userId) {
            $user = $this->userManager->get($userId);
            if ($user === null) {
                continue;
            }

            $createdGroup->addUser($user);
        }

        $this->logger->debug('Finished adding project members to project group', [
            'projectGroupGid' => $createdGroup->getGID(),
            'memberIds' => array_values(array_unique(array_map('strval', $members))),
        ]);

        return $createdGroup;
    }

    private function generateProjectGroupId(): string
    {
        $prefix = 'proj-';

        while (true) {
            $groupId = $prefix . bin2hex(random_bytes(8));
            if (!$this->groupManager->groupExists($groupId)) {
                return $groupId;
            }
        }
    }

    /**
     * Creates and shares a Deck board for the project.
     */
    private function createBoardForProject(string $projectName, IUser $owner, string $projectGroupGid, int $projectType): Board
    {
        $color = strtoupper(sprintf('%06X', random_int(0, 0xFFFFFF)));
        $board = $this->boardService->create("{$projectName} - Main Board", $owner->getUID(), $color);

        if ($this->stackService !== null) {
            $defaultStacks = [
                0 => 'Process steps',
                1 => 'Next priority',
                2 => 'In progress',
                3 => 'To review',
                4 => 'Approved',
                5 => 'Done',
            ];

            $doneStack = null;
            foreach ($defaultStacks as $order => $title) {
                $stack = $this->stackService->create($title, (int)$board->getId(), $order);
                if ($title === 'Done') {
                    $doneStack = $stack;
                }
            }

            if ($projectType === ProjectTypeDeckDefaults::TYPE_COMBI && $doneStack !== null) {
                $this->stackService->setDoneStack((int)$doneStack->getId(), (int)$board->getId(), true);
            }
        }

        $this->boardService->addAcl(
            $board->getId(),
            IShare::TYPE_GROUP,
            $projectGroupGid,
            true,
            false,
            false
        );

        return $board;
    }

    public function getBoardWorkflow(int $boardId): array
    {
        return $this->cardPolicyService->getBoardWorkflow($boardId);
    }


    /**
     * Creates and shares all necessary folders for the project.
     * @return array{'shared': Folder, 'private': Folder[], 'all': Folder[]}
     */
    private function createFoldersForProject(
        string $projectName,
        array $members,
        IUser $owner,
        IGroup $group,
        ?object $plan
    ): array {
        if ($this->folderManager === null || $this->folderStorageManager === null) {
            throw new OCSException('Team Folders must be enabled to create quota-managed projects.', 503);
        }
        if ($plan === null) {
            throw new OCSException('The organization plan is required to provision project storage.', 500);
        }
        if ($plan->getSharedStoragePerProject() <= 0) {
            throw new OCSException('The organization plan must define a positive project storage quota.', 500);
        }

        $ownerFolder = $this->rootFolder->getUserFolder($owner->getUID());
        $allCreatedFolders = [];

        // Create shared folders
        $sharedFolderName = $this->getUniqueFolderName(
            $projectName,
            'Shared Files',
            $ownerFolder
        );

        $groupFolderId = $this->folderManager->createFolder($sharedFolderName);
        $folder = $this->folderManager->getFolder($groupFolderId);
        if ($folder === null) {
            throw new OCSException('The project Team Folder could not be loaded after creation.', 500);
        }
        $rootId = $folder->rootId;

        $this->folderManager->addApplicableGroup($groupFolderId, $group->getGID());
        $this->folderManager->setFolderQuota($groupFolderId, $plan->getSharedStoragePerProject());
        $this->folderManager->setGroupPermissions(
            $groupFolderId,
            $group->getGID(),
            Constants::PERMISSION_ALL
        );

        // Create private folders for each member
        $privateFolders = [];
        $allMembers = array_merge($members, [$owner->getUID()]);

        foreach ($allMembers as $memberId) {
            // Get the specific member's root folder
            $memberFolder = $this->rootFolder->getUserFolder($memberId);
            $privateFolderName = $this->getUniqueFolderName(
                $projectName,
                "Private Files",
                $memberFolder
            );

            $privateFolder = $memberFolder->newFolder($privateFolderName);

            $allCreatedFolders[] = $privateFolder;
            $privateFolders[] = [
                'userId' => $memberId,
                'folderId' => $privateFolder->getId(),
                'path' => $privateFolder->getPath(),
            ];
        }

        return [
            'shared' => ['id' => $rootId, 'name' => $sharedFolderName, 'group_folder_id' => $groupFolderId],
            'private' => $privateFolders,
            'all' => $allCreatedFolders,
        ];
    }

    private function refreshFilesystemMountsForUser(IUser $user): void
    {
        try {
            /** @var SetupManager $setupManager */
            $setupManager = \OC::$server->get(SetupManager::class);
            $setupManager->tearDown();
            \OC\Files\Filesystem::initMountPoints($user);

            $this->logger->debug('Refreshed filesystem mounts before inline Talk whiteboard share', [
                'userId' => $user->getUID(),
            ]);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to refresh filesystem mounts before inline Talk whiteboard share', [
                'userId' => $user->getUID(),
                'exception' => $e,
            ]);
        }
    }

    private function getUniqueFolderName(string $projectName, string $suffix, Folder $folder): string
    {
        $folderName = "{$projectName} - {$suffix}";

        if (!$folder->nodeExists($folderName)) {
            return $folderName;
        }

        $counter = 2;
        while (true) {
            $folderName = "{$projectName} ({$counter}) - {$suffix}";
            if (!$folder->nodeExists($folderName)) {
                return $folderName;
            }
            $counter++;
        }
    }

    private function cleanupResources(
        ?object $board,
        ?IGroup $group,
        ?array $folders,
        ?int $groupFolderId = null,
    ): void {
        if ($this->folderManager !== null && $this->folderStorageManager !== null && $groupFolderId !== null && $groupFolderId > 0) {
            try {
                $groupFolder = $this->folderManager->getFolder($groupFolderId);
                if ($groupFolder !== null) {
                    $this->folderStorageManager->deleteStoragesForFolder($groupFolder);
                    $this->folderManager->removeFolder($groupFolderId);
                }
            } catch (Throwable $e) {
                error_log('Failed to cleanup group folder: ' . $e->getMessage());
            }
        }

        if (!empty($folders)) {
            foreach ($folders as $folder) {
                if ($folder !== null && $folder->isDeletable()) {
                    $folder->delete();
                }
            }
        }

        if ($board !== null && $this->boardService !== null) {
            try {
                $this->boardService->delete((int) $board->getId());
            } catch (Throwable $e) {
                $this->logger->error('Failed to remove an incomplete Deck board', [
                    'boardId' => (int) $board->getId(),
                    'exception' => $e,
                ]);
            }
        }

        if ($group !== null) {
            $group->delete();
        }
    }

    /**
     * Finds the project folder and delegates tree-building to the FileTreeService.
     */
    public function getProjectFiles(int $projectId): array
    {
        $currentUser = $this->userSession->getUser();

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new Exception("Project with ID $projectId not found.");
        }

        $userFolder = $this->rootFolder->getUserFolder($currentUser->getUID());
        $sharedFiles = $userFolder->get($project->getFolderPath());

        if (empty($sharedFiles)) {
            throw new NotFoundException("Project folder node not found on the filesystem.");
        }

        $sharedFilesTree = $this->fileTreeService->buildTree($sharedFiles);

        $privateFolderLinks = [];
        $privateFilesTrees = [];

        $link = $this->projectMapper->findPrivateFolderForUser(
            $projectId,
            $currentUser->getUID()
        );
        if ($link !== null) {
            $privateFolderLinks[] = $link;
        }

        error_log("privateFolderLinks  : " . print_r($privateFolderLinks, true));

        foreach ($privateFolderLinks as $link) {
            try {
                $path = basename($link->getFolderPath());
                $privateFolderNode = $userFolder->get($path);
                $privateFilesTrees[] = $this->fileTreeService->buildTree($privateFolderNode);
            } catch (NotFoundException $e) {
                continue;
            }
        }

        return [
            'shared' => [$sharedFilesTree],
            'private' => $privateFilesTrees
        ];
    }

    /**
     * Returns project notes stored as files.
     *
     * public note:  <project shared folder>/Public Notes/public-note.md
     * private note: <user private project folder>/private-note.md (per-user)
     *
     * @return array{public: string, private: string, private_available: bool}
     */
    public function getProjectNotes(int $projectId): array
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSException('Authentication required');
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $userFolder = $this->rootFolder->getUserFolder($currentUser->getUID());

        $projectRoot = null;
        try {
            $node = $userFolder->get($project->getFolderPath());
            if ($node instanceof Folder) {
                $projectRoot = $node;
            }
        } catch (NotFoundException $e) {
            $projectRoot = null;
        }

        $public = $projectRoot instanceof Folder
            ? $this->readOrCreateNoteFile($projectRoot, 'Public Notes', 'public-note.md')
            : '';

        $privateFolder = $this->resolvePrivateFolderForCurrentUser($userFolder, $projectId, $currentUser->getUID());
        if ($privateFolder === null) {
            return [
                'public' => $public,
                'private' => '',
                'private_available' => false,
            ];
        }

        // Legacy fallback: older versions stored "private" note in the shared folder.
        $privateNoteFileName = 'private-note.md';
        if (!$privateFolder->nodeExists($privateNoteFileName) && $projectRoot instanceof Folder) {
            $legacy = $this->readLegacySharedPrivateNote($projectRoot);
            if ($legacy !== '') {
                $this->writeOrCreateFile($privateFolder, $privateNoteFileName, $legacy);
            }
        }

        $private = $this->readOrCreateFile($privateFolder, $privateNoteFileName);

        return [
            'public' => $public,
            'private' => $private,
            'private_available' => true,
        ];
    }

    /**
     * Updates project notes.
     *
     * @return array{public: string, private: string, private_available: bool}
     */
    public function updateProjectNotes(int $projectId, ?string $publicNote = null, ?string $privateNote = null): array
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSException('Authentication required');
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $userFolder = $this->rootFolder->getUserFolder($currentUser->getUID());

        if ($publicNote !== null) {
            $projectRoot = $userFolder->get($project->getFolderPath());
            if (!$projectRoot instanceof Folder) {
                throw new OCSException('Project shared folder not found', 404);
            }
            $this->writeOrCreateNoteFile($projectRoot, 'Public Notes', 'public-note.md', $publicNote);
        }

        if ($privateNote !== null) {
            $privateFolder = $this->resolvePrivateFolderForCurrentUser($userFolder, $projectId, $currentUser->getUID());
            if ($privateFolder === null) {
                throw new OCSException('Private note is not available for this user', 403);
            }
            $this->writeOrCreateFile($privateFolder, 'private-note.md', $privateNote);
        }

        $this->projectActivityService->recordProjectNotesUpdated(
            $project,
            $currentUser,
            $publicNote !== null,
            $privateNote !== null,
        );

        return $this->getProjectNotes($projectId);
    }

    /**
     * Check if user has a private folder for this project
     */
    public function hasPrivateFolderForUser(int $projectId, string $userId): bool
    {
        $link = $this->projectMapper->findPrivateFolderForUser($projectId, $userId);
        return $link !== null;
    }

    /**
     * Get paginated list of notes for a project by visibility
     *
     * @return array{notes: array, total: int, private_available: bool}
     */
    public function getProjectNotesList(int $projectId, string $userId, string $visibility = 'public', string $noteType = '', int $page = 1, int $limit = 12): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $offset = ($page - 1) * $limit;

        if ($visibility === 'private') {
            $notes = $this->noteMapper->findPrivateByProjectAndUser($projectId, $userId, $noteType, $limit, $offset);
            $total = $this->noteMapper->countPrivateByProjectAndUser($projectId, $userId, $noteType);
        } else {
            $notes = $this->noteMapper->findPublicByProject($projectId, $noteType, $limit, $offset);
            $total = $this->noteMapper->countPublicByProject($projectId, $noteType);
        }

        $hasPrivateFolder = $this->hasPrivateFolderForUser($projectId, $userId);

        return [
            'notes' => array_map(fn($note) => $note->jsonSerialize(), $notes),
            'total' => $total,
            'private_available' => $hasPrivateFolder,
        ];
    }

    public function getCardNotesList(int $projectId, string $userId, int $page = 1, int $limit = 12): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $boardId = $project->getBoardId();
        if ($boardId === null || $boardId === '' || $this->cardMapper === null || $this->deckPermissionService === null) {
            return [
                'notes' => [],
                'total' => 0,
                'private_available' => $this->hasPrivateFolderForUser($projectId, $userId),
            ];
        }

        $offset = ($page - 1) * $limit;
        $this->deckPermissionService->checkPermission(null, (int) $boardId, Acl::PERMISSION_READ, $userId);
        $cards = $this->cardMapper->findAllByBoardId((int) $boardId, $limit, $offset);
        $total = $this->cardMapper->countByBoardId((int) $boardId);

        $cardIds = array_map(fn($card) => (int) $card->getId(), $cards);
        
        $notesByCardId = [];
        if (!empty($cardIds)) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from('deck_private_notes')
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
                ->andWhere($qb->expr()->in('card_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('created_at', 'DESC');

            $rows = $qb->executeQuery()->fetchAll();
            foreach ($rows as $row) {
                $notesByCardId[(int)$row['card_id']][] = [
                    'id' => (int) $row['id'],
                    'cardId' => (int) $row['card_id'],
                    'userId' => $row['user_id'],
                    'text' => $row['text'],
                    'createdAt' => (int) $row['created_at'],
                    'lastModified' => (int) $row['last_modified'],
                ];
            }
        }

        return [
            'notes' => array_map(function ($card) use ($projectId, $notesByCardId) {
                $cardId = (int) $card->getId();
                $cardNotes = $notesByCardId[$cardId] ?? [];

                return [
                    'id' => 'card_' . $cardId,
                    'cardId' => $cardId,
                    'projectId' => $projectId,
                    'userId' => $card->getOwner() ?? '',
                    'title' => $card->getTitle() ?? '',
                    'content' => $card->getDescription() ?? '',
                    'visibility' => 'card',
                    'createdAt' => $this->formatDeckTimestamp($card->getCreatedAt()),
                    'updatedAt' => $this->formatDeckTimestamp($card->getLastModified()),
                    'cardNotes' => $cardNotes,
                    'cardNoteCount' => count($cardNotes),
                ];
            }, $cards),
            'total' => $total,
            'private_available' => $this->hasPrivateFolderForUser($projectId, $userId),
        ];
    }

    public function getCardCommentsList(int $projectId, string $userId, int $page = 1, int $limit = 20): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $boardId = $project->getBoardId();
        if ($boardId === null || $boardId === '' || $this->cardMapper === null || $this->deckPermissionService === null) {
            return [
                'comments' => [],
                'total' => 0,
            ];
        }

        $boardId = (int) $boardId;
        $this->deckPermissionService->checkPermission(null, $boardId, Acl::PERMISSION_READ, $userId);

        $cards = $this->cardMapper->findAllByBoardId($boardId);
        $visibleCardIds = [];
        foreach ($cards as $card) {
            if ($this->cardPolicyService->assertActionLogic($card, $boardId, 'view', $userId)) {
                $visibleCardIds[] = (int) $card->getId();
            }
        }

        if ($visibleCardIds === []) {
            return [
                'comments' => [],
                'total' => 0,
            ];
        }

        $offset = ($page - 1) * $limit;

        $qb = $this->db->getQueryBuilder();

        // Count total comments
        $countQb = $this->db->getQueryBuilder();
        $countQb->selectAlias($countQb->func()->count('c.id'), 'cnt')
            ->from('comments', 'c')
            ->innerJoin('c', 'deck_cards', 'card', $countQb->expr()->eq('c.object_id', $countQb->expr()->castColumn('card.id', IQueryBuilder::PARAM_STR)))
            ->innerJoin('card', 'deck_stacks', 'stack', $countQb->expr()->eq('card.stack_id', 'stack.id'))
            ->where($countQb->expr()->eq('stack.board_id', $countQb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
            ->andWhere($countQb->expr()->in('card.id', $countQb->createNamedParameter($visibleCardIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($countQb->expr()->eq('c.object_type', $countQb->createNamedParameter('deckCard')));
        $total = (int) $countQb->executeQuery()->fetchOne();

        // Fetch comments with card info
        $qb->select(
            'c.id', 'c.object_id', 'c.message', 'c.actor_id', 'c.actor_type',
            'c.creation_timestamp', 'c.parent_id', 'c.meta_data',
            'card.title as card_title', 'card.id as card_id'
        )
            ->from('comments', 'c')
            ->innerJoin('c', 'deck_cards', 'card', $qb->expr()->eq('c.object_id', $qb->expr()->castColumn('card.id', IQueryBuilder::PARAM_STR)))
            ->innerJoin('card', 'deck_stacks', 'stack', $qb->expr()->eq('card.stack_id', 'stack.id'))
            ->where($qb->expr()->eq('stack.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('card.id', $qb->createNamedParameter($visibleCardIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('c.object_type', $qb->createNamedParameter('deckCard')))
            ->orderBy('c.creation_timestamp', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $rows = $qb->executeQuery()->fetchAll();

        $comments = array_map(function ($row) {
            $actorDisplayName = $this->userManager->getDisplayName($row['actor_id']) ?? $row['actor_id'];
            $noteType = ProjectNote::NOTE_TYPE_GENERAL;
            if (is_string($row['meta_data'] ?? null) && $row['meta_data'] !== '') {
                $metadata = json_decode($row['meta_data'], true);
                if (is_array($metadata) && ProjectNote::isValidNoteType($metadata['deck.noteType'] ?? null)) {
                    $noteType = $metadata['deck.noteType'];
                }
            }

            return [
                'id' => (int) $row['id'],
                'cardId' => (int) $row['card_id'],
                'cardTitle' => $row['card_title'],
                'actorId' => $row['actor_id'],
                'actorDisplayName' => $actorDisplayName,
                'message' => $row['message'],
                'createdAt' => $this->formatDeckTimestamp((int) $row['creation_timestamp']),
                'parentId' => $row['parent_id'] !== '0' ? (int) $row['parent_id'] : null,
                'noteType' => $noteType,
            ];
        }, $rows);

        return [
            'comments' => $comments,
            'total' => $total,
        ];
    }

    private function formatDeckTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return null;
        }
        return (new \DateTime())->setTimestamp($timestamp)->format(\DateTime::ATOM);
    }

    private function resolvePrivateFolderForCurrentUser(Folder $userFolder, int $projectId, string $userId): ?Folder
    {
        $link = $this->projectMapper->findPrivateFolderForUser($projectId, $userId);
        if ($link === null) {
            return null;
        }

        $folderId = (int) ($link->getFolderId() ?? 0);
        if ($folderId <= 0) {
            return null;
        }

        $node = $userFolder->getFirstNodeById($folderId);
        return $node instanceof Folder ? $node : null;
    }

    private function readLegacySharedPrivateNote(Folder $projectRoot): string
    {
        $legacyFolderName = 'Private Notes';
        $legacyFileName = 'private-note.md';

        if (!$projectRoot->nodeExists($legacyFolderName)) {
            return '';
        }

        $legacyFolder = $projectRoot->get($legacyFolderName);
        if (!$legacyFolder instanceof Folder) {
            return '';
        }

        if (!$legacyFolder->nodeExists($legacyFileName)) {
            return '';
        }

        $node = $legacyFolder->get($legacyFileName);
        if (!$node instanceof File) {
            return '';
        }

        $content = $node->getContent();
        return is_string($content) ? $content : '';
    }

    private function readOrCreateNoteFile(Folder $projectRoot, string $notesFolderName, string $noteFileName): string
    {
        $notesFolder = null;
        if ($projectRoot->nodeExists($notesFolderName)) {
            $notesFolder = $projectRoot->get($notesFolderName);
            if (!$notesFolder instanceof Folder) {
                throw new OCSException(sprintf('%s exists but is not a folder', $notesFolderName), 500);
            }
        } else {
            $notesFolder = $projectRoot->newFolder($notesFolderName);
        }

        if (!$notesFolder->nodeExists($noteFileName)) {
            $noteFile = $notesFolder->newFile($noteFileName);
            $noteFile->putContent('');
            return '';
        }

        $node = $notesFolder->get($noteFileName);
        if (!$node instanceof File) {
            throw new OCSException(sprintf('%s exists but is not a file', $noteFileName), 500);
        }

        $content = $node->getContent();
        return is_string($content) ? $content : '';
    }

    private function writeOrCreateNoteFile(Folder $projectRoot, string $notesFolderName, string $noteFileName, string $content): void
    {
        $notesFolder = null;
        if ($projectRoot->nodeExists($notesFolderName)) {
            $notesFolder = $projectRoot->get($notesFolderName);
            if (!$notesFolder instanceof Folder) {
                throw new OCSException(sprintf('%s exists but is not a folder', $notesFolderName), 500);
            }
        } else {
            $notesFolder = $projectRoot->newFolder($notesFolderName);
        }

        $this->writeOrCreateFile($notesFolder, $noteFileName, $content);
    }

    private function readOrCreateFile(Folder $folder, string $fileName): string
    {
        if (!$folder->nodeExists($fileName)) {
            $file = $folder->newFile($fileName);
            $file->putContent('');
            return '';
        }

        $node = $folder->get($fileName);
        if (!$node instanceof File) {
            throw new OCSException(sprintf('%s exists but is not a file', $fileName), 500);
        }

        $content = $node->getContent();
        return is_string($content) ? $content : '';
    }

    private function writeOrCreateFile(Folder $folder, string $fileName, string $content): void
    {
        if (!$folder->nodeExists($fileName)) {
            $file = $folder->newFile($fileName);
            $file->putContent($content);
            return;
        }

        $node = $folder->get($fileName);
        if (!$node instanceof File) {
            throw new OCSException(sprintf('%s exists but is not a file', $fileName), 500);
        }

        $node->putContent($content);
    }

    public function findProjectByBoard(int $boardId)
    {
        return $this->projectMapper->findByBoardId($boardId);
    }

    /**
     * @return array{
     *   project_id: int,
     *   project_type: int,
     *   questions: array<int, array{field: string, category: string, question: string, options: array<int, array{label: string, show: int}>>>,
     *   answers: array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int},
     *   enabled_sets: int[]
     * }
     */
    public function getProjectCardVisibility(int $projectId): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $projectType = (int) ($project->getType() ?? -1);
        $answers = $this->extractCardVisibilityAnswers($project);

        return [
            'project_id' => (int) $project->getId(),
            'project_type' => $projectType,
            'questions' => $this->getCardVisibilityQuestions(),
            'answers' => $answers,
            'enabled_sets' => $this->getEnabledCardVisibilitySets($answers),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *   project_id: int,
     *   project_type: int,
     *   questions: array<int, array{field: string, category: string, question: string, options: array<int, array{label: string, show: int}>>>,
     *   answers: array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int},
     *   enabled_sets: int[],
     *   deck_cards_updated: int
     * }
     */
    public function updateProjectCardVisibility(int $projectId, array $payload): array
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSException("Project with ID $projectId not found", 404);
        }

        $projectType = (int) ($project->getType() ?? -1);
        if ($projectType !== ProjectTypeDeckDefaults::TYPE_COMBI) {
            throw new OCSException('Form configuration is only available for Combi projects.', 400);
        }

        if (array_key_exists(self::CV_FIELD_OBJECT_OWNERSHIP, $payload)) {
            $project->setCvObjectOwnership(
                $this->normalizeCardVisibilityAnswer($payload[self::CV_FIELD_OBJECT_OWNERSHIP], self::CV_FIELD_OBJECT_OWNERSHIP)
            );
        }

        if (array_key_exists(self::CV_FIELD_TRACE_OWNERSHIP, $payload)) {
            $project->setCvTraceOwnership(
                $this->normalizeCardVisibilityAnswer($payload[self::CV_FIELD_TRACE_OWNERSHIP], self::CV_FIELD_TRACE_OWNERSHIP)
            );
        }

        if (array_key_exists(self::CV_FIELD_BUILDING_TYPE, $payload)) {
            $project->setCvBuildingType(
                $this->normalizeCardVisibilityAnswer($payload[self::CV_FIELD_BUILDING_TYPE], self::CV_FIELD_BUILDING_TYPE)
            );
        }

        if (array_key_exists(self::CV_FIELD_AVP_LOCATION, $payload)) {
            $project->setCvAvpLocation(
                $this->normalizeCardVisibilityAnswer($payload[self::CV_FIELD_AVP_LOCATION], self::CV_FIELD_AVP_LOCATION)
            );
        }

        $project = $this->projectMapper->updateProjectDetails($project);

        $answers = $this->extractCardVisibilityAnswers($project);
        $updatedCount = $this->applyCardVisibilityToDeckCards($project, $answers);

        return [
            'project_id' => (int) $project->getId(),
            'project_type' => $projectType,
            'questions' => $this->getCardVisibilityQuestions(),
            'answers' => $answers,
            'enabled_sets' => $this->getEnabledCardVisibilitySets($answers),
            'deck_cards_updated' => $updatedCount,
        ];
    }

    /**
     * @return array<int, array{field: string, category: string, question: string, options: array<int, array{label: string, value: int, show: int}>>>
     */
	private function getCardVisibilityQuestions(): array
	{
		return CardVisibility::getQuestions();
	}

	/**
	 * @return array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int}
	 */
	private function extractCardVisibilityAnswers(Project $project): array
	{
		return CardVisibility::extractAnswers($project);
	}

    /**
     * @param array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int} $answers
     * @return int[]
     */
	private function getEnabledCardVisibilitySets(array $answers): array
	{
		return CardVisibility::getEnabledSets($answers);
	}

    /**
     * @param mixed $value
     */
	private function normalizeCardVisibilityAnswer(mixed $value, string $field, bool $allowNull = false): ?int
	{
		return CardVisibility::normalizeAnswer($value, $field, $allowNull);
	}

    /**
     * @param array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int} $answers
     */
    private function applyCardVisibilityToDeckCards(Project $project, array $answers): int
    {
        if ($this->boardService === null || $this->changeHelper === null) {
            return 0;
        }

        $boardId = $this->parseIntOrZero($project->getBoardId());
        if ($boardId <= 0) {
            return 0;
        }

        $projectType = (int) ($project->getType() ?? -1);
        if ($projectType !== ProjectTypeDeckDefaults::TYPE_COMBI) {
            return 0;
        }

        $enabledSets = $this->getEnabledCardVisibilitySets($answers);
        $isSet1Enabled = in_array(1, $enabledSets, true);
        $isSet2Enabled = in_array(2, $enabledSets, true);

        $nextPriorityCards = ProjectTypeDeckDefaults::getNextPriorityCards($projectType);
        $processStepCards = ProjectTypeDeckDefaults::getProcessStepCards($projectType);
        $defaultTitles = array_values(array_unique(array_filter(array_map(
            static fn(array $item): string => (string) ($item['title'] ?? ''),
            array_merge($nextPriorityCards, $processStepCards)
        ))));

        $set1Titles = ProjectTypeDeckDefaults::getConditionalSet1Titles();
        $set2Titles = ProjectTypeDeckDefaults::getConditionalSet2Titles();
        $aliasesByCanonical = ProjectTypeDeckDefaults::getCardTitleAliases();

        $allManagedTitles = [];
        $canonicalByNormalizedTitle = [];
        $groupByCanonicalTitle = [];

        foreach ($defaultTitles as $title) {
            $group = 'always';
            if (in_array($title, $set1Titles, true)) {
                $group = 'set1';
            } elseif (in_array($title, $set2Titles, true)) {
                $group = 'set2';
            }

            $groupByCanonicalTitle[$title] = $group;

            $aliases = $aliasesByCanonical[$title] ?? [$title];
            if (!in_array($title, $aliases, true)) {
                $aliases[] = $title;
            }

            foreach ($aliases as $alias) {
                $alias = trim((string) $alias);
                if ($alias === '') {
                    continue;
                }
                $allManagedTitles[] = $alias;
                $canonicalByNormalizedTitle[strtolower($alias)] = $title;
            }
        }

        if ($allManagedTitles === []) {
            return 0;
        }

        $allManagedTitles = array_values(array_unique($allManagedTitles));

        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.title', 'c.archived')
            ->from('deck_cards', 'c')
            ->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
            ->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('c.title', $qb->createNamedParameter($allManagedTitles, IQueryBuilder::PARAM_STR_ARRAY)));

        $result = $qb->executeQuery();

        $updated = 0;
        while ($row = $result->fetch()) {
            $cardId = (int) ($row['id'] ?? 0);
            $title = trim((string) ($row['title'] ?? ''));
            if ($cardId <= 0 || $title === '') {
                continue;
            }

            $canonicalTitle = $canonicalByNormalizedTitle[strtolower($title)] ?? null;
            if ($canonicalTitle === null) {
                continue;
            }

            $group = $groupByCanonicalTitle[$canonicalTitle] ?? 'always';
            $targetArchived = false;
            if ($group === 'set1') {
                $targetArchived = !$isSet1Enabled;
            } elseif ($group === 'set2') {
                $targetArchived = !$isSet2Enabled;
            }

            $isArchived = (bool) ($row['archived'] ?? false);
            if ($isArchived === $targetArchived) {
                continue;
            }

            $update = $this->db->getQueryBuilder();
            $update->update('deck_cards')
                ->set('archived', $update->createNamedParameter($targetArchived, IQueryBuilder::PARAM_BOOL))
                ->where($update->expr()->eq('id', $update->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
                ->executeStatement();

            $this->changeHelper->cardChanged($cardId, true);
            $updated++;
        }
        $result->closeCursor();

        return $updated;
    }

    private function parseIntOrZero(?string $value): int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || !ctype_digit($value)) {
            return 0;
        }

        return (int) $value;
    }

    public function updateProjectDetails(
        int $id,
        ?string $name = null,
        ?string $number = null,
        ?int $type = null,
        ?string $description = null,
        ?string $client_name = null,
        ?string $client_role = null,
        ?string $client_phone = null,
        ?string $client_email = null,
        ?string $client_address = null,
        ?string $loc_street = null,
        ?string $loc_city = null,
        ?string $loc_zip = null,
        ?string $external_ref = null,
        ?int $status = null,
        ?int $required_preparation_weeks = null
    ) {
        // 1. Fetch the existing project
        $project = $this->projectMapper->find($id);
        if ($project === null) {
            throw new OCSException("Project Not Found", 404);
        }

        // 2. Update Fields (Only if sent in request)
        if ($name !== null)
            $project->setName($name);
        if ($number !== null)
            $project->setNumber($number);
        if ($type !== null)
            $project->setType($type);
        if ($description !== null)
            $project->setDescription($description);

        // Client
        if ($client_name !== null)
            $project->setClientName($client_name);
        if ($client_role !== null)
            $project->setClientRole($client_role);
        if ($client_phone !== null)
            $project->setClientPhone($client_phone);
        if ($client_email !== null)
            $project->setClientEmail($client_email);
        if ($client_address !== null)
            $project->setClientAddress($client_address);

        // Location
        if ($loc_street !== null)
            $project->setLocStreet($loc_street);
        if ($loc_city !== null)
            $project->setLocCity($loc_city);
        if ($loc_zip !== null)
            $project->setLocZip($loc_zip);
        if ($external_ref !== null)
            $project->setExternalRef($external_ref);

        if ($status !== null) {
            if (!ProjectStatus::isValid($status)) {
                throw new OCSException('Unsupported project status.', 400);
            }

            if ($status === ProjectStatus::DONE) {
                $doneReadiness = $this->projectDeckActivityService->getDoneReadiness($project);
                if (!$doneReadiness['ready']) {
                    throw new OCSException(sprintf(
                        'Project can only be marked Done when all active deck cards are in the done stack. Currently %d of %d cards are complete.',
                        (int) $doneReadiness['doneCards'],
                        (int) $doneReadiness['totalCards'],
                    ), 400);
                }
            }

            $project->setStatus($status);

            if ($status === ProjectStatus::ARCHIVED) {
                if (!$project->getArchivedAt() instanceof \DateTimeInterface) {
                    $project->setArchivedAt(new DateTime());
                }
            } else {
                $project->setArchivedAt(null);
            }
        }

        if ($required_preparation_weeks !== null) {
            $project->setRequiredPreparationWeeks(max(0, (int) $required_preparation_weeks));
        }

        // 3. Save via Mapper
        $updatedProject = $this->projectMapper->updateProjectDetails($project);
        return $updatedProject;

    }

    /**
     * Creates a .whiteboard file in the specified shared folder.
     * Optimized to avoid slow file scanning by using direct filecache insertion.
     */
    private function createWhiteboardFile(string $folderName, string $projectName, ?int $groupFolderId = 0, ?Folder $fallbackFolder = null): int
    {
        $fileName = $projectName . '.whiteboard';
        $initialWhiteboardContent = '{"elements":[],"scrollToContent":true}';

        if ($groupFolderId === null || $groupFolderId <= 0) {
            // Group folders app is disabled or not used. Use the fallback folder if available.
            if ($fallbackFolder === null) {
                throw new Exception("Missing fallback folder and missing GroupFolder ID for whiteboard creation.");
            }
            if ($fallbackFolder->nodeExists($fileName)) {
                $file = $fallbackFolder->get($fileName);
                return (int) $file->getId();
            }
            $file = $fallbackFolder->newFile($fileName, $initialWhiteboardContent);
            return (int) $file->getId();
        }

        $groupFolder = $this->folderManager->getFolder($groupFolderId);
        if ($groupFolder === null) {
            throw new Exception("GroupFolder {$groupFolderId} not found for shared folder {$folderName}");
        }

        $storage = $this->folderStorageManager->getBaseStorageForFolder(
            $groupFolderId,
            $groupFolder->useSeparateStorage(),
            $groupFolder,
            null,
            false,
            'files'
        );

        $cache = $storage->getCache();
        $existingId = $cache->getId($fileName);
        if ($existingId !== -1) {
            return (int) $existingId;
        }

        if ($storage->file_put_contents($fileName, $initialWhiteboardContent) === false) {
            throw new Exception("Unable to write whiteboard file {$fileName} in GroupFolder {$groupFolderId}");
        }

        $storage->getScanner()->scan($fileName);
        $createdId = $cache->getId($fileName);

        if ($createdId === -1) {
            throw new Exception("Whiteboard file {$fileName} was written but not found in filecache");
        }

        return (int) $createdId;
    }

    /**
     * Creates a default PDF file in the shared folder for the project's organization.
     */
    private function createDefaultPdfFile(
        string $folderName,
        ?int $organizationId,
        ?int $groupFolderId = 0,
        ?Folder $fallbackFolder = null
    ): ?int {
        $pdfContent = $this->organizationPdfService->getOrganizationPdfContent($organizationId);
        if ($pdfContent === null || $pdfContent === '') {
            $this->logger->info('No default PDF template available for project creation.', [
                'organizationId' => $organizationId,
            ]);
            return null;
        }

        $fileName = $this->organizationPdfService->getOrganizationPdfFileName($organizationId);

        if ($groupFolderId === null || $groupFolderId <= 0) {
            if ($fallbackFolder === null) {
                return null;
            }
            if ($fallbackFolder->nodeExists($fileName)) {
                $file = $fallbackFolder->get($fileName);
                return (int) $file->getId();
            }
            $file = $fallbackFolder->newFile($fileName, $pdfContent);
            return (int) $file->getId();
        }

        if ($this->folderManager === null || $this->folderStorageManager === null) {
            return null;
        }

        $groupFolder = $this->folderManager->getFolder($groupFolderId);
        if ($groupFolder === null) {
            return null;
        }

        $storage = $this->folderStorageManager->getBaseStorageForFolder(
            $groupFolderId,
            $groupFolder->useSeparateStorage(),
            $groupFolder,
            null,
            false,
            'files'
        );

        $cache = $storage->getCache();
        $existingId = $cache->getId($fileName);
        if ($existingId !== -1) {
            return (int) $existingId;
        }

        if ($storage->file_put_contents($fileName, $pdfContent) === false) {
            $this->logger->warning("Unable to write default PDF file {$fileName} in GroupFolder {$groupFolderId}");
            return null;
        }

        $storage->getScanner()->scan($fileName);
        $createdId = $cache->getId($fileName);

        return $createdId !== -1 ? (int) $createdId : null;
    }

    /**
     * Recalculate the new Team Folder root after all seeded files are written.
     * Individual file scans create their cache entries but do not update the
     * aggregate size stored on the Team Folder root.
     */
    private function refreshTeamFolderSize(int $groupFolderId): void
    {
        if ($this->folderManager === null || $this->folderStorageManager === null) {
            throw new OCSException('Team Folders is unavailable while finalizing project storage.', 503);
        }

        $groupFolder = $this->folderManager->getFolder($groupFolderId);
        if ($groupFolder === null) {
            throw new OCSException('The project Team Folder could not be loaded while finalizing storage.', 500);
        }

        $storage = $this->folderStorageManager->getBaseStorageForFolder(
            $groupFolderId,
            $groupFolder->useSeparateStorage(),
            $groupFolder,
            null,
            false,
            'files'
        );
        $storage->getScanner()->scan('');
    }
}
