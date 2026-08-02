<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010035Date20260801000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('pc_board_profiles')) {
			$table = $schema->createTable('pc_board_profiles');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('organization_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('creator_uid', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('schema_version', Types::INTEGER, ['notnull' => true, 'default' => 2]);
			$table->addColumn('payload_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['organization_id', 'name'], 'pc_profile_org_name_uniq');
			$table->addIndex(['organization_id'], 'pc_profile_org_idx');
		}
		if (!$schema->hasTable('pc_board_profile_cards')) {
			$table = $schema->createTable('pc_board_profile_cards');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('profile_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('board_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('portable_ref', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('card_id', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['profile_id', 'board_id', 'portable_ref'], 'pc_profile_card_ref_uniq');
			$table->addIndex(['card_id'], 'pc_profile_card_id_idx');
		}
		return $schema;
	}
}
