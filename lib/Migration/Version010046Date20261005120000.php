<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use DateTime;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Members picked when a project was created got no DRASCIVS role, so the
 * card policy hid every card from them. Give each project member without a
 * role the Informed role, which lets them see the board and nothing more.
 */
class Version010046Date20261005120000 extends SimpleMigrationStep
{
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$withRoles = [];
		$roles = $this->db->getQueryBuilder();
		$roles->selectDistinct(['project_id', 'user_id'])->from('project_member_roles');
		$result = $roles->executeQuery();
		while ($row = $result->fetch()) {
			$withRoles[(int)$row['project_id']][(string)$row['user_id']] = true;
		}
		$result->closeCursor();

		$members = $this->db->getQueryBuilder();
		$members->select('p.id', 'm.uid')
			->from('custom_projects', 'p')
			->innerJoin('p', 'group_user', 'm', $members->expr()->eq('p.project_group_gid', 'm.gid'));
		$result = $members->executeQuery();
		$now = new DateTime();
		$added = 0;
		while ($row = $result->fetch()) {
			$projectId = (int)$row['id'];
			$userId = (string)$row['uid'];
			if (isset($withRoles[$projectId][$userId])) {
				continue;
			}

			$insert = $this->db->getQueryBuilder();
			$insert->insert('project_member_roles')
				->values([
					'project_id' => $insert->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
					'user_id' => $insert->createNamedParameter($userId),
					'drasci_role' => $insert->createNamedParameter('informed'),
					'created_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
					'updated_at' => $insert->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE),
				])
				->executeStatement();
			$withRoles[$projectId][$userId] = true;
			$added++;
		}
		$result->closeCursor();

		if ($added > 0) {
			$output->info(sprintf('Gave %d project member(s) without a role the Informed role.', $added));
		}
	}
}
