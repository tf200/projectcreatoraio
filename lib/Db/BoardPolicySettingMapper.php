<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class BoardPolicySettingMapper extends QBMapper {
	public const TABLE_NAME = 'pc_board_policy_settings';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, BoardPolicySetting::class);
	}

	public function findByBoard(int $boardId): ?BoardPolicySetting {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}
}
