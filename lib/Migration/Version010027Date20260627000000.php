<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010027Date20260627000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('pca_geocode_cache')) {
			$table = $schema->createTable('pca_geocode_cache');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
			$table->addColumn('addr_hash', Types::STRING, ['length' => 64, 'notnull' => true]);
			$table->addColumn('lat', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
			$table->addColumn('lng', Types::DECIMAL, ['precision' => 10, 'scale' => 7, 'notnull' => false]);
			$table->addColumn('display_name', Types::STRING, ['length' => 255, 'notnull' => false]);
			$table->addColumn('source', Types::STRING, ['length' => 32, 'notnull' => true]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'pca_geocache_pk');
			$table->addUniqueIndex(['addr_hash'], 'pca_geocache_hash_idx');
		}

		return $schema;
	}
}
