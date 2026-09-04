<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010040Date20260904000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('proj_direct_chats')) {
			$table = $schema->createTable('proj_direct_chats');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);
			$table->addColumn('project_id', Types::BIGINT, [
				'notnull' => true,
			]);
			$table->addColumn('user1_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('user2_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('talk_conversation_token', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['project_id', 'user1_id', 'user2_id'], 'pdc_proj_users_uniq');
			$table->addUniqueIndex(['talk_conversation_token'], 'pdc_token_uniq');
			$table->addIndex(['project_id'], 'pdc_project_idx');
			$table->addIndex(['user1_id'], 'pdc_user1_idx');
			$table->addIndex(['user2_id'], 'pdc_user2_idx');
		}

		return $schema;
	}
}
