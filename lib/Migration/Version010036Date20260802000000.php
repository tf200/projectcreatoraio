<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010036Date20260802000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('pc_board_profiles')) {
			return null;
		}

		$table = $schema->getTable('pc_board_profiles');
		if ($table->hasColumn('schema_version')) {
			$table->getColumn('schema_version')->setDefault(2);
		}

		return $schema;
	}
}
