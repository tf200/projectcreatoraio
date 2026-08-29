<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use DateTimeInterface;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProjectActivityEventMapper extends QBMapper {
	public const TABLE_NAME = 'project_activity_events';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE_NAME, ProjectActivityEvent::class);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	public function createEvent(
		int $projectId,
		string $eventType,
		?string $actorUid,
		?string $actorDisplayName,
		array $payload = [],
		?DateTimeInterface $occurredAt = null,
		string $source = 'internal',
	): ProjectActivityEvent {
		$event = new ProjectActivityEvent();
		$event->setProjectId($projectId);
		$event->setEventType($eventType);
		$event->setSource($source);
		$event->setActorUid($actorUid);
		$event->setActorDisplayName($actorDisplayName);
		$event->setPayloadArray($payload);
		$event->setOccurredAt($occurredAt instanceof DateTimeInterface ? DateTime::createFromInterface($occurredAt) : new DateTime());

		return $this->insert($event);
	}

	/**
	 * @return ProjectActivityEvent[]
	 */
	public function findForDigest(int $projectId, int $afterEventId = 0, ?DateTimeInterface $notBefore = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterEventId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		if ($notBefore instanceof DateTimeInterface) {
			$qb->andWhere($qb->expr()->gte('occurred_at', $qb->createNamedParameter(DateTime::createFromInterface($notBefore), IQueryBuilder::PARAM_DATETIME_MUTABLE)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * @return ProjectActivityEvent[]
	 */
	public function findForProject(int $projectId, ?string $eventType = null, int $limit = 20, int $offset = 0, ?string $source = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		if ($eventType !== null && $eventType !== '') {
			$qb->andWhere($qb->expr()->eq('event_type', $qb->createNamedParameter($eventType, IQueryBuilder::PARAM_STR)));
		}

		if ($source !== null && $source !== '') {
			$qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source, IQueryBuilder::PARAM_STR)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * @param array{timestamp?: string, id?: int}|null $cursor
	 * @return ProjectActivityEvent[]
	 */
	public function findForProjectBefore(int $projectId, int $limit = 20, ?string $source = null, ?array $cursor = null, ?string $excludeSource = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->orderBy('occurred_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($limit);

		if ($source !== null && $source !== '') {
			$qb->andWhere($qb->expr()->eq('source', $qb->createNamedParameter($source, IQueryBuilder::PARAM_STR)));
		}
		if ($excludeSource !== null && $excludeSource !== '') {
			$qb->andWhere($qb->expr()->neq('source', $qb->createNamedParameter($excludeSource, IQueryBuilder::PARAM_STR)));
		}

		$timestamp = (string)($cursor['timestamp'] ?? '');
		$id = (int)($cursor['id'] ?? 0);
		if ($timestamp !== '' && $id > 0) {
			$before = new DateTime($timestamp);
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->lt('occurred_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
				$qb->expr()->andX(
					$qb->expr()->eq('occurred_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)),
					$qb->expr()->lt('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)),
				),
			));
		}

		return $this->findEntities($qb);
	}

	public function deleteByProject(int $projectId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
