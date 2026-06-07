<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010026Date20260607000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('project_signing_requests')) {
			$table = $schema->createTable('project_signing_requests');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('project_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('file_id', Types::BIGINT, ['unsigned' => true, 'notnull' => true]);
			$table->addColumn('file_path', Types::STRING, ['length' => 1024, 'notnull' => true]);
			$table->addColumn('file_name', Types::STRING, ['length' => 255, 'notnull' => true]);
			$table->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('signature_flow', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('signers_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('libresign_file_id', Types::STRING, ['length' => 128, 'notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('last_error', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id'], 'psr_pk');
			$table->addIndex(['project_id'], 'psr_project_idx');
			$table->addIndex(['project_id', 'file_id'], 'psr_project_file_idx');
			$table->addIndex(['status'], 'psr_status_idx');
		}

		return $schema;
	}
}
