<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010028Date20260723000000 extends SimpleMigrationStep {
	public function __construct(private readonly IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('custom_projects')
			|| !$schema->hasTable('pc_board_policy_settings')
			|| !$schema->hasTable('deck_stacks')
			|| !$schema->hasTable('deck_cards')
			|| !$schema->getTable('deck_stacks')->hasColumn('is_done_column')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.board_id')
			->from('custom_projects', 'p')
			->where($qb->expr()->eq('p.type', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNotNull('p.board_id'))
			->executeQuery()
			->fetchAll();

		foreach ($rows as $row) {
			$boardId = (int)$row['board_id'];
			$doneStackId = $this->findConfiguredDoneStackId($boardId);
			if ($doneStackId <= 0) {
				$doneStackId = $this->findDoneStackId($boardId);
			}
			if ($doneStackId <= 0) {
				continue;
			}

			$update = $this->db->getQueryBuilder();
			$update->update('deck_stacks')
				->set('is_done_column', $update->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				->where($update->expr()->eq('board_id', $update->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$update = $this->db->getQueryBuilder();
			$update->update('deck_stacks')
				->set('is_done_column', $update->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				->where($update->expr()->eq('id', $update->createNamedParameter($doneStackId, IQueryBuilder::PARAM_INT)))
				->andWhere($update->expr()->eq('board_id', $update->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$update = $this->db->getQueryBuilder();
			$update->update('pc_board_policy_settings')
				->set('done_stack_id', $update->createNamedParameter($doneStackId, IQueryBuilder::PARAM_INT))
				->where($update->expr()->eq('board_id', $update->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			foreach ($this->findCardIds($doneStackId) as $cardId) {
				if ($schema->hasTable('deck_dependent_cards') && $this->hasUnmetDependencies($cardId)) {
					continue;
				}
				$update = $this->db->getQueryBuilder();
				$update->update('deck_cards')
					->set('done', $update->createNamedParameter(new \DateTime(), Types::DATETIME))
					->where($update->expr()->eq('id', $update->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
					->andWhere($update->expr()->isNull('done'))
					->executeStatement();
			}

			$stackIds = $this->findStackIds($boardId);
			if ($stackIds !== []) {
				$update = $this->db->getQueryBuilder();
				$update->update('deck_cards')
					->set('done', $update->createNamedParameter(null))
					->where($update->expr()->neq('stack_id', $update->createNamedParameter($doneStackId, IQueryBuilder::PARAM_INT)))
					->andWhere($update->expr()->isNotNull('done'))
					->andWhere($update->expr()->in('stack_id', $update->createNamedParameter($stackIds, IQueryBuilder::PARAM_INT_ARRAY)))
					->executeStatement();
			}
		}
	}

	private function findConfiguredDoneStackId(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select('done_stack_id')
			->from('pc_board_policy_settings')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchOne();
	}

	private function findDoneStackId(int $boardId): int {
		$qb = $this->db->getQueryBuilder();
		return (int)$qb->select('id')
			->from('deck_stacks')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq($qb->func()->lower('title'), $qb->createNamedParameter('done')))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1)
			->executeQuery()
			->fetchOne();
	}

	/** @return int[] */
	private function findStackIds(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', $qb->select('id')
			->from('deck_stacks')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchFirstColumn());
	}

	/** @return int[] */
	private function findCardIds(int $stackId): array {
		$qb = $this->db->getQueryBuilder();
		return array_map('intval', $qb->select('id')
			->from('deck_cards')
			->where($qb->expr()->eq('stack_id', $qb->createNamedParameter($stackId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->executeQuery()
			->fetchFirstColumn());
	}

	private function hasUnmetDependencies(int $cardId): bool {
		$qb = $this->db->getQueryBuilder();
		$count = $qb->selectAlias($qb->func()->count('d.id'), 'dependency_count')
			->from('deck_dependent_cards', 'r')
			->innerJoin('r', 'deck_cards', 'd', $qb->expr()->eq('d.id', 'r.dependent_card_id'))
			->where($qb->expr()->eq('r.card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('d.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('d.done'))
			->executeQuery()
			->fetchOne();

		return (int)$count > 0;
	}
}
