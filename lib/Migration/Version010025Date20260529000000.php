<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010025Date20260529000000 extends SimpleMigrationStep {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('project_member_roles')) {
			$table = $schema->createTable('project_member_roles');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'unsigned' => true,
				'notnull' => true,
			]);
			$table->addColumn('project_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('drasci_role', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['project_id', 'user_id'], 'pmr_project_user_uniq');
			$table->addIndex(['project_id'], 'pmr_project_idx');
			$table->addIndex(['user_id'], 'pmr_user_idx');
		}

		return $schema;
	}
}
