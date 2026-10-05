<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use Closure;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Records why each user is a project member: added manually (team_id 0) or
 * through an assigned organization team. Team-driven removals only drop
 * members that have no remaining origin.
 */
class Version010045Date20261005090000 extends SimpleMigrationStep
{
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable(ProjectMemberSourceMapper::TABLE_NAME)) {
			return null;
		}

		$table = $schema->createTable(ProjectMemberSourceMapper::TABLE_NAME);
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('project_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('team_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
			'default' => 0,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['project_id', 'user_id', 'team_id'], 'pc_msrc_unique');
		$table->addIndex(['team_id'], 'pc_msrc_team');

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$existing = $this->db->getQueryBuilder();
		$existing->select($existing->func()->count('*'))->from(ProjectMemberSourceMapper::TABLE_NAME);
		if ((int)$existing->executeQuery()->fetchOne() > 0) {
			return;
		}

		$assignedTeams = $this->loadAssignedTeams();
		$teamMembers = [];

		$projects = $this->db->getQueryBuilder();
		$projects->select('id', 'owner_id', 'project_group_gid')->from('custom_projects');
		$result = $projects->executeQuery();
		while ($project = $result->fetch()) {
			$projectId = (int)$project['id'];
			$ownerId = trim((string)($project['owner_id'] ?? ''));
			$teamId = $assignedTeams[$projectId] ?? 0;
			if ($teamId > 0 && !isset($teamMembers[$teamId])) {
				$teamMembers[$teamId] = $this->loadTeamMembers($teamId);
			}

			$memberIds = $this->loadGroupMembers(trim((string)($project['project_group_gid'] ?? '')));
			if ($ownerId !== '') {
				$memberIds[$ownerId] = true;
			}

			foreach (array_keys($memberIds) as $userId) {
				$userId = (string)$userId;
				// Members of the currently assigned team are treated as team-sourced;
				// everyone else (and always the owner) as manually added.
				$source = $userId !== $ownerId && $teamId > 0 && isset($teamMembers[$teamId][$userId])
					? $teamId
					: ProjectMemberSourceMapper::MANUAL;
				$this->insert($projectId, $userId, $source);
			}
		}
		$result->closeCursor();
	}

	/** @return array<int, int> project id => team id */
	private function loadAssignedTeams(): array
	{
		if (!$this->db->tableExists('organization_project_teams')) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('project_id', 'team_id')->from('organization_project_teams');
		$teams = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$teams[(int)$row['project_id']] = (int)$row['team_id'];
		}
		return $teams;
	}

	/** @return array<string, true> */
	private function loadTeamMembers(int $teamId): array
	{
		if (!$this->db->tableExists('organization_team_members')) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_uid')
			->from('organization_team_members')
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		$members = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$members[(string)$row['user_uid']] = true;
		}
		return $members;
	}

	/** @return array<string, true> */
	private function loadGroupMembers(string $groupId): array
	{
		if ($groupId === '') {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('uid')
			->from('group_user')
			->where($qb->expr()->eq('gid', $qb->createNamedParameter($groupId)));
		$members = [];
		foreach ($qb->executeQuery()->fetchAll() as $row) {
			$members[(string)$row['uid']] = true;
		}
		return $members;
	}

	private function insert(int $projectId, string $userId, int $teamId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert(ProjectMemberSourceMapper::TABLE_NAME)
			->values([
				'project_id' => $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT),
				'user_id' => $qb->createNamedParameter($userId),
				'team_id' => $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
	}
}
