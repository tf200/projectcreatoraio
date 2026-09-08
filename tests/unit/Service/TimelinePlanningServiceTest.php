<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TimelinePlanningServiceTest extends TestCase {
	public function testBuildSummaryIncludesPlanningMetricsAndKpis(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new TimelinePlanningService($db, $logger);

		$project = new Project();
		$project->setId(42);
		$project->setType(0); // Combi
		$project->setCreatedAt(new DateTime('2026-09-07 10:00:00')); // Monday
		$project->setRequiredPreparationWeeks(4);
		$project->setDesiredStartDate(new DateTime('2026-12-28')); // Desired start

		$summary = $service->buildSummary($project);

		$this->assertArrayHasKey('kpis', $summary);
		$this->assertArrayHasKey('systemPlanning', $summary);
		$this->assertArrayHasKey('desiredStartDate', $summary);
		$this->assertArrayHasKey('minimumStartDate', $summary);
		$this->assertArrayHasKey('overallFloatWeeks', $summary);
		$this->assertArrayHasKey('planningStatus', $summary);

		$this->assertSame('2026-09-07', $summary['requestDate']);
		$this->assertSame(4, $summary['requiredPreparationWeeks']);
		$this->assertSame('2026-12-28', $summary['desiredStartDate']);

		// Process completion counter and state
		$this->assertArrayHasKey('processCompleted', $summary);
		$this->assertArrayHasKey('processCompleted', $summary['kpis']);
		$this->assertArrayHasKey('doneCount', $summary['kpis']['processCompleted']);
		$this->assertArrayHasKey('totalRequired', $summary['kpis']['processCompleted']);
		$this->assertArrayHasKey('status', $summary['kpis']['processCompleted']);

		// System Planning segments
		$sp = $summary['systemPlanning'];
		$this->assertArrayHasKey('deckTasks', $sp);
		$this->assertArrayHasKey('preparation', $sp);
		$this->assertArrayHasKey('minimumStart', $sp);
		$this->assertArrayHasKey('desiredStart', $sp);
		$this->assertArrayHasKey('float', $sp);

		$this->assertSame(4, $sp['preparation']['weeks']);
		$this->assertSame('2026-12-28', $sp['desiredStart']['date']);
	}

	public function testFloatStatusBehindAtRiskWhenMinimumStartExceedsDesiredStart(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new TimelinePlanningService($db, $logger);

		$project = new Project();
		$project->setId(43);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-09-07 10:00:00'));
		$project->setRequiredPreparationWeeks(6);
		// Desired start earlier than minimum start
		$project->setDesiredStartDate(new DateTime('2026-10-01'));

		$summary = $service->buildSummary($project);

		$this->assertSame('behind_at_risk', $summary['planningStatus']);
		$this->assertLessThan(0, $summary['overallFloatWeeks']);
		$this->assertSame('behind_at_risk', $summary['systemPlanning']['float']['status']);
	}

	public function testFloatStatusOnTrackWhenDesiredStartIsLaterThanMinimumStart(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new TimelinePlanningService($db, $logger);

		$project = new Project();
		$project->setId(44);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-09-07 10:00:00'));
		$project->setRequiredPreparationWeeks(2);
		// Minimum start is roughly request date + 13w + 2w = ~15 weeks = mid-December.
		// Setting desired start to next year gives plenty of positive float.
		$project->setDesiredStartDate(new DateTime('2027-02-01'));

		$summary = $service->buildSummary($project);

		$this->assertSame('on_track', $summary['planningStatus']);
		$this->assertGreaterThan(0, $summary['overallFloatWeeks']);
	}
}
