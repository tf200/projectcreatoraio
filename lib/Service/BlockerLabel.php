<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The "Blokkade" label: people put it on a card that is stuck. The Deck fork
 * draws it first and in red, and lets it be set on Combi cards, where other
 * labels are locked. Written directly, so it also works during an upgrade,
 * when there is no user to pass Deck's permission checks.
 */
final class BlockerLabel
{
	public const TITLE = 'Blokkade';
	public const COLOR = 'C62828';

	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	/** Adds the label to the board unless it already has one. True when added. */
	public function ensure(int $boardId): bool
	{
		if ($boardId <= 0 || !$this->db->tableExists('deck_labels')) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('deck_labels')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq($qb->func()->lower('title'), $qb->createNamedParameter(mb_strtolower(self::TITLE))))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$existing = $result->fetchOne();
		$result->closeCursor();
		if ($existing !== false) {
			return false;
		}

		$insert = $this->db->getQueryBuilder();
		$insert->insert('deck_labels')
			->values([
				'title' => $insert->createNamedParameter(self::TITLE),
				'color' => $insert->createNamedParameter(self::COLOR),
				'board_id' => $insert->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
				'last_modified' => $insert->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
		return true;
	}
}
