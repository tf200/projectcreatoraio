<?php

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

class TimelinePlanningService
{
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	public function buildSummary(Project $project): array
	{
		$createdAt = $project->getCreatedAt();
		$createdAt = $createdAt instanceof DateTime ? clone $createdAt : new DateTime('now');

		$requestDateDate = $this->firstMondayOnOrAfter($createdAt);
		$requestDate = $requestDateDate->format('Y-m-d');

		$projectType = (int) ($project->getType() ?? -1);

		$prepWeeks = $project->getRequiredPreparationWeeks();
		if ($prepWeeks === null) {
			$prepWeeks = ProjectTypeDeckDefaults::getDefaultPreparationWeeks($projectType);
		}
		$prepWeeks = max(0, (int) $prepWeeks);

		$enabledSets = CardVisibility::getEnabledSetsForProject($project);
		$requiredTitles = ProjectTypeDeckDefaults::getVisibleImportantTitles($projectType, $enabledSets);
		if ($requiredTitles === []) {
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return [
				'requestDate' => $requestDate,
				'requiredPreparationWeeks' => $prepWeeks,
				'processCompleted' => [
					'status' => 'not_configured',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => 0,
					'missingTitles' => [],
				],
				'earliestExecutionDate' => null,
				'coordinationPendingPeriod' => $pending,
			];
		}

		$appManager = \OC::$server->get(\OCP\App\IAppManager::class);
		if (!$appManager->isEnabledForUser('deck')) {
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return [
				'requestDate' => $requestDate,
				'requiredPreparationWeeks' => $prepWeeks,
				'processCompleted' => [
					'status' => 'not_configured',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => $requiredTitles,
				],
				'earliestExecutionDate' => null,
				'coordinationPendingPeriod' => $pending,
			];
		}

		$boardId = $this->parseIntOrZero($project->getBoardId());
		if ($boardId <= 0) {
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return [
				'requestDate' => $requestDate,
				'requiredPreparationWeeks' => $prepWeeks,
				'processCompleted' => [
					'status' => 'missing_cards',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => $requiredTitles,
				],
				'earliestExecutionDate' => null,
				'coordinationPendingPeriod' => $pending,
			];
		}

		try {
			$cardsByTitle = $this->loadRequiredCardsOnBoardByTitle($boardId, $requiredTitles);

			$missing = [];
			$doneCount = 0;
			$maxDone = null;

			foreach ($requiredTitles as $title) {
				if (!array_key_exists($title, $cardsByTitle)) {
					$missing[] = $title;
					continue;
				}

				$done = $cardsByTitle[$title]['done'] ?? null;
				if (!$done instanceof DateTime) {
					continue;
				}

				$doneCount++;
				if ($maxDone === null || $done > $maxDone) {
					$maxDone = $done;
				}
			}

			if ($missing !== []) {
				$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
				return [
					'requestDate' => $requestDate,
					'requiredPreparationWeeks' => $prepWeeks,
					'processCompleted' => [
						'status' => 'missing_cards',
						'date' => null,
						'doneCount' => $doneCount,
						'totalRequired' => count($requiredTitles),
						'missingTitles' => $missing,
					],
					'earliestExecutionDate' => null,
					'coordinationPendingPeriod' => $pending,
				];
			}

			if ($doneCount !== count($requiredTitles) || $maxDone === null) {
				$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
				return [
					'requestDate' => $requestDate,
					'requiredPreparationWeeks' => $prepWeeks,
					'processCompleted' => [
						'status' => 'incomplete',
						'date' => null,
						'doneCount' => $doneCount,
						'totalRequired' => count($requiredTitles),
						'missingTitles' => [],
					],
					'earliestExecutionDate' => null,
					'coordinationPendingPeriod' => $pending,
				];
			}

			$processDate = $maxDone->format('Y-m-d');

			$earliest = clone $maxDone;
			if ($prepWeeks > 0) {
				$earliest->modify('+' . (7 * $prepWeeks) . ' days');
			}

			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, $maxDone);
			return [
				'requestDate' => $requestDate,
				'requiredPreparationWeeks' => $prepWeeks,
				'processCompleted' => [
					'status' => 'complete',
					'date' => $processDate,
					'doneCount' => $doneCount,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => [],
				],
				'earliestExecutionDate' => $earliest->format('Y-m-d'),
				'coordinationPendingPeriod' => $pending,
			];
		} catch (Throwable $e) {
			$this->logger->error('Failed to compute timeline summary', [
				'exception' => $e,
				'projectId' => $project->getId(),
				'boardId' => $boardId,
			]);

			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return [
				'requestDate' => $requestDate,
				'requiredPreparationWeeks' => $prepWeeks,
				'processCompleted' => [
					'status' => 'error',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => [],
				],
				'earliestExecutionDate' => null,
				'coordinationPendingPeriod' => $pending,
			];
		}
	}

	/**
	 * @return array{startDate: string, endDate: string}|null
	 */
	public function getDeckDateBounds(Project $project): ?array
	{
		$boardId = $this->parseIntOrZero($project->getBoardId());
		if ($boardId <= 0) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->min('c.startdate'), 'earliest_start')
			->selectAlias($qb->func()->max('c.duedate'), 'latest_end')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		$start = $this->parseCardDate($row['earliest_start'] ?? null);
		$end = $this->parseCardDate($row['latest_end'] ?? null);
		if ($start === null || $end === null || $end < $start) {
			return null;
		}

		return [
			'startDate' => $start->format('Y-m-d'),
			'endDate' => $end->format('Y-m-d'),
		];
	}

	private function firstMondayOnOrAfter(DateTime $date): DateTime
	{
		$out = clone $date;
		$out->setTime(0, 0, 0);
		$day = (int) $out->format('N'); // 1=Mon .. 7=Sun
		$daysUntil = (8 - $day) % 7;
		if ($daysUntil > 0) {
			$out->modify('+' . $daysUntil . ' days');
		}
		return $out;
	}

	private function parseIntOrZero(?string $value): int
	{
		$value = (string) ($value ?? '');
		$value = trim($value);
		if ($value === '' || !ctype_digit($value)) {
			return 0;
		}
		return (int) $value;
	}

	private function buildCoordinationPendingPeriod(DateTime $requestDate, ?DateTime $completedDate): array
	{
		$start = clone $requestDate;
		$start->setTime(0, 0, 0);

		$end = $completedDate instanceof DateTime ? clone $completedDate : new DateTime('now');
		$end->setTime(0, 0, 0);

		if ($end < $start) {
			$end = clone $start;
		}

		$days = (int) $start->diff($end)->days;
		$weeks = round($days / 7, 1);

		return [
			'weeks' => $weeks,
			'fromDate' => $start->format('Y-m-d'),
			'toDate' => $end->format('Y-m-d'),
			'isFinal' => $completedDate instanceof DateTime,
		];
	}

	/**
	 * @param string[] $requiredTitles
	 * @return array<string, array{done: ?DateTime}>
	 */
	private function loadRequiredCardsOnBoardByTitle(int $boardId, array $requiredTitles): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.title', 'c.done', 'c.last_modified', 's.order')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('c.title', $qb->createNamedParameter($requiredTitles, IQueryBuilder::PARAM_STR_ARRAY)));

		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$title = (string) ($row['title'] ?? '');
			if ($title === '') {
				continue;
			}

			$done = $this->parseDoneDate($row);
			if (!array_key_exists($title, $out) || ($done instanceof DateTime && (!($out[$title]['done'] ?? null) instanceof DateTime || $done > $out[$title]['done']))) {
				$out[$title] = ['done' => $done];
			}
		}
		$result->closeCursor();

		return $out;
	}

	private function parseDoneDate(array $row): ?DateTime
	{
		$doneRaw = $row['done'] ?? null;
		if (is_string($doneRaw) && trim($doneRaw) !== '') {
			try {
				$done = new DateTime($doneRaw);
				$done->setTime(0, 0, 0);
				return $done;
			} catch (Throwable $e) {
				// ignore
			}
		}

		return null;
	}

	private function parseCardDate(mixed $value): ?DateTime
	{
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			$date = new DateTime($value);
			$date->setTime(0, 0, 0);
			return $date;
		} catch (Throwable) {
			return null;
		}
	}
}
