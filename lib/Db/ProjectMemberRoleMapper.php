<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class ProjectMemberRoleMapper extends QBMapper {
    public const TABLE_NAME = 'project_member_roles';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE_NAME, ProjectMemberRole::class);
    }

    /**
     * Find a role record for a project user.
     */
    public function findByProjectAndUser(int $projectId, string $userId): ?ProjectMemberRole {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
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
     * Upsert a DRASCI role for a project user.
     */
    public function upsertRole(int $projectId, string $userId, string $drasciRole): ProjectMemberRole {
        $existing = $this->findByProjectAndUser($projectId, $userId);
        $now = new \DateTime();

        if ($existing !== null) {
            $existing->setDrasciRole($drasciRole);
            $existing->setUpdatedAt($now);
            return $this->update($existing);
        }

        $entity = new ProjectMemberRole();
        $entity->setProjectId($projectId);
        $entity->setUserId($userId);
        $entity->setDrasciRole($drasciRole);
        $entity->setCreatedAt($now);
        $entity->setUpdatedAt($now);
        return $this->insert($entity);
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
