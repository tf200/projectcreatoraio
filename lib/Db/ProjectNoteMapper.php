<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\AppFramework\Db\DoesNotExistException;

class ProjectNoteMapper extends QBMapper
{
    public const TABLE_NAME = "project_notes";

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE_NAME, ProjectNote::class);
    }

    /**
     * Create a new note
     */
    public function createNote(
        int $projectId,
        string $userId,
        string $title,
        string $content,
        string $visibility,
        string $noteType = ProjectNote::NOTE_TYPE_GENERAL,
    ): ProjectNote {
        $note = new ProjectNote();
        $note->setProjectId($projectId);
        $note->setUserId($userId);
        $note->setTitle($title);
        $note->setContent($content);
        $note->setVisibility($visibility);
        $note->setNoteType(ProjectNote::normalizeNoteType($noteType));

        $now = new \DateTime();
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);

        return $this->insert($note);
    }

    /**
     * Find a note by ID
     */
    public function find(int $id): ?ProjectNote {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Get all notes for a project
     */
    public function findByProject(int $projectId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get public notes for a project
     */
    public function findPublicByProject(int $projectId, string $noteType = '', ?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter('public')))
            ->orderBy('created_at', 'DESC');

        if (ProjectNote::isValidNoteType($noteType)) {
            $qb->andWhere($qb->expr()->eq('note_type', $qb->createNamedParameter($noteType)));
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }

    /**
     * Get private notes for a project and user
     */
    public function findPrivateByProjectAndUser(int $projectId, string $userId, string $noteType = '', ?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter('private')))
            ->orderBy('created_at', 'DESC');

        if (ProjectNote::isValidNoteType($noteType)) {
            $qb->andWhere($qb->expr()->eq('note_type', $qb->createNamedParameter($noteType)));
        }

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }

    /**
     * Count public notes for a project
     */
    public function countPublicByProject(int $projectId, string $noteType = ''): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter('public')));

        if (ProjectNote::isValidNoteType($noteType)) {
            $qb->andWhere($qb->expr()->eq('note_type', $qb->createNamedParameter($noteType)));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int) ($row['count'] ?? 0);
    }

    /**
     * Count private notes for a project and user
     */
    public function countPrivateByProjectAndUser(int $projectId, string $userId, string $noteType = ''): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter('private')));

        if (ProjectNote::isValidNoteType($noteType)) {
            $qb->andWhere($qb->expr()->eq('note_type', $qb->createNamedParameter($noteType)));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (int) ($row['count'] ?? 0);
    }

    /**
     * Update a note
     */
    public function updateNote(ProjectNote $note): ProjectNote {
        $note->setUpdatedAt(new \DateTime());
        return $this->update($note);
    }

    /**
     * Delete a note
     */
    public function deleteNote(int $id): bool {
        $note = $this->find($id);
        if ($note === null) {
            return false;
        }

        $this->delete($note);
        return true;
    }

    /**
     * Delete all notes for a project
     */
    public function deleteByProject(int $projectId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
    }
}
