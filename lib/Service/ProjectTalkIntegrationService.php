<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCP\Comments\ICommentsManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IServerContainer;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Talk\IBroker;
use OCP\Talk\Exceptions\NoBackendException;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class ProjectTalkIntegrationService
{
    private const SPREED_MANAGER_CLASS = 'OCA\\Talk\\Manager';
    private const SPREED_PARTICIPANT_SERVICE_CLASS = 'OCA\\Talk\\Service\\ParticipantService';
    private const SPREED_ROOM_SERVICE_CLASS = 'OCA\\Talk\\Service\\RoomService';
    private const TALK_ACTOR_USERS = 'users';
    private const TALK_PARTICIPANT_USER = 3;
    private const TALK_HUMAN_MESSAGE_VERBS = ['comment', 'object_shared'];

    public function __construct(
        private readonly ?object $talkBroker,
        private readonly IServerContainer $serverContainer,
        private readonly IUserManager $userManager,
        private readonly IURLGenerator $urlGenerator,
        private readonly IRootFolder $rootFolder,
        private readonly IShareManager $shareManager,
        private readonly ICommentsManager $commentsManager,
        private readonly IL10NFactory $l10nFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        if ($this->talkBroker === null) {
            return false;
        }
        try {
            return $this->talkBroker->hasBackend();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param string[] $memberIds
     * @return array{token: string, url: string}
     */
    public function createProjectConversation(string $projectName, IUser $owner, array $memberIds): array
    {
        try {
            $conversation = $this->talkBroker->createConversation(
                trim($projectName) . ' - Chat',
                [$owner],
                $this->talkBroker->newConversationOptions()->setPublic(),
            );
        } catch (NoBackendException $e) {
            throw new RuntimeException('Talk is not available.', 0, $e);
        }

        $token = $conversation->getId();
        $otherMemberIds = array_values(array_filter(
            array_unique(array_map('strval', $memberIds)),
            static fn (string $memberId): bool => $memberId !== '' && $memberId !== $owner->getUID(),
        ));

        try {
            if ($otherMemberIds !== []) {
                $room = $this->getTalkManager()->getRoomByToken($token);
                $participants = [];
                foreach ($otherMemberIds as $memberId) {
                    $user = $this->userManager->get($memberId);
                    if ($user === null) {
                        continue;
                    }

                    $participants[] = [
                        'actorType' => self::TALK_ACTOR_USERS,
                        'actorId' => $user->getUID(),
                        'displayName' => $user->getDisplayName(),
                        'participantType' => self::TALK_PARTICIPANT_USER,
                    ];
                }

                $this->getParticipantService()->addUsers($room, $participants, $owner);
            }
        } catch (Throwable $e) {
            try {
                $this->talkBroker->deleteConversation($token);
            } catch (Throwable) {
            }

            throw $e;
        }

        return [
            'token' => $token,
            'url' => $conversation->getAbsoluteUrl(),
        ];
    }

    /**
     * Create a private 1-to-1 conversation between two project members for a specific project.
     *
     * @return array{token: string, url: string}
     */
    public function createProjectDirectConversation(
        string $projectName,
        int $projectId,
        IUser $user1,
        IUser $user2,
    ): array {
        $roomName = sprintf('%s - %s & %s', trim($projectName), $user1->getDisplayName(), $user2->getDisplayName());
        if (mb_strlen($roomName) > 255) {
            $roomName = mb_substr($roomName, 0, 252) . '...';
        }

        try {
            $conversation = $this->talkBroker->createConversation(
                $roomName,
                [$user1, $user2],
                $this->talkBroker->newConversationOptions(),
            );
        } catch (NoBackendException $e) {
            throw new RuntimeException('Talk is not available.', 0, $e);
        }

        $token = $conversation->getId();

        try {
            $room = $this->getTalkManager()->getRoomByToken($token);

            $participants = [
                [
                    'actorType' => self::TALK_ACTOR_USERS,
                    'actorId' => $user1->getUID(),
                    'displayName' => $user1->getDisplayName(),
                    'participantType' => self::TALK_PARTICIPANT_USER,
                ],
                [
                    'actorType' => self::TALK_ACTOR_USERS,
                    'actorId' => $user2->getUID(),
                    'displayName' => $user2->getDisplayName(),
                    'participantType' => self::TALK_PARTICIPANT_USER,
                ],
            ];

            try {
                $this->getParticipantService()->addUsers($room, $participants, $user1);
            } catch (Throwable) {
                // Participants may already be seeded by createConversation
            }

            if ($projectId > 0) {
                $this->trySetRoomDescription($room, sprintf(
                    'Direct project chat for %s between %s and %s.',
                    trim($projectName),
                    $user1->getDisplayName(),
                    $user2->getDisplayName(),
                ));
            }
        } catch (Throwable $e) {
            try {
                $this->talkBroker->deleteConversation($token);
            } catch (Throwable) {
            }

            throw $e;
        }

        $url = $conversation->getAbsoluteUrl();
        if ($url === null || $url === '') {
            $url = $this->buildConversationUrl($token) ?? '';
        }

        return [
            'token' => $token,
            'url' => $url,
        ];
    }

    public function addUserToConversation(string $conversationToken, IUser $user, ?IUser $addedBy = null): void
    {
        $conversationToken = trim($conversationToken);
        if ($conversationToken === '') {
            return;
        }

        if (!$this->isAvailable()) {
            $this->logger->warning('Talk is not available; skipping adding user to conversation', [
                'token' => $conversationToken,
                'userId' => $user->getUID(),
            ]);
            return;
        }

        try {
            $room = $this->getTalkManager()->getRoomByToken($conversationToken);
            $participants = [[
                'actorType' => self::TALK_ACTOR_USERS,
                'actorId' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
                'participantType' => self::TALK_PARTICIPANT_USER,
            ]];

            $this->getParticipantService()->addUsers($room, $participants, $addedBy);
        } catch (Throwable $e) {
            $this->logger->warning('Failed to add user to project Talk conversation', [
                'token' => $conversationToken,
                'userId' => $user->getUID(),
                'exception' => $e,
            ]);
        }
    }

    public function shareFileInConversation(
        string $conversationToken,
        int $fileId,
        IUser $actor,
        ?string $projectFolderPath = null,
        ?string $projectName = null,
        ?int $projectId = null,
        ?int $projectFolderId = null,
    ): bool
    {
        $conversationToken = trim($conversationToken);
        if ($conversationToken === '' || $fileId <= 0) {
            return false;
        }

        $room = $this->getTalkManager()->getRoomByToken($conversationToken);
        $file = $this->resolveUserFile($actor, $fileId, $projectFolderPath, $projectName, $projectId, $projectFolderId);
        if ($this->hasRoomShare($conversationToken, $file, $actor)) {
            return false;
        }

        $this->createRoomShare($conversationToken, $file, $actor);
        return true;
    }

    public function deleteConversation(string $conversationToken): void
    {
        $conversationToken = trim($conversationToken);
        if ($conversationToken === '') {
            return;
        }

        try {
            $this->talkBroker->deleteConversation($conversationToken);
        } catch (NoBackendException) {
        }
    }

    /**
     * @param string[] $conversationTokens
     */
    public function deleteConversations(array $conversationTokens): void
    {
        foreach ($conversationTokens as $token) {
            $this->deleteConversation((string) $token);
        }
    }

    public function buildConversationUrl(?string $conversationToken): ?string
    {
        $conversationToken = trim((string) $conversationToken);
        if ($conversationToken === '') {
            return null;
        }

        return $this->urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $conversationToken]);
    }

    /**
     * Fetch chat messages for a project's Talk conversation.
     *
     * @return array{messages: list<array{id: int, actorDisplayName: string, message: string, timestamp: int, messageType: string}>, hasMore: bool, nextOffset: int}
     */
    public function getConversationMessages(string $conversationToken, int $limit = 50, int $offset = 0): array
    {
        $conversationToken = trim($conversationToken);
        if ($conversationToken === '' || !$this->isAvailable()) {
            return ['messages' => [], 'hasMore' => false, 'nextOffset' => 0];
        }

        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        try {
            $room = $this->getTalkManager()->getRoomByToken($conversationToken);
        } catch (Throwable) {
            return ['messages' => [], 'hasMore' => false, 'nextOffset' => $offset];
        }

        try {
            $messageParser = $this->resolveTalkService('OCA\\Talk\\Chat\\MessageParser');
            $l10n = $this->l10nFactory->get('spreed');

            $messages = [];
            $nextOffset = $offset;
            $hasMore = false;
            $batchSize = max(50, $limit + 1);

            do {
                $comments = $this->commentsManager->getCommentsWithVerbForObjectSinceComment(
                    'chat',
                    (string)$room->getId(),
                    self::TALK_HUMAN_MESSAGE_VERBS,
                    $nextOffset,
                    'desc',
                    $batchSize,
                );

                foreach ($comments as $index => $comment) {
                    $nextOffset = (int)$comment->getId();
                    $message = $messageParser->createMessage($room, null, $comment, $l10n);
                    $messageParser->parseMessage($message);

                    if (!$message->getVisibility()) {
                        continue;
                    }

                    $messages[] = [
                        'id' => (int)$comment->getId(),
                        'actorDisplayName' => $message->getActorDisplayName(),
                        'message' => $message->getMessage(),
                        'timestamp' => $comment->getCreationDateTime()->getTimestamp(),
                        'messageType' => $message->getMessageType(),
                    ];

                    if (count($messages) === $limit) {
                        $hasMore = $index < count($comments) - 1 || count($comments) === $batchSize;
                        break 2;
                    }
                }

                $hasMore = count($comments) === $batchSize;
            } while ($hasMore);

            return [
                'messages' => $messages,
                'hasMore' => $hasMore,
                'nextOffset' => $nextOffset,
            ];
        } catch (Throwable $e) {
            $this->logger->warning('Failed to fetch Talk conversation messages', [
                'token' => $conversationToken,
                'exception' => $e,
            ]);
            return ['messages' => [], 'hasMore' => false, 'nextOffset' => $offset];
        }
    }

    private function getTalkManager(): object
    {
        return $this->resolveTalkService(self::SPREED_MANAGER_CLASS);
    }

    private function getParticipantService(): object
    {
        return $this->resolveTalkService(self::SPREED_PARTICIPANT_SERVICE_CLASS);
    }

    private function resolveUserFile(
        IUser $actor,
        int $fileId,
        ?string $projectFolderPath = null,
        ?string $projectName = null,
        ?int $projectId = null,
        ?int $projectFolderId = null,
    ): File
    {
        $userFolder = $this->rootFolder->getUserFolder($actor->getUID());
        $node = $userFolder->getFirstNodeById($fileId);
        if ($node instanceof File) {
            return $node;
        }

        $globalFile = $this->resolveGlobalFileById($fileId);
        if ($globalFile instanceof File) {
            return $globalFile;
        }

        if (($projectFolderId ?? 0) > 0) {
            $projectFolder = $this->resolveGlobalFolderById((int) $projectFolderId);
            if ($projectFolder instanceof Folder) {
                $fallbackFile = $this->findWhiteboardInFolder(
                    $projectFolder,
                    trim((string) $projectName),
                    $projectId,
                    $actor->getUID(),
                );
                if ($fallbackFile instanceof File) {
                    return $fallbackFile;
                }
            }
        }

        $folderPath = trim((string) $projectFolderPath);
        if ($folderPath !== '') {
            try {
                $folderNode = $userFolder->get($folderPath);
                if ($folderNode instanceof Folder) {
                    $fallbackFile = $this->findWhiteboardInFolder(
                        $folderNode,
                        trim((string) $projectName),
                        $projectId,
                        $actor->getUID(),
                    );
                    if ($fallbackFile instanceof File) {
                        return $fallbackFile;
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Project whiteboard folder fallback lookup failed', [
                    'projectId' => $projectId,
                    'fileId' => $fileId,
                    'actorUserId' => $actor->getUID(),
                    'projectFolderId' => $projectFolderId,
                    'projectFolderPath' => $folderPath,
                    'projectName' => $projectName,
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->warning('Project whiteboard could not be resolved for Talk sharing', [
            'projectId' => $projectId,
            'fileId' => $fileId,
            'actorUserId' => $actor->getUID(),
            'projectFolderId' => $projectFolderId,
            'projectFolderPath' => $folderPath,
            'projectName' => $projectName,
        ]);
        throw new RuntimeException(sprintf('File %d is not accessible for user "%s".', $fileId, $actor->getUID()));
    }

    private function resolveGlobalFileById(int $fileId): ?File
    {
        foreach ($this->rootFolder->getById($fileId) as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }

        return null;
    }

    private function resolveGlobalFolderById(int $folderId): ?Folder
    {
        foreach ($this->rootFolder->getById($folderId) as $node) {
            if ($node instanceof Folder) {
                return $node;
            }
        }

        return null;
    }

    private function createRoomShare(string $conversationToken, File $file, IUser $actor): IShare
    {
        $share = $this->shareManager->newShare();
        $share->setNodeId($file->getId())
            ->setShareTime(new DateTime())
            ->setSharedBy($actor->getUID())
            ->setNode($file)
            ->setShareType(IShare::TYPE_ROOM)
            ->setSharedWith($conversationToken)
            ->setPermissions(Constants::PERMISSION_READ);

        return $this->shareManager->createShare($share);
    }

    private function hasRoomShare(string $conversationToken, File $file, IUser $actor): bool
    {
        $shares = $this->shareManager->getSharesBy(
            $actor->getUID(),
            IShare::TYPE_ROOM,
            $file,
            false,
            -1,
            0,
        );

        foreach ($shares as $share) {
            if ($share->getSharedWith() === $conversationToken) {
                return true;
            }
        }

        return false;
    }

    private function findWhiteboardInFolder(
        Folder $folder,
        string $projectName,
        ?int $projectId = null,
        ?string $actorUserId = null,
    ): ?File
    {
        $preferred = $projectName !== '' ? $projectName . '.whiteboard' : null;

        foreach ($folder->getDirectoryListing() as $child) {
            if (!$child instanceof File) {
                continue;
            }

            $name = $child->getName();
            if (!is_string($name) || $name === '') {
                continue;
            }

            $lower = strtolower($name);
            if ($preferred !== null && $name === $preferred) {
                return $child;
            }

            if (str_ends_with($lower, '.whiteboard') || str_ends_with($lower, '.excalidraw')) {
                return $child;
            }
        }

        return null;
    }

    private function trySetRoomDescription(object $room, string $description): void
    {
        try {
            if (class_exists(self::SPREED_ROOM_SERVICE_CLASS)) {
                $roomService = $this->resolveTalkService(self::SPREED_ROOM_SERVICE_CLASS);
                if (method_exists($roomService, 'setDescription')) {
                    $roomService->setDescription($room, $description);
                }
            }
        } catch (Throwable) {
            // Non-fatal metadata enhancement
        }
    }

    private function resolveTalkService(string $serviceClass): object
    {
        if (!class_exists($serviceClass)) {
            throw new RuntimeException(sprintf('Talk service "%s" is not available.', $serviceClass));
        }

        return $this->serverContainer->get($serviceClass);
    }
}
