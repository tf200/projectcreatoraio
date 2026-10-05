<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Named What-If scenarios, saved per project so members can come back to them and compare them.
 */
class Version010047Date20261005150000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('project_timeline_scenarios')) {
			return null;
		}

		$table = $schema->createTable('project_timeline_scenarios');
		$table->addColumn('id', Types::INTEGER, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('project_id', Types::INTEGER, [
			'notnull' => true,
		]);
		$table->addColumn('name', Types::STRING, [
			'length' => 120,
			'notnull' => true,
		]);
		$table->addColumn('changes', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('created_by', Types::STRING, [
			'length' => 64,
			'notnull' => true,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['project_id'], 'idx_tl_scenarios_project');

		return $schema;
	}
}
