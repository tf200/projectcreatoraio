<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEvent;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectActivityServiceTest extends TestCase {
	public function testRecordNoteCreatedStoresNormalizedPayload(): void {
		$eventMapper = $this->createMock(ProjectActivityEventMapper::class);
		$eventMapper->expects($this->once())
			->method('createEvent')
			->with(
				42,
				ProjectActivityService::EVENT_NOTE_CREATED,
				'owner1',
				'Owner One',
				[
					'noteId' => 7,
					'title' => 'Weekly recap',
					'visibility' => 'public',
					'noteType' => 'decision',
					'projectName' => 'Alpha',
				],
			);

		$service = new ProjectActivityService($eventMapper, $this->createMock(LoggerInterface::class));

		$project = new Project();
		$project->setId(42);
		$project->setName('Alpha');

		$note = new ProjectNote();
		$note->setId(7);
		$note->setTitle('Weekly recap');
		$note->setVisibility('public');
		$note->setNoteType('decision');

		$actor = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'owner1',
			'getDisplayName' => 'Owner One',
		]);

		$service->recordNoteCreated($project, $note, $actor);
	}

	public function testPrivateNotePayloadIsRedactedForOtherUsers(): void {
		$event = new ProjectActivityEvent();
		$event->setActorUid('owner1');
		$event->setEventType(ProjectActivityService::EVENT_NOTE_CREATED);
		$event->setPayloadArray([
			'noteId' => 7,
			'title' => 'Confidential risk',
			'visibility' => 'private',
			'noteType' => ProjectNote::NOTE_TYPE_RISK_BLOCKER,
			'projectName' => 'Alpha',
		]);

		$ownerEvent = ProjectActivityService::prepareEventForUser($event, 'owner1');
		$memberEvent = ProjectActivityService::prepareEventForUser($event, 'member1');

		$this->assertSame('Confidential risk', $ownerEvent->getPayloadArray()['title']);
		$this->assertSame([
			'visibility' => 'private',
			'redacted' => true,
			'projectName' => 'Alpha',
		], $memberEvent->getPayloadArray());
		$this->assertNotSame($event, $memberEvent);
	}

	public function testDirectMessagePayloadIsOnlyVisibleToParticipants(): void {
		$event = new ProjectActivityEvent();
		$event->setActorUid('alice');
		$event->setEventType(ProjectActivityService::EVENT_TALK_DIRECT_MESSAGE_SENT);
		$event->setPayloadArray([
			'messagePreview' => 'Confidential message',
			'isDirectChat' => true,
			'otherUserId' => 'bob',
			'projectName' => 'Alpha',
		]);

		$this->assertSame('Confidential message', ProjectActivityService::prepareEventForUser($event, 'alice')->getPayloadArray()['messagePreview']);
		$this->assertSame('Confidential message', ProjectActivityService::prepareEventForUser($event, 'bob')->getPayloadArray()['messagePreview']);
		$this->assertSame([
			'redacted' => true,
			'isDirectChat' => true,
			'projectName' => 'Alpha',
		], ProjectActivityService::prepareEventForUser($event, 'charlie')->getPayloadArray());
	}

	public function testDirectChatCreationPayloadIsOnlyVisibleToParticipants(): void {
		$event = new ProjectActivityEvent();
		$event->setActorUid('alice');
		$event->setEventType(ProjectActivityService::EVENT_TALK_DIRECT_CHAT_CREATED);
		$event->setPayloadArray([
			'targetUserId' => 'bob',
			'conversationToken' => 'private-token',
		]);

		$this->assertSame('private-token', ProjectActivityService::prepareEventForUser($event, 'bob')->getPayloadArray()['conversationToken']);
		$this->assertArrayNotHasKey('conversationToken', ProjectActivityService::prepareEventForUser($event, 'charlie')->getPayloadArray());
	}
}
