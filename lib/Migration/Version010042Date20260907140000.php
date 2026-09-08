<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010042Date20260907140000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('project_timeline_phases')) {
			$table = $schema->createTable('project_timeline_phases');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
			]);

			$table->addColumn('project_id', Types::BIGINT, [
				'notnull' => true,
			]);

			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);

			$table->addColumn('category', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);

			$table->addColumn('order_index', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);

			$table->addColumn('color', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => '#3b82f6',
			]);

			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => false,
			]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['project_id'], 'ptp_project_idx');
		}

		if ($schema->hasTable('project_timeline_items')) {
			$itemsTable = $schema->getTable('project_timeline_items');

			if (!$itemsTable->hasColumn('phase_id')) {
				$itemsTable->addColumn('phase_id', Types::BIGINT, [
					'notnull' => false,
				]);
				$itemsTable->addIndex(['phase_id'], 'pti_phase_idx');
			}

			if (!$itemsTable->hasColumn('deck_card_id')) {
				$itemsTable->addColumn('deck_card_id', Types::BIGINT, [
					'notnull' => false,
				]);
				$itemsTable->addIndex(['deck_card_id'], 'pti_deck_card_idx');
			}

			if (!$itemsTable->hasColumn('duration_days')) {
				$itemsTable->addColumn('duration_days', Types::INTEGER, [
					'notnull' => false,
				]);
			}

			if (!$itemsTable->hasColumn('status')) {
				$itemsTable->addColumn('status', Types::STRING, [
					'notnull' => false,
					'length' => 32,
					'default' => 'not_started',
				]);
			}

			if (!$itemsTable->hasColumn('planned_end_date')) {
				$itemsTable->addColumn('planned_end_date', Types::DATE, [
					'notnull' => false,
				]);
			}
		}

		return $schema;
	}
}
