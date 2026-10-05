<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\PrivateFolderLink;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRole;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCA\ProjectCreatorAIO\Service\ProjectHandoverService;
use OCA\ProjectCreatorAIO\Service\ProjectMembershipService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectHandoverServiceTest extends TestCase {
	public function testHandoverCopiesRolesAndRevokesTheSourceUser(): void {
		$project = new Project();
		$project->setId(42);
		$project->setOwnerId('alice');
		$project->setBoardId('10');
		$project->setProjectGroupGid('project-42');

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('findOwnedByUserAndOrganization')->willReturn([$project]);
		$projectMapper->method('findByUserIdAndOrganizationId')->willReturn([$project]);
		$projectMapper->method('findPrivateFolderForUser')->willReturn(new PrivateFolderLink());
		$projectMapper->expects($this->once())->method('transferOwnershipByOrg')->with('alice', 'carol', 9)->willReturn(1);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $this->createConfiguredMock(IUser::class, ['getUID' => 'alice'])],
			['carol', $this->createConfiguredMock(IUser::class, ['getUID' => 'carol'])],
		]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isInGroup')->willReturn(true);

		$memberRoleMapper = $this->createMock(ProjectMemberRoleMapper::class);
		$memberRoleMapper->method('findByProjectAndUser')->willReturnMap([
			[42, 'carol', [$this->drasci('informed')]],
			[42, 'alice', [$this->drasci('accountable')]],
		]);
		$memberRoleMapper->expects($this->once())->method('replaceRoles')->with(42, 'carol', ['informed', 'accountable']);

		$cpl = new BoardPolicyRole();
		$cpl->setId(7);
		$policyRoleMapper = $this->createMock(BoardPolicyRoleMapper::class);
		$policyRoleMapper->method('findByBoard')->with(10)->willReturn([$cpl]);
		$policyMembershipMapper = $this->createMock(BoardPolicyMembershipMapper::class);
		$policyMembershipMapper->method('findUnique')->willReturnMap([
			[7, 'user', 'alice', new BoardPolicyMembership()],
			[7, 'user', 'carol', null],
		]);
		$policyMembershipMapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (BoardPolicyMembership $m): bool => $m->getRoleId() === 7 && $m->getParticipantId() === 'carol'));

		$sourceMapper = $this->createMock(ProjectMemberSourceMapper::class);
		$sourceMapper->method('findSources')->with(42, 'alice')->willReturn([]);
		$sourceMapper->expects($this->once())->method('addSource')->with(42, 'carol', ProjectMemberSourceMapper::MANUAL);

		$membershipService = $this->createMock(ProjectMembershipService::class);
		$membershipService->expects($this->once())->method('removeMember')->with(42, 'alice');

		$organizationUserMapper = $this->createMock(OrganizationUserMapper::class);
		$organizationUserMapper->method('getOrganizationMembership')->willReturn(['organization_id' => 9, 'role' => 'member']);

		$service = new ProjectHandoverService(
			$projectMapper,
			$userManager,
			$groupManager,
			$this->createMock(IRootFolder::class),
			$memberRoleMapper,
			$sourceMapper,
			$policyRoleMapper,
			$policyMembershipMapper,
			$membershipService,
			$this->createMock(ProjectTalkIntegrationService::class),
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class),
			null,
			$organizationUserMapper,
		);

		$result = $service->handoverUserInOrganization('alice', 'carol', 9, true);

		$this->assertSame(1, $result['projectsOwnedTransferred']);
		$this->assertSame(1, $result['projectMembershipsRemoved']);
	}

	private function drasci(string $role): ProjectMemberRole {
		$row = new ProjectMemberRole();
		$row->setDrasciRole($role);
		return $row;
	}
}
