<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The day work on site ends. Handover comes before the start, so it is not the end.
 */
class Version010050Date20261016090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('custom_projects')) {
			return null;
		}

		$table = $schema->getTable('custom_projects');
		if (!$table->hasColumn('actual_end_date')) {
			$table->addColumn('actual_end_date', Types::DATE, [
				'notnull' => false,
			]);
		}

		return $schema;
	}
}
