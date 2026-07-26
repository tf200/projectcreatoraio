<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class CardPolicyOverrideMapper extends QBMapper {
	public const TABLE_NAME = 'pc_card_policy_overrides';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, CardPolicyOverride::class);
	}

	/** @return CardPolicyOverride[] */
	public function findByPolicy(int $cardPolicyId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('card_policy_id', $qb->createNamedParameter($cardPolicyId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	public function findByPolicyAndAction(int $cardPolicyId, string $action): ?CardPolicyOverride {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('card_policy_id', $qb->createNamedParameter($cardPolicyId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
