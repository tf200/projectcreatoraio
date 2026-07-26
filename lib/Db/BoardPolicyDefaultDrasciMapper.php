<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BoardPolicyDefaultDrasciMapper extends QBMapper {
	public const TABLE_NAME = 'pc_board_policy_default_drasci';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, BoardPolicyDefaultDrasci::class);
	}

	/** @return BoardPolicyDefaultDrasci[] */
	public function findByBoard(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/** @return BoardPolicyDefaultDrasci[] */
	public function findByBoardAndAction(int $boardId, string $action): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE_NAME)
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));

		return $this->findEntities($qb);
	}
}
