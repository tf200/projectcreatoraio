<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectPortfolioService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioServiceTest extends TestCase {
	private ProjectPortfolioService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ProjectPortfolioService(
			$this->createMock(ProjectMapper::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testSummarizeAssignsEveryBoundaryToTheExpectedBucket(): void {
		$projects = [
			$this->project(1, 0, 0),
			$this->project(2, 24, 100),
			$this->project(3, 25, 100),
			$this->project(4, 49, 100),
			$this->project(5, 50, 100),
			$this->project(6, 74, 100),
			$this->project(7, 75, 100),
			$this->project(8, 99, 100),
			$this->project(9, 100, 100),
		];

		$result = $this->service->summarize($projects);

		self::assertSame([2, 2, 2, 2, 1], array_column($result['buckets'], 'count'));
		self::assertSame(['0-24', '0-24', '25-49', '25-49', '50-74', '50-74', '75-99', '75-99', '100'], array_column($result['projects'], 'bucket'));
	}

	public function testSummarizeReportsUntrackedProjectsSeparately(): void {
		$untracked = [['id' => 8, 'name' => 'Missing board']];

		$result = $this->service->summarize([$this->project(1, 1, 4)], $untracked);

		self::assertSame(2, $result['totalProjects']);
		self::assertSame(1, $result['trackedProjects']);
		self::assertSame($untracked, $result['untrackedProjects']);
		self::assertSame(25, $result['projects'][0]['completionPct']);
		self::assertSame(100.0, $result['buckets'][1]['percent']);
	}

	public function testSummarizeTreatsEmptyProjectAsZeroAndCapsInvalidDoneCount(): void {
		$result = $this->service->summarize([
			$this->project(1, 0, 0),
			$this->project(2, 8, 4),
		]);

		self::assertSame(0, $result['projects'][0]['completionPct']);
		self::assertSame(4, $result['projects'][1]['doneCards']);
		self::assertSame(100, $result['projects'][1]['completionPct']);
	}

	public function testCapacityUsesInclusiveBoundariesAndRoundsCapacity(): void {
		$result = $this->service->summarizeCapacity(
			['id' => 7, 'organizationId' => 42, 'name' => 'Design', 'fte' => 1.5, 'projectsPerFte' => 2.333],
			'2026-09-16',
			[
				['id' => 1, 'name' => 'Starts Monday', 'status' => 1, 'start' => '2026-09-14', 'end' => '2026-09-20', 'actualEnd' => null],
				['id' => 2, 'name' => 'Continues', 'status' => 1, 'start' => '2026-09-01', 'end' => null, 'actualEnd' => null],
			],
		);

		self::assertSame('2026-09-14', $result['period']['weekStart']);
		self::assertCount(6, $result['weeks']);
		self::assertSame(1, $result['weeks'][0]['starting']);
		self::assertSame(1, $result['weeks'][0]['ending']);
		self::assertSame(2, $result['weeks'][0]['totalActive']);
		self::assertSame(3.5, $result['team']['capacity']);
	}

	public function testCapacityNormalizesRequestedDateToItsIsoMonday(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(1, 1),
			'2026-09-20',
			[],
		);

		self::assertSame('2026-09-14', $result['period']['weekStart']);
		self::assertSame('2026-10-25', $result['period']['weekEnd']);
	}

	public function testCapacityKeepsOpenEndedProjectsActiveInAllLaterWeeks(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(1, 1),
			'2026-09-16',
			[$this->capacityProject(1, '2026-09-14', null)],
		);

		self::assertSame([1, 1, 1, 1, 1, 1], array_column($result['weeks'], 'totalActive'));
		self::assertSame([1, 0, 0, 0, 0, 0], array_column($result['weeks'], 'starting'));
		self::assertSame([0, 0, 0, 0, 0, 0], array_column($result['weeks'], 'ending'));
		self::assertSame([0, 1, 1, 1, 1, 1], array_column($result['weeks'], 'continuing'));
	}

	public function testCapacityCountsSameWeekStartAndEndButNotContinuing(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(2, 1),
			'2026-09-14',
			[$this->capacityProject(1, '2026-09-15', '2026-09-17')],
		);

		self::assertSame(1, $result['weeks'][0]['starting']);
		self::assertSame(1, $result['weeks'][0]['ending']);
		self::assertSame(0, $result['weeks'][0]['continuing']);
		self::assertSame(1, $result['weeks'][0]['totalActive']);
	}

	public function testCapacityFormatsIsoYearRolloverAndOverCapacity(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(1, 1),
			'2026-12-30',
			[
				$this->capacityProject(1, '2026-12-28', null),
				$this->capacityProject(2, '2026-12-28', null),
			],
		);

		self::assertSame('2026-W53', $result['weeks'][0]['label']);
		self::assertSame('2027-W01', $result['weeks'][1]['label']);
		self::assertTrue($result['weeks'][0]['overCapacity']);
		self::assertSame(-1.0, $result['weeks'][0]['remaining']);
	}

	public function testCapacityTurnsAnEndBeforeStartIntoAPlanningGap(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(4, 1),
			'2026-09-14',
			[$this->capacityProject(7, '2026-09-20', '2026-09-19')],
		);

		self::assertCount(1, $result['planningGaps']);
		self::assertSame([1, 1, 1, 1, 1, 1], array_column($result['weeks'], 'totalActive'));
	}

	private function team(float $fte, float $projectsPerFte): array {
		return [
			'id' => 7,
			'organizationId' => 42,
			'name' => 'Design',
			'fte' => $fte,
			'projectsPerFte' => $projectsPerFte,
		];
	}

	private function capacityProject(int $id, string $start, ?string $end): array {
		return [
			'id' => $id,
			'name' => 'Project ' . $id,
			'status' => 1,
			'start' => $start,
			'end' => $end,
			'actualEnd' => $end,
		];
	}

	private function project(int $id, int $done, int $total): array {
		return [
			'id' => $id,
			'name' => 'Project ' . $id,
			'boardId' => $id + 100,
			'totalCards' => $total,
			'doneCards' => $done,
		];
	}
}
