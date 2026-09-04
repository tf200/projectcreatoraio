<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Controller;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\Projectcreatoraio\Controller\ProjectApiController;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Service\ProjectActivityAggregationService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectDownloadService;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCA\ProjectCreatorAIO\Service\ProjectRetentionService;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\BackgroundJob\IJobList;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ProjectApiControllerDirectChatTest extends TestCase {
	private IRequest $request;
	private IUserSession $userSession;
	private ProjectMapper $projectMapper;
	private ProjectService $projectService;
	private IGroupManager $groupManager;
	private OrganizationUserMapper $orgUserMapper;
	private ProjectApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->projectService = $this->createMock(ProjectService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->orgUserMapper = $this->createMock(OrganizationUserMapper::class);

		$this->controller = new ProjectApiController(
			'projectcreatoraio',
			$this->request,
			$this->userSession,
			$this->projectMapper,
			$this->createMock(ProjectNoteMapper::class),
			$this->projectService,
			$this->createMock(ProjectActivityService::class),
			$this->createMock(ProjectActivityAggregationService::class),
			$this->createMock(ProjectNotificationService::class),
			$this->createMock(ProjectRetentionService::class),
			$this->createMock(ProjectDownloadService::class),
			$this->createMock(ProjectTalkIntegrationService::class),
			$this->groupManager,
			$this->createMock(IRootFolder::class),
			$this->createMock(IJobList::class),
			$this->createMock(IAppManager::class),
			$this->orgUserMapper,
		);
	}

	private function mockAuthenticatedUser(string $uid = 'alice', bool $isAdmin = true): IUser {
		$user = $this->createConfiguredMock(IUser::class, [
			'getUID' => $uid,
			'getDisplayName' => ucfirst($uid),
		]);
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with($uid)->willReturn($isAdmin);
		return $user;
	}

	public function testListDirectChatsThrowsWhenProjectNotFound(): void {
		$this->projectMapper->method('find')->with(42)->willReturn(null);

		$this->expectException(OCSNotFoundException::class);
		$this->controller->listDirectChats(42);
	}

	public function testListDirectChatsThrowsWhenAuthenticationRequired(): void {
		$project = new Project();
		$project->setId(42);
		$this->projectMapper->method('find')->with(42)->willReturn($project);
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(OCSForbiddenException::class);
		$this->controller->listDirectChats(42);
	}

	public function testListDirectChatsReturnsChats(): void {
		$this->mockAuthenticatedUser('alice', true);

		$project = new Project();
		$project->setId(42);
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$expectedChats = [
			[
				'id' => 1,
				'projectId' => 42,
				'user1Id' => 'alice',
				'user2Id' => 'bob',
				'otherUser' => ['id' => 'bob', 'displayName' => 'Bob'],
				'talkConversationToken' => 'tok123',
				'talkUrl' => 'https://example.test/call/tok123',
			],
		];

		$this->projectService->expects($this->once())
			->method('listUserDirectChats')
			->with(42, 'alice')
			->willReturn($expectedChats);

		$response = $this->controller->listDirectChats(42);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame($expectedChats, $response->getData());
	}

	public function testGetOrCreateDirectChatThrowsWhenTargetEmpty(): void {
		$this->mockAuthenticatedUser('alice', true);

		$project = new Project();
		$project->setId(42);
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$this->expectException(OCSBadRequestException::class);
		$this->controller->getOrCreateDirectChat(42, '');
	}

	public function testGetOrCreateDirectChatThrowsWhenSelfChat(): void {
		$this->mockAuthenticatedUser('alice', true);

		$project = new Project();
		$project->setId(42);
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$this->expectException(OCSBadRequestException::class);
		$this->controller->getOrCreateDirectChat(42, 'alice');
	}

	public function testGetOrCreateDirectChatSuccess(): void {
		$this->mockAuthenticatedUser('alice', true);

		$project = new Project();
		$project->setId(42);
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$expectedChat = [
			'id' => 10,
			'projectId' => 42,
			'user1Id' => 'alice',
			'user2Id' => 'bob',
			'otherUser' => ['id' => 'bob', 'displayName' => 'Bob'],
			'talkConversationToken' => 'token-abc',
			'talkUrl' => 'https://example.test/call/token-abc',
		];

		$this->projectService->expects($this->once())
			->method('getOrCreateDirectChat')
			->with(42, 'alice', 'bob')
			->willReturn($expectedChat);

		$response = $this->controller->getOrCreateDirectChat(42, 'bob');

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame($expectedChat, $response->getData());
	}

	public function testGetDirectChatMessagesReturnsMessages(): void {
		$this->mockAuthenticatedUser('alice', true);

		$project = new Project();
		$project->setId(42);
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$expectedResult = [
			'messages' => [
				[
					'id' => 1,
					'actorDisplayName' => 'Bob',
					'message' => 'Hey Alice',
					'timestamp' => 1700000000,
					'messageType' => 'comment',
				],
			],
			'hasMore' => false,
			'nextOffset' => 1,
		];

		$this->projectService->expects($this->once())
			->method('getDirectChatMessages')
			->with(42, 'alice', 'bob', 50, 0)
			->willReturn($expectedResult);

		$response = $this->controller->getDirectChatMessages(42, 'bob', 50, 0);

		$this->assertInstanceOf(DataResponse::class, $response);
		$this->assertSame($expectedResult, $response->getData());
	}

	public function testNonMemberCannotAccessProjectDirectChats(): void {
		// Non-admin user
		$this->mockAuthenticatedUser('eve', false);

		$project = new Project();
		$project->setId(42);
		$project->setOrganizationId(1);
		$project->setProjectGroupGid('proj-42-group');
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$this->orgUserMapper->method('getOrganizationMembership')
			->with('eve')
			->willReturn(['organization_id' => 1, 'role' => 'member']);

		// Not in project group
		$this->groupManager->method('isInGroup')
			->with('eve', 'proj-42-group')
			->willReturn(false);

		$this->expectException(OCSNotFoundException::class);
		$this->controller->listDirectChats(42);
	}

	public function testDifferentOrganizationCannotAccessProjectDirectChats(): void {
		// Non-admin user from org 2
		$this->mockAuthenticatedUser('eve', false);

		$project = new Project();
		$project->setId(42);
		$project->setOrganizationId(1);
		$this->projectMapper->method('find')->with(42)->willReturn($project);

		$this->orgUserMapper->method('getOrganizationMembership')
			->with('eve')
			->willReturn(['organization_id' => 2, 'role' => 'admin']);

		$this->expectException(OCSNotFoundException::class);
		$this->controller->listDirectChats(42);
	}
}
