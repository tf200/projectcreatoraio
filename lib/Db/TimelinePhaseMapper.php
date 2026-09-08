<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TimelinePhaseMapper extends QBMapper
{
	public const TABLE_NAME = 'project_timeline_phases';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE_NAME, TimelinePhase::class);
	}

	/**
	 * @return TimelinePhase[]
	 */
	public function findByProject(int $projectId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->orderBy('order_index', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function find(int $id): ?TimelinePhase
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function createPhase(
		int $projectId,
		string $name,
		string $category,
		int $orderIndex = 0,
		string $color = '#3b82f6',
	): TimelinePhase {
		$now = new DateTime();
		$phase = new TimelinePhase();
		$phase->setProjectId($projectId);
		$phase->setName($name);
		$phase->setCategory($category);
		$phase->setOrderIndex($orderIndex);
		$phase->setColor($color);
		$phase->setCreatedAt($now);
		$phase->setUpdatedAt($now);

		return $this->insert($phase);
	}

	public function deleteByProject(int $projectId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
