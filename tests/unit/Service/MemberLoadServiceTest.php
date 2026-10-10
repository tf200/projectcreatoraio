<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCA\ProjectCreatorAIO\Service\MemberLoadService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class MemberLoadServiceTest extends TestCase {
	public function testBusyWindowRunsFromActualStartUntilRecordedHandover(): void {
		self::assertSame(
			['start' => '2026-09-14', 'end' => '2026-09-30'],
			MemberLoadService::busyWindow($this->window(ProjectStatus::ACTIVE, '2026-09-14 00:00:00', 1, '2026-09-30')),
		);
	}

	public function testBusyWindowOfPlannedWeeksEndsOnTheLastDayOfTheLastWeek(): void {
		self::assertSame(['start' => '2026-09-14', 'end' => '2026-09-27'], MemberLoadService::busyWindow($this->window(ProjectStatus::ACTIVE, '2026-09-14', 2, null)));
		self::assertSame(['start' => '2026-09-14', 'end' => '2026-09-14'], MemberLoadService::busyWindow($this->window(ProjectStatus::ACTIVE, '2026-09-14', 0, null)));
	}

	public function testBusyWindowStaysOpenWithoutHandoverOrWeeks(): void {
		self::assertSame(['start' => '2026-09-14', 'end' => null], MemberLoadService::busyWindow($this->window(ProjectStatus::ON_HOLD, '2026-09-14', null, null)));
	}

	public function testStateIsFreeBelowFullAtAndOverloadedAboveTheMaximum(): void {
		self::assertSame(['free', 'free', 'full', 'overloaded'], array_map([MemberLoadService::class, 'state'], [0, 1, 2, 3]));
	}

	public function testBusyWindowSkipsUnstartedClosedAndInvalidProjects(): void {
		self::assertNull(MemberLoadService::busyWindow($this->window(ProjectStatus::ACTIVE, null, 4, null)));
		self::assertNull(MemberLoadService::busyWindow($this->window(ProjectStatus::DONE, '2026-09-14', 4, null)));
		self::assertNull(MemberLoadService::busyWindow($this->window(ProjectStatus::ARCHIVED, '2026-09-14', null, null)));
		self::assertNull(MemberLoadService::busyWindow($this->window(ProjectStatus::ACTIVE, '2026-09-14', null, '2026-09-01')));
		self::assertSame(
			['start' => '2026-09-14', 'end' => '2026-09-20'],
			MemberLoadService::busyWindow($this->window(ProjectStatus::DONE, '2026-09-14', null, '2026-09-20')),
		);
	}

	public function testProjectsPerWeekCombinesAllTeamsOfAMember(): void {
		$weeks = MemberLoadService::weeks(new DateTimeImmutable('2026-09-14'), 3);
		$projects = [
			1 => ['teamId' => 7, 'start' => '2026-09-14', 'end' => '2026-09-27'],
			2 => ['teamId' => 7, 'start' => '2026-09-20', 'end' => null],
			3 => ['teamId' => 8, 'start' => '2026-09-28', 'end' => '2026-09-28'],
		];

		$perWeek = MemberLoadService::projectsPerWeek(['ada' => [7, 8], 'bob' => [8], 'cy' => []], $projects, $weeks);

		self::assertSame([[1, 2], [1, 2], [2, 3]], $perWeek['ada']);
		self::assertSame([[], [], [3]], $perWeek['bob']);
		self::assertSame([[], [], []], $perWeek['cy']);
	}

	public function testLoadsReportEveryMemberAndOverloadAboveTwo(): void {
		$service = $this->serviceWithQueries([
			['organization_team_members', [
				['team_id' => 7, 'user_uid' => 'bob'],
				['team_id' => 7, 'user_uid' => 'ada'],
				['team_id' => 8, 'user_uid' => 'ada'],
				['team_id' => 9, 'user_uid' => 'cy'],
			]],
			['custom_projects', [
				$this->row(1, 7, '2026-09-14', 4),
				$this->row(2, 7, '2026-09-21', 1),
				$this->row(3, 8, '2026-09-14', 4),
				$this->row(4, 8, null, 4),
			]],
		]);

		$loads = $service->getLoads(42, new DateTimeImmutable('2026-09-14'));

		self::assertSame(['ada', 'bob', 'cy'], array_keys($loads['members']));
		self::assertSame('Ada Lovelace', $loads['members']['ada']['displayName']);
		self::assertSame([7, 8], $loads['members']['ada']['teamIds']);
		self::assertSame([2, 3, 2, 2, 0, 0], array_column($loads['members']['ada']['weeks'], 'load'));
		self::assertSame(['full', 'overloaded', 'full', 'full', 'free', 'free'], array_column($loads['members']['ada']['weeks'], 'state'));
		self::assertSame([1, 2, 3], $loads['members']['ada']['weeks'][1]['projectIds']);
		self::assertSame([1, 2, 1, 1, 0, 0], array_column($loads['members']['bob']['weeks'], 'load'));
		self::assertSame([0, 0, 0, 0, 0, 0], array_column($loads['members']['cy']['weeks'], 'load'));
		self::assertSame('2026-W38', $loads['weeks'][0]['label']);
		self::assertSame([1, 2, 3], array_keys($loads['projects']));
		self::assertSame(['id' => 2, 'name' => 'Project 2', 'start' => '2026-09-21', 'end' => '2026-09-27', 'teamId' => 7], $loads['projects'][2]);
	}

	private function window(int $status, ?string $start, ?int $weeks, ?string $handover): array {
		return ['status' => $status, 'actualStartDate' => $start, 'executionWeeks' => $weeks, 'actualHandoverDate' => $handover];
	}

	private function row(int $id, int $teamId, ?string $start, ?int $weeks): array {
		return [
			'id' => $id, 'name' => 'Project ' . $id, 'status' => ProjectStatus::ACTIVE,
			'actual_start_date' => $start, 'actual_handover_date' => null, 'execution_weeks' => $weeks,
			'team_id' => $teamId,
		];
	}

	private function serviceWithQueries(array $queries): MemberLoadService {
		$builders = [];
		foreach ($queries as [$table, $rows]) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAllAssociative')->willReturn($rows);
			$expression = $this->createMock(IExpressionBuilder::class);
			$expression->method('eq')->willReturn('condition');
			$builder = $this->createMock(IQueryBuilder::class);
			foreach (['select', 'innerJoin', 'where', 'andWhere'] as $method) {
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
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'ada' ? 'Ada Lovelace' : null);
		return new MemberLoadService($db, $users);
	}
}
