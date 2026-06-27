<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class CardPolicyMapper extends QBMapper {
	public const TABLE_NAME = 'pc_card_policies';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, CardPolicy::class);
	}

	public function findByCard(int $cardId): ?CardPolicy {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
