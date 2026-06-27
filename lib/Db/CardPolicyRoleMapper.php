<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class CardPolicyRoleMapper extends QBMapper {
	public const TABLE_NAME = 'pc_card_policy_roles';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, CardPolicyRole::class);
	}

	/**
	 * @return CardPolicyRole[]
	 */
	public function findByPolicy(int $cardPolicyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('card_policy_id', $qb->createNamedParameter($cardPolicyId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/**
	 * @return CardPolicyRole[]
	 */
	public function findByPolicyAndAction(int $cardPolicyId, string $action): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('card_policy_id', $qb->createNamedParameter($cardPolicyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));

		return $this->findEntities($qb);
	}
}
