<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;

class ProjectMemberRoleMapper extends QBMapper {
    public const TABLE_NAME = 'project_member_roles';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE_NAME, ProjectMemberRole::class);
    }

    /**
     * Find all DRASCIVS roles for a project user.
     *
     * @return ProjectMemberRole[]
     */
    public function findByProjectAndUser(int $projectId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('drasci_role', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find all role records for a project.
     *
     * @return ProjectMemberRole[]
     */
    public function findByProject(int $projectId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('user_id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Replace all DRASCIVS roles for a project user.
     *
     * @param string[] $drasciRoles
     */
    public function replaceRoles(int $projectId, string $userId, array $drasciRoles): void {
        $this->deleteByProjectAndUser($projectId, $userId);
        $now = new \DateTime();

        foreach (array_values(array_unique($drasciRoles)) as $drasciRole) {
            $entity = new ProjectMemberRole();
            $entity->setProjectId($projectId);
            $entity->setUserId($userId);
            $entity->setDrasciRole($drasciRole);
            $entity->setCreatedAt($now);
            $entity->setUpdatedAt($now);
            $this->insert($entity);
        }
    }

    public function deleteByProjectAndUser(int $projectId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->executeStatement();
    }

    /**
     * Delete all role records for a project.
     */
    public function deleteByProject(int $projectId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
