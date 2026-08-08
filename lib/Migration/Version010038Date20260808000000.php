<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010038Date20260808000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('project_notes')) {
			return null;
		}

		$table = $schema->getTable('project_notes');
		if ($table->hasIndex('idx_notes_project_visibility_type')) {
			return null;
		}

		$table->addIndex(['project_id', 'visibility', 'note_type'], 'idx_notes_project_visibility_type');
		return $schema;
	}
}
