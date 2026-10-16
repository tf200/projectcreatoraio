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
	public function testHandoverDateIsManualAndCanBeClearedWithoutChangingPlanning(): void {
		$service = new TimelinePlanningService($this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		$project = new Project();
		$project->setType(-1);
		$project->setCreatedAt(new DateTime('2026-09-07'));
		$before = $service->buildSummary($project);
		$this->assertNull($before['actualHandoverDate']);

		$project->setActualHandoverDate(new DateTime('2026-10-01'));
		$after = $service->buildSummary($project);
		$this->assertSame('2026-10-01', $after['actualHandoverDate']);
		$this->assertSame('2026-10-01', $after['kpis']['actualHandoverDate']);
		$this->assertSame('2026-10-01', $after['systemPlanning']['actualHandover']['date']);
		$this->assertSame('2026-10-01', $project->jsonSerialize()['actual_handover_date']);
		$this->assertSame($before['processCompleted'], $after['processCompleted']);
		$this->assertSame($before['minimumStartDate'], $after['minimumStartDate']);

		$project->setActualHandoverDate(null);
		$this->assertSame($before, $service->buildSummary($project));
		$this->assertNull($project->jsonSerialize()['actual_handover_date']);
	}

	public function testPlannedEndIsActualStartPlusExecutionWeeksUntilTheEndIsRecorded(): void {
		$service = new TimelinePlanningService($this->createMock(IDBConnection::class), $this->createMock(LoggerInterface::class));
		$project = new Project();
		$project->setType(-1);
		$project->setCreatedAt(new DateTime('2026-09-07'));
		$project->setExecutionWeeks(6);

		$noStart = $service->buildSummary($project);
		$this->assertSame(6, $noStart['kpis']['executionWeeks']);
		$this->assertNull($noStart['kpis']['plannedEndDate']);

		$project->setActualStartDate(new DateTime('2027-09-06'));
		$planned = $service->buildSummary($project);
		$this->assertSame('2027-10-18', $planned['plannedEndDate']);
		$this->assertSame('2027-10-18', $planned['kpis']['plannedEndDate']);
		$this->assertSame(['label' => 'Execution', 'startDate' => '2027-09-06', 'endDate' => '2027-10-18', 'weeks' => 6], $planned['systemPlanning']['execution']);
		$this->assertSame(6, $project->jsonSerialize()['execution_weeks']);

		// Handover comes before the start: it never moves the end.
		$project->setActualHandoverDate(new DateTime('2027-08-30'));
		$this->assertSame('2027-10-18', $service->buildSummary($project)['plannedEndDate']);

		$project->setActualEndDate(new DateTime('2027-11-01'));
		$ended = $service->buildSummary($project);
		$this->assertSame('2027-11-01', $ended['plannedEndDate']);
		$this->assertSame('2027-11-01', $ended['actualEndDate']);
		$this->assertSame('2027-11-01', $ended['systemPlanning']['actualEnd']['date']);
		$this->assertSame('2027-11-01', $project->jsonSerialize()['actual_end_date']);
	}

	public function testPlannedEndDateNeedsStartAndWeeksOrARecordedEnd(): void {
		$this->assertNull(TimelinePlanningService::plannedEndDate(null, 4, null));
		$this->assertNull(TimelinePlanningService::plannedEndDate('2027-09-06', null, null));
		$this->assertSame('2027-09-06', TimelinePlanningService::plannedEndDate('2027-09-06', 0, null));
		$this->assertSame('2027-10-04', TimelinePlanningService::plannedEndDate('2027-09-06 00:00:00', 4, null));
		$this->assertSame('2027-12-01', TimelinePlanningService::plannedEndDate(null, null, '2027-12-01'));
	}

	public function testHandoverIsWarnedAboutOutsideProcessStepsDoneAndActualStart(): void {
		$done = ['status' => 'complete', 'date' => '2027-08-20'];
		$this->assertSame([], TimelinePlanningService::handoverWarnings(null, ['status' => 'incomplete'], '2027-09-06'));
		$this->assertSame([], TimelinePlanningService::handoverWarnings('2027-08-27', $done, '2027-09-06'));
		$this->assertSame([], TimelinePlanningService::handoverWarnings('2027-09-06', $done, '2027-09-06'));
		$this->assertSame(['Handover before the process steps are done'], TimelinePlanningService::handoverWarnings('2027-08-13', $done, null));
		$this->assertSame(['Handover before the process steps are done'], TimelinePlanningService::handoverWarnings('2027-08-27', ['status' => 'incomplete', 'date' => null], null));
		$this->assertSame(['Handover after the actual start'], TimelinePlanningService::handoverWarnings('2027-09-13', $done, '2027-09-06'));
		$this->assertSame([], TimelinePlanningService::handoverWarnings('2027-08-27', ['status' => 'not_configured', 'date' => null], null));
	}

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
		$this->assertArrayHasKey('actualStartDate', $summary);
		$this->assertArrayHasKey('minimumStartDate', $summary);
		$this->assertArrayHasKey('overallFloatWeeks', $summary);
		$this->assertArrayHasKey('planningStatus', $summary);

		$this->assertSame('2026-09-07', $summary['requestDate']);
		$this->assertSame(4, $summary['requiredPreparationWeeks']);
		$this->assertSame('2026-12-28', $summary['desiredStartDate']);
		$this->assertNull($summary['actualStartDate']);
		$this->assertNull($summary['kpis']['actualStartDate']);

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
		$this->assertArrayHasKey('actualStart', $sp);
		$this->assertArrayHasKey('float', $sp);

		$this->assertSame(4, $sp['preparation']['weeks']);
		$this->assertSame('2026-12-28', $sp['desiredStart']['date']);
		$this->assertNull($sp['actualStart']['date']);
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

	public function testMinimumStartUsesInitiationPhaseBoundsAndPreparationTime(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new TimelinePlanningService($db, $logger);

		$project = new Project();
		$project->setId(45);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-01-01 10:00:00'));
		$project->setRequiredPreparationWeeks(2);

		$summary = $service->buildSummary($project, [[
			'category' => 'initiation',
			'startDate' => '2026-01-05',
			'endDate' => '2026-04-20',
		]]);

		$this->assertSame('2026-01-05', $summary['systemPlanning']['deckTasks']['startDate']);
		$this->assertSame('2026-04-20', $summary['systemPlanning']['deckTasks']['endDate']);
		$this->assertSame('2026-05-04', $summary['minimumStartDate']);
		$this->assertSame('2026-05-04', $summary['kpis']['minimumStartDate']);
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

	public function testBuildSummaryIncludesActualStartDateWhenConfigured(): void {
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$service = new TimelinePlanningService($db, $logger);

		$project = new Project();
		$project->setId(45);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-09-07 10:00:00'));
		$project->setDesiredStartDate(new DateTime('2026-12-28'));
		$project->setActualStartDate(new DateTime('2027-01-05'));

		$summary = $service->buildSummary($project);

		$this->assertSame('2027-01-05', $summary['actualStartDate']);
		$this->assertSame('2027-01-05', $summary['kpis']['actualStartDate']);
		$this->assertSame('2027-01-05', $summary['systemPlanning']['actualStart']['date']);
	}
}
