<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010030Date20260726000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('pc_board_policy_settings')) {
			$table = $schema->getTable('pc_board_policy_settings');
			if (!$table->hasColumn('policy_version')) {
				$table->addColumn('policy_version', Types::INTEGER, [
					'notnull' => true,
					'default' => 1,
				]);
			}
			if (!$table->hasColumn('revision')) {
				$table->addColumn('revision', Types::BIGINT, [
					'unsigned' => true,
					'notnull' => true,
					'default' => 0,
				]);
			}
		}

		if (!$schema->hasTable('pc_board_policy_default_drasci')) {
			$table = $schema->createTable('pc_board_policy_default_drasci');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('board_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('drasci_role', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_bpdd_pk');
			$table->addIndex(['board_id', 'action'], 'pc_bpdd_board_act_idx');
			$table->addUniqueIndex(['board_id', 'action', 'drasci_role'], 'pc_bpdd_board_act_role_uidx');
		}

		if (!$schema->hasTable('pc_card_policy_overrides')) {
			$table = $schema->createTable('pc_card_policy_overrides');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('card_policy_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_cpo_pk');
			$table->addIndex(['card_policy_id'], 'pc_cpo_policy_idx');
			$table->addUniqueIndex(['card_policy_id', 'action'], 'pc_cpo_policy_act_uidx');
		}

		return $schema;
	}
}
