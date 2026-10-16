<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\Deck\Service\BoardService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectOwnershipService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCP\AppFramework\OCS\OCSException;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectOwnershipServiceTest extends TestCase {
	private ProjectMapper&MockObject $projectMapper;
	private ProjectAccessService&MockObject $access;
	private ProjectMemberSourceMapper&MockObject $sources;
	private ProjectTalkIntegrationService&MockObject $talk;
	private ProjectActivityService&MockObject $activity;
	private IGroupManager&MockObject $groupManager;
	private IGroup&MockObject $group;
	private BoardService&MockObject $boards;
	private IUser $alice;
	private IUser $bob;
	private ProjectOwnershipService $service;

	protected function setUp(): void {
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->access = $this->createMock(ProjectAccessService::class);
		$this->sources = $this->createMock(ProjectMemberSourceMapper::class);
		$this->talk = $this->createMock(ProjectTalkIntegrationService::class);
		$this->activity = $this->createMock(ProjectActivityService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->group = $this->createMock(IGroup::class);
		$this->groupManager->method('get')->with('project-42')->willReturn($this->group);
		$this->boards = $this->createMock(BoardService::class);
		$this->alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice', 'getDisplayName' => 'Alice']);
		$this->bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob']);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([['alice', $this->alice], ['bob', $this->bob]]);
		$this->talk->method('isAvailable')->willReturn(true);

		$this->service = new ProjectOwnershipService(
			$this->projectMapper,
			$this->access,
			$this->sources,
			$this->talk,
			$this->activity,
			$this->groupManager,
			$users,
			$this->createMock(IUserSession::class),
			$this->createMock(LoggerInterface::class),
			$this->boards,
		);
	}

	public function testTheNewOwnerTakesTheProjectBoardAndConversationAndThePreviousOwnerStays(): void {
		// Alice owns the project without being in its group; Bob is a member.
		$this->groupManager->method('isInGroup')->willReturnMap([['bob', 'project-42', true], ['alice', 'project-42', false]]);
		$this->access->method('getOrganizationMembership')->with('bob')->willReturn(['organization_id' => 9, 'role' => 'member']);
		$project = $this->project();

		$this->group->expects($this->once())->method('addUser')->with($this->alice);
		$this->sources->expects($this->once())->method('addSource')->with(42, 'alice', ProjectMemberSourceMapper::MANUAL);
		$this->projectMapper->expects($this->once())->method('updateProjectDetails')
			->with($this->callback(static fn (Project $p): bool => $p->getOwnerId() === 'bob'));
		$this->boards->expects($this->once())->method('transferBoardOwnership')->with(10, 'bob', false);
		$this->talk->expects($this->once())->method('promoteToModerator')->with('talk-token', $this->bob)->willReturn(true);
		$this->activity->expects($this->once())->method('recordOwnerChanged')->with($project, $this->alice, $this->bob);

		$this->assertSame(
			['ownerId' => 'bob', 'previousOwnerId' => 'alice', 'board' => 'done', 'talk' => 'done'],
			$this->service->transfer($project, 'bob'),
		);
	}

	public function testTheNewOwnerMustAlreadyWorkOnTheProject(): void {
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->projectMapper->expects($this->never())->method('updateProjectDetails');

		$this->expectException(OCSException::class);
		$this->expectExceptionCode(409);
		$this->service->transfer($this->project(), 'bob');
	}

	public function testExternalsAndOtherOrganizationsCannotOwnTheProject(): void {
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->access->method('getOrganizationMembership')->willReturnOnConsecutiveCalls(null, ['organization_id' => 8, 'role' => 'member']);
		$this->projectMapper->expects($this->never())->method('updateProjectDetails');

		foreach (['external', 'other organization'] as $case) {
			try {
				$this->service->transfer($this->project(), 'bob');
				$this->fail('Accepted an owner from ' . $case);
			} catch (OCSException $e) {
				$this->assertSame(409, $e->getCode());
			}
		}
	}

	public function testTheSameOwnerChangesNothing(): void {
		$this->projectMapper->expects($this->never())->method('updateProjectDetails');
		$this->boards->expects($this->never())->method('transferBoardOwnership');

		$this->assertSame('skipped', $this->service->transfer($this->project(), 'alice')['board']);
	}

	public function testABoardThatCannotFollowIsReportedWhileTheOwnerStillChanges(): void {
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->access->method('getOrganizationMembership')->willReturn(['organization_id' => 9, 'role' => 'member']);
		$this->boards->method('transferBoardOwnership')->willThrowException(new \RuntimeException('deck down'));
		$this->talk->method('promoteToModerator')->willReturn(false);
		$this->group->expects($this->never())->method('addUser');
		$this->projectMapper->expects($this->once())->method('updateProjectDetails');

		$result = $this->service->transfer($this->project(), 'bob');
		$this->assertSame(['bob', 'failed', 'failed'], [$result['ownerId'], $result['board'], $result['talk']]);
	}

	private function project(): Project {
		$project = new Project();
		$project->setId(42);
		$project->setOwnerId('alice');
		$project->setOrganizationId(9);
		$project->setProjectGroupGid('project-42');
		$project->setBoardId(10);
		$project->setTalkConversationToken('talk-token');
		return $project;
	}
}
