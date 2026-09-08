<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010041Date20260907120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('custom_projects')) {
			return null;
		}

		$table = $schema->getTable('custom_projects');
		if (!$table->hasColumn('desired_start_date')) {
			$table->addColumn('desired_start_date', Types::DATE, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
