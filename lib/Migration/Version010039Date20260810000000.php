<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010039Date20260810000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('custom_projects')) {
			return null;
		}

		$table = $schema->getTable('custom_projects');
		if (!$table->hasColumn('group_folder_id')) {
			$table->addColumn('group_folder_id', Types::BIGINT, [
				'notnull' => false,
			]);
		}
		if (!$table->hasIndex('custom_projects_group_folder')) {
			$table->addUniqueIndex(['group_folder_id'], 'custom_projects_group_folder');
		}

		return $schema;
	}
}
