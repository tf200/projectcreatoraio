<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateInterval;
use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineItem;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\TimelinePhase;
use OCA\ProjectCreatorAIO\Db\TimelinePhaseMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TimelinePhaseService
{
	private const DECK_CARD_DURATION = 'P3M';

	public function __construct(
		private readonly IDBConnection $db,
		private readonly TimelinePhaseMapper $phaseMapper,
		private readonly TimelineItemMapper $itemMapper,
	) {
	}

	/**
	 * @return TimelinePhase[]
	 */
	public function ensurePhasesForProject(Project $project): array
	{
		if ((int) ($project->getType() ?? -1) !== ProjectTypeDeckDefaults::TYPE_COMBI) {
			return [];
		}

		$projectId = (int) $project->getId();
		$phases = $this->phaseMapper->findByProject($projectId);
		if (!empty($phases)) {
			return $phases;
		}

		// Seed default Combi phases
		$defaults = CombiPhaseDefaults::getPhases();
		$seeded = [];
		foreach ($defaults as $key => $config) {
			$phase = $this->phaseMapper->createPhase(
				$projectId,
				$config['name'],
				$config['category'],
				$config['order'],
				$config['color']
			);
			$seeded[] = $phase;
		}

		return $seeded;
	}

	/**
	 * Build the complete phase hierarchy with tasks, deck cards, dependencies, and rollups.
	 *
	 * @return array{
	 *     phases: array<int, array{
	 *         id: int,
	 *         order: int,
	 *         name: string,
	 *         category: string,
	 *         color: string,
	 *         startDate: string|null,
	 *         endDate: string|null,
	 *         status: string,
	 *         milestone: array{label: string, date: string, status: string}|null,
	 *         tasks: array<int, array{
	 *             id: int|string,
	 *             deckCardId: int|null,
	 *             label: string,
	 *             startDate: string,
	 *             endDate: string,
	 *             durationDays: int,
	 *             status: string,
	 *             isDone: bool,
	 *             predecessorIds: int[],
	 *             successorIds: int[]
	 *         }>
	 *     }>,
	 *     dependencies: array<int, array{predecessorId: int|string, successorId: int|string, type: string}>
	 * }
	 */
	public function getProjectPhaseHierarchy(Project $project, array $overrides = []): array
	{
		if ((int) ($project->getType() ?? -1) !== ProjectTypeDeckDefaults::TYPE_COMBI) {
			return ['phases' => [], 'dependencies' => []];
		}

		$phases = $this->ensurePhasesForProject($project);
		$boardId = (int) ($project->getBoardId() ?? 0);

		// 1. Load Deck cards if board exists
		$deckCards = $boardId > 0 ? $this->loadDeckCards($boardId) : [];
		$cardIds = array_keys($deckCards);

		// 2. Load Native Deck dependencies from deck_dependent_cards
		$deckDeps = !empty($cardIds) ? $this->loadDeckDependencies($cardIds) : [];

		// 3. Load custom timeline items
		$customItems = $this->itemMapper->findByProject((int) $project->getId());
		$persistedOverrides = [];
		foreach ($customItems as $item) {
			if (!str_starts_with((string) $item->getSystemKey(), 'schedule_override:')) {
				continue;
			}
			$persistedOverrides[(string) $item->getLabel()] = array_filter([
				'startDate' => $item->getStartDate()?->format('Y-m-d'),
				'durationDays' => $item->getDurationDays(),
				'plannedEndDate' => $item->getPlannedEndDate()?->format('Y-m-d'),
				'status' => $item->getStatus(),
			], static fn (mixed $value): bool => $value !== null && $value !== '');
		}

		// 4. Map phases into structured hierarchy
		$phasesByCategory = [];
		foreach ($phases as $phase) {
			$phasesByCategory[$phase->getCategory()] = $phase;
		}

		$requestDate = $this->calculateRequestDate($project);
		$today = (new DateTime('today'))->setTime(0, 0, 0);
		$taskOverrides = $overrides['taskOverrides'] ?? [];

		$cardIdByTitle = [];
		foreach ($deckCards as $c) {
			$cardIdByTitle[trim(strtolower((string)$c['title']))] = (int)$c['id'];
		}
		$defaultCardDependencies = CombiPhaseDefaults::getDefaultCardDependencies();
		foreach ($deckCards as $cardId => $card) {
			if (!empty($deckDeps[$cardId])) {
				continue;
			}
			$cardTitleLower = trim(strtolower((string)$card['title']));
			foreach ($defaultCardDependencies as $successorTitle => $predecessorTitles) {
				if (trim(strtolower($successorTitle)) !== $cardTitleLower) {
					continue;
				}
				foreach ($predecessorTitles as $predecessorTitle) {
					$predecessorId = $cardIdByTitle[trim(strtolower($predecessorTitle))] ?? null;
					if ($predecessorId !== null) {
						$deckDeps[$cardId][] = $predecessorId;
						$this->persistDeckDependency($cardId, $predecessorId);
					}
				}
				break;
			}
		}
		$deckSchedules = $this->calculateDeckCardSchedules($deckCards, $deckDeps, $requestDate, $persistedOverrides, $taskOverrides);
		$resultPhases = [];
		$allDependencies = [];
		$previousPhaseLastEnd = clone $requestDate;

		$combiDefaults = CombiPhaseDefaults::getPhases();

		foreach ($combiDefaults as $categoryKey => $phaseConfig) {
			/** @var TimelinePhase|null $phaseEntity */
			$phaseEntity = $phasesByCategory[$phaseConfig['category']] ?? null;
			$phaseId = $phaseEntity ? (int) $phaseEntity->getId() : $phaseConfig['order'];

			$tasks = [];
			$phaseCardTitles = $phaseConfig['cards'];

			// Gather deck cards matching this phase
			$phaseCards = [];
			foreach ($phaseCardTitles as $title) {
				foreach ($deckCards as $card) {
					if (strcasecmp(trim((string)$card['title']), trim($title)) === 0) {
						$phaseCards[] = $card;
					}
				}
			}

			// If no deck cards matched or this is execution/handover phase, use customTasks
			if (empty($phaseCards) && !empty($phaseConfig['customTasks'])) {
				$cursorStart = clone $previousPhaseLastEnd;
				$prevTaskId = null;

				foreach ($phaseConfig['customTasks'] as $taskConfig) {
					$taskId = 'cust-' . $phaseId . '-' . preg_replace('/[^a-zA-Z0-9]/', '', $taskConfig['name']);
					$override = array_merge(
						$persistedOverrides[$taskId] ?? [],
						$taskOverrides[$taskId] ?? $taskOverrides[$taskConfig['name']] ?? [],
					);

					$baseDuration = (int) $taskConfig['durationDays'];
					$duration = $baseDuration;
					if ($override && isset($override['durationDays'])) {
						$duration = max(1, (int) $override['durationDays']);
					}
					if ($override && isset($override['delayDays'])) {
						$duration += (int) $override['delayDays'];
					}

					$taskStart = clone $cursorStart;
					if (!empty($override['startDate'])) {
						$taskStart = new DateTime((string) $override['startDate']);
					}
					if ($override && isset($override['overlapDays']) && $prevTaskId !== null) {
						$overlap = max(0, (int) $override['overlapDays']);
						$taskStart->modify('-' . $overlap . ' days');
					}
					$taskEnd = clone $taskStart;
					$taskEnd->modify('+' . ($duration - 1) . ' days');

					$plannedEnd = clone $taskStart;
					$plannedEnd->modify('+' . ($baseDuration - 1) . ' days');
					if (!empty($override['plannedEndDate'])) {
						$plannedEnd = new DateTime((string) $override['plannedEndDate']);
					}
					$delayDays = ($taskEnd > $plannedEnd) ? (int) $taskEnd->diff($plannedEnd)->days : 0;

					$status = $taskStart > $today ? 'not_started' : 'on_track';
					if ($delayDays > 0) {
						$status = 'behind_at_risk';
					}
					if (!empty($override['status'])) {
						$status = (string) $override['status'];
					}

					$tasks[] = [
						'id' => $taskId,
						'deckCardId' => null,
						'label' => $taskConfig['name'],
						'startDate' => $taskStart->format('Y-m-d'),
						'endDate' => $taskEnd->format('Y-m-d'),
						'plannedEndDate' => $plannedEnd->format('Y-m-d'),
						'durationDays' => $duration,
						'delayDays' => $delayDays,
						'isDelayed' => $delayDays > 0,
						'status' => $status,
						'isDone' => false,
						'predecessorIds' => $prevTaskId ? [$prevTaskId] : [],
						'successorIds' => [],
					];

					if ($prevTaskId) {
						$allDependencies[] = [
							'predecessorId' => $prevTaskId,
							'successorId' => $taskId,
							'type' => 'FS',
						];
					}

					$prevTaskId = $taskId;
					$cursorStart = clone $taskEnd;
					$cursorStart->modify('+1 day');
				}
			} elseif (!empty($phaseCards)) {
				// We have deck cards for this phase
				foreach ($phaseCards as $card) {
					$cardId = (int) $card['id'];
					$cardTitle = trim((string) $card['title']);
					$persistedCardOverride = $persistedOverrides[(string)$cardId] ?? [];
					if ($card['startdate'] instanceof DateTime || $card['duedate'] instanceof DateTime) {
						unset($persistedCardOverride['startDate'], $persistedCardOverride['durationDays'], $persistedCardOverride['plannedEndDate']);
					}
					$override = array_merge(
						$persistedCardOverride,
						$taskOverrides[$cardId] ?? $taskOverrides[(string)$cardId] ?? $taskOverrides[$cardTitle] ?? [],
					);

					$schedule = $deckSchedules[$cardId];
					$predecessors = $schedule['predecessors'];
					$cardStart = $schedule['start'];
					$cardEnd = $schedule['end'];
					$plannedEnd = $schedule['plannedEnd'];
					$durationDays = $schedule['durationDays'];
					$delayDays = $schedule['delayDays'];
					$isDone = $schedule['isDone'];
					$status = $isDone ? 'on_track' : ($cardStart > $today ? 'not_started' : 'on_track');
					if ($delayDays > 0 || (!$isDone && $cardEnd < $today)) {
						$status = 'behind_at_risk';
					}
					if (!empty($override['status'])) {
						$status = (string) $override['status'];
					}

					$tasks[] = [
						'id' => $cardId,
						'deckCardId' => $cardId,
						'label' => $cardTitle,
						'startDate' => $cardStart->format('Y-m-d'),
						'endDate' => $cardEnd->format('Y-m-d'),
						'plannedEndDate' => $plannedEnd->format('Y-m-d'),
						'durationDays' => $durationDays,
						'delayDays' => $delayDays,
						'isDelayed' => $delayDays > 0,
						'status' => $status,
						'isDone' => $isDone,
						'predecessorIds' => $predecessors,
						'successorIds' => [],
					];

					if (!empty($predecessors)) {
						foreach ($predecessors as $predId) {
							$allDependencies[] = [
								'predecessorId' => $predId,
								'successorId' => $cardId,
								'type' => 'FS',
							];
						}
					}

				}
			}

			// Phase date rollup. Empty future phases remain unscheduled placeholders.
			$minStart = null;
			$maxEnd = null;
			$phaseStatus = empty($tasks) ? 'not_started' : 'on_track';

			foreach ($tasks as $t) {
				$s = new DateTime($t['startDate']);
				$e = new DateTime($t['endDate']);
				if ($minStart === null || $s < $minStart) $minStart = $s;
				if ($maxEnd === null || $e > $maxEnd) $maxEnd = $e;
				if ($t['status'] === 'behind_at_risk') {
					$phaseStatus = 'behind_at_risk';
				} elseif ($t['status'] === 'attention_needed' && $phaseStatus !== 'behind_at_risk') {
					$phaseStatus = 'attention_needed';
				}
			}

			if ($maxEnd !== null) {
				$previousPhaseLastEnd = clone $maxEnd;
				$previousPhaseLastEnd->modify('+1 day');
			}

			$milestone = null;
			if ($maxEnd !== null && !empty($phaseConfig['milestone'])) {
				$milestone = [
					'label' => $phaseConfig['milestone'],
					'date' => $maxEnd->format('Y-m-d'),
					'status' => $phaseStatus,
				];
			}

			$resultPhases[] = [
				'id' => $phaseId,
				'order' => $phaseConfig['order'],
				'name' => $phaseConfig['name'],
				'category' => $phaseConfig['category'],
				'color' => $phaseConfig['color'],
				'startDate' => $minStart?->format('Y-m-d'),
				'endDate' => $maxEnd?->format('Y-m-d'),
				'status' => $phaseStatus,
				'milestone' => $milestone,
				'tasks' => $tasks,
			];
		}

		return [
			'phases' => $resultPhases,
			'dependencies' => $allDependencies,
		];
	}

	/**
	 * @param array<int, array{id: int, title: string, startdate: ?DateTime, duedate: ?DateTime, done: ?DateTime}> $cards
	 * @param array<int, int[]> $dependencies
	 * @param array<string, array<string, mixed>> $persistedOverrides
	 * @param array<int|string, array<string, mixed>> $taskOverrides
	 * @return array<int, array{start: DateTime, end: DateTime, plannedEnd: DateTime, durationDays: int, delayDays: int, isDone: bool, predecessors: int[]}>
	 */
	public function calculateDeckCardSchedules(
		array $cards,
		array $dependencies,
		DateTime $requestDate,
		array $persistedOverrides,
		array $taskOverrides,
	): array {
		$schedules = [];
		$visiting = [];

		$schedule = function (int $cardId) use (&$schedule, &$schedules, &$visiting, $cards, $dependencies, $requestDate, $persistedOverrides, $taskOverrides): array {
			if (isset($schedules[$cardId])) {
				return $schedules[$cardId];
			}
			if (isset($visiting[$cardId])) {
				throw new \RuntimeException('Deck card dependencies contain a cycle');
			}

			$card = $cards[$cardId];
			$visiting[$cardId] = true;
			$predecessors = array_values(array_filter(
				array_unique($dependencies[$cardId] ?? []),
				static fn (int $predecessorId): bool => isset($cards[$predecessorId]),
			));
			$earliestStart = clone $requestDate;
			foreach ($predecessors as $predecessorId) {
				$predecessorEnd = clone $schedule($predecessorId)['end'];
				$predecessorEnd->modify('+1 day');
				if ($predecessorEnd > $earliestStart) {
					$earliestStart = $predecessorEnd;
				}
			}

			$persistedOverride = $persistedOverrides[(string)$cardId] ?? [];
			if ($card['startdate'] instanceof DateTime || $card['duedate'] instanceof DateTime) {
				unset($persistedOverride['startDate'], $persistedOverride['durationDays'], $persistedOverride['plannedEndDate']);
			}
			$taskOverride = $taskOverrides[$cardId] ?? $taskOverrides[(string)$cardId] ?? $taskOverrides[$card['title']] ?? [];
			$override = array_merge(
				$persistedOverride,
				$taskOverride,
			);

			$isDone = $card['done'] instanceof DateTime;
			$cardStart = clone $earliestStart;
			$requestedStart = !empty($override['startDate'])
				? new DateTime((string)$override['startDate'])
				: $card['startdate'];
			if ($isDone && $requestedStart instanceof DateTime) {
				$cardStart = clone $requestedStart;
				$cardStart->setTime(0, 0);
			} elseif ($predecessors === [] && $requestedStart instanceof DateTime && $requestedStart > $cardStart) {
				$cardStart = clone $requestedStart;
				$cardStart->setTime(0, 0);
			}

			if ($isDone) {
				$cardEnd = clone $card['done'];
				$cardEnd->setTime(0, 0);
				if ($cardStart > $cardEnd) {
					$cardStart = clone $cardEnd;
				}
				$durationDays = max(1, (int)$cardStart->diff($cardEnd)->days + 1);
				$plannedEnd = clone $cardEnd;
				$delayDays = 0;
			} else {
				if (isset($override['durationDays'])) {
					$durationDays = max(1, (int)$override['durationDays']);
				} elseif (
					$card['startdate'] instanceof DateTime
					&& $card['duedate'] instanceof DateTime
					&& $card['duedate'] >= $card['startdate']
				) {
					$durationDays = (int)$card['startdate']->diff($card['duedate'])->days + 1;
				} else {
					$defaultEnd = (clone $cardStart)->add(new DateInterval(self::DECK_CARD_DURATION));
					$durationDays = (int)$cardStart->diff($defaultEnd)->days + 1;
				}
				$cardEnd = (clone $cardStart)->modify('+' . ($durationDays - 1) . ' days');
				$plannedEnd = clone $cardEnd;
				if (isset($override['delayDays'])) {
					$delayDays = max(0, (int)$override['delayDays']);
					$cardEnd->modify('+' . $delayDays . ' days');
					$durationDays += $delayDays;
				} else {
					$delayDays = max(0, (int)$plannedEnd->diff($cardEnd)->days);
				}
				if (!empty($override['plannedEndDate'])) {
					$plannedEnd = new DateTime((string)$override['plannedEndDate']);
					$delayDays = $cardEnd > $plannedEnd ? (int)$plannedEnd->diff($cardEnd)->days : 0;
				}
			}

			unset($visiting[$cardId]);
			return $schedules[$cardId] = [
				'start' => $cardStart,
				'end' => $cardEnd,
				'plannedEnd' => $plannedEnd,
				'durationDays' => $durationDays,
				'delayDays' => $delayDays,
				'isDone' => $isDone,
				'predecessors' => $predecessors,
			];
		};

		foreach (array_keys($cards) as $cardId) {
			$schedule($cardId);
		}

		return $schedules;
	}

	/**
	 * @return array<int, array{id: int, title: string, startdate: ?DateTime, duedate: ?DateTime, done: ?DateTime}>
	 */
	private function loadDeckCards(int $boardId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.title', 'c.startdate', 'c.duedate', 'c.done')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('s.order', 'ASC')
			->addOrderBy('c.order', 'ASC');

		$res = $qb->executeQuery();
		$cards = [];
		while ($row = $res->fetch()) {
			$id = (int) $row['id'];
			$cards[$id] = [
				'id' => $id,
				'title' => (string) ($row['title'] ?? ''),
				'startdate' => !empty($row['startdate']) ? new DateTime((string) $row['startdate']) : null,
				'duedate' => !empty($row['duedate']) ? new DateTime((string) $row['duedate']) : null,
				'done' => !empty($row['done']) ? new DateTime((string) $row['done']) : null,
			];
		}
		$res->closeCursor();

		return $cards;
	}

	/**
	 * @param int[] $cardIds
	 * @return array<int, int[]>
	 */
	private function loadDeckDependencies(array $cardIds): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('card_id', 'dependent_card_id')
			->from('deck_dependent_cards')
			->where($qb->expr()->in('card_id', $qb->createNamedParameter($cardIds, IQueryBuilder::PARAM_INT_ARRAY)));

		$res = $qb->executeQuery();
		$deps = [];
		while ($row = $res->fetch()) {
			$cardId = (int) $row['card_id'];
			$deps[$cardId][] = (int) $row['dependent_card_id'];
		}
		$res->closeCursor();

		return $deps;
	}

	private function persistDeckDependency(int $cardId, int $dependentCardId): void
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('deck_dependent_cards')
				->values([
					'card_id' => $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT),
					'dependent_card_id' => $qb->createNamedParameter($dependentCardId, IQueryBuilder::PARAM_INT),
				]);
			$qb->executeStatement();
		} catch (\Throwable) {
			// Timeline rendering must continue if the native dependency already exists or cannot be stored.
		}
	}

	private function calculateRequestDate(Project $project): DateTime
	{
		$createdAt = $project->getCreatedAt();
		if ($createdAt instanceof DateTime) {
			$d = clone $createdAt;
		} else {
			$d = new DateTime('today');
		}
		$d->setTime(0, 0, 0);
		return $d;
	}
}
