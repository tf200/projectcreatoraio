<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\IDBConnection;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use DateTime;
use DateTimeInterface;
use OCA\Organization\Db\Organization;
use OCP\AppFramework\Db\DoesNotExistException;

class ProjectMapper extends QBMapper
{
    public const TABLE_NAME = "custom_projects";
    public function __construct(
        IDBConnection $db,
        private PrivateFolderLinkMapper $linkMapper
    ) {
        parent::__construct($db, self::TABLE_NAME, Project::class);
    }

    public function createProject(
        ?object $organization,
        string $name,
        string $number,
        int $type,
        string $description,
        string $ownerId,
        ?string $boardId,
        string $projectGroupGid,
        ?string $talkConversationToken,
        int $folderId,
        string $folderPath,
        array $privateFolders,
        ?string $whiteBoardId,
        ?int $requiredPreparationWeeks = null,
        ?string $clientName = null,
        ?string $clientRole = null,
        ?string $clientPhone = null,
        ?string $clientEmail = null,
        ?string $clientAddress = null,
        ?string $locStreet = null,
        ?string $locCity = null,
        ?string $locZip = null,
    ) {
        $project = new Project();

        $project->setName($name);
        $project->setNumber($number);
        $project->setType($type);
        $project->setDescription($description);
        $project->setOwnerId($ownerId);
        $project->setBoardId($boardId);
        $project->setProjectGroupGid($projectGroupGid);
        $project->setTalkConversationToken($talkConversationToken);
        $project->setFolderId($folderId);
        $project->setFolderPath($folderPath);
        $project->setClientName($clientName);
        $project->setClientRole($clientRole);
        $project->setClientPhone($clientPhone);
        $project->setClientEmail($clientEmail);
        $project->setClientAddress($clientAddress);
        $project->setLocStreet($locStreet);
        $project->setLocCity($locCity);
        $project->setLocZip($locZip);
        $project->setStatus(ProjectStatus::ACTIVE);
        $project->setOrganizationId($organization !== null ? (int) $organization->getId() : null);
        $project->setWhiteBoardId($whiteBoardId);
        $project->setRequiredPreparationWeeks((int) ($requiredPreparationWeeks ?? 0));

        $now = new DateTime();
        $project->setCreatedAt($now);
        $project->setUpdatedAt($now);


        $insertedProject = $this->insert($project);

        foreach ($privateFolders as $privateFolder) {
            $this->linkMapper->createLink(
                $insertedProject->getId(),
                $privateFolder['userId'],
                $privateFolder['folderId'],
                $privateFolder['path']
            );
        }

        return $insertedProject;
    }

    public function find(int $id)
    {
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

    public function search(string $name, int $limit, int $offset)
    {
        $qb = $this->db->getQueryBuilder();

        $searchTerm = strtolower($name);
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where('LOWER(name) LIKE ' . $qb->createNamedParameter('%' . $searchTerm . '%'));

        return $this->findEntities($qb);
    }

    public function list(int $limit = null, int $offset = null)
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }


    public function findByUserId(string $userId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('p.*')
            ->from(self::TABLE_NAME, 'p')
            ->innerJoin(
                'p',
                'group_user',
                'm',
                'p.project_group_gid = m.gid'
            )
            ->where(
                $qb->expr()->eq('m.uid', $qb->createNamedParameter($userId))
            )
            ->orderBy('p.created_at', 'DESC');

        return $this->findEntities($qb);
    }

    public function findByUserIdAndOrganizationId(string $userId, int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('p.*')
            ->from(self::TABLE_NAME, 'p')
            ->innerJoin(
                'p',
                'group_user',
                'm',
                'p.project_group_gid = m.gid'
            )
            ->where(
                $qb->expr()->eq('m.uid', $qb->createNamedParameter($userId))
            )
            ->andWhere(
                $qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT))
            )
            ->orderBy('p.created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @return Project[]
     */
    public function findOwnedByUserAndOrganization(string $userId, int $organizationId): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $qb->expr()->eq('owner_id', $qb->createNamedParameter($userId))
            )
            ->andWhere(
                $qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT))
            )
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    public function transferOwnershipByOrg(string $sourceUserId, string $targetUserId, int $organizationId): int
    {
        $qb = $this->db->getQueryBuilder();

        return $qb->update(self::TABLE_NAME)
            ->set('owner_id', $qb->createNamedParameter($targetUserId))
            ->where(
                $qb->expr()->eq('owner_id', $qb->createNamedParameter($sourceUserId))
            )
            ->andWhere(
                $qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT))
            )
            ->executeStatement();
    }

    public function findByOrganizationId(int $organizationId, int $limit = null, int $offset = null): array
    {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT))
            )
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * @return Project[]
     */
    public function findArchivedBefore(DateTimeInterface $cutoff, int $limit = 50): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(ProjectStatus::ARCHIVED, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->isNotNull('archived_at'))
            ->andWhere($qb->expr()->lte('archived_at', $qb->createNamedParameter(DateTime::createFromInterface($cutoff), IQueryBuilder::PARAM_DATETIME_MUTABLE)))
            ->orderBy('archived_at', 'ASC')
            ->setMaxResults(max(1, $limit));

        return $this->findEntities($qb);
    }

    public function deleteProject(Project $project): void
    {
        $this->delete($project);
    }

    /**
     * Finds a project by its associated board_id.
     *
     * @param int $boardId The ID of the board.
     * @return Project|null The found project entity or null if not found.
     */
    public function findByBoardId($boardId)
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $qb->expr()->eq('board_id', $qb->createNamedParameter((string) $boardId))
            );
        try {
            $row = $qb->executeQuery()->fetch();
            return ($row === false)
                ? null
                : Project::fromRow($row);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findByTalkConversationToken(string $talkConversationToken): ?Project
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $qb->expr()->eq('talk_conversation_token', $qb->createNamedParameter($talkConversationToken))
            );

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findByFolderId(int $folderId): ?Project
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $qb->expr()->eq('folder_id', $qb->createNamedParameter($folderId, IQueryBuilder::PARAM_INT))
            );

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findByWhiteBoardId(int $whiteBoardId)
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where(
                $qb->expr()->eq('white_board_id', $qb->createNamedParameter((string) $whiteBoardId))
            );

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * @return Project[]
     */
    public function listDeckTrackedProjects(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME)
            ->where($qb->expr()->isNotNull('board_id'))
            ->andWhere($qb->expr()->neq('board_id', $qb->createNamedParameter('')))
            ->orderBy('id', 'ASC');

        return $this->findEntities($qb);
    }

    public function findByCardId(int $cardId): ?Project
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('p.*')
            ->from(self::TABLE_NAME, 'p')
            ->innerJoin('p', 'deck_stacks', 's', 'p.board_id = s.board_id')
            ->innerJoin('s', 'deck_cards', 'c', 's.id = c.stack_id')
            ->where($qb->expr()->eq('c.id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    public function findPrivateFolderForUser(int $projectId, string $userId): ?PrivateFolderLink
    {
        return $this->linkMapper->findByProjectAndUser($projectId, $userId);
    }

    public function createPrivateFolderLink(int $projectId, string $userId, int $folderId, string $folderPath): PrivateFolderLink
    {
        return $this->linkMapper->createLink($projectId, $userId, $folderId, $folderPath);
    }

    public function deletePrivateFolderLink(int $projectId, string $userId): void
    {
        $this->linkMapper->deleteByProjectAndUser($projectId, $userId);
    }

    /**
     * @return PrivateFolderLink[]
     */
    public function findAllPrivateFoldersByProject(int $projectId): array
    {
        return $this->linkMapper->findByProject($projectId);
    }

    /**
     * Updates project details using the Project Entity.
     * @param Project $project The project entity with updated values
     * @return Project The updated entity
     */
    public function updateProjectDetails(Project $project): Project
    {
        $project->setUpdatedAt(new DateTime());
        return $this->update($project);
    }
}
