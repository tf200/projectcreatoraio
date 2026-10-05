<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\Deck\Db\Card;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItem;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\TimelinePhaseMapper;
use OCA\ProjectCreatorAIO\Service\DeckCardScheduleService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\TimelineCriticalPath;
use OCA\ProjectCreatorAIO\Service\TimelinePhaseService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCA\ProjectCreatorAIO\Service\TimelineScenarioApplyService;
use OCA\ProjectCreatorAIO\Service\TimelineScenarioConflictException;
use OCA\ProjectCreatorAIO\Service\TimelineScenarioService;
use OCP\IDBConnection;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

final class TimelineScenarioApplyServiceTest extends TestCase
{
	/** Permits (1) -> Preparation (2) -> Earthworks (3). */
	private const DEPENDENCIES = [2 => [1], 3 => [2]];

	/** Deck cards and saved overrides as the fake storage holds them. */
	private array $cards = [];
	private array $savedItems = [];
	private array $calls = [];

	protected function setUp(): void
	{
		$this->cards = [
			1 => $this->card(1, 'Permits', '2026-01-01', '2026-01-31'),
			2 => $this->card(2, 'Preparation', '2026-02-01', '2026-02-10'),
			3 => $this->card(3, 'Earthworks', '2026-02-11', '2026-02-20'),
		];
		$this->savedItems = [];
		$this->calls = [];
	}

	public function testAppliedScenarioReloadsExactlyAsSimulated(): void
	{
		$changes = [
			['type' => 'delay', 'taskId' => 1, 'days' => 14],
			['type' => 'overlap', 'predecessorId' => 1, 'successorId' => 2, 'days' => 5],
			['type' => 'startNotBefore', 'taskId' => 3, 'date' => '2026-03-01'],
		];
		[$scenarioService, $applyService, $project] = $this->services();
		$preview = $scenarioService->simulate($project, $changes);

		$result = $applyService->apply($project, $changes, $preview['impact']['deckCardUpdates'], $this->createMock(IUser::class));

		$this->assertSame([], $result['mismatches']);
		$this->assertSame(3, $result['deckCardsUpdated']);
		$this->assertSame('2026-02-14', $this->cards[1]['duedate']->format('Y-m-d'));
		$this->assertSame('2026-02-10', $this->cards[2]['startdate']->format('Y-m-d'));
		$this->assertSame('2026-03-01', $this->cards[3]['startdate']->format('Y-m-d'));
		$this->assertSame(['1>2' => 5], $this->savedOverlaps());
		$this->assertSame(['begin', 'commit', 'activity'], $this->calls);

		// Once applied, the same plan simulates with nothing left to move.
		$this->assertSame(0, $scenarioService->simulate($project, [])['impact']['movedTaskCount']);
	}

	public function testRemovingAnOverlapDeletesTheSavedOne(): void
	{
		[$scenarioService, $applyService, $project] = $this->services();
		$applyService->apply($project, [['type' => 'overlap', 'predecessorId' => 1, 'successorId' => 2, 'days' => 5]], null, null);
		$this->assertSame(['1>2' => 5], $this->savedOverlaps());

		$applyService->apply($project, [['type' => 'overlap', 'predecessorId' => 1, 'successorId' => 2, 'days' => 0]], null, null);

		$this->assertSame([], $this->savedOverlaps());
		$this->assertSame('2026-02-01', $this->cards[2]['startdate']->format('Y-m-d'));
	}

	public function testPlanningChangesAreSavedOnTheProject(): void
	{
		[, $applyService, $project] = $this->services();

		$result = $applyService->apply($project, [['type' => 'planning', 'requiredPreparationWeeks' => 4, 'desiredStartDate' => '2026-05-01']], null, null);

		$this->assertTrue($result['planningChanged']);
		$this->assertSame(4, $project->getRequiredPreparationWeeks());
		$this->assertSame('2026-05-01', $project->getDesiredStartDate()->format('Y-m-d'));
		$this->assertContains('project', $this->calls);
	}

	public function testRefusesWhenThePlanMovedSinceThePreview(): void
	{
		[$scenarioService, $applyService, $project] = $this->services();
		$changes = [['type' => 'delay', 'taskId' => 1, 'days' => 14]];
		$preview = $scenarioService->simulate($project, $changes);

		// Someone moves Permits in Deck before the scenario is applied.
		$this->cards[1]['duedate'] = new DateTime('2026-02-05');

		try {
			$applyService->apply($project, $changes, $preview['impact']['deckCardUpdates'], null);
			$this->fail('Expected the outdated scenario to be refused');
		} catch (TimelineScenarioConflictException $e) {
			$this->assertStringContainsString('plan changed', $e->getMessage());
		}
		$this->assertSame([], $this->calls);
	}

	public function testRollsBackWhenAWriteFails(): void
	{
		[, $applyService, $project] = $this->services(failOnCard: 2);

		try {
			$applyService->apply($project, [['type' => 'delay', 'taskId' => 1, 'days' => 14]], null, null);
			$this->fail('Expected the failing Deck write to surface');
		} catch (\RuntimeException $e) {
			$this->assertSame('Deck write failed', $e->getMessage());
		}
		$this->assertSame(['begin', 'rollback'], $this->calls);
	}

	public function testRejectsAnEmptyScenario(): void
	{
		[, $applyService, $project] = $this->services();

		$this->expectException(\InvalidArgumentException::class);
		$applyService->apply($project, [], null, null);
	}

	/** @return array{TimelineScenarioService, TimelineScenarioApplyService, Project} */
	private function services(?int $failOnCard = null): array
	{
		$engine = new TimelinePhaseService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimelinePhaseMapper::class),
			$this->createMock(TimelineItemMapper::class),
		);

		$phaseService = $this->createMock(TimelinePhaseService::class);
		$phaseService->method('getProjectPhaseHierarchy')->willReturnCallback(function (Project $project, array $overrides = []) use ($engine): array {
			$startLimits = [];
			foreach ($this->savedItems as $item) {
				if (str_starts_with((string)$item->getSystemKey(), TimelinePhaseService::START_LIMIT_KEY_PREFIX)) {
					$startLimits[$item->getLabel()] = ['startNotBefore' => $item->getStartDate()->format('Y-m-d')];
				}
			}
			$schedules = $engine->calculateDeckCardSchedules(
				$this->cards,
				self::DEPENDENCIES,
				new DateTime('2026-01-01'),
				$startLimits,
				$overrides['taskOverrides'] ?? [],
				array_replace($this->savedOverlaps(), $overrides['dependencyOverlaps'] ?? []),
			);
			$tasks = [];
			$dependencies = [];
			foreach ($schedules as $id => $schedule) {
				$tasks[] = [
					'id' => $id,
					'deckCardId' => $id,
					'label' => $this->cards[$id]['title'],
					'startDate' => $schedule['start']->format('Y-m-d'),
					'endDate' => $schedule['end']->format('Y-m-d'),
					'durationDays' => $schedule['durationDays'],
					'isDone' => $schedule['isDone'],
					'predecessorIds' => $schedule['predecessors'],
				];
				foreach ($schedule['predecessors'] as $predecessorId) {
					$dependencies[] = ['predecessorId' => $predecessorId, 'successorId' => $id, 'type' => 'FS', 'overlapDays' => $schedule['overlaps'][$predecessorId] ?? 0];
				}
			}
			return [
				'phases' => [['category' => 'initiation', 'milestone' => ['label' => 'Initiation ready', 'date' => max(array_column($tasks, 'endDate'))], 'tasks' => $tasks]],
				'dependencies' => $dependencies,
			];
		});

		$planningService = $this->createMock(TimelinePlanningService::class);
		$planningService->method('buildSummary')->willReturnCallback(static function (Project $project, ?array $phases): array {
			$weeks = (int)$project->getRequiredPreparationWeeks();
			return [
				'minimumStartDate' => (new DateTime($phases[0]['milestone']['date']))->modify('+' . (7 * $weeks) . ' days')->format('Y-m-d'),
				'desiredStartDate' => $project->getDesiredStartDate()?->format('Y-m-d'),
				'requiredPreparationWeeks' => $weeks,
				'overallFloatDays' => null,
			];
		});

		$deck = $this->createMock(DeckCardScheduleService::class);
		$deck->method('updateCardSchedule')->willReturnCallback(function (Project $project, int $cardId, DateTime $start, DateTime $end) use ($failOnCard): Card {
			if ($cardId === $failOnCard) {
				throw new \RuntimeException('Deck write failed');
			}
			$this->cards[$cardId]['startdate'] = $start;
			$this->cards[$cardId]['duedate'] = $end;
			return new Card();
		});

		$itemMapper = $this->createMock(TimelineItemMapper::class);
		$itemMapper->method('findByProjectAndSystemKey')->willReturnCallback(fn (int $projectId, string $key): ?TimelineItem => $this->savedItems[$key] ?? null);
		$itemMapper->method('createItem')->willReturnCallback(function (int $projectId, string $label, ?string $start, ?string $end, string $color, int $order, ?string $key): TimelineItem {
			$item = new TimelineItem();
			$item->setLabel($label);
			$item->setSystemKey($key);
			return $this->savedItems[$key] = $item;
		});
		$itemMapper->method('updateItem')->willReturnArgument(0);
		$itemMapper->method('delete')->willReturnCallback(function (TimelineItem $item): TimelineItem {
			unset($this->savedItems[$item->getSystemKey()]);
			return $item;
		});

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('updateProjectDetails')->willReturnCallback(function (Project $project): Project {
			$this->calls[] = 'project';
			return $project;
		});

		$activity = $this->createMock(ProjectActivityService::class);
		$activity->method('record')->willReturnCallback(function (): void {
			$this->calls[] = 'activity';
		});

		$db = $this->createMock(IDBConnection::class);
		$db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->calls[] = 'begin';
		});
		$db->method('commit')->willReturnCallback(function (): void {
			$this->calls[] = 'commit';
		});
		$db->method('rollBack')->willReturnCallback(function (): void {
			$this->calls[] = 'rollback';
		});

		$scenarioService = new TimelineScenarioService($phaseService, $planningService, new TimelineCriticalPath());
		$applyService = new TimelineScenarioApplyService($scenarioService, $phaseService, $deck, $itemMapper, $projectMapper, $activity, $db);

		$project = new Project();
		$project->setId(7);
		$project->setType(0);
		$project->setRequiredPreparationWeeks(2);

		return [$scenarioService, $applyService, $project];
	}

	/** @return array<string, int> */
	private function savedOverlaps(): array
	{
		$overlaps = [];
		foreach ($this->savedItems as $item) {
			if (str_starts_with((string)$item->getSystemKey(), TimelinePhaseService::OVERLAP_KEY_PREFIX)) {
				$overlaps[$item->getLabel()] = (int)$item->getDurationDays();
			}
		}
		return $overlaps;
	}

	private function card(int $id, string $title, string $start, string $end): array
	{
		return ['id' => $id, 'title' => $title, 'startdate' => new DateTime($start), 'duedate' => new DateTime($end), 'done' => null];
	}
}
