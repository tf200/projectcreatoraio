<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTimeImmutable;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProjectPortfolioService {
	private const CAPACITY_STATUSES = [ProjectStatus::ACTIVE, ProjectStatus::WAITING_ON_CUSTOMER, ProjectStatus::ON_HOLD];
	private const HANDOVER_TITLE = 'handover 1';
	private const BUCKETS = [
		['key' => '0-24', 'label' => '0 - 24%', 'min' => 0, 'max' => 24],
		['key' => '25-49', 'label' => '25 - 49%', 'min' => 25, 'max' => 49],
		['key' => '50-74', 'label' => '50 - 74%', 'min' => 50, 'max' => 74],
		['key' => '75-99', 'label' => '75 - 99%', 'min' => 75, 'max' => 99],
		['key' => '100', 'label' => '100%', 'min' => 100, 'max' => 100],
	];

	public function __construct(
		private ProjectMapper $projectMapper,
		private IDBConnection $db,
	) {
	}

	public function getCompletion(int $organizationId): array {
		$projects = array_values(array_filter(
			$this->projectMapper->findByOrganizationId($organizationId),
			static fn (Project $project): bool => $project->getStatus() !== ProjectStatus::ARCHIVED,
		));

		$boardIds = [];
		foreach ($projects as $project) {
			$boardId = $this->normalizeBoardId($project->getBoardId());
			if ($boardId !== null) {
				$boardIds[$boardId] = $boardId;
			}
		}

		$liveBoardIds = $this->getLiveBoardIds(array_values($boardIds));
		$cardCounts = $this->getCardCounts($liveBoardIds);
		$projectRows = [];
		$untrackedProjects = [];

		foreach ($projects as $project) {
			$boardId = $this->normalizeBoardId($project->getBoardId());
			if ($boardId === null || !isset($liveBoardIds[$boardId])) {
				$untrackedProjects[] = [
					'id' => (int)$project->getId(),
					'name' => (string)$project->getName(),
				];
				continue;
			}

			$counts = $cardCounts[$boardId] ?? ['total' => 0, 'done' => 0];
			$projectRows[] = [
				'id' => (int)$project->getId(),
				'name' => (string)$project->getName(),
				'boardId' => $boardId,
				'totalCards' => $counts['total'],
				'doneCards' => $counts['done'],
			];
		}

		return $this->summarize($projectRows, $untrackedProjects);
	}

	public static function isIsoDate(string $value): bool {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}

	/**
	 * Fetches the portfolio in batches. The card query deliberately returns done as text:
	 * PostgreSQL Deck installations have used both datetime and date-like values there.
	 */
	public function getCapacity(int $organizationId, int $teamId, ?string $weekStart = null): array {
		$requestedMonday = $this->normalizeMonday($weekStart);
		$periodEnd = $requestedMonday->modify('+41 days');
		$team = $this->loadTeam($organizationId, $teamId);
		if ($team === null) {
			throw new \InvalidArgumentException('Team does not belong to organization');
		}

		$projects = $this->loadCapacityProjects($organizationId, $teamId);
		$boardIds = [];
		foreach ($projects as $project) {
			if (ctype_digit((string)$project['boardId']) && (int)$project['boardId'] > 0) {
				$boardIds[(int)$project['boardId']] = true;
			}
		}
		$cards = $this->loadCapacityCards(array_keys($boardIds));
		$planningGaps = [];
		$eligible = [];
		foreach ($projects as $project) {
			$dates = $this->deriveCapacityDates($project, $cards[(int)$project['boardId']] ?? []);
			$project = array_merge($project, $dates);
			$actual = $project['actualEnd'];
			$isHistoricalCompletion = $actual !== null && $actual >= $requestedMonday->format('Y-m-d') && $actual <= $periodEnd->format('Y-m-d');
			if (!in_array((int)$project['status'], self::CAPACITY_STATUSES, true) && !$isHistoricalCompletion) {
				continue;
			}
			if ($project['end'] === null) {
				$planningGaps[] = $this->projectSummary($project);
			}
			$eligible[] = $project;
		}

		$unassigned = $this->loadUnassignedProjects($organizationId);
		return $this->summarizeCapacity($team, $requestedMonday->format('Y-m-d'), $eligible, $planningGaps, $unassigned);
	}

	/**
	 * @param array<string,mixed> $team
	 * @param array<int,array<string,mixed>> $projects
	 */
	public function summarizeCapacity(array $team, string $weekStart, array $projects, array $planningGaps = [], array $unassigned = []): array {
		$monday = $this->normalizeMonday($weekStart);
		$capacity = round((float)$team['fte'] * (float)$team['projectsPerFte'], 2);
		$normalizedProjects = [];
		$normalizedPlanningGaps = $planningGaps;
		foreach ($projects as $project) {
			if ($project['end'] !== null && $project['end'] < $project['start']) {
				$project['end'] = null;
				$project['actualEnd'] = null;
				$normalizedPlanningGaps[] = $this->projectSummary($project);
			}
			$normalizedProjects[] = $project;
		}

		$weeks = [];
		for ($i = 0; $i < 6; $i++) {
			$start = $monday->modify('+' . ($i * 7) . ' days');
			$end = $start->modify('+6 days');
			$starting = $ending = $continuing = $total = 0;
			foreach ($normalizedProjects as $project) {
				$from = $project['start'];
				$to = $project['end'];
				$weekStartDate = $start->format('Y-m-d');
				$weekEndDate = $end->format('Y-m-d');
				if ($from > $weekEndDate || ($to !== null && $to < $weekStartDate)) {
					continue;
				}
				$total++;
				$starts = $from >= $weekStartDate && $from <= $weekEndDate;
				$ends = $to !== null && $to >= $weekStartDate && $to <= $weekEndDate;
				if ($starts) {
					$starting++;
				}
				if ($ends) {
					$ending++;
				}
				if (!$starts && !$ends) {
					$continuing++;
				}
			}
			$remaining = round($capacity - $total, 2);
			$message = 'Within capacity';
			if ($remaining < 0) {
				$message = 'Capacity exceeded';
			} elseif ($total === 0) {
				$message = 'No active projects';
			}
			$weeks[] = [
				'label' => $start->format('o-\WW'),
				'start' => $start->format('Y-m-d'),
				'end' => $end->format('Y-m-d'),
				'starting' => $starting,
				'ending' => $ending,
				'continuing' => $continuing,
				'totalActive' => $total,
				'capacity' => $capacity,
				'remaining' => $remaining,
				'overCapacity' => $remaining < 0,
				'message' => $message,
			];
		}

		return [
			'team' => [
				'id' => (int)$team['id'],
				'organizationId' => (int)$team['organizationId'],
				'name' => (string)$team['name'],
				'fte' => (float)$team['fte'],
				'projectsPerFte' => (float)$team['projectsPerFte'],
				'capacity' => $capacity,
			],
			'period' => [
				'weekStart' => $monday->format('Y-m-d'),
				'weekEnd' => $monday->modify('+41 days')->format('Y-m-d'),
				'weeks' => 6,
			],
			'weeks' => $weeks,
			'planningGaps' => array_values($normalizedPlanningGaps),
			'unassignedProjects' => array_values($unassigned),
		];
	}

	/** @return array<string,mixed>|null */
	private function loadTeam(int $organizationId, int $teamId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('id', 'organization_id', 'name', 'fte', 'projects_per_fte')
			->from('organization_teams')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}

		return [
			'id' => (int)$row['id'],
			'organizationId' => (int)$row['organization_id'],
			'name' => (string)$row['name'],
			'fte' => (float)$row['fte'],
			'projectsPerFte' => (float)$row['projects_per_fte'],
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function loadCapacityProjects(int $organizationId, int $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.board_id', 'p.created_at', 'p.desired_start_date')
			->from('custom_projects', 'p')
			->innerJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row): array {
			return [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'status' => (int)$row['status'],
				'boardId' => (string)($row['board_id'] ?? ''),
				'createdAt' => (string)($row['created_at'] ?? ''),
				'desiredStartDate' => $row['desired_start_date'] === null ? null : (string)$row['desired_start_date'],
			];
		}, $rows);
	}

	/** @param int[] $boardIds @return array<int,array<int,array<string,mixed>>> */
	private function loadCapacityCards(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('s.board_id', 'c.title', 'c.startdate', 'c.duedate', 'c.done')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
			->where($qb->expr()->in('s.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()->fetchAllAssociative();
		$out = [];
		foreach ($rows as $row) {
			$out[(int)$row['board_id']][] = $row;
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function loadUnassignedProjects(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status')
			->from('custom_projects', 'p')
			->leftJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id AND pt.organization_id = p.organization_id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('pt.project_id'))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(self::CAPACITY_STATUSES, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row): array {
			return [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'status' => (int)$row['status'],
			];
		}, $rows);
	}

	/** @param array<int,array<string,mixed>> $cards @return array{start:string,end:?string,actualEnd:?string,invalidEnd:bool} */
	private function deriveCapacityDates(array $project, array $cards): array {
		$starts = [];
		$dues = [];
		$dones = [];
		$handoverDue = null;
		$active = count($cards);
		$doneCount = 0;
		foreach ($cards as $card) {
			$startDate = $this->dateString($card['startdate'] ?? null);
			if ($startDate !== null) {
				$starts[] = $startDate;
			}

			$dueDate = $this->dateString($card['duedate'] ?? null);
			if ($dueDate !== null) {
				$dues[] = $dueDate;
			}
			$title = strtolower(trim((string)($card['title'] ?? '')));
			if ($title === self::HANDOVER_TITLE && $dueDate !== null) {
				$handoverDue = $dueDate;
			}

			$doneValue = $card['done'] ?? null;
			if ($doneValue !== null && trim((string)$doneValue) !== '') {
				$doneCount++;
			}
			$doneDate = $this->dateString($doneValue);
			if ($doneDate !== null) {
				$dones[] = $doneDate;
			}
		}

		$created = $this->dateString($project['createdAt']) ?? (new DateTimeImmutable('today'))->format('Y-m-d');
		if ($starts !== []) {
			$start = min($starts);
		} else {
			$start = $this->firstMondayOnOrAfter($created)->format('Y-m-d');
		}
		$actual = null;
		if ($active > 0 && $doneCount === $active) {
			if ($dones !== []) {
				$actual = max($dones);
			} elseif ($dues !== []) {
				$actual = max($dues);
			}
		}
		$planned = $handoverDue;
		if ($planned === null && $dues !== []) {
			$planned = max($dues);
		}
		if ($planned === null && $project['desiredStartDate'] !== null) {
			$planned = $this->dateString($project['desiredStartDate']);
		}

		$invalidEnd = ($actual !== null && $actual < $start) || ($planned !== null && $planned < $start);
		if ($invalidEnd) {
			return [
				'start' => $start,
				'end' => null,
				'actualEnd' => null,
				'invalidEnd' => true,
			];
		}

		return [
			'start' => $start,
			'end' => $actual ?? $planned,
			'actualEnd' => $actual,
			'invalidEnd' => false,
		];
	}

	private function dateString(mixed $value): ?string {
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (\Exception) {
			return null;
		}
	}

	private function projectSummary(array $project): array {
		return ['id' => (int)$project['id'], 'name' => (string)$project['name'], 'status' => (int)$project['status'], 'start' => $project['start'], 'plannedEnd' => $project['end'], 'actualEnd' => $project['actualEnd']];
	}

	private function normalizeMonday(?string $value): DateTimeImmutable {
		if ($value === null) {
			$date = new DateTimeImmutable('today');
		} else {
			$date = new DateTimeImmutable($value);
		}

		return $date->modify('-' . ((int)$date->format('N') - 1) . ' days')->setTime(0, 0);
	}

	private function firstMondayOnOrAfter(string $value): DateTimeImmutable {
		$date = new DateTimeImmutable($value);
		$days = (8 - (int)$date->format('N')) % 7;
		return $date->setTime(0, 0)->modify('+' . $days . ' days');
	}

	/**
	 * @param array<int,array{id:int,name:string,boardId:int,totalCards:int,doneCards:int}> $projects
	 * @param array<int,array{id:int,name:string}> $untrackedProjects
	 */
	public function summarize(array $projects, array $untrackedProjects = []): array {
		$buckets = array_map(
			static fn (array $bucket): array => $bucket + ['count' => 0, 'percent' => 0.0],
			self::BUCKETS,
		);

		foreach ($projects as &$project) {
			$total = max(0, (int)$project['totalCards']);
			$done = min($total, max(0, (int)$project['doneCards']));
			$completion = $total === 0 ? 0 : (int)round(($done / $total) * 100);
			$bucketIndex = min(4, intdiv($completion, 25));

			$project['totalCards'] = $total;
			$project['doneCards'] = $done;
			$project['completionPct'] = $completion;
			$project['bucket'] = $buckets[$bucketIndex]['key'];
			$buckets[$bucketIndex]['count']++;
		}
		unset($project);

		$trackedCount = count($projects);
		foreach ($buckets as &$bucket) {
			$bucket['percent'] = $trackedCount === 0
				? 0.0
				: round(($bucket['count'] / $trackedCount) * 100, 1);
		}
		unset($bucket);

		return [
			'totalProjects' => $trackedCount + count($untrackedProjects),
			'trackedProjects' => $trackedCount,
			'untrackedProjects' => $untrackedProjects,
			'buckets' => $buckets,
			'projects' => $projects,
		];
	}

	private function normalizeBoardId(?string $boardId): ?int {
		if ($boardId === null || !ctype_digit($boardId) || (int)$boardId < 1) {
			return null;
		}

		return (int)$boardId;
	}

	/** @param int[] $boardIds @return array<int,int> */
	private function getLiveBoardIds(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('id')
			->from('deck_boards')
			->where($qb->expr()->in('id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()
			->fetchAllAssociative();

		$liveBoardIds = [];
		foreach ($rows as $row) {
			$liveBoardIds[(int)$row['id']] = (int)$row['id'];
		}
		return $liveBoardIds;
	}

	/** @param array<int,int> $boardIds @return array<int,array{total:int,done:int}> */
	private function getCardCounts(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('s.board_id')
			->selectAlias($qb->func()->count('c.id'), 'card_count')
			->from('deck_stacks', 's')
			->innerJoin('s', 'deck_cards', 'c', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->in('s.board_id', $qb->createNamedParameter(array_values($boardIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('s.board_id')
			->executeQuery()
			->fetchAllAssociative();

		$counts = [];
		foreach ($rows as $row) {
			$counts[(int)$row['board_id']] = ['total' => (int)$row['card_count'], 'done' => 0];
		}

		$doneQb = $this->db->getQueryBuilder();
		$doneRows = $doneQb->select('s.board_id')
			->selectAlias($doneQb->func()->count('c.id'), 'card_count')
			->from('deck_stacks', 's')
			->innerJoin('s', 'deck_cards', 'c', $doneQb->expr()->eq('c.stack_id', 's.id'))
			->where($doneQb->expr()->in('s.board_id', $doneQb->createNamedParameter(array_values($boardIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($doneQb->expr()->eq('s.deleted_at', $doneQb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($doneQb->expr()->eq('c.deleted_at', $doneQb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($doneQb->expr()->eq('c.archived', $doneQb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($doneQb->expr()->isNotNull('c.done'))
			->groupBy('s.board_id')
			->executeQuery()
			->fetchAllAssociative();

		foreach ($doneRows as $row) {
			$boardId = (int)$row['board_id'];
			$counts[$boardId] ??= ['total' => 0, 'done' => 0];
			$counts[$boardId]['done'] = (int)$row['card_count'];
		}

		return $counts;
	}
}
