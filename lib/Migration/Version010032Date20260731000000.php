<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version010032Date20260731000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('project_member_roles')) {
			return null;
		}

		$table = $schema->getTable('project_member_roles');
		if ($table->hasIndex('pmr_project_user_uniq')) {
			$table->dropIndex('pmr_project_user_uniq');
		}
		if (!$table->hasIndex('pmr_project_user_role_uniq')) {
			$table->addUniqueIndex(['project_id', 'user_id', 'drasci_role'], 'pmr_project_user_role_uniq');
		}

		return $schema;
	}
}
