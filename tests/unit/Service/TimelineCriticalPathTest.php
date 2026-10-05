<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Service\TimelineCriticalPath;
use PHPUnit\Framework\TestCase;

final class TimelineCriticalPathTest extends TestCase
{
	public function testLongestBranchIsCriticalAndShortBranchHasSlack(): void
	{
		// A (10d) -> C, B (20d) -> C: A can slip 10 days before C moves.
		$result = (new TimelineCriticalPath())->analyze([
			'A' => $this->task('2026-01-01', '2026-01-10'),
			'B' => $this->task('2026-01-01', '2026-01-20'),
			'C' => $this->task('2026-01-21', '2026-01-30', ['A', 'B']),
		]);

		$this->assertSame(10, $result['A']['floatDays']);
		$this->assertFalse($result['A']['isCritical']);
		$this->assertSame('2026-01-20', $result['A']['lateFinish']);
		$this->assertSame('2026-01-11', $result['A']['lateStart']);
		$this->assertSame(0, $result['B']['floatDays']);
		$this->assertTrue($result['B']['isCritical']);
		$this->assertTrue($result['C']['isCritical']);
	}

	public function testDeadlineGivesTheDrivingChainItsFloatEvenWhenNegative(): void
	{
		$tasks = [
			'A' => $this->task('2026-01-01', '2026-01-10'),
			'B' => $this->task('2026-01-11', '2026-01-20', ['A']),
		];

		$early = (new TimelineCriticalPath())->analyze($tasks, [], new DateTime('2026-01-27'));
		$this->assertSame(7, $early['A']['floatDays']);
		$this->assertSame(7, $early['B']['floatDays']);
		$this->assertTrue($early['A']['isCritical']);

		$late = (new TimelineCriticalPath())->analyze($tasks, [], new DateTime('2026-01-15'));
		$this->assertSame(-5, $late['B']['floatDays']);
		$this->assertTrue($late['B']['isCritical']);
	}

	public function testOverlapGivesThePredecessorMoreRoom(): void
	{
		$result = (new TimelineCriticalPath())->analyze([
			'A' => $this->task('2026-01-01', '2026-01-10'),
			'B' => $this->task('2026-01-06', '2026-01-15', ['A']),
		], ['A>B' => 5]);

		$this->assertSame(0, $result['A']['floatDays']);
		$this->assertSame('2026-01-10', $result['A']['lateFinish']);
	}

	public function testCompletedTasksHaveNoSlackAndAreNeverCritical(): void
	{
		$result = (new TimelineCriticalPath())->analyze([
			'A' => $this->task('2026-01-01', '2026-01-10', [], true),
			'B' => $this->task('2026-01-11', '2026-01-20', ['A']),
		]);

		$this->assertNull($result['A']['floatDays']);
		$this->assertFalse($result['A']['isCritical']);
		$this->assertTrue($result['B']['isCritical']);
	}

	private function task(string $start, string $end, array $predecessors = [], bool $done = false): array
	{
		return ['startDate' => $start, 'endDate' => $end, 'predecessorIds' => $predecessors, 'isDone' => $done];
	}
}
