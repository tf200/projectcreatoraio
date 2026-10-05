<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Tracks why a user is a project member. A row with team_id 0 means the user
 * was added manually; any other value is the organization team that brought
 * them in. A member can have several origins at once.
 */
class ProjectMemberSourceMapper {
	public const TABLE_NAME = 'pc_member_sources';
	public const MANUAL = 0;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function addSource(int $projectId, string $userId, int $teamId = self::MANUAL): void {
		if ($this->hasSource($projectId, $userId, $teamId)) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE_NAME)
			->values([
				'project_id' => $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
				'team_id' => $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT),
			]);
		try {
			$qb->executeStatement();
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	/** @param string[] $userIds */
	public function addSources(int $projectId, array $userIds, int $teamId = self::MANUAL): void {
		foreach (array_unique($userIds) as $userId) {
			$this->addSource($projectId, (string)$userId, $teamId);
		}
	}

	public function hasSource(int $projectId, string $userId, int $teamId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	public function hasAnySource(int $projectId, string $userId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	/** @return int[] team ids (0 for a manual origin) */
	public function findSources(int $projectId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('team_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$teamIds = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $teamIds;
	}

	/** @return string[] */
	public function findUserIdsByProjectAndTeam(int $projectId, int $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$userIds = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $userIds;
	}

	/** @return array<int, array{projectId: int, userId: string}> */
	public function findByTeam(int $teamId, ?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('project_id', 'user_id')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		$result = $qb->executeQuery();
		$rows = array_map(static fn (array $row): array => [
			'projectId' => (int)$row['project_id'],
			'userId' => (string)$row['user_id'],
		], $result->fetchAll());
		$result->closeCursor();
		return $rows;
	}

	public function removeSource(int $projectId, string $userId, int $teamId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function deleteByProjectAndUser(int $projectId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->executeStatement();
	}

	public function deleteByProject(int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE_NAME)
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
