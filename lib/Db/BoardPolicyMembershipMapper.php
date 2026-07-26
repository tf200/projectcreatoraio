<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class BoardPolicyMembershipMapper extends QBMapper {
	public const TABLE_NAME = 'pc_board_policy_memberships';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, BoardPolicyMembership::class);
	}

	public function find(int $id): ?BoardPolicyMembership {
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
	 * @return BoardPolicyMembership[]
	 */
	public function findByRole(int $roleId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $roleIds
	 * @return BoardPolicyMembership[]
	 */
	public function findByRoles(array $roleIds): array {
		if (empty($roleIds)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->in('role_id', $qb->createNamedParameter($roleIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $this->findEntities($qb);
	}

	public function findUnique(int $roleId, string $type, string $id): ?BoardPolicyMembership {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('participant_type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('participant_id', $qb->createNamedParameter($id)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
