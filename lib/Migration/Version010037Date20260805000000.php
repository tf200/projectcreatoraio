<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010037Date20260805000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('project_notes')) {
			return null;
		}

		$table = $schema->getTable('project_notes');
		if (!$table->hasColumn('note_type')) {
			$table->addColumn('note_type', Types::STRING, [
				'length' => 32,
				'notnull' => true,
				'default' => 'general',
			]);
			return $schema;
		}

		return null;
	}
}
