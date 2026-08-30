<?php

namespace OCA\ProjectCreatorAIO\Service;

use DateInterval;
use DateTime;
use OCA\Deck\Db\Board;
use OCA\Deck\Db\Label;
use OCA\Deck\Db\Stack;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\StackService;
use OCP\IUser;
use Psr\Log\LoggerInterface;
use Throwable;

class DeckDefaultCardsService
{
	private const IMPORTANT_LABEL_TITLE = 'Kritieke Processtap';
	private const LEGACY_IMPORTANT_LABEL_TITLES = ['Belangrijk'];
	private const IMPORTANT_LABEL_COLOR = 'FF0000';
	private const DEFAULT_CARD_DEADLINE_INTERVAL = 'P3M';

	public function __construct(
		private readonly CardService $cardService,
		private readonly LabelService $labelService,
		private readonly StackService $stackService,
		private readonly BoardService $boardService,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @return array<string, \OCA\Deck\Db\Card> */
	public function seedForProjectType(int $projectType, Board $board, IUser $owner): array
	{
		$nextPriorityCards = ProjectTypeDeckDefaults::getNextPriorityCards($projectType);
		$processStepCards = ProjectTypeDeckDefaults::getProcessStepCards($projectType);

		if ($nextPriorityCards === [] && $processStepCards === []) {
			return [];
		}

		try {
			$boardId = (int)$board->getId();
			if ($boardId <= 0) {
				return [];
			}

			$board = $this->boardService->find($boardId, true);

			$stacks = $this->getBoardStacks($board, $owner);
			$processStepsStack = $this->findStackByOrder($stacks, 0);
			$nextPriorityStack = $this->findStackByOrder($stacks, 1);

			if ($processStepsStack === null || $nextPriorityStack === null) {
				$this->logger->warning('Deck default card seeding skipped: missing default stacks', [
					'boardId' => $boardId,
					'hasProcessSteps' => $processStepsStack !== null,
					'hasNextPriority' => $nextPriorityStack !== null,
				]);
				return [];
			}

			$importantLabelId = $this->ensureImportantLabelId($boardId, $board, $owner);

			$seededCards = $this->seedCardsIntoStack(
				$boardId,
				$nextPriorityStack,
				$nextPriorityCards,
				$owner->getUID(),
				$importantLabelId,
			);

			$seededCards += $this->seedCardsIntoStack(
				$boardId,
				$processStepsStack,
				$processStepCards,
				$owner->getUID(),
				$importantLabelId,
			);

			return $seededCards;
		} catch (Throwable $e) {
			$this->logger->error('Deck default card seeding failed', [
				'exception' => $e,
				'boardId' => $board->getId(),
				'projectType' => $projectType,
			]);
			throw $e;
		}
	}

	/**
	 * @return Stack[]
	 */
	private function getBoardStacks(Board $board, IUser $owner): array
	{
		$stacks = $board->getStacks() ?? [];
		if (!empty($stacks)) {
			return $stacks;
		}

		return $this->stackService->findAll((int)$board->getId());
	}

	/**
	 * @param Stack[] $stacks
	 */
	private function findStackByOrder(array $stacks, int $order): ?Stack
	{
		foreach ($stacks as $stack) {
			if ((int)$stack->getOrder() === $order) {
				return $stack;
			}
		}
		return null;
	}

	private function ensureImportantLabelId(int $boardId, Board $board, IUser $owner): ?int
	{
		$label = $this->findLabelByTitle($board, self::IMPORTANT_LABEL_TITLE);
		if ($label !== null) {
			return (int) $label->getId();
		}

		foreach (self::LEGACY_IMPORTANT_LABEL_TITLES as $legacyTitle) {
			$legacyLabel = $this->findLabelByTitle($board, $legacyTitle);
			if ($legacyLabel === null) {
				continue;
			}

			try {
				$updatedLabel = $this->labelService->update(
					(int) $legacyLabel->getId(),
					self::IMPORTANT_LABEL_TITLE,
					self::IMPORTANT_LABEL_COLOR
				);
				return (int) $updatedLabel->getId();
			} catch (Throwable $e) {
				$this->logger->warning('Unable to rename legacy important label; using existing label title', [
					'boardId' => $boardId,
					'legacyTitle' => $legacyTitle,
					'exception' => $e,
				]);
				return (int) $legacyLabel->getId();
			}
		}

		try {
			/** @var Label $label */
			$label = $this->labelService->create(self::IMPORTANT_LABEL_TITLE, self::IMPORTANT_LABEL_COLOR, $boardId);
			return (int)$label->getId();
		} catch (Throwable $e) {
			try {
				$refreshedBoard = $this->boardService->find($boardId, true);
				$refreshedLabel = $this->findLabelByTitle($refreshedBoard, self::IMPORTANT_LABEL_TITLE);
				return $refreshedLabel !== null ? (int) $refreshedLabel->getId() : null;
			} catch (Throwable $e2) {
				$this->logger->warning('Unable to create/find important label; important cards will be unlabelled', [
					'boardId' => $boardId,
					'exception' => $e2,
				]);
				return null;
			}
		}
	}

	private function findLabelByTitle(Board $board, string $title): ?Label
	{
		$labels = $board->getLabels() ?? [];
		foreach ($labels as $label) {
			if ($label instanceof Label && $label->getTitle() === $title) {
				return $label;
			}
		}
		return null;
	}

	/**
	 * @param array<int, array{key: string, title: string, important: bool}> $cards
	 * @return array<string, \OCA\Deck\Db\Card>
	 */
	private function seedCardsIntoStack(int $boardId, Stack $stack, array $cards, string $ownerUid, ?int $importantLabelId): array
	{
		$seededCards = [];
		foreach ($cards as $index => $cardTemplate) {
			try {
				$card = $this->cardService->create(
					$cardTemplate['title'],
					(int)$stack->getId(),
					'plain',
					(int)$index,
					$ownerUid,
					'',
					$this->buildDefaultCardDeadline(),
					new DateTime(),
				);
			} catch (Throwable $e) {
				$this->logger->error('Deck default card seeding: unable to create card', [
					'exception' => $e,
					'stackId' => $stack->getId(),
					'stackOrder' => $stack->getOrder(),
					'title' => $cardTemplate['title'] ?? null,
				]);
				throw $e;
			}

			$seededCards[$cardTemplate['key']] = $card;
			if ($importantLabelId !== null && ($cardTemplate['important'] ?? false)) {
				try {
					$this->cardService->assignLabelForSystem((int)$card->getId(), $importantLabelId);
				} catch (Throwable $e) {
					$this->logger->warning('Deck default card seeding: unable to assign important label', [
						'exception' => $e,
						'cardId' => (int)$card->getId(),
						'title' => $cardTemplate['title'] ?? null,
					]);
				}
			}
		}

		return $seededCards;
	}

	private function buildDefaultCardDeadline(): DateTime
	{
		return (new DateTime())->add(new DateInterval(self::DEFAULT_CARD_DEADLINE_INTERVAL));
	}
}
