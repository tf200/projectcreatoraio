<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateInterval;
use DateTime;
use DateTimeInterface;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\Stack;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\StackService;
use OCA\ProjectCreatorAIO\Service\DeckDefaultCardsService;
use OCA\ProjectCreatorAIO\Service\ProjectTypeDeckDefaults;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DeckDefaultCardsServiceTest extends TestCase {
	public function testSeedForProjectTypeAssignsThreeMonthDeadlinesToDefaultCards(): void {
		$processStepsStack = new Stack();
		$processStepsStack->setId(101);
		$processStepsStack->setOrder(0);

		$nextPriorityStack = new Stack();
		$nextPriorityStack->setId(102);
		$nextPriorityStack->setOrder(1);

		$importantLabel = new Label();
		$importantLabel->setId(8);
		$importantLabel->setTitle('Kritieke Processtap');

		$board = new Board();
		$board->setId(15);
		$board->setStacks([$processStepsStack, $nextPriorityStack]);
		$board->setLabels([$importantLabel]);

		$owner = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'owner1',
		]);

		$expectedCardCount = count(ProjectTypeDeckDefaults::getNextPriorityCards(ProjectTypeDeckDefaults::TYPE_COMBI))
			+ count(ProjectTypeDeckDefaults::getProcessStepCards(ProjectTypeDeckDefaults::TYPE_COMBI));

		$lowerBound = (new DateTime())->add(new DateInterval('P3M'))->modify('-5 seconds');
		$capturedDueDates = [];
		$nextCardId = 1000;

		$cardService = $this->createMock(CardService::class);
		$cardService->expects($this->exactly($expectedCardCount))
			->method('create')
			->willReturnCallback(function (
				string $title,
				int $stackId,
				string $type,
				int $order,
				string $ownerUid,
				string $description,
				$dueDate
			) use (&$capturedDueDates, &$nextCardId): Card {
				$capturedDueDates[] = $dueDate;

				$card = new Card();
				$card->setId($nextCardId++);

				return $card;
			});

		$boardService = $this->createMock(BoardService::class);
		$boardService->expects($this->once())
			->method('find')
			->with(15, true)
			->willReturn($board);

		$service = new DeckDefaultCardsService(
			$cardService,
			$this->createMock(LabelService::class),
			$this->createMock(StackService::class),
			$boardService,
			$this->createMock(LoggerInterface::class),
		);

		$service->seedForProjectType(ProjectTypeDeckDefaults::TYPE_COMBI, $board, $owner);

		$upperBound = (new DateTime())->add(new DateInterval('P3M'))->modify('+5 seconds');

		$this->assertCount($expectedCardCount, $capturedDueDates);
		foreach ($capturedDueDates as $dueDate) {
			$this->assertInstanceOf(DateTimeInterface::class, $dueDate);
			$this->assertGreaterThanOrEqual($lowerBound->getTimestamp(), $dueDate->getTimestamp());
			$this->assertLessThanOrEqual($upperBound->getTimestamp(), $dueDate->getTimestamp());
		}
	}
}
