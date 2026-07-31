<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010033Date20260731010000 extends SimpleMigrationStep {
	public function __construct(private readonly IDBConnection $db) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('pc_board_policy_default_drasci')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('board_id', 'action', 'drasci_role')
			->from('pc_board_policy_default_drasci')
			->where($qb->expr()->in('action', $qb->createNamedParameter(['sign', 'verify'], IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()
			->fetchAll();

		$defaults = [];
		foreach ($rows as $row) {
			$defaults[(int)$row['board_id']][(string)$row['action']][] = (string)$row['drasci_role'];
		}

		foreach ($defaults as $boardId => $actions) {
			$changed = false;
			$signRoles = array_values(array_unique($actions['sign'] ?? []));
			sort($signRoles);
			if ($signRoles === ['accountable']) {
				$this->replaceDefault((int)$boardId, 'sign', 'signer');
				$changed = true;
			}

			$verifyRoles = array_values(array_unique($actions['verify'] ?? []));
			sort($verifyRoles);
			if ($verifyRoles === ['accountable', 'responsible']) {
				$this->replaceDefault((int)$boardId, 'verify', 'verifier');
				$changed = true;
			}

			if ($changed && $schema->hasTable('pc_board_policy_settings')) {
				$update = $this->db->getQueryBuilder();
				$update->update('pc_board_policy_settings')
					->set('revision', $update->createFunction('revision + 1'))
					->where($update->expr()->eq('board_id', $update->createNamedParameter((int)$boardId, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		}
	}

	private function replaceDefault(int $boardId, string $action, string $role): void {
		$delete = $this->db->getQueryBuilder();
		$delete->delete('pc_board_policy_default_drasci')
			->where($delete->expr()->eq('board_id', $delete->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($delete->expr()->eq('action', $delete->createNamedParameter($action)))
			->executeStatement();

		$insert = $this->db->getQueryBuilder();
		$insert->insert('pc_board_policy_default_drasci')
			->values([
				'board_id' => $insert->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
				'action' => $insert->createNamedParameter($action),
				'drasci_role' => $insert->createNamedParameter($role),
			])
			->executeStatement();
	}
}
