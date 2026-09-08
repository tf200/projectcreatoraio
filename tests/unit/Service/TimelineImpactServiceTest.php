<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\TimelinePhase;
use OCA\ProjectCreatorAIO\Db\TimelinePhaseMapper;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\TimelineImpactService;
use OCA\ProjectCreatorAIO\Service\TimelinePhaseService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCP\IDBConnection;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class TimelineImpactServiceTest extends TestCase
{
	private function createTestProject(): Project
	{
		$project = new Project();
		$project->setId(101);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-09-07 09:00:00'));
		$project->setDesiredStartDate(new DateTime('2026-12-14 00:00:00'));
		$project->setRequiredPreparationWeeks(7);
		return $project;
	}

	private function createImpactService(
		?IDBConnection $db = null,
		?TimelineItemMapper $itemMapper = null,
		?ProjectActivityService $activityService = null
	): array {
		$db ??= $this->createMock(IDBConnection::class);
		$phaseMapper = $this->createMock(TimelinePhaseMapper::class);
		$itemMapper ??= $this->createMock(TimelineItemMapper::class);
		$activityService ??= $this->createMock(ProjectActivityService::class);
		$userSession = $this->createMock(IUserSession::class);
		$logger = $this->createMock(\Psr\Log\LoggerInterface::class);

		$p1 = new TimelinePhase();
		$p1->setId(1);
		$p1->setCategory('initiation');
		$p1->setName('Initiation Phase');
		$p1->setOrderIndex(2);

		$p2 = new TimelinePhase();
		$p2->setId(2);
		$p2->setCategory('preparation');
		$p2->setName('Preparation Phase');
		$p2->setOrderIndex(3);

		$p3 = new TimelinePhase();
		$p3->setId(3);
		$p3->setCategory('execution');
		$p3->setName('Execution / Construction');
		$p3->setOrderIndex(4);

		$p4 = new TimelinePhase();
		$p4->setId(4);
		$p4->setCategory('handover');
		$p4->setName('Handover Phase');
		$p4->setOrderIndex(5);

		$phaseMapper->method('findByProject')->willReturn([$p1, $p2, $p3, $p4]);
		$itemMapper->method('findByProject')->willReturn([]);

		$phaseService = new TimelinePhaseService($db, $phaseMapper, $itemMapper);
		$planningService = new TimelinePlanningService($db, $logger);

		$impactService = new TimelineImpactService(
			$phaseService,
			$planningService,
			$itemMapper,
			$activityService,
			$userSession,
			$db,
		);

		return [$impactService, $db, $itemMapper, $activityService];
	}

	public function testCalculateTaskImpactReturnsExpectedImpactAndOptions(): void
	{
		[$impactService] = $this->createImpactService();
		$project = $this->createTestProject();

		// Calculate impact for 28-day (4-week) delay on "Permits"
		$impact = $impactService->calculateTaskImpact($project, 'Permits', 28);

		$this->assertArrayHasKey('task', $impact);
		$this->assertSame(28, $impact['task']['delayDays']);
		$this->assertSame(4, $impact['task']['delayWeeks']);
		$this->assertSame('high', $impact['severity']);

		$this->assertNotEmpty($impact['unmitigatedImpacts']);
		$this->assertNotEmpty($impact['recoveryOptions']);
		$this->assertCount(6, $impact['recoveryOptions']);

		// Verify Options A to F
		$options = $impact['recoveryOptions'];
		$this->assertSame('A', $options[0]['letter']);
		$this->assertSame('shift_everything', $options[0]['id']);
		$this->assertSame('B', $options[1]['letter']);
		$this->assertSame('use_float', $options[1]['id']);
		$this->assertFalse($options[1]['available']);
		$this->assertSame('C', $options[2]['letter']);
		$this->assertSame('execute_in_parallel', $options[2]['id']);
		$this->assertSame('D', $options[3]['letter']);
		$this->assertSame('accelerate', $options[3]['id']);
		$this->assertSame('E', $options[4]['letter']);
		$this->assertSame('keep_date', $options[4]['id']);
		$this->assertSame('F', $options[5]['letter']);
		$this->assertSame('new_baseline', $options[5]['id']);
	}

	public function testSimulateScenarioAccelerateReducesImpact(): void
	{
		[$impactService] = $this->createImpactService();
		$project = $this->createTestProject();

		// Simulate scenario: Accelerate subsequent task by 14 days (2 weeks)
		$simulation = $impactService->simulateScenario($project, [
			'rootTaskId' => 'Permits',
			'delayDays' => 28,
			'strategy' => 'accelerate',
			'accelerateDays' => 14,
		]);

		$this->assertArrayHasKey('scenario', $simulation);
		$this->assertArrayHasKey('simulatedPhases', $simulation);
		$this->assertSame('accelerate', $simulation['scenario']['strategy']);
		$this->assertStringContainsString('Accelerate', $simulation['scenario']['description']);
		$this->assertStringContainsString('+2 weeks', $simulation['scenario']['scenarioResult']);
		$this->assertFalse($simulation['scenario']['isAchievable']);
		$this->assertNotEmpty($simulation['application']['overrides']);
	}

	public function testSimulateScenarioShiftEverythingMovesAllTasks(): void
	{
		[$impactService] = $this->createImpactService();
		$project = $this->createTestProject();

		$simulation = $impactService->simulateScenario($project, [
			'rootTaskId' => 'Permits',
			'delayDays' => 28,
			'strategy' => 'shift_everything',
		]);

		$this->assertSame('shift_everything', $simulation['scenario']['strategy']);
		$this->assertStringContainsString('Shift all downstream tasks by +4 weeks', $simulation['scenario']['description']);
		$this->assertStringContainsString('+4 weeks', $simulation['scenario']['scenarioResult']);
		$this->assertFalse($simulation['scenario']['isAchievable']);
		$this->assertNotEmpty($simulation['simulatedPhases']);
	}

	public function testSimulateScenarioExecuteInParallelAppliesOverlap(): void
	{
		[$impactService] = $this->createImpactService();
		$project = $this->createTestProject();

		$simulation = $impactService->simulateScenario($project, [
			'rootTaskId' => 'Permits',
			'delayDays' => 28,
			'strategy' => 'execute_in_parallel',
		]);

		$this->assertSame('execute_in_parallel', $simulation['scenario']['strategy']);
		$this->assertStringContainsString('parallel', $simulation['scenario']['description']);
		$this->assertStringContainsString('+2 weeks', $simulation['scenario']['scenarioResult']);
		$this->assertNotEmpty($simulation['application']['overrides']);
	}

	public function testSimulateScenarioUseFloatAbsorbsDelayWhenFloatAvailable(): void
	{
		[$impactService] = $this->createImpactService();
		// Create project with plenty of float
		$project = new Project();
		$project->setId(102);
		$project->setType(0);
		$project->setCreatedAt(new DateTime('2026-09-07 09:00:00'));
		$project->setDesiredStartDate(new DateTime('2027-06-01 00:00:00'));
		$project->setRequiredPreparationWeeks(2);

		$simulation = $impactService->simulateScenario($project, [
			'rootTaskId' => 'Permits',
			'delayDays' => 14,
			'strategy' => 'use_float',
		]);

		$this->assertSame('use_float', $simulation['scenario']['strategy']);
		$this->assertStringContainsString('float buffer', $simulation['scenario']['description']);
		$this->assertTrue($simulation['scenario']['isAchievable']);
		$this->assertSame('On track', $simulation['scenario']['scenarioStatus']);
	}

	public function testSimulateScenarioKeepDatePreservesTargetAndFlagsRisk(): void
	{
		[$impactService] = $this->createImpactService();
		$project = $this->createTestProject();

		$simulation = $impactService->simulateScenario($project, [
			'rootTaskId' => 'Permits',
			'delayDays' => 21,
			'strategy' => 'keep_date',
		]);

		$this->assertSame('keep_date', $simulation['scenario']['strategy']);
		$this->assertStringContainsString('Maintain deadline', $simulation['scenario']['description']);
		$this->assertSame('At risk', $simulation['scenario']['scenarioStatus']);
		$this->assertFalse($simulation['scenario']['isAchievable']);
	}

	public function testSimulateScenarioNewBaselineEstablishesDurableBaseline(): void
	{
		[$impactService] = $this->createImpactService();
		$project = $this->createTestProject();

		$simulation = $impactService->simulateScenario($project, [
			'rootTaskId' => 'Permits',
			'delayDays' => 28,
			'strategy' => 'new_baseline',
		]);

		$this->assertSame('new_baseline', $simulation['scenario']['strategy']);
		$this->assertStringContainsString('official schedule baseline', $simulation['scenario']['description']);
		$this->assertSame('New baseline active', $simulation['scenario']['scenarioStatus']);
		$this->assertTrue($simulation['scenario']['isAchievable']);
		$this->assertNotEmpty($simulation['application']['overrides']);
	}

	public function testApplyRecoveryStrategyPersistsOverridesAndLogsActivity(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$itemMapper = $this->createMock(TimelineItemMapper::class);
		$itemMapper->method('findByProjectAndSystemKey')->willReturn(null);
		$itemMapper->expects($this->atLeastOnce())->method('createItem');

		$activityService = $this->createMock(ProjectActivityService::class);
		$activityService->expects($this->once())->method('record');

		[$impactService] = $this->createImpactService($db, $itemMapper, $activityService);
		$project = $this->createTestProject();

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getDisplayName')->willReturn('Lead Engineer');

		$result = $impactService->applyRecoveryStrategy($project, 'accelerate', [
			'rootTaskId' => 'Permits',
			'delayDays' => 28,
			'accelerateDays' => 14,
		], $user);

		$this->assertTrue($result['success']);
		$this->assertSame('accelerate', $result['strategy']);
		$this->assertStringContainsString('Accelerate', $result['message']);
		$this->assertArrayHasKey('summary', $result);
		$this->assertArrayHasKey('hierarchy', $result);
	}
}
