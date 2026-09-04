<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Listener;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChat;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChatMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Listener\TalkEventListener;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\Talk\Events\CallStartedEvent;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCA\Talk\Room;
use OCP\Comments\IComment;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class TalkEventListenerTest extends TestCase {
	private ProjectMapper $projectMapper;
	private ProjectDirectChatMapper $directChatMapper;
	private ProjectActivityService $activityService;
	private IUserManager $userManager;
	private TalkEventListener $listener;

	protected function setUp(): void {
		parent::setUp();

		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->directChatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$this->activityService = $this->createMock(ProjectActivityService::class);
		$this->userManager = $this->createMock(IUserManager::class);

		$alice = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
		]);
		$this->userManager->method('get')->with('alice')->willReturn($alice);

		$this->listener = new TalkEventListener(
			$this->projectMapper,
			$this->activityService,
			$this->userManager,
			$this->directChatMapper,
		);
	}

	public function testChatMessageSentInGroupChatRecordsStandardTalkEvent(): void {
		$project = new Project();
		$project->setId(42);
		$project->setName('Project Group');

		$this->projectMapper->method('findByTalkConversationToken')
			->with('group-token')
			->willReturn($project);

		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('group-token');

		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn('Hello Team');
		$comment->method('getActorId')->willReturn('alice');

		$event = new ChatMessageSentEvent($room, $comment);

		$this->activityService->expects($this->once())
			->method('recordWithActorInfo')
			->with(
				$project,
				ProjectActivityService::EVENT_TALK_MESSAGE_SENT,
				ProjectActivityService::SOURCE_TALK,
				'alice',
				'Alice',
				[
					'messagePreview' => 'Hello Team',
					'messageLength' => 10,
				]
			);

		$this->listener->handle($event);
	}

	public function testChatMessageSentInDirectChatRecordsDirectMessageSentEvent(): void {
		$project = new Project();
		$project->setId(42);
		$project->setName('Project Group');

		// Not a group chat token
		$this->projectMapper->method('findByTalkConversationToken')
			->with('direct-token')
			->willReturn(null);

		$directChat = new ProjectDirectChat();
		$directChat->setId(5);
		$directChat->setProjectId(42);
		$directChat->setUser1Id('alice');
		$directChat->setUser2Id('bob');
		$directChat->setTalkConversationToken('direct-token');

		$this->directChatMapper->method('findByTalkConversationToken')
			->with('direct-token')
			->willReturn($directChat);

		$this->projectMapper->method('find')
			->with(42)
			->willReturn($project);

		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('direct-token');

		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn('Private message to Bob');
		$comment->method('getActorId')->willReturn('alice');

		$event = new ChatMessageSentEvent($room, $comment);

		$this->activityService->expects($this->once())
			->method('recordWithActorInfo')
			->with(
				$project,
				ProjectActivityService::EVENT_TALK_DIRECT_MESSAGE_SENT,
				ProjectActivityService::SOURCE_TALK,
				'alice',
				'Alice',
				[
					'messagePreview' => 'Private message to Bob',
					'messageLength' => 22,
					'isDirectChat' => true,
					'directChatId' => 5,
					'otherUserId' => 'bob',
				]
			);

		$this->listener->handle($event);
	}

	public function testCallStartedInDirectChatRecordsCallStartedEvent(): void {
		$project = new Project();
		$project->setId(42);

		$this->projectMapper->method('findByTalkConversationToken')
			->with('call-token')
			->willReturn(null);

		$directChat = new ProjectDirectChat();
		$directChat->setId(7);
		$directChat->setProjectId(42);
		$directChat->setUser1Id('alice');
		$directChat->setUser2Id('bob');
		$directChat->setTalkConversationToken('call-token');

		$this->directChatMapper->method('findByTalkConversationToken')
			->with('call-token')
			->willReturn($directChat);

		$this->projectMapper->method('find')
			->with(42)
			->willReturn($project);

		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('call-token');

		$event = new CallStartedEvent($room, new \DateTime(), 1, [], null);

		$this->activityService->expects($this->once())
			->method('recordWithActorInfo')
			->with(
				$project,
				ProjectActivityService::EVENT_TALK_CALL_STARTED,
				ProjectActivityService::SOURCE_TALK,
				null,
				null,
				['callFlag' => 1]
			);

		$this->listener->handle($event);
	}

	public function testUnknownRoomTokenIsIgnored(): void {
		$this->projectMapper->method('findByTalkConversationToken')
			->with('unknown-tok')
			->willReturn(null);
		$this->directChatMapper->method('findByTalkConversationToken')
			->with('unknown-tok')
			->willReturn(null);

		$room = $this->createMock(Room::class);
		$room->method('getToken')->willReturn('unknown-tok');

		$comment = $this->createMock(IComment::class);
		$comment->method('getMessage')->willReturn('Unrelated message');

		$event = new ChatMessageSentEvent($room, $comment);

		$this->activityService->expects($this->never())->method('recordWithActorInfo');

		$this->listener->handle($event);
	}
}
