<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Service\TimelineImpactService;
use OCA\ProjectCreatorAIO\Service\TimelinePhaseService;
use PHPUnit\Framework\TestCase;

final class TimelineImpactServiceTest extends TestCase
{
	public function testListsDelayedAndAtRiskTasksWithTheirPhase(): void
	{
		$phaseService = $this->createMock(TimelinePhaseService::class);
		$phaseService->expects($this->never())->method('getProjectPhaseHierarchy');

		$result = (new TimelineImpactService($phaseService))->analyzeProjectDelays(new Project(), [[
			'category' => 'initiation',
			'name' => 'Initiation Phase',
			'tasks' => [
				['id' => 1, 'label' => 'Permits', 'isDelayed' => true, 'status' => 'on_track'],
				['id' => 2, 'label' => 'VO', 'isDelayed' => false, 'status' => 'behind_at_risk'],
				['id' => 3, 'label' => 'DO', 'isDelayed' => false, 'status' => 'on_track'],
			],
		]]);

		$this->assertTrue($result['hasActiveDelays']);
		$this->assertSame([1, 2], array_column($result['delayedTasks'], 'id'));
		$this->assertSame('initiation', $result['delayedTasks'][0]['phaseCategory']);
		$this->assertSame('Initiation Phase', $result['delayedTasks'][1]['phaseName']);
	}

	public function testLoadsTheHierarchyWhenNotGivenOne(): void
	{
		$phaseService = $this->createMock(TimelinePhaseService::class);
		$phaseService->expects($this->once())->method('getProjectPhaseHierarchy')->willReturn([
			'phases' => [['category' => 'initiation', 'name' => 'Initiation Phase', 'tasks' => [
				['id' => 1, 'label' => 'Permits', 'isDelayed' => false, 'status' => 'on_track'],
			]]],
			'dependencies' => [],
		]);

		$result = (new TimelineImpactService($phaseService))->analyzeProjectDelays(new Project());

		$this->assertFalse($result['hasActiveDelays']);
		$this->assertSame([], $result['delayedTasks']);
	}
}
