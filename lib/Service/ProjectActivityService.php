<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEvent;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCA\ProjectCreatorAIO\Db\TimelineItem;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class ProjectActivityService {
	public const SOURCE_INTERNAL = 'internal';
	public const SOURCE_DECK = 'deck';
	public const SOURCE_FILES = 'files';
	public const SOURCE_TALK = 'talk';
	public const SOURCE_WHITEBOARD = 'whiteboard';

	public const EVENT_PROJECT_CREATED = 'project_created';
	public const EVENT_PROJECT_UPDATED = 'project_updated';
	public const EVENT_PROJECT_ARCHIVED = 'project_archived';
	public const EVENT_PROJECT_RESTORED = 'project_restored';
	public const EVENT_PROJECT_DELETED = 'project_deleted';
	public const EVENT_MEMBER_ADDED = 'member_added';
	public const EVENT_MEMBER_REMOVED = 'member_removed';
	public const EVENT_WHITEBOARD_UPDATED = 'whiteboard_updated';
	public const EVENT_PROJECT_NOTES_UPDATED = 'project_notes_updated';
	public const EVENT_NOTE_CREATED = 'note_created';
	public const EVENT_NOTE_UPDATED = 'note_updated';
	public const EVENT_NOTE_DELETED = 'note_deleted';
	public const EVENT_TIMELINE_ITEM_CREATED = 'timeline_item_created';
	public const EVENT_TIMELINE_ITEM_UPDATED = 'timeline_item_updated';
	public const EVENT_TIMELINE_ITEM_DELETED = 'timeline_item_deleted';
	public const EVENT_TIMELINE_REORDERED = 'timeline_reordered';

	// Deck events
	public const EVENT_DECK_CARD_CREATED = 'deck_card_created';
	public const EVENT_DECK_CARD_UPDATED = 'deck_card_updated';
	public const EVENT_DECK_CARD_DELETED = 'deck_card_deleted';
	public const EVENT_DECK_ACL_ADDED = 'deck_acl_added';
	public const EVENT_DECK_ACL_REMOVED = 'deck_acl_removed';
	public const EVENT_DECK_ACL_UPDATED = 'deck_acl_updated';
	public const EVENT_DECK_BOARD_CREATED = 'deck_board_created';
	public const EVENT_DECK_BOARD_UPDATED = 'deck_board_updated';
	public const EVENT_DECK_BOARD_DELETED = 'deck_board_deleted';

	// Files events
	public const EVENT_FILE_CREATED = 'file_created';
	public const EVENT_FILE_UPDATED = 'file_updated';
	public const EVENT_FILE_DELETED = 'file_deleted';
	public const EVENT_FILE_RENAMED = 'file_renamed';
	public const EVENT_FILE_MOVED = 'file_moved';
	public const EVENT_FILE_COPIED = 'file_copied';
	public const EVENT_FOLDER_CREATED = 'folder_created';
	public const EVENT_FOLDER_DELETED = 'folder_deleted';

	// Talk events
	public const EVENT_TALK_MESSAGE_SENT = 'talk_message_sent';
	public const EVENT_TALK_DIRECT_MESSAGE_SENT = 'talk_direct_message_sent';
	public const EVENT_TALK_DIRECT_CHAT_CREATED = 'talk_direct_chat_created';
	public const EVENT_TALK_PARTICIPANT_ADDED = 'talk_participant_added';
	public const EVENT_TALK_PARTICIPANT_REMOVED = 'talk_participant_removed';
	public const EVENT_TALK_CALL_STARTED = 'talk_call_started';
	public const EVENT_TALK_CALL_ENDED = 'talk_call_ended';
	public const EVENT_TALK_ROOM_UPDATED = 'talk_room_updated';
	public const EVENT_TALK_REACTION_ADDED = 'talk_reaction_added';
	public const EVENT_TALK_REACTION_REMOVED = 'talk_reaction_removed';
	public const EVENT_TALK_USER_JOINED = 'talk_user_joined';

	public function __construct(
		private readonly ProjectActivityEventMapper $eventMapper,
		private readonly LoggerInterface $logger,
	) {
	}

	public function record(
		Project $project,
		string $eventType,
		string $source = self::SOURCE_INTERNAL,
		?IUser $actor = null,
		array $payload = [],
	): void {
		$actorUid = $actor?->getUID();
		$actorDisplayName = $actor !== null ? $this->getUserDisplayName($actor) : null;
		$this->recordWithActorInfo($project, $eventType, $source, $actorUid, $actorDisplayName, $payload);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function recordWithActorInfo(
		Project $project,
		string $eventType,
		string $source = self::SOURCE_INTERNAL,
		?string $actorUid = null,
		?string $actorDisplayName = null,
		array $payload = [],
	): void {
		$projectId = (int) ($project->getId() ?? 0);
		if ($projectId <= 0) {
			return;
		}

		$payload['projectName'] = trim((string) ($project->getName() ?? ''));

		try {
			$this->eventMapper->createEvent($projectId, $eventType, $actorUid, $actorDisplayName, $payload, null, $source);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to record project activity event', [
				'exception' => $e,
				'projectId' => $projectId,
				'eventType' => $eventType,
				'source' => $source,
			]);
		}
	}

	public function recordProjectCreated(Project $project, ?IUser $actor = null): void {
		$this->record($project, self::EVENT_PROJECT_CREATED, self::SOURCE_INTERNAL, $actor);
	}

	public function recordProjectUpdated(Project $project, ?IUser $actor, array $changedFields): void {
		if (empty($changedFields)) {
			return;
		}
		$this->record($project, self::EVENT_PROJECT_UPDATED, self::SOURCE_INTERNAL, $actor, [
			'changedFields' => $changedFields,
		]);
	}

	public function recordProjectArchived(Project $project, ?IUser $actor): void {
		$this->record($project, self::EVENT_PROJECT_ARCHIVED, self::SOURCE_INTERNAL, $actor);
	}

	public function recordProjectRestored(Project $project, ?IUser $actor): void {
		$this->record($project, self::EVENT_PROJECT_RESTORED, self::SOURCE_INTERNAL, $actor);
	}

	public function recordProjectDeleted(Project $project, ?IUser $actor): void {
		$this->record($project, self::EVENT_PROJECT_DELETED, self::SOURCE_INTERNAL, $actor);
	}

	public function recordMemberAdded(Project $project, IUser $member, ?IUser $actor = null): void {
		$this->record($project, self::EVENT_MEMBER_ADDED, self::SOURCE_INTERNAL, $actor, [
			'memberUid' => $member->getUID(),
			'memberDisplayName' => $this->getUserDisplayName($member),
		]);
	}

	public function recordMemberRemoved(Project $project, string $memberUid, ?string $memberDisplayName = null, ?IUser $actor = null): void {
		$this->record($project, self::EVENT_MEMBER_REMOVED, self::SOURCE_INTERNAL, $actor, [
			'memberUid' => $memberUid,
			'memberDisplayName' => $memberDisplayName ?? $memberUid,
		]);
	}

	/**
	 * @param array<string, mixed> $extraPayload
	 */
	public function recordWhiteboardUpdated(Project $project, IUser $actor, array $extraPayload = []): void {
		$this->record($project, self::EVENT_WHITEBOARD_UPDATED, self::SOURCE_WHITEBOARD, $actor, $extraPayload);
	}

	/**
	 * @return ProjectActivityEvent[]
	 */
	public function getWhiteboardActivity(int $projectId, int $limit = 20, int $offset = 0): array {
		return $this->eventMapper->findForProject($projectId, self::EVENT_WHITEBOARD_UPDATED, $limit, $offset);
	}

	/**
	 * @return ProjectActivityEvent[]
	 */
	public function getProjectActivity(int $projectId, string $userId, int $limit = 20, int $offset = 0, ?string $source = null): array {
		return array_map(
			static fn (ProjectActivityEvent $event): ProjectActivityEvent => self::prepareEventForUser($event, $userId),
			$this->eventMapper->findForProject($projectId, null, $limit, $offset, $source),
		);
	}

	public static function prepareEventForUser(ProjectActivityEvent $event, string $userId): ProjectActivityEvent {
		$payload = $event->getPayloadArray();
		$isDirectChatEvent = in_array($event->getEventType(), [
			self::EVENT_TALK_DIRECT_CHAT_CREATED,
			self::EVENT_TALK_DIRECT_MESSAGE_SENT,
		], true);
		$otherUserId = (string)($payload['targetUserId'] ?? $payload['otherUserId'] ?? '');

		if ($isDirectChatEvent && $event->getActorUid() !== $userId && $otherUserId !== $userId) {
			$redacted = clone $event;
			$redacted->setPayloadArray([
				'redacted' => true,
				'isDirectChat' => true,
				'projectName' => $payload['projectName'] ?? '',
			]);
			return $redacted;
		}

		$isPrivateNote = in_array($event->getEventType(), [
			self::EVENT_NOTE_CREATED,
			self::EVENT_NOTE_UPDATED,
			self::EVENT_NOTE_DELETED,
		], true) && ($payload['visibility'] ?? null) === 'private';

		if (!$isPrivateNote || $event->getActorUid() === $userId) {
			return $event;
		}

		$redacted = clone $event;
		$redacted->setPayloadArray([
			'visibility' => 'private',
			'redacted' => true,
			'projectName' => $payload['projectName'] ?? '',
		]);
		return $redacted;
	}

	public function recordProjectNotesUpdated(Project $project, IUser $actor, bool $publicUpdated, bool $privateUpdated): void {
		if (!$publicUpdated && !$privateUpdated) {
			return;
		}

		$this->record($project, self::EVENT_PROJECT_NOTES_UPDATED, self::SOURCE_INTERNAL, $actor, [
			'publicUpdated' => $publicUpdated,
			'privateUpdated' => $privateUpdated,
		]);
	}

	public function recordNoteCreated(Project $project, ProjectNote $note, IUser $actor): void {
		$this->record($project, self::EVENT_NOTE_CREATED, self::SOURCE_INTERNAL, $actor, $this->buildNotePayload($note));
	}

	public function recordNoteUpdated(Project $project, ProjectNote $note, IUser $actor): void {
		$this->record($project, self::EVENT_NOTE_UPDATED, self::SOURCE_INTERNAL, $actor, $this->buildNotePayload($note));
	}

	public function recordNoteDeleted(Project $project, ProjectNote $note, IUser $actor): void {
		$this->record($project, self::EVENT_NOTE_DELETED, self::SOURCE_INTERNAL, $actor, $this->buildNotePayload($note));
	}

	public function recordTimelineItemCreated(Project $project, TimelineItem $item, ?IUser $actor): void {
		$this->record($project, self::EVENT_TIMELINE_ITEM_CREATED, self::SOURCE_INTERNAL, $actor, $this->buildTimelinePayload($item));
	}

	public function recordTimelineItemUpdated(Project $project, TimelineItem $item, ?IUser $actor): void {
		$this->record($project, self::EVENT_TIMELINE_ITEM_UPDATED, self::SOURCE_INTERNAL, $actor, $this->buildTimelinePayload($item));
	}

	public function recordTimelineItemDeleted(Project $project, TimelineItem $item, ?IUser $actor): void {
		$this->record($project, self::EVENT_TIMELINE_ITEM_DELETED, self::SOURCE_INTERNAL, $actor, $this->buildTimelinePayload($item));
	}

	public function recordTimelineReordered(Project $project, int $count, ?IUser $actor): void {
		$this->record($project, self::EVENT_TIMELINE_REORDERED, self::SOURCE_INTERNAL, $actor, [
			'count' => $count,
		]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildNotePayload(ProjectNote $note): array {
		return [
			'noteId' => (int) ($note->getId() ?? 0),
			'title' => trim((string) ($note->getTitle() ?? '')),
			'visibility' => trim((string) ($note->getVisibility() ?? 'public')),
			'noteType' => ProjectNote::normalizeNoteType($note->getNoteType()),
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildTimelinePayload(TimelineItem $item): array {
		return [
			'itemId' => (int) ($item->getId() ?? 0),
			'label' => trim((string) ($item->getLabel() ?? '')),
			'itemType' => trim((string) ($item->getItemType() ?? 'phase')),
		];
	}

	private function getUserDisplayName(IUser $user): string {
		$displayName = trim((string) ($user->getDisplayName() ?? ''));
		return $displayName !== '' ? $displayName : (string) $user->getUID();
	}
}
