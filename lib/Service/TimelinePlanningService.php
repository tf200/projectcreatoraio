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
		$desiredStartDate = $project->getDesiredStartDate();

		if ($requiredTitles === []) {
			$deckTasksEndDate = (clone $requestDateDate)->modify('+13 weeks');
			$planningMetrics = $this->calculatePlanningMetrics(
				$requestDateDate,
				$deckTasksEndDate,
				$prepWeeks,
				$desiredStartDate,
				0,
				0,
				false,
			);
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return $this->assembleSummary(
				$requestDate,
				$prepWeeks,
				[
					'status' => 'not_configured',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => 0,
					'missingTitles' => [],
				],
				null,
				$pending,
				$planningMetrics,
			);
		}

		$appManager = \OC::$server->get(\OCP\App\IAppManager::class);
		if (!$appManager->isEnabledForUser('deck')) {
			$deckTasksEndDate = (clone $requestDateDate)->modify('+13 weeks');
			$planningMetrics = $this->calculatePlanningMetrics(
				$requestDateDate,
				$deckTasksEndDate,
				$prepWeeks,
				$desiredStartDate,
				0,
				count($requiredTitles),
				false,
			);
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return $this->assembleSummary(
				$requestDate,
				$prepWeeks,
				[
					'status' => 'not_configured',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => $requiredTitles,
				],
				null,
				$pending,
				$planningMetrics,
			);
		}

		$boardId = $this->parseIntOrZero($project->getBoardId());
		if ($boardId <= 0) {
			$deckTasksEndDate = (clone $requestDateDate)->modify('+13 weeks');
			$planningMetrics = $this->calculatePlanningMetrics(
				$requestDateDate,
				$deckTasksEndDate,
				$prepWeeks,
				$desiredStartDate,
				0,
				count($requiredTitles),
				false,
			);
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return $this->assembleSummary(
				$requestDate,
				$prepWeeks,
				[
					'status' => 'missing_cards',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => $requiredTitles,
				],
				null,
				$pending,
				$planningMetrics,
			);
		}

		try {
			$cardsByTitle = $this->loadRequiredCardsOnBoardByTitle($boardId, $requiredTitles);

			$missing = [];
			$doneCount = 0;
			$maxDone = null;
			$maxPlanned = null;
			$today = new DateTime('today');

			foreach ($requiredTitles as $title) {
				if (!array_key_exists($title, $cardsByTitle)) {
					$missing[] = $title;
					continue;
				}

				$card = $cardsByTitle[$title];
				$done = $card['done'] ?? null;
				if ($done instanceof DateTime) {
					$doneCount++;
					if ($maxDone === null || $done > $maxDone) {
						$maxDone = $done;
					}
					$effective = $done;
				} else {
					$due = $card['dueDate'] ?? null;
					if ($due instanceof DateTime) {
						$effective = $due < $today ? $today : $due;
					} else {
						$effective = (clone $requestDateDate)->modify('+13 weeks');
					}
				}

				if ($maxPlanned === null || $effective > $maxPlanned) {
					$maxPlanned = clone $effective;
				}
			}

			$isAllDone = ($doneCount === count($requiredTitles) && count($requiredTitles) > 0 && $missing === [] && $maxDone instanceof DateTime);
			$deckTasksEndDate = $isAllDone ? clone $maxDone : ($maxPlanned ?? (clone $requestDateDate)->modify('+13 weeks'));
			if ($deckTasksEndDate < $requestDateDate) {
				$deckTasksEndDate = (clone $requestDateDate)->modify('+1 day');
			}

			$planningMetrics = $this->calculatePlanningMetrics(
				$requestDateDate,
				$deckTasksEndDate,
				$prepWeeks,
				$desiredStartDate,
				$doneCount,
				count($requiredTitles),
				$isAllDone,
			);

			if ($missing !== []) {
				$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
				return $this->assembleSummary(
					$requestDate,
					$prepWeeks,
					[
						'status' => 'missing_cards',
						'date' => null,
						'doneCount' => $doneCount,
						'totalRequired' => count($requiredTitles),
						'missingTitles' => $missing,
					],
					null,
					$pending,
					$planningMetrics,
				);
			}

			if (!$isAllDone || $maxDone === null) {
				$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
				return $this->assembleSummary(
					$requestDate,
					$prepWeeks,
					[
						'status' => 'incomplete',
						'date' => null,
						'doneCount' => $doneCount,
						'totalRequired' => count($requiredTitles),
						'missingTitles' => [],
					],
					null,
					$pending,
					$planningMetrics,
				);
			}

			$processDate = $maxDone->format('Y-m-d');
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, $maxDone);
			return $this->assembleSummary(
				$requestDate,
				$prepWeeks,
				[
					'status' => 'complete',
					'date' => $processDate,
					'doneCount' => $doneCount,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => [],
				],
				$planningMetrics['minimumStartDate'],
				$pending,
				$planningMetrics,
			);
		} catch (Throwable $e) {
			$this->logger->error('Failed to compute timeline summary', [
				'exception' => $e,
				'projectId' => $project->getId(),
				'boardId' => $boardId,
			]);

			$deckTasksEndDate = (clone $requestDateDate)->modify('+13 weeks');
			$planningMetrics = $this->calculatePlanningMetrics(
				$requestDateDate,
				$deckTasksEndDate,
				$prepWeeks,
				$desiredStartDate,
				0,
				count($requiredTitles),
				false,
			);
			$pending = $this->buildCoordinationPendingPeriod($requestDateDate, null);
			return $this->assembleSummary(
				$requestDate,
				$prepWeeks,
				[
					'status' => 'error',
					'date' => null,
					'doneCount' => 0,
					'totalRequired' => count($requiredTitles),
					'missingTitles' => [],
				],
				null,
				$pending,
				$planningMetrics,
			);
		}
	}

	private function assembleSummary(
		string $requestDate,
		int $prepWeeks,
		array $processCompleted,
		?string $earliestExecutionDate,
		array $coordinationPendingPeriod,
		array $planningMetrics,
	): array {
		$planningMetrics['kpis']['processCompleted'] = $processCompleted;
		$planningMetrics['kpis']['coordinationPendingPeriod'] = $coordinationPendingPeriod;

		return array_merge([
			'requestDate' => $requestDate,
			'requiredPreparationWeeks' => $prepWeeks,
			'processCompleted' => $processCompleted,
			'earliestExecutionDate' => $earliestExecutionDate,
			'coordinationPendingPeriod' => $coordinationPendingPeriod,
		], $planningMetrics);
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
	 * @return array<string, array{done: ?DateTime, dueDate: ?DateTime, startDate: ?DateTime}>
	 */
	private function loadRequiredCardsOnBoardByTitle(int $boardId, array $requiredTitles): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.title', 'c.done', 'c.duedate', 'c.startdate', 'c.last_modified', 's.order')
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
			$dueDate = $this->parseCardDate($row['duedate'] ?? null);
			$startDate = $this->parseCardDate($row['startdate'] ?? null);

			if (!array_key_exists($title, $out) || ($done instanceof DateTime && (!($out[$title]['done'] ?? null) instanceof DateTime || $done > $out[$title]['done']))) {
				$out[$title] = [
					'done' => $done,
					'dueDate' => $dueDate,
					'startDate' => $startDate,
				];
			}
		}
		$result->closeCursor();

		return $out;
	}

	private function calculatePlanningMetrics(
		DateTime $requestDateDate,
		DateTime $deckTasksEndDate,
		int $prepWeeks,
		?DateTime $desiredStartDate,
		int $doneCount,
		int $totalRequired,
		bool $isAllDone,
	): array {
		$requestDate = $requestDateDate->format('Y-m-d');
		$minimumStartDate = clone $deckTasksEndDate;
		if ($prepWeeks > 0) {
			$minimumStartDate->modify('+' . (7 * $prepWeeks) . ' days');
		}

		$deckDays = max(1, (int) $requestDateDate->diff($deckTasksEndDate)->days);
		$deckWeeks = max(1, (int) round($deckDays / 7));
		$minimumDurationWeeks = $deckWeeks + $prepWeeks;

		$desiredStartDateStr = $desiredStartDate instanceof DateTime ? $desiredStartDate->format('Y-m-d') : null;
		$floatDays = null;
		$floatWeeks = null;
		$planningStatus = 'on_track';

		if ($desiredStartDate instanceof DateTime) {
			$desiredClean = clone $desiredStartDate;
			$desiredClean->setTime(0, 0, 0);
			$minClean = clone $minimumStartDate;
			$minClean->setTime(0, 0, 0);

			$invert = ($desiredClean < $minClean) ? -1 : 1;
			$diff = (int) $minClean->diff($desiredClean)->days;
			$floatDays = $invert * $diff;
			$floatWeeks = round($floatDays / 7, 1);

			if ($floatDays < 0) {
				$planningStatus = 'behind_at_risk';
			} elseif ($floatDays === 0) {
				$planningStatus = 'attention_needed';
			} else {
				$planningStatus = 'on_track';
			}
		}

		$systemPlanning = [
			'requestDate' => $requestDate,
			'deckTasks' => [
				'label' => 'Deck tasks',
				'startDate' => $requestDate,
				'endDate' => $deckTasksEndDate->format('Y-m-d'),
				'weeks' => $deckWeeks,
				'isDone' => $isAllDone,
				'doneCount' => $doneCount,
				'totalRequired' => $totalRequired,
			],
			'preparation' => [
				'label' => 'Preparation',
				'startDate' => $deckTasksEndDate->format('Y-m-d'),
				'endDate' => $minimumStartDate->format('Y-m-d'),
				'weeks' => $prepWeeks,
			],
			'minimumStart' => [
				'label' => 'Minimum start',
				'date' => $minimumStartDate->format('Y-m-d'),
			],
			'desiredStart' => [
				'label' => 'Desired start',
				'date' => $desiredStartDateStr,
			],
			'float' => [
				'label' => 'Float',
				'days' => $floatDays,
				'weeks' => $floatWeeks,
				'status' => $planningStatus,
				'startDate' => $minimumStartDate->format('Y-m-d'),
				'endDate' => $desiredStartDateStr,
			],
		];

		$kpis = [
			'requestDate' => $requestDate,
			'minimumDurationWeeks' => $minimumDurationWeeks,
			'minimumDurationRange' => $requestDateDate->format('d/m/Y') . ' - ' . $minimumStartDate->format('d/m/Y'),
			'minimumStartDate' => $minimumStartDate->format('Y-m-d'),
			'desiredStartDate' => $desiredStartDateStr,
			'deckTasksWeeks' => $deckWeeks,
			'preparationWeeks' => $prepWeeks,
			'overallFloatWeeks' => $floatWeeks,
			'overallFloatDays' => $floatDays,
			'planningStatus' => $planningStatus,
			'today' => (new DateTime('today'))->format('Y-m-d'),
		];

		return [
			'minimumStartDate' => $minimumStartDate->format('Y-m-d'),
			'minimumDurationWeeks' => $minimumDurationWeeks,
			'desiredStartDate' => $desiredStartDateStr,
			'overallFloatWeeks' => $floatWeeks,
			'overallFloatDays' => $floatDays,
			'planningStatus' => $planningStatus,
			'systemPlanning' => $systemPlanning,
			'kpis' => $kpis,
		];
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
