<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProjectPortfolioService {
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
