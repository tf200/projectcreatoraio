<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCP\Comments\ICommentsManager;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Talk\Exceptions\NoBackendException;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class ProjectTalkIntegrationService {
	private const SPREED_MANAGER_CLASS = 'OCA\\Talk\\Manager';
	private const SPREED_PARTICIPANT_SERVICE_CLASS = 'OCA\\Talk\\Service\\ParticipantService';
	private const SPREED_ROOM_SERVICE_CLASS = 'OCA\\Talk\\Service\\RoomService';
	private const TALK_ACTOR_USERS = 'users';
	private const TALK_PARTICIPANT_OWNER = 1;
	private const TALK_PARTICIPANT_MODERATOR = 2;
	private const TALK_PARTICIPANT_USER = 3;
	private const TALK_REMOVAL_REASON = 'remove';
	private const TALK_ROOM_READ_WRITE = 0;
	private const TALK_ROOM_READ_ONLY = 1;
	// File shares are stored as system messages; parsing turns them into normal messages.
	private const TALK_HUMAN_MESSAGE_VERBS = ['comment', 'object_shared', 'system', 'comment_deleted'];
	private const TALK_SYSTEM_MESSAGE_TYPE = 'system';
	private const TALK_DELETED_MESSAGE_TYPE = 'comment_deleted';

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

	public function isAvailable(): bool {
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
	public function createProjectConversation(string $projectName, IUser $owner, array $memberIds): array {
		try {
			$conversation = $this->talkBroker->createConversation(
				self::projectConversationName($projectName),
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

	public static function projectConversationName(string $projectName): string {
		return self::fitRoomName(trim($projectName) . ' - Chat');
	}

	public static function directConversationName(string $projectName, string $displayName1, string $displayName2): string {
		return self::fitRoomName(sprintf('%s - %s & %s', trim($projectName), $displayName1, $displayName2));
	}

	private static function fitRoomName(string $name): string {
		return mb_strlen($name) > 255 ? mb_substr($name, 0, 252) . '...' : $name;
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
		$roomName = self::directConversationName($projectName, $user1->getDisplayName(), $user2->getDisplayName());

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

    public function addUserToConversation(string $conversationToken, IUser $user, ?IUser $addedBy = null): void {
        $this->addUsersToConversation($conversationToken, [$user], $addedBy);
    }

    /**
     * @param IUser[] $users
     */
    public function addUsersToConversation(string $conversationToken, array $users, ?IUser $addedBy = null): void {
        $conversationToken = trim($conversationToken);
        if ($conversationToken === '' || $users === []) {
            return;
        }

		if (!$this->isAvailable()) {
			$this->logger->warning('Talk is not available; skipping adding users to conversation', [
				'token' => $conversationToken,
				'count' => count($users),
			]);
			return;
		}

		try {
			$room = $this->getTalkManager()->getRoomByToken($conversationToken);
			$participants = [];
			foreach ($users as $user) {
				if (!$user instanceof IUser) {
					continue;
				}
				$participants[] = [
					'actorType' => self::TALK_ACTOR_USERS,
					'actorId' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
					'participantType' => self::TALK_PARTICIPANT_USER,
				];
			}

			if ($participants === []) {
				return;
			}

			$this->getParticipantService()->addUsers($room, $participants, $addedBy);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to add users to project Talk conversation', [
				'token' => $conversationToken,
				'count' => count($users),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Makes a participant a moderator, adding them first when needed. Owners
	 * and moderators stay as they are. False when Talk could not do it.
	 */
	public function promoteToModerator(string $conversationToken, IUser $user): bool {
		$conversationToken = trim($conversationToken);
		if ($conversationToken === '' || !$this->isAvailable()) {
			return false;
		}

		try {
			$room = $this->getTalkManager()->getRoomByToken($conversationToken);
			$participantService = $this->getParticipantService();
			try {
				$participant = $participantService->getParticipant($room, $user->getUID());
			} catch (Throwable) {
				$this->addUsersToConversation($conversationToken, [$user]);
				$participant = $participantService->getParticipant($room, $user->getUID());
			}
			$type = (int)$participant->getAttendee()->getParticipantType();
			if ($type !== self::TALK_PARTICIPANT_OWNER && $type !== self::TALK_PARTICIPANT_MODERATOR) {
				$participantService->updateParticipantType($room, $participant, self::TALK_PARTICIPANT_MODERATOR);
			}
			return true;
		} catch (Throwable $e) {
			$this->logger->warning('Failed to make a user moderator of the project Talk conversation', [
				'token' => $conversationToken,
				'userId' => $user->getUID(),
				'exception' => $e,
			]);
			return false;
		}
	}

	public function removeUserFromConversation(string $conversationToken, IUser $user): void {
		$conversationToken = trim($conversationToken);
		if ($conversationToken === '' || !$this->isAvailable()) {
			return;
		}

		try {
			$room = $this->getTalkManager()->getRoomByToken($conversationToken);
			$this->getParticipantService()->removeUser($room, $user, self::TALK_REMOVAL_REASON);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to remove user from project Talk conversation', [
				'token' => $conversationToken,
				'userId' => $user->getUID(),
				'exception' => $e,
			]);
		}
	}

	/**
	 * Renames a project room; see projectConversationName() and directConversationName().
	 */
	public function renameConversation(string $conversationToken, string $name): void {
		$conversationToken = trim($conversationToken);
		$name = self::fitRoomName(trim($name));
		if ($conversationToken === '' || $name === '' || !$this->isAvailable()) {
			return;
		}

		try {
			$room = $this->getTalkManager()->getRoomByToken($conversationToken);
			$this->resolveTalkService(self::SPREED_ROOM_SERVICE_CLASS)->setName($room, $name);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to rename project Talk conversation', [
				'token' => $conversationToken,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Adds back whoever of these users left the room, and lets them post
	 * again unless the project is archived.
	 *
	 * @param IUser[] $users
	 */
	public function reopenConversation(string $conversationToken, array $users, bool $writable): void {
		$conversationToken = trim($conversationToken);
		if ($conversationToken === '' || !$this->isAvailable()) {
			return;
		}

		try {
			$room = $this->getTalkManager()->getRoomByToken($conversationToken);
			$missing = array_values(array_filter(
				$users,
				fn (IUser $user): bool => $this->findViewerParticipant($room, $user->getUID()) === null,
			));
		} catch (Throwable $e) {
			$this->logger->warning('Failed to reopen project Talk conversation', [
				'token' => $conversationToken,
				'exception' => $e,
			]);
			return;
		}

		if ($missing === []) {
			return;
		}
		$this->addUsersToConversation($conversationToken, $missing);
		if ($writable) {
			$this->setConversationReadOnly($conversationToken, false);
		}
	}

	/**
	 * An archived project's rooms stay readable but nobody can post in them.
	 */
	public function setConversationReadOnly(string $conversationToken, bool $readOnly): void {
		$conversationToken = trim($conversationToken);
		if ($conversationToken === '' || !$this->isAvailable()) {
			return;
		}

		try {
			$room = $this->getTalkManager()->getRoomByToken($conversationToken);
			$this->resolveTalkService(self::SPREED_ROOM_SERVICE_CLASS)->setReadOnly(
				$room,
				$readOnly ? self::TALK_ROOM_READ_ONLY : self::TALK_ROOM_READ_WRITE,
			);
		} catch (Throwable $e) {
			$this->logger->warning('Failed to change whether the project Talk conversation is read-only', [
				'token' => $conversationToken,
				'readOnly' => $readOnly,
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
	): bool {
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

	public function deleteConversation(string $conversationToken): void {
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
	public function deleteConversations(array $conversationTokens): void {
		foreach ($conversationTokens as $token) {
			$this->deleteConversation((string)$token);
		}
	}

	public function buildConversationUrl(?string $conversationToken): ?string {
		$conversationToken = trim((string)$conversationToken);
		if ($conversationToken === '') {
			return null;
		}

		return $this->urlGenerator->linkToRouteAbsolute('spreed.Page.showCall', ['token' => $conversationToken]);
	}

	/**
	 * Fetch chat messages for a project's Talk conversation.
	 *
	 * Messages carry Talk's rich parameters (mentions, files, shared objects) so
	 * the client can render them. With a viewer who is in the room, file links
	 * and previews resolve the way Talk shows them to that user.
	 *
	 * @return array{messages: list<array<string, mixed>>, hasMore: bool, nextOffset: int}
	 */
	public function getConversationMessages(string $conversationToken, int $limit = 50, int $offset = 0, ?string $viewerId = null): array {
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
			$participant = $this->findViewerParticipant($room, $viewerId);

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
					$message = $this->serializeChatMessage($messageParser, $room, $participant, $comment, $l10n);
					if ($message === null) {
						continue;
					}

					$parent = $this->findParentMessage($messageParser, $room, $participant, $comment, $l10n);
					if ($parent !== null) {
						$message['parent'] = $parent;
					}
					$messages[] = $message;

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

	/**
	 * @return array<string, mixed>|null null when Talk hides the message or it is a system notice
	 */
	private function serializeChatMessage(object $messageParser, object $room, ?object $participant, object $comment, object $l10n): ?array {
		$message = $messageParser->createMessage($room, $participant, $comment, $l10n);
		$messageParser->parseMessage($message);

		if (!$message->getVisibility() || $message->getMessageType() === self::TALK_SYSTEM_MESSAGE_TYPE) {
			return null;
		}

		$reactions = method_exists($comment, 'getReactions') ? $comment->getReactions() : [];

		$data = [
			'id' => (int)$comment->getId(),
			'actorType' => $message->getActorType(),
			'actorId' => $message->getActorId(),
			'actorDisplayName' => $message->getActorDisplayName(),
			'message' => $message->getMessage(),
			'messageParameters' => $message->getMessageParameters(),
			'timestamp' => $comment->getCreationDateTime()->getTimestamp(),
			'messageType' => $message->getMessageType(),
			'reactions' => is_array($reactions) ? $reactions : [],
		];

		if ($message->getMessageType() === self::TALK_DELETED_MESSAGE_TYPE) {
			$data['deleted'] = true;
		}

		// Talk keeps the last edit in the comment's metadata.
		$metaData = method_exists($comment, 'getMetaData') ? ($comment->getMetaData() ?? []) : [];
		if (isset($metaData['last_edited_time'])) {
			$data['lastEditTimestamp'] = (int)$metaData['last_edited_time'];
			$data['lastEditActorDisplayName'] = (string)($metaData['last_edited_by_displayname'] ?? '');
		}

		return $data;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function findParentMessage(object $messageParser, object $room, ?object $participant, object $comment, object $l10n): ?array {
		$parentId = method_exists($comment, 'getParentId') ? (string)$comment->getParentId() : '0';
		if ($parentId === '' || $parentId === '0') {
			return null;
		}

		try {
			$parentComment = $this->commentsManager->get($parentId);
			$parent = $this->serializeChatMessage($messageParser, $room, $participant, $parentComment, $l10n);
		} catch (Throwable) {
			return null;
		}
		if ($parent === null) {
			return null;
		}
		unset($parent['reactions']);
		return $parent;
	}

	private function findViewerParticipant(object $room, ?string $viewerId): ?object {
		$viewerId = trim((string)$viewerId);
		if ($viewerId === '') {
			return null;
		}

		try {
			return $this->getParticipantService()->getParticipant($room, $viewerId);
		} catch (Throwable) {
			// Not in the room (for example an admin looking at the project).
			return null;
		}
	}

	private function getTalkManager(): object {
		return $this->resolveTalkService(self::SPREED_MANAGER_CLASS);
	}

	private function getParticipantService(): object {
		return $this->resolveTalkService(self::SPREED_PARTICIPANT_SERVICE_CLASS);
	}

	private function resolveUserFile(
		IUser $actor,
		int $fileId,
		?string $projectFolderPath = null,
		?string $projectName = null,
		?int $projectId = null,
		?int $projectFolderId = null,
	): File {
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
			$projectFolder = $this->resolveGlobalFolderById((int)$projectFolderId);
			if ($projectFolder instanceof Folder) {
				$fallbackFile = $this->findWhiteboardInFolder(
					$projectFolder,
					trim((string)$projectName),
					$projectId,
					$actor->getUID(),
				);
				if ($fallbackFile instanceof File) {
					return $fallbackFile;
				}
			}
		}

		$folderPath = trim((string)$projectFolderPath);
		if ($folderPath !== '') {
			try {
				$folderNode = $userFolder->get($folderPath);
				if ($folderNode instanceof Folder) {
					$fallbackFile = $this->findWhiteboardInFolder(
						$folderNode,
						trim((string)$projectName),
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

	private function resolveGlobalFileById(int $fileId): ?File {
		foreach ($this->rootFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}

	private function resolveGlobalFolderById(int $folderId): ?Folder {
		foreach ($this->rootFolder->getById($folderId) as $node) {
			if ($node instanceof Folder) {
				return $node;
			}
		}

		return null;
	}

	private function createRoomShare(string $conversationToken, File $file, IUser $actor): IShare {
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

	private function hasRoomShare(string $conversationToken, File $file, IUser $actor): bool {
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
	): ?File {
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

	private function trySetRoomDescription(object $room, string $description): void {
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

	private function resolveTalkService(string $serviceClass): object {
		if (!class_exists($serviceClass)) {
			throw new RuntimeException(sprintf('Talk service "%s" is not available.', $serviceClass));
		}

		return $this->serverContainer->get($serviceClass);
	}
}
