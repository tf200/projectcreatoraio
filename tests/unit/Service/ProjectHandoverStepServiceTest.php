<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\Organization\Service\TeamService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectHandoverStepService;
use OCA\ProjectCreatorAIO\Service\ProjectOwnershipService;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCP\AppFramework\OCS\OCSException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectHandoverStepServiceTest extends TestCase {
	private ProjectMapper&MockObject $projectMapper;
	private ProjectService&MockObject $projectService;
	private ProjectAccessService&MockObject $access;
	private ProjectOwnershipService&MockObject $ownership;
	private TimelinePlanningService&MockObject $planning;
	private ProjectActivityService&MockObject $activity;
	private TeamService&MockObject $teams;
	private ExternalCollaboratorService&MockObject $externals;
	private IUser $actor;
	private Project $project;
	/** @var list<string> */
	private array $order = [];
	private ProjectHandoverStepService $service;

	protected function setUp(): void {
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->projectService = $this->createMock(ProjectService::class);
		$this->access = $this->createMock(ProjectAccessService::class);
		$this->ownership = $this->createMock(ProjectOwnershipService::class);
		$this->planning = $this->createMock(TimelinePlanningService::class);
		$this->activity = $this->createMock(ProjectActivityService::class);
		$this->teams = $this->createMock(TeamService::class);
		$this->externals = $this->createMock(ExternalCollaboratorService::class);
		$this->actor = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);

		$this->project = new Project();
		$this->project->setId(42);
		$this->project->setOrganizationId(9);
		$this->project->setOwnerId('alice');
		$this->projectMapper->method('find')->with(42)->willReturn($this->project);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => in_array($uid, ['alice', 'bob', 'carol', 'dave'], true)
			? $this->createConfiguredMock(IUser::class, ['getUID' => $uid])
			: null);
		// Alice and Bob work on the project; Carol and Dave are org members who do not yet.
		$this->projectService->method('isProjectMember')->willReturnCallback(static fn (Project $p, string $uid): bool => in_array($uid, ['alice', 'bob'], true));
		$this->projectService->method('validateMemberRoles')->willReturn(['drasciRoles' => ['responsible'], 'functionalRoleKeys' => []]);
		$this->access->method('getOrganizationMembership')->willReturnCallback(static fn (string $uid): ?array => in_array($uid, ['alice', 'bob', 'carol', 'dave'], true)
			? ['organization_id' => 9, 'role' => 'member']
			: null);
		$this->teams->method('list')->with(9)->willReturn([
			['id' => 3, 'name' => 'Team A', 'members' => [['uid' => 'bob', 'displayName' => 'Bob']]],
			['id' => 4, 'name' => 'Team B', 'members' => [['uid' => 'dave', 'displayName' => 'Dave']]],
		]);
		$this->teams->method('listProjectTeams')->with(9)->willReturn([['projectId' => 42, 'team' => ['id' => 3, 'name' => 'Team A']]]);
		$this->externals->method('listForProject')->with(42)->willReturn([
			['userId' => 'ext_klaas', 'status' => 'active', 'displayName' => 'Klaas', 'expiresAt' => '2027-02-15T23:59:59+00:00'],
			['userId' => 'ext_gone', 'status' => 'revoked'],
		]);
		$this->planning->method('buildSummary')->willReturn(['actualHandoverDate' => null, 'plannedEndDate' => '2099-11-23']);

		$this->service = new ProjectHandoverStepService(
			$this->projectMapper,
			$this->projectService,
			$this->access,
			$this->ownership,
			$this->createMock(ProjectMemberSourceMapper::class),
			$this->planning,
			$this->activity,
			$users,
			$this->createMock(LoggerInterface::class),
			$this->teams,
			$this->externals,
		);
	}

	public function testTheHandoverAddsPeopleThenSetsTheTeamOwnerExternalsAndDateInThatOrder(): void {
		$this->projectService->expects($this->once())->method('addMemberToProject')->with(42, 'carol', ['responsible'], null)
			->willReturnCallback(function () { $this->order[] = 'member'; return []; });
		$this->teams->expects($this->once())->method('assignProjectTeam')->with(9, 42, 4, 'alice')
			->willReturnCallback(function () { $this->order[] = 'team'; return []; });
		$this->ownership->expects($this->once())->method('transfer')->with($this->project, 'dave')
			->willReturnCallback(function () { $this->order[] = 'owner'; return ['ownerId' => 'dave', 'previousOwnerId' => 'alice', 'board' => 'done', 'talk' => 'done']; });
		$this->externals->expects($this->once())->method('changeEndDate')
			->with(9, 42, 'ext_klaas', $this->callback(static fn (DateTime $d): bool => $d->format('Y-m-d H:i:s') === '2099-11-23 23:59:59'), 'alice')
			->willReturnCallback(function () { $this->order[] = 'external'; return new \OCA\Organization\Db\ExternalGrant(); });
		$this->projectMapper->expects($this->once())->method('updateProjectDetails')
			->with($this->callback(static fn (Project $p): bool => $p->getActualHandoverDate()?->format('Y-m-d') === '2026-10-28'))
			->willReturnCallback(function (Project $p) { $this->order[] = 'date'; return $p; });
		$this->activity->expects($this->once())->method('recordHandedOver');

		$result = $this->service->apply($this->project, [
			'handoverDate' => '2026-10-28',
			// Dave joins with Team B, so he may become the owner in the same handover.
			'ownerUid' => 'dave',
			'team' => ['mode' => 'set', 'teamId' => 4],
			'addMembers' => [['uid' => 'carol'], ['uid' => 'bob']],
			'externals' => [['uid' => 'ext_klaas', 'mode' => 'untilEnd']],
		], $this->actor);

		$this->assertSame(['member', 'team', 'owner', 'external', 'date'], $this->order);
		$this->assertSame(['member:carol', 'team', 'owner', 'external:ext_klaas', 'handoverDate'], array_column($result['steps'], 'step'));
		$this->assertSame(['done'], array_values(array_unique(array_column($result['steps'], 'status'))));
	}

	public function testThePreviewListsMembersWithTheirOriginTheTeamsAndTheOpenExternals(): void {
		$this->projectService->method('getProjectMembers')->with(42)->willReturn([
			['id' => 'alice', 'displayName' => 'Alice', 'isOwner' => true, 'drascivsRoles' => ['accountable']],
			['id' => 'bob', 'displayName' => 'Bob', 'isOwner' => false, 'drascivsRoles' => []],
		]);
		$sources = $this->createMock(ProjectMemberSourceMapper::class);
		$sources->method('findSources')->willReturnMap([[42, 'alice', []], [42, 'bob', [3, 0]]]);
		$service = new ProjectHandoverStepService($this->projectMapper, $this->projectService, $this->access, $this->ownership, $sources, $this->planning, $this->activity, $this->createMock(IUserManager::class), $this->createMock(LoggerInterface::class), $this->teams, $this->externals);

		$preview = $service->preview($this->project);

		$this->assertSame(['id' => 3, 'name' => 'Team A'], $preview['team']);
		$this->assertSame([[], [3]], array_column($preview['members'], 'teamIds'));
		$this->assertSame([false, true], array_column($preview['members'], 'addedByHand'));
		$this->assertSame(['Team A', 'Team B'], array_column($preview['teams'], 'name'));
		$this->assertSame(['ext_klaas'], array_column($preview['externals'], 'id'));
		$this->assertSame('2099-11-23', $preview['plannedEndDate']);
	}

	public function testNothingChangesWhenTheRequestIsInvalid(): void {
		$this->projectService->expects($this->never())->method('addMemberToProject');
		$this->teams->expects($this->never())->method('assignProjectTeam');
		$this->projectMapper->expects($this->never())->method('updateProjectDetails');

		$cases = [
			'no date' => ['handoverDate' => ''],
			'not a date' => ['handoverDate' => '2026-02-30'],
			'unknown team' => ['handoverDate' => '2026-10-28', 'team' => ['mode' => 'set', 'teamId' => 99]],
			'owner not on the project' => ['handoverDate' => '2026-10-28', 'ownerUid' => 'carol'],
			'external owner' => ['handoverDate' => '2026-10-28', 'ownerUid' => 'ext_klaas'],
			'external not invited' => ['handoverDate' => '2026-10-28', 'externals' => [['uid' => 'ext_gone', 'mode' => 'untilEnd']]],
			'unknown person' => ['handoverDate' => '2026-10-28', 'addMembers' => [['uid' => 'nobody']]],
			'unknown external choice' => ['handoverDate' => '2026-10-28', 'externals' => [['uid' => 'ext_klaas', 'mode' => 'forever']]],
		];
		foreach ($cases as $case => $request) {
			try {
				$this->service->apply($this->project, $request, $this->actor);
				$this->fail('Accepted: ' . $case);
			} catch (OCSException $e) {
				$this->assertSame(400, $e->getCode(), $case);
			}
		}
	}

	public function testKeepingTheTeamOrSettingTheSameTeamLeavesItAlone(): void {
		$this->teams->expects($this->never())->method('assignProjectTeam');

		$this->service->apply($this->project, ['handoverDate' => '2026-10-28'], $this->actor);
		$this->service->apply($this->project, ['handoverDate' => '2026-10-28', 'team' => ['mode' => 'set', 'teamId' => 3]], $this->actor);
	}

	public function testAnExternalEndingAtAPastHandoverLosesAccessNowAndOneWithoutAPlannedEndIsLeftAlone(): void {
		$this->externals->expects($this->once())->method('revoke')->with(9, 42, 'ext_klaas', 'alice');
		$this->externals->expects($this->never())->method('changeEndDate');

		$result = $this->service->apply($this->project, [
			'handoverDate' => '2026-01-05',
			'externals' => [['uid' => 'ext_klaas', 'mode' => 'atHandover']],
		], $this->actor);
		$this->assertSame('Access ended now: the handover is not in the future.', $result['steps'][0]['message']);
	}

	public function testAFailedStepIsReportedAndTheRestStillRuns(): void {
		$this->teams->method('assignProjectTeam')->willThrowException(new \RuntimeException('db down'));
		$this->projectMapper->expects($this->once())->method('updateProjectDetails');

		$result = $this->service->apply($this->project, ['handoverDate' => '2026-10-28', 'team' => ['mode' => 'none']], $this->actor);

		$this->assertSame(['team' => 'failed', 'handoverDate' => 'done'], array_column($result['steps'], 'status', 'step'));
		$this->assertSame('Something went wrong.', $result['steps'][0]['message']);
	}
}
