<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCA\ProjectCreatorAIO\Service\ProjectPortfolioService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Ada is in Delivery (projects 1 and 2) and Build (project 3), Bob only in
 * Delivery. All three projects run in the week of 2026-10-05, so Ada
 * carries three projects and Bob two.
 */
final class ProjectPortfolioMembershipTest extends TestCase {
	private const MEMBERSHIPS = [
		['team_id' => 9, 'user_uid' => 'ada'],
		['team_id' => 9, 'user_uid' => 'bob'],
		['team_id' => 10, 'user_uid' => 'ada'],
	];
	private const TEAMS = [
		['id' => 9, 'organization_id' => 42, 'name' => 'Delivery'],
		['id' => 10, 'organization_id' => 42, 'name' => 'Build'],
	];

	public function testMembershipIncludesOwnerAndProjectGroupButNotOtherUsers(): void {
		$service = new ProjectPortfolioService($this->createMock(ProjectMapper::class), $this->createMock(IDBConnection::class));
		self::assertTrue($service->isMemberProject(['ownerId' => 'member'], 'member', []));
		self::assertTrue($service->isMemberProject(['projectGroupGid' => 'project-one'], 'member', ['project-one' => true]));
		self::assertFalse($service->isMemberProject(['ownerId' => 'other', 'projectGroupGid' => 'project-two'], 'member', ['project-one' => true]));
		self::assertFalse($service->isMemberProject(['ownerId' => '', 'projectGroupGid' => null], '', []));
	}

	public function testAllTeamsCountsEachPersonOnceAndFlagsTheOverloadedOne(): void {
		$projects = $this->projects();
		$service = $this->serviceWithQueries([
			['organization_team_members', self::MEMBERSHIPS],
			['custom_projects', $projects],
			['custom_projects', $projects],
			['organization_teams', self::TEAMS],
			['custom_projects', []],
			['custom_projects', []],
		]);

		$result = $service->getCapacityForAll(42, '2026-10-05');

		self::assertSame(['type' => 'all', 'team' => null], $result['scope']);
		self::assertSame(3, $result['weeks'][0]['totalActive']);
		self::assertTrue($result['weeks'][0]['overCapacity']);
		self::assertSame(1, $result['weeks'][0]['overloadedPeople']);
		self::assertSame(1, $result['weeks'][0]['fullPeople']);
		self::assertNull($result['weeks'][0]['freeSlots']);
		self::assertSame(['ada', 'bob'], array_column($result['people'], 'uid'));
		self::assertSame([['id' => 9, 'name' => 'Delivery'], ['id' => 10, 'name' => 'Build']], $result['people'][0]['teams']);
		self::assertSame([1, 2, 3], $result['people'][0]['weeks'][0]['projectIds']);
		self::assertSame(['Delivery', 'Delivery', 'Build'], array_column($result['projects'], 'teamName'));
		self::assertSame([['uid' => 'ada', 'displayName' => 'ada', 'peakLoad' => 3, 'overWeeks' => ['2026-W41', '2026-W42', '2026-W43', '2026-W44', '2026-W45', '2026-W46'], 'projectIds' => [1, 2, 3]]], $result['overloadWarnings']);
		self::assertSame([
			['id' => 9, 'name' => 'Delivery', 'memberCount' => 2],
			['id' => 10, 'name' => 'Build', 'memberCount' => 1],
		], $result['teams']);
	}

	public function testTeamIsOverloadedThroughAMembersOtherTeam(): void {
		$service = $this->serviceWithQueries([
			['organization_teams', self::TEAMS],
			['custom_projects', [$this->project(3, 10)]],
			['organization_team_members', self::MEMBERSHIPS],
			['custom_projects', $this->projects()],
			['custom_projects', []],
		]);

		$result = $service->getCapacity(42, 10, '2026-10-05');

		self::assertSame(1, $result['weeks'][0]['totalActive']);
		self::assertTrue($result['weeks'][0]['overCapacity']);
		self::assertSame(0, $result['weeks'][0]['freeSlots']);
		self::assertSame(['type' => 'team', 'team' => ['id' => 10, 'name' => 'Build', 'memberCount' => 1]], $result['scope']);
		self::assertSame(['ada'], array_column($result['people'], 'uid'));
		self::assertSame([3, 3, 3, 3, 3, 3], array_map(static fn (array $week): int => $week['load'], $result['people'][0]['weeks']));
	}

	public function testMineShowsTheMembersOwnLoadAndTeams(): void {
		$projects = $this->projects();
		$service = $this->serviceWithQueries([
			['organization_team_members', self::MEMBERSHIPS],
			['custom_projects', $projects],
			['custom_projects', $projects],
			['organization_teams', self::TEAMS],
			['group_user', [['gid' => 'group-1'], ['gid' => 'group-2']]],
			['custom_projects', []],
			['custom_projects', []],
		]);

		$result = $service->getCapacityForAll(42, '2026-10-05', 'bob');

		self::assertSame(['type' => 'mine', 'team' => null], $result['scope']);
		self::assertSame(2, $result['weeks'][0]['totalActive']);
		self::assertFalse($result['weeks'][0]['overCapacity']);
		self::assertSame(0, $result['weeks'][0]['freeSlots']);
		self::assertSame(['bob'], array_column($result['people'], 'uid'));
		self::assertSame(['full', 'full', 'full', 'full', 'full', 'full'], array_column($result['people'][0]['weeks'], 'state'));
		self::assertSame(1, $result['weeks'][0]['fullPeople']);
		self::assertSame([['id' => 9, 'name' => 'Delivery', 'memberCount' => 2]], $result['teams']);
		self::assertSame([], $result['overloadWarnings']);
	}

	public function testMineWithoutTeamsReturnsEmptyPortfolioCapacity(): void {
		$service = $this->serviceWithQueries([
			['organization_team_members', []],
			['custom_projects', []],
			['custom_projects', []],
			['organization_teams', []],
			['custom_projects', []],
			['custom_projects', []],
		]);
		$result = $service->getCapacityForAll(42, '2026-10-05', 'member');
		self::assertSame([], $result['teams']);
		self::assertSame([], $result['people']);
		self::assertSame([], $result['overloadWarnings']);
		self::assertSame([], $result['planningGaps']);
		self::assertSame([], $result['scheduleIssues']);
		self::assertSame([], $result['toSchedule']);
		self::assertSame(0, $result['weeks'][0]['totalActive']);
		self::assertNull($result['weeks'][0]['freeSlots']);
	}

	/** @return array<int,array<string,mixed>> */
	private function projects(): array {
		return [$this->project(1, 9), $this->project(2, 9), $this->project(3, 10)];
	}

	private function project(int $id, int $teamId): array {
		return [
			'id' => $id, 'name' => 'Project ' . $id, 'status' => ProjectStatus::ACTIVE,
			'board_id' => null, 'created_at' => '2026-09-01', 'desired_start_date' => '2026-10-05',
			'actual_start_date' => '2026-10-05', 'actual_handover_date' => '2026-11-16', 'execution_weeks' => 6,
			'owner_id' => 'owner', 'project_group_gid' => 'group-' . $id, 'team_id' => $teamId,
		];
	}

	/** Query fixtures exercise the real membership and capacity calculation paths. */
	private function serviceWithQueries(array $queries): ProjectPortfolioService {
		$builders = [];
		foreach ($queries as [$table, $rows]) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAllAssociative')->willReturn($rows);
			$result->method('fetch')->willReturn($rows[0] ?? false);
			$expression = $this->createMock(IExpressionBuilder::class);
			foreach (['eq', 'in', 'isNull'] as $method) {
				$expression->method($method)->willReturn('condition');
			}
			$builder = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'selectDistinct', 'innerJoin', 'leftJoin', 'where', 'andWhere'] as $method) {
				$builder->method($method)->willReturnSelf();
			}
			$builder->expects($this->once())->method('from')->with($table)->willReturnSelf();
			$builder->method('expr')->willReturn($expression);
			$builder->method('createNamedParameter')->willReturn(':parameter');
			$builder->method('executeQuery')->willReturn($result);
			$builders[] = $builder;
		}
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->exactly(count($builders)))->method('getQueryBuilder')->willReturnOnConsecutiveCalls(...$builders);
		return new ProjectPortfolioService($this->createMock(ProjectMapper::class), $db);
	}
}
