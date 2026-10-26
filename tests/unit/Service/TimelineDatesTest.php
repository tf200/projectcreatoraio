<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Service\TimelineDates;
use PHPUnit\Framework\TestCase;

final class TimelineDatesTest extends TestCase {
	public function testOnlyRealDaysInAPlausibleYearAreAccepted(): void {
		$this->assertSame('2026-10-28', TimelineDates::parse('2026-10-28', 'handover')->format('Y-m-d'));
		$this->assertSame('2026-10-28', TimelineDates::parse(' 2026-10-28T00:00:00Z ', 'handover')->format('Y-m-d'));
		$this->assertNull(TimelineDates::parse(null, 'handover'));
		$this->assertNull(TimelineDates::parse('', 'handover'));
		$this->assertNull(TimelineDates::parse('null', 'handover'));

		foreach (['2026-02-30', 'tomorrow', '28-10-2026', '2026-10-28 junk', '0226-10-28', '2266-10-28', 42] as $value) {
			try {
				TimelineDates::parse($value, 'handover');
				$this->fail('Accepted: ' . var_export($value, true));
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('handover', $e->getMessage());
			}
		}
	}

	public function testHandoverStartAndEndMustFollowEachOther(): void {
		$project = $this->project('2026-10-01', '2026-10-05', '2026-11-30');
		TimelineDates::assertInOrder($project, [TimelineDates::HANDOVER, TimelineDates::ACTUAL_START, TimelineDates::ACTUAL_END]);

		$same = $this->project('2026-10-05', '2026-10-05', '2026-10-05');
		TimelineDates::assertInOrder($same, [TimelineDates::HANDOVER, TimelineDates::ACTUAL_START, TimelineDates::ACTUAL_END]);

		$this->assertRefused($this->project('2026-10-10', '2026-10-05', null), [TimelineDates::ACTUAL_START], 'The actual start (5 Oct 2026) can\'t be before the handover (10 Oct 2026).');
		$this->assertRefused($this->project('2026-10-10', '2026-10-05', null), [TimelineDates::HANDOVER], 'The handover (10 Oct 2026) can\'t be after the actual start (5 Oct 2026).');
		$this->assertRefused($this->project(null, '2026-10-05', '2026-10-01'), [TimelineDates::ACTUAL_END], 'The end date (1 Oct 2026) can\'t be before the actual start (5 Oct 2026).');
	}

	public function testTheEndNeedsAnActualStart(): void {
		$this->assertRefused($this->project(null, null, '2026-10-01'), [TimelineDates::ACTUAL_END], 'Set the actual start before the end date.');
		$this->assertRefused($this->project(null, null, '2026-10-01'), [TimelineDates::ACTUAL_START], 'Clear the end date before the actual start.');
	}

	public function testOlderOutOfOrderDatesDoNotBlockOtherChanges(): void {
		$project = $this->project('2026-10-10', '2026-10-05', null);
		TimelineDates::assertInOrder($project, []);
		TimelineDates::assertInOrder($project, [TimelineDates::ACTUAL_END]);
		$this->addToAssertionCount(2);
	}

	private function project(?string $handover, ?string $start, ?string $end): Project {
		$project = new Project();
		$project->setActualHandoverDate($handover === null ? null : new DateTime($handover));
		$project->setActualStartDate($start === null ? null : new DateTime($start));
		$project->setActualEndDate($end === null ? null : new DateTime($end));
		return $project;
	}

	/** @param list<string> $changed */
	private function assertRefused(Project $project, array $changed, string $message): void {
		try {
			TimelineDates::assertInOrder($project, $changed);
			$this->fail('Accepted: ' . $message);
		} catch (InvalidArgumentException $e) {
			$this->assertSame($message, $e->getMessage());
		}
	}
}
