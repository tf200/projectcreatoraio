<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectPortfolioService;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioGapTest extends TestCase {
	private ProjectPortfolioService $service;

	protected function setUp(): void {
		$this->service = (new \ReflectionClass(ProjectPortfolioService::class))->newInstanceWithoutConstructor();
	}

	public function testInternalGapMergesOverlapsAndCountsWeekendDays(): void {
		[$gaps] = $this->service->findPlanningGaps(
			[$this->project(1, [7])],
			[101 => [
				$this->card(1, 'First', '2026-09-24', '2026-09-25'),
				$this->card(2, 'Overlap', '2026-09-25', '2026-09-25'),
				$this->card(3, 'Next', '2026-09-28', '2026-09-30'),
			]],
			[7 => 'Design'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertCount(1, $gaps);
		self::assertSame('internal', $gaps[0]['type']);
		self::assertSame(['2026-09-26', '2026-09-27', 2], [$gaps[0]['startDate'], $gaps[0]['endDate'], $gaps[0]['days']]);
	}

	public function testBetweenProjectGapIsPerTeamAndOneDayQualifies(): void {
		[$gaps] = $this->service->findPlanningGaps(
			[
				$this->executing(1, [7, 8], '2026-09-14', '2026-09-21'),
				$this->executing(2, [7], '2026-09-23', '2026-10-30'),
				$this->executing(3, [8], '2026-09-22', '2026-10-30'),
			],
			[],
			[7 => 'Design', 8 => 'Build'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertCount(1, $gaps);
		self::assertSame(['between', 7, '2026-09-22', 1, [1, 2]], [$gaps[0]['type'], $gaps[0]['teamId'], $gaps[0]['startDate'], $gaps[0]['days'], $gaps[0]['projectIds']]);
	}

	public function testBetweenProjectGapsIgnoreInitiationCards(): void {
		[$gaps] = $this->service->findPlanningGaps(
			[$this->project(1, [7]), $this->project(2, [7])],
			[
				101 => [$this->card(1, 'A', '2026-09-21', '2026-09-21')],
				102 => [$this->card(2, 'B', '2026-09-28', '2026-09-28')],
			],
			[7 => 'Design'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertSame([], array_values(array_filter($gaps, static fn (array $gap): bool => $gap['type'] === 'between')));
	}

	public function testBetweenGapUsesStartPlusWeeksAndOpenEndedWorkFillsTheRest(): void {
		$planned = $this->executing(1, [7], '2026-09-07', null);
		$planned['executionWeeks'] = 2; // planned handover 2026-09-21
		$openEnded = $this->executing(2, [7], '2026-09-28', null);
		$archived = $this->executing(3, [7], '2026-10-12', '2026-10-16');
		$archived['status'] = 0;
		[$gaps] = $this->service->findPlanningGaps([$planned, $openEnded, $archived], [], [7 => 'Design'], new DateTimeImmutable('2026-09-21'));

		self::assertCount(1, $gaps);
		self::assertSame(['2026-09-22', '2026-09-27', [1, 2]], [$gaps[0]['startDate'], $gaps[0]['endDate'], $gaps[0]['projectIds']]);
	}

	public function testIncompleteCardsAreIssuesAndOneKnownDateIsOneDayWork(): void {
		[$gaps, $issues] = $this->service->findPlanningGaps(
			[$this->project(1, [7])],
			[101 => [
				$this->card(1, 'Milestone', null, '2026-09-21'),
				$this->card(2, 'Unknown', null, null),
				$this->card(3, 'Next', '2026-09-23', '2026-09-23'),
			]],
			[7 => 'Design'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertSame('2026-09-22', $gaps[0]['startDate']);
		self::assertCount(2, $issues);
	}

	public function testOnlyIntervalsOverlappingSelectedPeriodAppear(): void {
		[$gaps] = $this->service->findPlanningGaps(
			[$this->project(1, [7])],
			[101 => [
				$this->card(1, 'A', '2026-09-01', '2026-09-01'),
				$this->card(2, 'B', '2026-09-25', '2026-09-25'),
				$this->card(3, 'C', '2026-12-01', '2026-12-01'),
			]],
			[7 => 'Design'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertCount(2, $gaps);
		self::assertSame('2026-09-02', $gaps[0]['startDate']);
		self::assertSame('2026-11-30', $gaps[1]['endDate']);
	}

	public function testBetweenGapCrossesYearAndTableLinksBothProjects(): void {
		$first = $this->executing(1, [7], '2026-11-02', '2026-12-31');
		$first['status'] = 4;
		[$gaps] = $this->service->findPlanningGaps(
			[$first, $this->executing(2, [7], '2027-01-02', '2027-02-26')],
			[],
			[7 => 'Design'],
			new DateTimeImmutable('2026-12-28'),
		);
		self::assertSame('2027-01-01', $gaps[0]['startDate']);
		self::assertSame(1, $gaps[0]['days']);
		$summary = [
			'planningGaps' => $gaps,
			'scheduleIssues' => [],
		];
		$projectRows = [$this->tableProject(1), $this->tableProject(2)];
		$result = $this->service->buildTableOverview($projectRows, [], $summary, new DateTimeImmutable('2026-12-28'), new DateTimeImmutable('2026-12-28'));
		self::assertSame(1, $result['planningGapCount']);
		self::assertSame($gaps[0]['id'], $result['projects'][0]['planningGap']['gapIds'][0]);
		self::assertTrue($result['projects'][1]['planningGap']['hasGap']);
	}

	public function testCompletedProjectsFillTheTimelineWithoutRaisingIssues(): void {
		$done = $this->project(1, [7]);
		$done['status'] = 4;
		[$gaps, $issues] = $this->service->findPlanningGaps(
			[$done, $this->project(2, [7])],
			[
				101 => [
					$this->card(1, 'Old', '2026-09-21', '2026-09-24'),
					$this->card(2, 'Undated', null, null),
				],
				102 => [$this->card(3, 'Start', '2026-09-25', '2026-09-25')],
			],
			[7 => 'Design'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertSame([], $gaps);
		self::assertSame([], $issues);
	}

	public function testGapChipCountsProjectsNotGapRecords(): void {
		$gaps = [
			['id' => 'between:7:2026-09-22', 'projectIds' => [1, 2]],
			['id' => 'internal:1:2026-09-28', 'projectIds' => [1]],
			['id' => 'internal:9:2026-09-28', 'projectIds' => [9]],
		];
		$result = $this->service->buildTableOverview(
			[$this->tableProject(1), $this->tableProject(2)],
			[],
			['planningGaps' => $gaps, 'scheduleIssues' => []],
			new DateTimeImmutable('2026-09-21'),
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertSame(3, $result['planningGapCount']);
		$chips = array_column($result['buckets'], 'count', 'key');
		self::assertSame(2, $chips['gaps']);
		self::assertSame('2 gaps', $result['projects'][0]['planningGap']['display']);
	}

	public function testMissingEndIsNotReportedTwice(): void {
		$result = $this->service->buildTableOverview(
			[$this->tableProject(1), $this->tableProject(2)],
			[],
			['planningGaps' => [], 'scheduleIssues' => [
				['id' => 'project:1', 'projectId' => 1, 'projectName' => 'Project 1', 'note' => 'No dated Deck cards'],
			]],
			new DateTimeImmutable('2026-09-21'),
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertSame(['project:1'], array_column($result['scheduleIssues'], 'id'));
		self::assertSame(['project-plan:2'], array_column($result['planningConflicts'], 'id'));
	}

	public function testCardTimestampsUseTheViewersCalendarDay(): void {
		$zone = $this->createMock(IDateTimeZone::class);
		$zone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Amsterdam'));
		$service = new ProjectPortfolioService($this->createMock(ProjectMapper::class), $this->createMock(IDBConnection::class), $zone);
		[$gaps] = $service->findPlanningGaps(
			[$this->project(1, [7])],
			[101 => [
				// 00:30 on the 22nd in Amsterdam is still the 21st in UTC.
				$this->card(1, 'Monday', '2026-09-21 06:00:00', '2026-09-21 22:30:00'),
				$this->card(2, 'Wednesday', '2026-09-23 06:00:00', '2026-09-23 16:00:00'),
			]],
			[7 => 'Design'],
			new DateTimeImmutable('2026-09-21'),
		);
		self::assertSame([], $gaps);
	}

	private function project(int $id, array $teamIds): array {
		return ['id' => $id, 'name' => 'Project ' . $id, 'status' => 1, 'boardId' => (string)(100 + $id), 'teamIds' => $teamIds];
	}

	private function executing(int $id, array $teamIds, string $actualStart, ?string $handover): array {
		return $this->project($id, $teamIds) + [
			'actualStartDate' => $actualStart,
			'actualHandoverDate' => $handover,
			'executionWeeks' => null,
		];
	}

	private function card(int $id, string $title, ?string $start, ?string $end): array {
		return ['id' => $id, 'title' => $title, 'startdate' => $start, 'duedate' => $end];
	}

	private function tableProject(int $id): array {
		return [
			'id' => $id, 'name' => 'Project ' . $id, 'status' => 1,
			'boardId' => (string)(100 + $id), 'createdAt' => '2026-12-01',
			'desiredStartDate' => null, 'actualStartDate' => null,
			'requiredPreparationWeeks' => 0,
		];
	}
}
