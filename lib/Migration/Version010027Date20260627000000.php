<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010027Date20260627000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pc_board_policy_settings')) {
			$table = $schema->createTable('pc_board_policy_settings');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('board_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('permission_mode', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('approved_stack_id', Types::BIGINT, ['unsigned' => true, 'notnull' => false]);
			$table->addColumn('done_stack_id', Types::BIGINT, ['unsigned' => true, 'notnull' => false]);
			$table->setPrimaryKey(['id'], 'pc_bps_pk');
			$table->addUniqueIndex(['board_id'], 'pc_bps_board_uidx');
		}

		if (!$schema->hasTable('pc_board_policy_roles')) {
			$table = $schema->createTable('pc_board_policy_roles');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('board_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('role_key', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('role_name', Types::STRING, ['length' => 128, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_bpr_pk');
			$table->addIndex(['board_id'], 'pc_bpr_board_idx');
			$table->addUniqueIndex(['board_id', 'role_key'], 'pc_bpr_board_role_uidx');
		}

		if (!$schema->hasTable('pc_board_policy_memberships')) {
			$table = $schema->createTable('pc_board_policy_memberships');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('role_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('participant_type', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('participant_id', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_bpm_pk');
			$table->addIndex(['role_id'], 'pc_bpm_role_idx');
			$table->addUniqueIndex(['role_id', 'participant_type', 'participant_id'], 'pc_bpm_role_part_uidx');
		}

		if (!$schema->hasTable('pc_board_policy_default_roles')) {
			$table = $schema->createTable('pc_board_policy_default_roles');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('board_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('role_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_bpdr_pk');
			$table->addIndex(['board_id'], 'pc_bpdr_board_idx');
			$table->addUniqueIndex(['board_id', 'action', 'role_id'], 'pc_bpdr_board_act_role_uidx');
		}

		if (!$schema->hasTable('pc_card_policies')) {
			$table = $schema->createTable('pc_card_policies');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('card_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('board_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_cp_pk');
			$table->addUniqueIndex(['card_id'], 'pc_cp_card_uidx');
			$table->addIndex(['board_id'], 'pc_cp_board_idx');
		}

		if (!$schema->hasTable('pc_card_policy_roles')) {
			$table = $schema->createTable('pc_card_policy_roles');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('card_policy_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('action', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('role_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->setPrimaryKey(['id'], 'pc_cpr_pk');
			$table->addIndex(['card_policy_id'], 'pc_cpr_policy_idx');
			$table->addUniqueIndex(['card_policy_id', 'action', 'role_id'], 'pc_cpr_policy_act_role_uidx');
		}

		return $schema;
	}
}
