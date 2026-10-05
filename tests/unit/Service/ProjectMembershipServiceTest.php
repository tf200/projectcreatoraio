<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\PrivateFolderLink;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectMembershipService;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCP\AppFramework\OCS\OCSException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectMembershipServiceTest extends TestCase {
	private ProjectMapper&MockObject $projectMapper;
	private ProjectService&MockObject $projectService;
	private ProjectMemberRoleMapper&MockObject $memberRoleMapper;
	private ProjectMemberSourceMapper&MockObject $memberSourceMapper;
	private BoardPolicyRoleMapper&MockObject $policyRoleMapper;
	private BoardPolicyMembershipMapper&MockObject $policyMembershipMapper;
	private ProjectTalkIntegrationService&MockObject $talk;
	private ProjectActivityService&MockObject $activity;
	private IGroupManager&MockObject $groupManager;
	private IUserManager&MockObject $userManager;
	private IRootFolder&MockObject $rootFolder;
	private IShareManager&MockObject $shareManager;
	private IGroup&MockObject $group;
	private IUser&MockObject $bob;
	private ProjectMembershipService $service;

	protected function setUp(): void {
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->projectService = $this->createMock(ProjectService::class);
		$this->memberRoleMapper = $this->createMock(ProjectMemberRoleMapper::class);
		$this->memberSourceMapper = $this->createMock(ProjectMemberSourceMapper::class);
		$this->policyRoleMapper = $this->createMock(BoardPolicyRoleMapper::class);
		$this->policyMembershipMapper = $this->createMock(BoardPolicyMembershipMapper::class);
		$this->talk = $this->createMock(ProjectTalkIntegrationService::class);
		$this->activity = $this->createMock(ProjectActivityService::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->shareManager = $this->createMock(IShareManager::class);

		$this->group = $this->createMock(IGroup::class);
		$this->groupManager->method('get')->with('project-42')->willReturn($this->group);
		$this->bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob']);
		$this->userManager->method('get')->willReturnMap([['bob', $this->bob]]);
		$this->projectMapper->method('find')->with(42)->willReturn($this->project());

		$this->service = new ProjectMembershipService(
			$this->projectMapper,
			$this->projectService,
			$this->memberRoleMapper,
			$this->memberSourceMapper,
			$this->policyRoleMapper,
			$this->policyMembershipMapper,
			$this->talk,
			$this->activity,
			$this->groupManager,
			$this->userManager,
			$this->createMock(IUserSession::class),
			$this->rootFolder,
			$this->shareManager,
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testRemoveMemberRevokesEveryProjectGrant(): void {
		$this->groupManager->method('isInGroup')->with('bob', 'project-42')->willReturn(true);
		$role = new BoardPolicyRole();
		$role->setId(7);
		$this->policyRoleMapper->method('findByBoard')->with(10)->willReturn([$role]);
		$membership = new BoardPolicyMembership();
		$this->policyMembershipMapper->method('findUnique')->with(7, 'user', 'bob')->willReturn($membership);

		$this->group->expects($this->once())->method('removeUser')->with($this->bob);
		$this->memberRoleMapper->expects($this->once())->method('deleteByProjectAndUser')->with(42, 'bob');
		$this->policyMembershipMapper->expects($this->once())->method('delete')->with($membership);
		$this->memberSourceMapper->expects($this->once())->method('deleteByProjectAndUser')->with(42, 'bob');
		$this->talk->expects($this->once())->method('removeUserFromConversation')->with('talk-token', $this->bob);
		$this->activity->expects($this->once())->method('recordMemberRemoved');

		$result = $this->service->removeMember(42, 'bob');

		$this->assertSame(['removed' => true, 'userId' => 'bob', 'privateFolder' => 'none'], $result);
	}

	public function testRemoveMemberRefusesTheOwner(): void {
		$this->group->expects($this->never())->method('removeUser');
		$this->memberRoleMapper->expects($this->never())->method('deleteByProjectAndUser');

		$this->expectException(OCSException::class);
		$this->expectExceptionCode(409);
		$this->service->removeMember(42, 'alice');
	}

	public function testRemoveMemberRejectsNonMembers(): void {
		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->memberSourceMapper->method('hasAnySource')->willReturn(false);

		$this->expectException(OCSException::class);
		$this->expectExceptionCode(404);
		$this->service->removeMember(42, 'bob');
	}

	public function testRemoveMemberMovesPrivateFolderToOwnersFormerMembers(): void {
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->projectMapper->method('findPrivateFolderForUser')->willReturnMap([
			[42, 'bob', $this->link(501)],
			[42, 'alice', $this->link(601)],
		]);

		$bobPrivate = $this->createMock(Folder::class);
		$bobPrivate->method('getDirectoryListing')->willReturn([$this->createMock(Folder::class)]);
		$bobHome = $this->createMock(Folder::class);
		$bobHome->method('getFirstNodeById')->with(501)->willReturn($bobPrivate);

		$formerMembers = $this->createMock(Folder::class);
		$formerMembers->method('getPath')->willReturn('/alice/files/Project - Private Files/Former members');
		$formerMembers->method('nodeExists')->willReturn(false);
		$alicePrivate = $this->createMock(Folder::class);
		$alicePrivate->method('nodeExists')->with('Former members')->willReturn(false);
		$alicePrivate->method('newFolder')->with('Former members')->willReturn($formerMembers);
		$aliceHome = $this->createMock(Folder::class);
		$aliceHome->method('getFirstNodeById')->with(601)->willReturn($alicePrivate);

		$this->rootFolder->method('getUserFolder')->willReturnMap([['bob', $bobHome], ['alice', $aliceHome]]);
		$this->shareManager->method('getSharesBy')->willReturn([]);

		$bobPrivate->expects($this->once())->method('move')
			->with('/alice/files/Project - Private Files/Former members/Bob (bob)');
		$this->projectMapper->expects($this->once())->method('deletePrivateFolderLink')->with(42, 'bob');

		$this->assertSame('moved', $this->service->removeMember(42, 'bob')['privateFolder']);
	}

	public function testReleasingATeamKeepsManuallyAddedMembers(): void {
		$this->memberSourceMapper->expects($this->once())->method('removeSource')->with(42, 'bob', 3);
		$this->memberSourceMapper->method('hasAnySource')->with(42, 'bob')->willReturn(true);
		$this->group->expects($this->never())->method('removeUser');

		$this->assertFalse($this->service->releaseTeamSource(42, 'bob', 3));
	}

	public function testReleasingTheLastTeamRemovesTheMember(): void {
		$this->memberSourceMapper->method('hasAnySource')->willReturn(false);
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->group->expects($this->once())->method('removeUser')->with($this->bob);

		$this->assertTrue($this->service->releaseTeamSource(42, 'bob', 3));
	}

	public function testSwitchingTeamsAddsTheNewTeamAndReleasesTheOldOne(): void {
		$this->projectService->expects($this->once())
			->method('addMembersToProjectBulk')
			->with(42, [], [], null, 4, null)
			->willReturn(['added' => [], 'alreadyMembers' => [], 'rejected' => [], 'teamId' => 4]);
		$this->memberSourceMapper->method('findUserIdsByProjectAndTeam')->with(42, 3)->willReturn(['bob']);
		$this->memberSourceMapper->expects($this->once())->method('removeSource')->with(42, 'bob', 3);
		// Bob is also in the new team, so he keeps a source and stays.
		$this->memberSourceMapper->method('hasAnySource')->willReturn(true);
		$this->group->expects($this->never())->method('removeUser');

		$this->service->syncProjectTeam(42, 3, 4);
	}

	public function testAFailedSwitchKeepsTheOldTeam(): void {
		$this->projectService->method('addMembersToProjectBulk')->willThrowException(new \RuntimeException('boom'));
		$this->memberSourceMapper->expects($this->never())->method('removeSource');
		$this->group->expects($this->never())->method('removeUser');

		$this->service->syncProjectTeam(42, 3, 4);
	}

	public function testLeavingTheOrganizationSkipsOwnedProjects(): void {
		$owned = $this->project();
		$owned->setOwnerId('bob');
		$this->projectMapper->method('findByUserIdAndOrganizationId')->with('bob', 9)->willReturn([$owned]);
		$this->group->expects($this->never())->method('removeUser');

		$this->service->removeFromOrganizationProjects(9, 'bob');
	}

	private function project(): Project {
		$project = new Project();
		$project->setId(42);
		$project->setOwnerId('alice');
		$project->setBoardId('10');
		$project->setProjectGroupGid('project-42');
		$project->setTalkConversationToken('talk-token');
		$project->setName('Project');
		return $project;
	}

	private function link(int $folderId): PrivateFolderLink {
		$link = new PrivateFolderLink();
		$link->setFolderId($folderId);
		return $link;
	}
}
