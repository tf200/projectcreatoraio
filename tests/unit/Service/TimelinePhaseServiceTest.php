<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineItem;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\TimelinePhase;
use OCA\ProjectCreatorAIO\Db\TimelinePhaseMapper;
use OCA\ProjectCreatorAIO\Service\TimelinePhaseService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class TimelinePhaseServiceTest extends TestCase
{
	public function testGetProjectPhaseHierarchyReturnsFourEmptyLifecyclePhasesWithoutDeckCards(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$phaseMapper = $this->createMock(TimelinePhaseMapper::class);
		$itemMapper = $this->createMock(TimelineItemMapper::class);

		$p1 = new TimelinePhase();
		$p1->setId(1);
		$p1->setCategory('initiation');
		$p1->setName('Initiation Phase');
		$p1->setOrderIndex(1);

		$p2 = new TimelinePhase();
		$p2->setId(2);
		$p2->setCategory('preparation');
		$p2->setName('Preparation Phase');
		$p2->setOrderIndex(2);

		$p3 = new TimelinePhase();
		$p3->setId(3);
		$p3->setCategory('execution');
		$p3->setName('Execution / Construction');
		$p3->setOrderIndex(3);

		$p4 = new TimelinePhase();
		$p4->setId(4);
		$p4->setCategory('handover');
		$p4->setName('Handover Phase');
		$p4->setOrderIndex(4);

		$phaseMapper->method('findByProject')->willReturn([$p1, $p2, $p3, $p4]);
		$override = new TimelineItem();
		$override->setLabel('cust-3-Earthworks');
		$override->setSystemKey('schedule_override:' . sha1('cust-3-Earthworks'));
		$override->setDurationDays(14);
		$override->setStatus('');
		$itemMapper->method('findByProject')->willReturn([$override]);

		$service = new TimelinePhaseService($db, $phaseMapper, $itemMapper);

		$project = new Project();
		$project->setId(99);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-09-07 09:00:00'));

		$hierarchy = $service->getProjectPhaseHierarchy($project);

		$this->assertArrayHasKey('phases', $hierarchy);
		$this->assertArrayHasKey('dependencies', $hierarchy);
		$this->assertCount(4, $hierarchy['phases']);

		$phases = $hierarchy['phases'];
		$this->assertSame('initiation', $phases[0]['category']);
		$this->assertSame(2, $phases[0]['order']);
		$this->assertSame('preparation', $phases[1]['category']);
		$this->assertSame(3, $phases[1]['order']);
		$this->assertSame('execution', $phases[2]['category']);
		$this->assertSame(4, $phases[2]['order']);
		$this->assertSame('handover', $phases[3]['category']);
		$this->assertSame(5, $phases[3]['order']);
		foreach ($phases as $phase) {
			$this->assertSame([], $phase['tasks']);
			$this->assertNull($phase['startDate']);
			$this->assertNull($phase['endDate']);
			$this->assertNull($phase['milestone']);
			$this->assertSame('not_started', $phase['status']);
		}
		$this->assertSame([], $hierarchy['dependencies']);
	}

	public function testDefaultCardDependenciesMappingAlignsWithCustomerFlow(): void
	{
		$phases = \OCA\ProjectCreatorAIO\Service\CombiPhaseDefaults::getPhases();
		$initiationCards = $phases['initiation']['cards'];
		$defaultCards = array_merge(
			\OCA\ProjectCreatorAIO\Service\ProjectTypeDeckDefaults::getNextPriorityCards(0),
			\OCA\ProjectCreatorAIO\Service\ProjectTypeDeckDefaults::getProcessStepCards(0),
		);
		$defaultTitles = array_column($defaultCards, 'title');

		$this->assertEqualsCanonicalizing($defaultTitles, $initiationCards);
		$this->assertSame('Intakeformulier', $initiationCards[0]);
		$this->assertSame([], $phases['preparation']['cards']);
		$this->assertSame([], $phases['execution']['cards']);
		$this->assertSame([], $phases['execution']['customTasks']);
		$this->assertSame([], $phases['handover']['cards']);
		$this->assertSame([], $phases['handover']['customTasks']);
		foreach ($defaultTitles as $title) {
			$this->assertSame('initiation', \OCA\ProjectCreatorAIO\Service\CombiPhaseDefaults::findPhaseKeyForCardTitle($title));
		}

		$deps = \OCA\ProjectCreatorAIO\Service\CombiPhaseDefaults::getDefaultCardDependencies();
		$dependencyKeys = \OCA\ProjectCreatorAIO\Service\ProjectTypeDeckDefaults::getDefaultDependencyKeys(0);
		$this->assertSame(['combi.intake_form'], $dependencyKeys['combi.peak_power_form']);
		$this->assertSame(['Intakeformulier'], $deps['Piekvermogensformulier']);
		$this->assertSame(['Intakeformulier'], $deps['Quickscan']);
		$this->assertSame(['Quickscan'], $deps['Situatie tekening']);
		$this->assertSame(['Piekvermogensformulier'], $deps['AVP']);
		$this->assertSame(['Situatie tekening', 'AVP'], $deps['VO']);
		$this->assertSame(['VO'], $deps['Intake inplannen & hosten']);
		$this->assertSame(['Intake inplannen & hosten'], $deps['Intakeverslag']);
		$this->assertSame(['VO', 'Intakeverslag'], $deps['DO']);
	}

	public function testDependencyScheduleRunsRootsInParallelAndUsesLatestPredecessor(): void
	{
		$service = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);
		$cards = [
			4 => $this->card(4, 'Last'),
			3 => $this->card(3, 'Dependent'),
			1 => $this->card(1, 'Root A'),
			2 => $this->card(2, 'Root B', '2026-01-15'),
		];

		$schedules = $service->calculateDeckCardSchedules(
			$cards,
			[3 => [1, 2], 4 => [3]],
			new DateTime('2026-01-01'),
			[],
			[3 => ['startDate' => '2026-02-01']],
		);

		$this->assertSame('2026-01-01', $schedules[1]['start']->format('Y-m-d'));
		$this->assertSame('2026-04-01', $schedules[1]['end']->format('Y-m-d'));
		$this->assertSame('2026-01-15', $schedules[2]['start']->format('Y-m-d'));
		$this->assertSame('2026-04-15', $schedules[2]['end']->format('Y-m-d'));
		$this->assertSame('2026-04-16', $schedules[3]['start']->format('Y-m-d'));
		$this->assertSame('2026-07-16', $schedules[3]['end']->format('Y-m-d'));
		$this->assertSame('2026-07-17', $schedules[4]['start']->format('Y-m-d'));
	}

	public function testDependencyScheduleUsesActualCompletionAndRejectsCycles(): void
	{
		$service = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);
		$cards = [
			1 => $this->card(1, 'Completed', '2026-01-01', '2026-04-01', '2026-02-10'),
			2 => $this->card(2, 'Successor'),
		];

		$schedules = $service->calculateDeckCardSchedules($cards, [2 => [1]], new DateTime('2026-01-01'), [], []);
		$this->assertSame('2026-02-10', $schedules[1]['end']->format('Y-m-d'));
		$this->assertSame('2026-02-11', $schedules[2]['start']->format('Y-m-d'));

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('dependencies contain a cycle');
		$service->calculateDeckCardSchedules($cards, [1 => [2], 2 => [1]], new DateTime('2026-01-01'), [], []);
	}

	public function testDependencyScheduleUsesDeckDueDateAndMovesSuccessor(): void
	{
		$service = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);
		$cards = [
			1 => $this->card(1, 'Root', '2026-01-01', '2026-05-20'),
			2 => $this->card(2, 'Successor'),
		];

		$schedules = $service->calculateDeckCardSchedules($cards, [2 => [1]], new DateTime('2026-01-01'), [], []);

		$this->assertSame('2026-05-20', $schedules[1]['end']->format('Y-m-d'));
		$this->assertSame('2026-05-21', $schedules[2]['start']->format('Y-m-d'));
	}

	public function testTimelineDurationOverrideSetsEditableEndDate(): void
	{
		$service = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);
		$cards = [1 => $this->card(1, 'Card', '2026-01-01', '2026-04-01')];

		$schedules = $service->calculateDeckCardSchedules(
			$cards,
			[],
			new DateTime('2026-01-01'),
			[],
			[1 => ['startDate' => '2026-01-10', 'durationDays' => 22]],
		);

		$this->assertSame('2026-01-10', $schedules[1]['start']->format('Y-m-d'));
		$this->assertSame('2026-01-31', $schedules[1]['end']->format('Y-m-d'));
	}

	public function testShortenedPredecessorPullsSuccessorsBackUnlessAnotherPredecessorBlocksThem(): void
	{
		$service = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);
		$cards = [
			1 => $this->card(1, 'Shortened root', '2026-01-01', '2026-03-01'),
			2 => $this->card(2, 'Blocking root', '2026-01-01', '2026-04-15'),
			3 => $this->card(3, 'Moves back', '2026-04-02', '2026-07-02'),
			4 => $this->card(4, 'Blocked', '2026-04-16', '2026-07-16'),
			5 => $this->card(5, 'Moves recursively', '2026-07-03', '2026-10-03'),
		];

		$schedules = $service->calculateDeckCardSchedules(
			$cards,
			[3 => [1], 4 => [1, 2], 5 => [3]],
			new DateTime('2026-01-01'),
			[],
			[],
		);

		$this->assertSame('2026-03-02', $schedules[3]['start']->format('Y-m-d'));
		$this->assertSame('2026-06-01', $schedules[3]['end']->format('Y-m-d'));
		$this->assertSame('2026-04-16', $schedules[4]['start']->format('Y-m-d'));
		$this->assertSame('2026-06-02', $schedules[5]['start']->format('Y-m-d'));
	}

	public function testNonCombiProjectDoesNotCreateOrReturnCombiPhases(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$phaseMapper = $this->createMock(TimelinePhaseMapper::class);
		$itemMapper = $this->createMock(TimelineItemMapper::class);
		$phaseMapper->expects($this->never())->method('createPhase');

		$project = new Project();
		$project->setId(100);
		$project->setType(1);

		$service = new TimelinePhaseService($db, $phaseMapper, $itemMapper);
		$this->assertSame(['phases' => [], 'dependencies' => []], $service->getProjectPhaseHierarchy($project));
	}

	/** @return array{id: int, title: string, startdate: ?DateTime, duedate: ?DateTime, done: ?DateTime} */
	private function card(int $id, string $title, ?string $start = null, ?string $end = null, ?string $done = null): array
	{
		return [
			'id' => $id,
			'title' => $title,
			'startdate' => $start !== null ? new DateTime($start) : null,
			'duedate' => $end !== null ? new DateTime($end) : null,
			'done' => $done !== null ? new DateTime($done) : null,
		];
	}
}
