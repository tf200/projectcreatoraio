<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\Service\CardService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Service\DeckCardScheduleService;
use PHPUnit\Framework\TestCase;

final class DeckCardScheduleServiceTest extends TestCase
{
	public function testUpdateCardSchedulePreservesCardFieldsAndUpdatesDeckDates(): void
	{
		$project = $this->createProject(42);
		$card = $this->createCard();
		$updatedCard = clone $card;
		$updatedCard->setStartdate(new DateTime('2026-10-01'));
		$updatedCard->setDuedate(new DateTime('2026-10-14'));

		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('findBoardId')->with(7)->willReturn(42);
		$cardMapper->method('find')->with(7)->willReturn($card);
		$cardService = $this->createMock(CardService::class);
		$cardService->expects($this->once())
			->method('update')
			->with(
				7,
				'VO',
				9,
				'plain',
				'owner',
				'Description',
				123,
				'2026-10-14T00:00:00+00:00',
				0,
				false,
				$this->isInstanceOf(OptionalNullableValue::class),
				'2026-10-01T00:00:00+00:00',
				'0082c9',
			)
			->willReturn($updatedCard);

		$service = new DeckCardScheduleService($cardMapper, $cardService);
		$result = $service->updateCardSchedule($project, 7, new DateTime('2026-10-01 UTC'), new DateTime('2026-10-14 UTC'));

		$this->assertSame($updatedCard, $result);
	}

	public function testUpdateCardScheduleRejectsCardFromAnotherBoard(): void
	{
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('findBoardId')->with(7)->willReturn(99);
		$cardService = $this->createMock(CardService::class);
		$cardService->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('does not belong');
		(new DeckCardScheduleService($cardMapper, $cardService))->updateCardSchedule(
			$this->createProject(42),
			7,
			new DateTime('2026-10-01'),
			new DateTime('2026-10-14'),
		);
	}

	public function testUpdateCardScheduleRejectsCompletedCard(): void
	{
		$card = $this->createCard();
		$card->setDone(new DateTime('2026-09-30'));
		$cardMapper = $this->createMock(CardMapper::class);
		$cardMapper->method('findBoardId')->willReturn(42);
		$cardMapper->method('find')->willReturn($card);
		$cardService = $this->createMock(CardService::class);
		$cardService->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Completed');
		(new DeckCardScheduleService($cardMapper, $cardService))->updateCardSchedule(
			$this->createProject(42),
			7,
			new DateTime('2026-10-01'),
			new DateTime('2026-10-14'),
		);
	}

	public function testSyncChangedSchedulesUpdatesEveryChangedDeckTask(): void
	{
		$service = $this->getMockBuilder(DeckCardScheduleService::class)
			->setConstructorArgs([null, null])
			->onlyMethods(['updateCardSchedule'])
			->getMock();
		$service->expects($this->exactly(2))->method('updateCardSchedule');
		$baseline = [[
			'tasks' => [
				['id' => 7, 'deckCardId' => 7, 'startDate' => '2026-10-01', 'endDate' => '2026-10-14', 'isDone' => false],
				['id' => 8, 'deckCardId' => 8, 'startDate' => '2026-10-15', 'endDate' => '2026-10-28', 'isDone' => false],
			],
		]];
		$target = [[
			'tasks' => [
				['id' => 7, 'deckCardId' => 7, 'startDate' => '2026-10-08', 'endDate' => '2026-10-21', 'isDone' => false],
				['id' => 8, 'deckCardId' => 8, 'startDate' => '2026-10-22', 'endDate' => '2026-11-04', 'isDone' => false],
			],
		]];

		$this->assertSame(2, $service->syncChangedSchedules($this->createProject(42), $baseline, $target));
	}

	public function testSyncCalculatedSchedulesUpdatesOnlyDatesThatDifferFromDeck(): void
	{
		$current = $this->createCard();
		$current->setStartdate(new DateTime('2026-01-01'));
		$current->setDuedate(new DateTime('2026-04-01'));
		$changed = clone $current;
		$changed->setId(8);

		$service = $this->getMockBuilder(DeckCardScheduleService::class)
			->setConstructorArgs([null, null])
			->onlyMethods(['assertCardCanBeRescheduled', 'updateCardSchedule'])
			->getMock();
		$service->method('assertCardCanBeRescheduled')
			->willReturnCallback(static fn (Project $project, int $cardId): Card => $cardId === 7 ? $current : $changed);
		$service->expects($this->once())
			->method('updateCardSchedule')
			->with(
				$this->isInstanceOf(Project::class),
				8,
				$this->callback(static fn (DateTime $date): bool => $date->format('Y-m-d') === '2026-04-02'),
				$this->callback(static fn (DateTime $date): bool => $date->format('Y-m-d') === '2026-07-02'),
			);

		$phases = [[
			'tasks' => [
				['id' => 7, 'deckCardId' => 7, 'startDate' => '2026-01-01', 'endDate' => '2026-04-01', 'isDone' => false],
				['id' => 8, 'deckCardId' => 8, 'startDate' => '2026-04-02', 'endDate' => '2026-07-02', 'isDone' => false],
			],
		]];

		$this->assertSame(1, $service->syncCalculatedSchedules($this->createProject(42), $phases));
	}

	private function createProject(int $boardId): Project
	{
		$project = new Project();
		$project->setId(5);
		$project->setBoardId($boardId);
		return $project;
	}

	private function createCard(): Card
	{
		$card = new Card();
		$card->setId(7);
		$card->setTitle('VO');
		$card->setStackId(9);
		$card->setType('plain');
		$card->setOwner('owner');
		$card->setDescription('Description');
		$card->setOrder(123);
		$card->setDeletedAt(0);
		$card->setArchived(false);
		$card->setDone(null);
		$card->setColor('0082c9');
		return $card;
	}
}
