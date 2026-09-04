<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Listener;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChat;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChatMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\Talk\Events\AttendeeRemovedEvent;
use OCA\Talk\Events\AttendeesAddedEvent;
use OCA\Talk\Events\CallEndedEvent;
use OCA\Talk\Events\CallStartedEvent;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCA\Talk\Events\ReactionAddedEvent;
use OCA\Talk\Events\ReactionRemovedEvent;
use OCA\Talk\Events\RoomModifiedEvent;
use OCA\Talk\Events\UserJoinedRoomEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;

/** @template-implements IEventListener<Event> */
class TalkEventListener implements IEventListener {
	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectActivityService $projectActivityService,
		private readonly IUserManager $userManager,
		private readonly ?ProjectDirectChatMapper $directChatMapper = null,
	) {
	}

	public function handle(Event $event): void {
		match (true) {
			$event instanceof ChatMessageSentEvent => $this->handleMessageSent($event),
			$event instanceof AttendeesAddedEvent => $this->handleAttendeesAdded($event),
			$event instanceof AttendeeRemovedEvent => $this->handleAttendeeRemoved($event),
			$event instanceof CallStartedEvent => $this->handleCallStarted($event),
			$event instanceof CallEndedEvent => $this->handleCallEnded($event),
			$event instanceof RoomModifiedEvent => $this->handleRoomModified($event),
			$event instanceof ReactionAddedEvent => $this->handleReactionAdded($event),
			$event instanceof ReactionRemovedEvent => $this->handleReactionRemoved($event),
			$event instanceof UserJoinedRoomEvent => $this->handleUserJoined($event),
			default => null,
		};
	}

	/**
	 * @return array{0: ?Project, 1: ?ProjectDirectChat}
	 */
	private function resolveProjectAndDirectChat(string $token): array {
		$token = trim($token);
		if ($token === '') {
			return [null, null];
		}

		$project = $this->projectMapper->findByTalkConversationToken($token);
		if ($project !== null) {
			return [$project, null];
		}

		if ($this->directChatMapper !== null) {
			$directChat = $this->directChatMapper->findByTalkConversationToken($token);
			if ($directChat !== null) {
				$project = $this->projectMapper->find((int)$directChat->getProjectId());
				if ($project !== null) {
					return [$project, $directChat];
				}
			}
		}

		return [null, null];
	}

	private function resolveActor(string $uid): array {
		$user = $this->userManager->get($uid);
		if ($user !== null) {
			return [$user->getUID(), $user->getDisplayName()];
		}
		return [$uid, $uid];
	}

	private function handleMessageSent(ChatMessageSentEvent $event): void {
		$room = $event->getRoom();
		$token = $room->getToken();

		[$project, $directChat] = $this->resolveProjectAndDirectChat($token);
		if ($project === null) {
			return;
		}

		$comment = $event->getComment();
		$message = $comment->getMessage();
		$preview = mb_substr($message, 0, 200);
		if (mb_strlen($message) > 200) {
			$preview .= '...';
		}

		[$actorUid, $actorDisplayName] = $this->resolveActor($comment->getActorId());

		$eventType = $directChat !== null
			? ProjectActivityService::EVENT_TALK_DIRECT_MESSAGE_SENT
			: ProjectActivityService::EVENT_TALK_MESSAGE_SENT;

		$payload = [
			'messagePreview' => $preview,
			'messageLength' => mb_strlen($message),
		];

		if ($directChat !== null) {
			$this->directChatMapper?->touch($directChat);
			$payload['isDirectChat'] = true;
			$payload['directChatId'] = $directChat->getId();
			$payload['otherUserId'] = $directChat->getOtherUserId($actorUid);
		}

		$this->projectActivityService->recordWithActorInfo($project, $eventType, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, $payload);
	}

	private function handleAttendeesAdded(AttendeesAddedEvent $event): void {
		$room = $event->getRoom();
		$token = $room->getToken();

		[$project] = $this->resolveProjectAndDirectChat($token);
		if ($project === null) {
			return;
		}

		$attendees = $event->getAttendees();
		foreach ($attendees as $attendee) {
			[$actorUid, $actorDisplayName] = $this->resolveActor($attendee->getActorId());

			$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_PARTICIPANT_ADDED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, [
				'participantUid' => $attendee->getActorId(),
				'participantDisplayName' => $attendee->getDisplayName(),
				'participantType' => $attendee->getActorType(),
			]);
		}
	}

	private function handleAttendeeRemoved(AttendeeRemovedEvent $event): void {
		$room = $event->getRoom();
		$token = $room->getToken();

		[$project] = $this->resolveProjectAndDirectChat($token);
		if ($project === null) {
			return;
		}

		$attendee = $event->getAttendee();
		$reason = $event->getReason();

		[$actorUid, $actorDisplayName] = $this->resolveActor($attendee->getActorId());

		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_PARTICIPANT_REMOVED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, [
			'participantUid' => $attendee->getActorId(),
			'participantDisplayName' => $attendee->getDisplayName(),
			'reason' => $reason,
		]);
	}

	private function handleCallStarted(CallStartedEvent $event): void {
		$room = $event->getRoom();
		$token = $room->getToken();

		[$project] = $this->resolveProjectAndDirectChat($token);
		if ($project === null) {
			return;
		}

		$callFlag = $event->getCallFlag();

		$actorUid = null;
		$actorDisplayName = null;
		$talkActor = $event->getActor();
		if ($talkActor !== null) {
			[$actorUid, $actorDisplayName] = $this->resolveActor($talkActor->getAttendee()->getActorId());
		}

		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_CALL_STARTED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, [
			'callFlag' => $callFlag,
		]);
	}

	private function handleCallEnded(CallEndedEvent $event): void {
		$room = $event->getRoom();
		$token = $room->getToken();

		[$project] = $this->resolveProjectAndDirectChat($token);
		if ($project === null) {
			return;
		}

		$actorUid = null;
		$actorDisplayName = null;
		$talkActor = $event->getActor();
		if ($talkActor !== null) {
			[$actorUid, $actorDisplayName] = $this->resolveActor($talkActor->getAttendee()->getActorId());
		}

		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_CALL_ENDED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName);
	}

	private function handleReactionAdded(ReactionAddedEvent $event): void {
		[$project] = $this->resolveProjectAndDirectChat($event->getRoom()->getToken());
		if ($project === null) {
			return;
		}

		[$actorUid, $actorDisplayName] = $this->resolveActor($event->getActorId());
		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_REACTION_ADDED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, [
			'reaction' => $event->getReaction(),
		]);
	}

	private function handleReactionRemoved(ReactionRemovedEvent $event): void {
		[$project] = $this->resolveProjectAndDirectChat($event->getRoom()->getToken());
		if ($project === null) {
			return;
		}

		[$actorUid, $actorDisplayName] = $this->resolveActor($event->getActorId());
		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_REACTION_REMOVED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, [
			'reaction' => $event->getReaction(),
		]);
	}

	private function handleUserJoined(UserJoinedRoomEvent $event): void {
		[$project] = $this->resolveProjectAndDirectChat($event->getRoom()->getToken());
		if ($project === null) {
			return;
		}

		$user = $event->getUser();
		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_USER_JOINED, ProjectActivityService::SOURCE_TALK, $user->getUID(), $user->getDisplayName());
	}

	private function handleRoomModified(RoomModifiedEvent $event): void {
		$room = $event->getRoom();
		$token = $room->getToken();

		[$project] = $this->resolveProjectAndDirectChat($token);
		if ($project === null) {
			return;
		}

		$property = $event->getProperty();

		$relevantProperties = [
			RoomModifiedEvent::PROPERTY_NAME,
			RoomModifiedEvent::PROPERTY_DESCRIPTION,
			RoomModifiedEvent::PROPERTY_READ_ONLY,
			RoomModifiedEvent::PROPERTY_LOBBY,
		];

		if (!in_array($property, $relevantProperties, true)) {
			return;
		}

		$actorUid = null;
		$actorDisplayName = null;
		$talkActor = $event->getActor();
		if ($talkActor !== null) {
			[$actorUid, $actorDisplayName] = $this->resolveActor($talkActor->getAttendee()->getActorId());
		}

		$this->projectActivityService->recordWithActorInfo($project, ProjectActivityService::EVENT_TALK_ROOM_UPDATED, ProjectActivityService::SOURCE_TALK, $actorUid, $actorDisplayName, [
			'property' => $property,
		]);
	}
}
