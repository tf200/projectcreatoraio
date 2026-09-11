<?php

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\Deck\Db\Card;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Model\OptionalNullableValue;
use OCA\Deck\Service\CardService;
use OCA\ProjectCreatorAIO\Db\Project;

class DeckCardScheduleService
{
	public function __construct(
		private readonly ?CardMapper $cardMapper,
		private readonly ?CardService $cardService,
	) {
	}

	public function updateCardSchedule(Project $project, int $cardId, DateTime $startDate, DateTime $endDate): Card
	{
		if ($endDate < $startDate) {
			throw new \InvalidArgumentException('End date cannot be before start date');
		}

		$card = $this->assertCardCanBeRescheduled($project, $cardId);
		if ($this->cardService === null) {
			throw new \RuntimeException('Deck is not available');
		}

		return $this->cardService->update(
			$cardId,
			(string)$card->getTitle(),
			(int)$card->getStackId(),
			(string)$card->getType(),
			(string)$card->getOwner(),
			(string)($card->getDescription() ?? ''),
			(int)$card->getOrder(),
			$endDate->format(DateTime::ATOM),
			(int)$card->getDeletedAt(),
			(bool)$card->getArchived(),
			new OptionalNullableValue($card->getDone()),
			$startDate->format(DateTime::ATOM),
			$card->getColor(),
		);
	}

	public function assertCardCanBeRescheduled(Project $project, int $cardId): Card
	{
		if ($this->cardMapper === null) {
			throw new \RuntimeException('Deck is not available');
		}

		$boardId = (int)($project->getBoardId() ?? 0);
		if ($boardId <= 0 || $this->cardMapper->findBoardId($cardId) !== $boardId) {
			throw new \InvalidArgumentException('Deck card does not belong to this project');
		}

		$card = $this->cardMapper->find($cardId);
		if ($card->getDeletedAt() !== 0 || $card->getArchived()) {
			throw new \InvalidArgumentException('Deleted or archived Deck cards cannot be rescheduled');
		}
		if ($card->getDone() instanceof DateTime) {
			throw new \InvalidArgumentException('Completed Deck cards cannot be rescheduled');
		}

		return $card;
	}

	/**
	 * @param array<int, array<string, mixed>> $baselinePhases
	 * @param array<int, array<string, mixed>> $targetPhases
	 */
	public function syncChangedSchedules(Project $project, array $baselinePhases, array $targetPhases): int
	{
		$baselineTasks = $this->indexTasks($baselinePhases);
		$changedTasks = [];
		foreach ($this->indexTasks($targetPhases) as $taskId => $task) {
			$baseline = $baselineTasks[$taskId] ?? null;
			if ($baseline === null || empty($task['deckCardId'])) {
				continue;
			}
			if ($task['startDate'] === $baseline['startDate'] && $task['endDate'] === $baseline['endDate']) {
				continue;
			}
			if (!empty($baseline['isDone'])) {
				throw new \InvalidArgumentException('Completed Deck cards cannot be rescheduled');
			}
			$changedTasks[] = $task;
		}

		foreach ($changedTasks as $task) {
			$this->updateCardSchedule(
				$project,
				(int)$task['deckCardId'],
				new DateTime((string)$task['startDate']),
				new DateTime((string)$task['endDate']),
			);
		}

		return count($changedTasks);
	}

	/** @param array<int, array<string, mixed>> $phases */
	private function indexTasks(array $phases): array
	{
		$tasks = [];
		foreach ($phases as $phase) {
			foreach ($phase['tasks'] ?? [] as $task) {
				$tasks[(string)$task['id']] = $task;
			}
		}
		return $tasks;
	}
}
