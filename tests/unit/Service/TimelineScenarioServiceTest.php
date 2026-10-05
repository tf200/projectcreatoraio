<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\TimelinePhaseMapper;
use OCA\ProjectCreatorAIO\Service\TimelineCriticalPath;
use OCA\ProjectCreatorAIO\Service\TimelinePhaseService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCA\ProjectCreatorAIO\Service\TimelineScenarioService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class TimelineScenarioServiceTest extends TestCase
{
	/**
	 * Permits (1) -> Preparation (3) -> Earthworks (4); Survey (2) -> Earthworks (4); Intake (5) is done.
	 * Permits ends 01-31, Survey 01-20, Preparation 02-10, Earthworks 02-20.
	 */
	private const CARDS = [
		1 => ['Permits', '2026-01-01', '2026-01-31', null],
		2 => ['Survey', '2026-01-01', '2026-01-20', null],
		3 => ['Preparation', '2026-02-01', '2026-02-10', null],
		4 => ['Earthworks', '2026-02-11', '2026-02-20', null],
		5 => ['Intake', '2026-01-01', '2026-01-05', '2026-01-05'],
	];
	private const DEPENDENCIES = [3 => [1], 4 => [3, 2]];

	public function testWithoutChangesNothingMovesAndTheLongestChainIsCritical(): void
	{
		$result = $this->service()->simulate($this->project(), []);

		$this->assertSame([], $result['changes']);
		$this->assertSame(0, $result['impact']['movedTaskCount']);
		$this->assertSame([], $result['impact']['deckCardUpdates']);
		$this->assertSame(['1', '3', '4'], $result['impact']['criticalPath']);
		$this->assertSame('2026-03-06', $result['impact']['planning']['minimumStartDate']);
		$this->assertSame(8, $result['impact']['planning']['floatDays']);
		$this->assertSame(8, $this->task($result, 1)['floatDays']);
		$this->assertSame(29, $this->task($result, 2)['floatDays']);
	}

	public function testDelayRipplesThroughSuccessorsAndUsesUpTheFloat(): void
	{
		$result = $this->service()->simulate($this->project(), [
			['type' => 'delay', 'taskId' => 1, 'days' => 14],
		]);

		$permits = $this->task($result, 1);
		$this->assertTrue($permits['changedDirectly']);
		$this->assertSame('2026-02-14', $permits['endDate']);
		$this->assertSame(14, $permits['endShiftDays']);
		$this->assertSame(14, $this->task($result, 3)['startShiftDays']);
		$this->assertSame(14, $this->task($result, 4)['endShiftDays']);
		$this->assertSame(0, $this->task($result, 2)['endShiftDays']);
		$this->assertSame(3, $result['impact']['movedTaskCount']);

		$planning = $result['impact']['planning'];
		$this->assertSame('2026-03-20', $planning['minimumStartDate']);
		$this->assertSame(14, $planning['minimumStartShiftDays']);
		$this->assertSame(8, $planning['baselineFloatDays']);
		$this->assertSame(-6, $planning['floatDays']);
		$this->assertTrue($planning['baselineDesiredStartAchievable']);
		$this->assertFalse($planning['desiredStartAchievable']);

		$this->assertSame([1, 3, 4], array_column($result['impact']['deckCardUpdates'], 'cardId'));
		$this->assertSame('2026-02-25', $result['impact']['deckCardUpdates'][2]['toStartDate']);
		$this->assertSame('Initiation ready', $result['impact']['milestones'][0]['label']);
		$this->assertSame(14, $result['impact']['milestones'][0]['shiftDays']);
	}

	public function testDelayWithinSlackMovesNothingDownstream(): void
	{
		$result = $this->service()->simulate($this->project(), [
			['type' => 'delay', 'taskId' => 2, 'days' => 10],
		]);

		$this->assertSame(1, $result['impact']['movedTaskCount']);
		$this->assertSame(0, $this->task($result, 4)['startShiftDays']);
		$this->assertSame(19, $this->task($result, 2)['floatDays']);
		$this->assertSame(0, $result['impact']['planning']['minimumStartShiftDays']);
	}

	public function testStackedFixesRecoverTheDelay(): void
	{
		$result = $this->service()->simulate($this->project(), [
			['type' => 'delay', 'taskId' => 1, 'days' => 14],
			['type' => 'duration', 'taskId' => 4, 'days' => 5],
			['type' => 'overlap', 'predecessorId' => 1, 'successorId' => 3, 'days' => 5],
		]);

		// Earthworks: 5 days shorter. Preparation: starts 5 days before Permits ends.
		$this->assertSame('2026-02-24', $this->task($result, 4)['endDate']);
		$this->assertSame(-5, $this->task($result, 4)['durationChangeDays']);
		$this->assertSame(4, $result['impact']['planning']['minimumStartShiftDays']);
		$this->assertSame(4, $result['impact']['planning']['floatDays']);
		$this->assertTrue($result['impact']['planning']['desiredStartAchievable']);
		$this->assertSame(['1', '3', '4'], $result['impact']['criticalPath']);

		$overlap = array_values(array_filter($result['dependencies'], static fn (array $d): bool => $d['successorId'] === 3));
		$this->assertSame(5, $overlap[0]['overlapDays']);
	}

	public function testPlanningChangesMoveTheDeadlineWithoutMovingCards(): void
	{
		$result = $this->service()->simulate($this->project(), [
			['type' => 'planning', 'requiredPreparationWeeks' => 0, 'desiredStartDate' => '2026-02-15'],
		]);

		$this->assertSame(0, $result['impact']['movedTaskCount']);
		$this->assertSame('2026-02-20', $result['impact']['planning']['minimumStartDate']);
		$this->assertSame(-5, $result['impact']['planning']['floatDays']);
		$this->assertSame(-5, $this->task($result, 4)['floatDays']);
	}

	public function testFinishingEarlyPullsSuccessorsForward(): void
	{
		$result = $this->service()->simulate($this->project(), [
			['type' => 'endDate', 'taskId' => 1, 'date' => '2026-01-24'],
		]);

		$this->assertSame(-7, $this->task($result, 3)['startShiftDays']);
		$this->assertSame(-7, $this->task($result, 4)['startShiftDays']);
	}

	public function testCardsWithoutDeckDatesKeepTheirLengthWhenTheyMove(): void
	{
		// The undated successor would otherwise run "three months from its start": 92 days from
		// 2026-09-22, but 93 days once a three-week delay moves it to 2026-10-13.
		$service = $this->service(
			[1 => ['Root', '2026-06-21', '2026-09-21', null], 2 => ['Undated', null, null, null]],
			[2 => [1]],
		);

		$result = $service->simulate($this->project(), [['type' => 'delay', 'taskId' => 1, 'days' => 21]]);

		$undated = $this->task($result, 2);
		$this->assertSame(21, $undated['startShiftDays']);
		$this->assertSame(21, $undated['endShiftDays']);
		$this->assertSame(0, $undated['durationChangeDays']);
	}

	public function testSuggestionsAreRealFixesCheckedByTheEngine(): void
	{
		$result = $this->service()->suggestFixes($this->project(), [
			['type' => 'delay', 'taskId' => 1, 'days' => 14],
		]);

		$this->assertSame(14, $result['slipDays']);
		$this->assertSame([
			'Shorten "Permits" by 14 days',
			'Start "Preparation" 14 days before "Permits" ends',
			'Cut preparation to 0 weeks',
			'Shorten "Preparation" by 5 days',
			'Shorten "Earthworks" by 5 days',
		], array_column($result['suggestions'], 'title'));

		$first = $result['suggestions'][0];
		$this->assertTrue($first['recoversFully']);
		$this->assertSame(14, $first['recoveredDays']);
		$this->assertSame('2026-03-06', $first['minimumStartDate']);
		$this->assertSame([['type' => 'delay', 'taskId' => 1, 'days' => -14]], $first['changes']);

		// Earthworks can only lose half its 10 days.
		$this->assertFalse($result['suggestions'][4]['recoversFully']);
		$this->assertSame(9, $result['suggestions'][4]['remainingSlipDays']);
	}

	public function testSuggestionsCombineFixesWhenNoSingleFixIsEnough(): void
	{
		$result = $this->service()->suggestFixes($this->project(), [
			['type' => 'planning', 'requiredPreparationWeeks' => 0],
			['type' => 'delay', 'taskId' => 4, 'days' => 60],
		]);

		$this->assertSame(46, $result['slipDays']);
		$combined = $result['suggestions'][0];
		$this->assertSame('combined', $combined['kind']);
		$this->assertTrue($combined['recoversFully']);
		$this->assertSame('Shorten "Earthworks" by 35 days + Shorten "Permits" by 11 days', $combined['title']);
		$this->assertSame('Shorten "Earthworks" by 35 days', $result['suggestions'][1]['title']);
		$this->assertFalse($result['suggestions'][1]['recoversFully']);
	}

	public function testNoSuggestionsWhenNothingSlips(): void
	{
		$result = $this->service()->suggestFixes($this->project(), [
			['type' => 'delay', 'taskId' => 2, 'days' => 10],
		]);

		$this->assertSame(0, $result['slipDays']);
		$this->assertSame([], $result['suggestions']);
	}

	/** @dataProvider invalidChanges */
	public function testInvalidChangesAreRejected(array $changes, string $message): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service()->simulate($this->project(), $changes);
	}

	public static function invalidChanges(): array
	{
		return [
			'unknown task' => [[['type' => 'delay', 'taskId' => 99, 'days' => 3]], 'unknown timeline task'],
			'completed card' => [[['type' => 'delay', 'taskId' => 5, 'days' => 3]], 'already completed'],
			'zero delay' => [[['type' => 'delay', 'taskId' => 1, 'days' => 0]], 'changes nothing'],
			'fractional days' => [[['type' => 'delay', 'taskId' => 1, 'days' => 1.5]], 'whole number'],
			'zero duration' => [[['type' => 'duration', 'taskId' => 1, 'days' => 0]], 'whole number'],
			'bad date' => [[['type' => 'endDate', 'taskId' => 1, 'date' => '2026-02-30']], 'YYYY-MM-DD'],
			'not a dependency' => [[['type' => 'overlap', 'predecessorId' => 2, 'successorId' => 3, 'days' => 2]], 'does not depend on'],
			'empty planning' => [[['type' => 'planning']], 'needs a desired start date'],
			'unknown type' => [[['type' => 'teleport', 'taskId' => 1]], 'unknown change type'],
			'not a list' => [['a' => ['type' => 'delay']], 'must be a list'],
		];
	}

	private function service(array $fixtureCards = self::CARDS, array $fixtureDependencies = self::DEPENDENCIES): TimelineScenarioService
	{
		$engine = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);
		$cards = [];
		foreach ($fixtureCards as $id => [$title, $start, $end, $done]) {
			$cards[$id] = [
				'id' => $id,
				'title' => $title,
				'startdate' => $start === null ? null : new DateTime($start),
				'duedate' => $end === null ? null : new DateTime($end),
				'done' => $done === null ? null : new DateTime($done),
			];
		}

		$phaseService = $this->createMock(TimelinePhaseService::class);
		$phaseService->method('getProjectPhaseHierarchy')->willReturnCallback(
			function (Project $project, array $overrides = []) use ($engine, $cards, $fixtureCards, $fixtureDependencies): array {
				$schedules = $engine->calculateDeckCardSchedules(
					$cards,
					$fixtureDependencies,
					new DateTime(min(array_filter(array_column($fixtureCards, 1)))),
					[],
					$overrides['taskOverrides'] ?? [],
					$overrides['dependencyOverlaps'] ?? [],
				);
				$tasks = [];
				$dependencies = [];
				foreach ($schedules as $id => $schedule) {
					$tasks[] = [
						'id' => $id,
						'deckCardId' => $id,
						'label' => $cards[$id]['title'],
						'startDate' => $schedule['start']->format('Y-m-d'),
						'endDate' => $schedule['end']->format('Y-m-d'),
						'durationDays' => $schedule['durationDays'],
						'isDone' => $schedule['isDone'],
						'predecessorIds' => $schedule['predecessors'],
					];
					foreach ($schedule['predecessors'] as $predecessorId) {
						$dependencies[] = [
							'predecessorId' => $predecessorId,
							'successorId' => $id,
							'type' => 'FS',
							'overlapDays' => $schedule['overlaps'][$predecessorId] ?? 0,
						];
					}
				}
				$end = max(array_column($tasks, 'endDate'));
				return [
					'phases' => [[
						'category' => 'initiation',
						'milestone' => ['label' => 'Initiation ready', 'date' => $end],
						'tasks' => $tasks,
					]],
					'dependencies' => $dependencies,
				];
			},
		);

		// Mirrors TimelinePlanningService: minimum start = Initiation end + preparation weeks.
		$planningService = $this->createMock(TimelinePlanningService::class);
		$planningService->method('buildSummary')->willReturnCallback(
			function (Project $project, ?array $phases): array {
				$end = new DateTime($phases[0]['milestone']['date']);
				$weeks = (int)$project->getRequiredPreparationWeeks();
				$minimum = (clone $end)->modify('+' . (7 * $weeks) . ' days');
				$desired = $project->getDesiredStartDate();
				$float = null;
				if ($desired !== null) {
					$days = (int)$minimum->diff($desired)->days;
					$float = $desired < $minimum ? -$days : $days;
				}
				return [
					'minimumStartDate' => $minimum->format('Y-m-d'),
					'desiredStartDate' => $desired?->format('Y-m-d'),
					'requiredPreparationWeeks' => $weeks,
					'overallFloatDays' => $float,
				];
			},
		);

		return new TimelineScenarioService($phaseService, $planningService, new TimelineCriticalPath());
	}

	private function project(): Project
	{
		$project = new Project();
		$project->setId(7);
		$project->setType(0);
		$project->setDesiredStartDate(new DateTime('2026-03-14'));
		$project->setRequiredPreparationWeeks(2);
		return $project;
	}

	private function task(array $result, int $id): array
	{
		foreach ($result['phases'] as $phase) {
			foreach ($phase['tasks'] as $task) {
				if ($task['id'] === $id) {
					return $task['whatIf'];
				}
			}
		}
		$this->fail("Task {$id} missing from the scenario");
	}
}
