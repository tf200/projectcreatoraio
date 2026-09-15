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
