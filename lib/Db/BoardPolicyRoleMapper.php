<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class BoardPolicyRoleMapper extends QBMapper {
	public const TABLE_NAME = 'pc_board_policy_roles';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, BoardPolicyRole::class);
	}

	public function find(int $id): ?BoardPolicyRole {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * @return BoardPolicyRole[]
	 */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	public function findByBoardAndKey(int $boardId, string $roleKey): ?BoardPolicyRole {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('role_key', $qb->createNamedParameter($roleKey)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
