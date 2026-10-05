<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Weeks a team works on site, so actual start + weeks gives the planned handover.
 */
class Version010048Date20261005180000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('custom_projects')) {
			return null;
		}

		$table = $schema->getTable('custom_projects');
		if (!$table->hasColumn('execution_weeks')) {
			$table->addColumn('execution_weeks', Types::INTEGER, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
