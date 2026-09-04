<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProjectDirectChatMapper extends QBMapper {
	public const TABLE_NAME = 'proj_direct_chats';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, ProjectDirectChat::class);
	}

	/**
	 * Find direct chat between two users in a project (using canonical pair).
	 */
	public function findPair(int $projectId, string $userA, string $userB): ?ProjectDirectChat {
		[$u1, $u2] = ProjectDirectChat::canonicalUserPair($userA, $userB);

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user1_id', $qb->createNamedParameter($u1)))
			->andWhere($qb->expr()->eq('user2_id', $qb->createNamedParameter($u2)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Find all direct chats involving a user in a given project.
	 *
	 * @return ProjectDirectChat[]
	 */
	public function findByProjectAndUser(int $projectId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('user1_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('user2_id', $qb->createNamedParameter($userId))
				)
			)
			->orderBy('updated_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all direct chats for a project.
	 *
	 * @return ProjectDirectChat[]
	 */
	public function findByProject(int $projectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find a direct chat by its Talk conversation token.
	 */
	public function findByTalkConversationToken(string $token): ?ProjectDirectChat {
		$token = trim($token);
		if ($token === '') {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('talk_conversation_token', $qb->createNamedParameter($token)));

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException) {
			return null;
		}
	}

	/**
	 * Create and insert a new direct chat record.
	 */
	public function createChat(int $projectId, string $userA, string $userB, string $token): ProjectDirectChat {
		[$u1, $u2] = ProjectDirectChat::canonicalUserPair($userA, $userB);
		$now = new DateTime();

		$entity = new ProjectDirectChat();
		$entity->setProjectId($projectId);
		$entity->setUser1Id($u1);
		$entity->setUser2Id($u2);
		$entity->setTalkConversationToken(trim($token));
		$entity->setCreatedAt($now);
		$entity->setUpdatedAt($now);

		return $this->insert($entity);
	}

	/**
	 * Delete all direct chats for a project.
	 */
	public function deleteByProject(int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Delete a specific direct chat by its Talk conversation token.
	 */
	public function deleteByToken(string $token): void {
		$token = trim($token);
		if ($token === '') {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('talk_conversation_token', $qb->createNamedParameter($token)))
			->executeStatement();
	}
}
