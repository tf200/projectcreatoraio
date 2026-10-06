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

final class ProjectPortfolioMembershipTest extends TestCase {
	public function testMembershipIncludesOwnerAndProjectGroupButNotOtherUsers(): void {
		$service = new ProjectPortfolioService($this->createMock(ProjectMapper::class), $this->createMock(IDBConnection::class));
		self::assertTrue($service->isMemberProject(['ownerId' => 'member'], 'member', []));
		self::assertTrue($service->isMemberProject(['projectGroupGid' => 'project-one'], 'member', ['project-one' => true]));
		self::assertFalse($service->isMemberProject(['ownerId' => 'other', 'projectGroupGid' => 'project-two'], 'member', ['project-one' => true]));
		self::assertFalse($service->isMemberProject(['ownerId' => '', 'projectGroupGid' => null], '', []));
	}

	public function testMineCapacityAndWarningsExcludeOtherTeamProjects(): void {
		$projects = [
			$this->project(1, 'other', 'mine-group'),
			$this->project(2, 'member', 'owner-group'),
			$this->project(3, 'other', 'other-group'),
		];
		$team = ['id' => 9, 'organization_id' => 42, 'name' => 'Delivery', 'fte' => 2, 'projects_per_fte' => 1];
		$service = $this->serviceWithQueries([
			['custom_projects', $projects],
			['group_user', [['gid' => 'mine-group']]],
			['custom_projects', []],
			['organization_project_teams', [['team_id' => 9]]],
			['organization_teams', [$team]],
			['custom_projects', []],
			['custom_projects', $projects],
			['group_user', [['gid' => 'mine-group']]],
		]);

		$result = $service->getCapacityForAll(42, '2026-10-05', 'member');

		self::assertSame('My teams', $result['team']['name']);
		self::assertSame(2, $result['weeks'][0]['totalActive']);
		self::assertSame(2.0, $result['weeks'][0]['capacity']);
		self::assertFalse($result['weeks'][0]['overCapacity']);
		self::assertSame([], $result['teamWarnings']);
		self::assertStringNotContainsString('Project 3', json_encode($result, JSON_THROW_ON_ERROR));
	}

	public function testAdminCapacityAndWarningsStillIncludeAllTeamProjects(): void {
		$projects = [$this->project(1, 'other', 'mine-group'), $this->project(2, 'member', 'owner-group'), $this->project(3, 'other', 'other-group')];
		$team = ['id' => 9, 'organization_id' => 42, 'name' => 'Delivery', 'fte' => 2, 'projects_per_fte' => 1];
		$service = $this->serviceWithQueries([
			['custom_projects', $projects],
			['custom_projects', []],
			['organization_teams', [$team]],
			['custom_projects', []],
			['custom_projects', $projects],
		]);

		$result = $service->getCapacityForAll(42, '2026-10-05');

		self::assertSame('All teams', $result['team']['name']);
		self::assertSame(3, $result['weeks'][0]['totalActive']);
		self::assertTrue($result['weeks'][0]['overCapacity']);
		self::assertCount(1, $result['teamWarnings']);
		self::assertSame('Delivery', $result['teamWarnings'][0]['name']);
	}

	public function testMineWithoutProjectsReturnsEmptyPortfolioCapacity(): void {
		$service = $this->serviceWithQueries([
			['custom_projects', []],
			['custom_projects', []],
			['custom_projects', []],
		]);
		$result = $service->getCapacityForAll(42, '2026-10-05', 'member');
		self::assertSame([], $result['teams']);
		self::assertSame([], $result['teamWarnings']);
		self::assertSame([], $result['planningGaps']);
		self::assertSame([], $result['scheduleIssues']);
		self::assertSame([], $result['toSchedule']);
		self::assertSame(0, $result['weeks'][0]['totalActive']);
	}

	private function project(int $id, string $owner, string $group): array {
		return [
			'id' => $id, 'name' => 'Project ' . $id, 'status' => ProjectStatus::ACTIVE,
			'board_id' => null, 'created_at' => '2026-09-01', 'desired_start_date' => '2026-10-05',
			'actual_start_date' => '2026-10-05', 'actual_handover_date' => '2026-11-16', 'execution_weeks' => 6,
			'owner_id' => $owner, 'project_group_gid' => $group, 'team_id' => 9,
		];
	}

	/** Query fixtures exercise the real membership and capacity calculation paths. */
	private function serviceWithQueries(array $queries): ProjectPortfolioService {
		$builders = [];
		foreach ($queries as [$table, $rows]) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAllAssociative')->willReturn($rows);
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
