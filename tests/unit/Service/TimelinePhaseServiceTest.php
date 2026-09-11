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
}
