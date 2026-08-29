<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTimeImmutable;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

class NativeDeckActivityReader {
	public function __construct(
		private readonly ?IDBConnection $activityConnection,
		private readonly ?CardMapper $cardMapper,
		private readonly ?BoardMapper $boardMapper,
		private readonly ?PermissionService $permissionService,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function isAvailable(): bool {
		return $this->activityConnection !== null
			&& $this->cardMapper !== null
			&& $this->boardMapper !== null
			&& $this->permissionService !== null;
	}

	/**
	 * @param array{timestamp?: int, id?: int}|null $cursor
	 * @return array{events: list<array<string, mixed>>, hasMore: bool, authoritative: bool}
	 */
	public function read(int $boardId, string $userId, int $limit, ?array $cursor = null): array {
		if (!$this->isAvailable() || $boardId <= 0) {
			return ['events' => [], 'hasMore' => false, 'authoritative' => false];
		}

		try {
			$this->permissionService->checkPermission($this->boardMapper, $boardId, Acl::PERMISSION_READ, $userId);
			$cardIds = $this->findReadableCardIds($boardId, $userId);
			return $this->queryActivity($boardId, $cardIds, $userId, $limit, $cursor);
		} catch (NoPermissionException) {
			return ['events' => [], 'hasMore' => false, 'authoritative' => true];
		} catch (Throwable $e) {
			$this->logger->warning('Unable to read native Deck activity', [
				'exception' => $e,
				'boardId' => $boardId,
				'userId' => $userId,
			]);
			return ['events' => [], 'hasMore' => false, 'authoritative' => false];
		}
	}

	/**
	 * @return list<int>
	 */
	private function findReadableCardIds(int $boardId, string $userId): array {
		$query = $this->cardMapper->queryCardsByBoard($boardId);
		$query->select('c.id');
		$result = $query->executeQuery();
		$cardIds = [];

		while (($cardId = $result->fetchOne()) !== false) {
			try {
				$this->permissionService->checkPermission($this->cardMapper, (int)$cardId, Acl::PERMISSION_READ, $userId);
				$cardIds[] = (int)$cardId;
			} catch (Throwable) {
				// Card policies can make individual cards on an otherwise readable board private.
			}
		}
		$result->closeCursor();

		return $cardIds;
	}

	/**
	 * @param list<int> $cardIds
	 * @param array{timestamp?: int, id?: int}|null $cursor
	 * @return array{events: list<array<string, mixed>>, hasMore: bool, authoritative: bool}
	 */
	protected function queryActivity(int $boardId, array $cardIds, string $userId, int $limit, ?array $cursor): array {
		$pages = [$this->queryObjectActivity('deck_board', [$boardId], $userId, $limit, $cursor)];
		foreach (array_chunk($cardIds, 400) as $cardIdChunk) {
			$pages[] = $this->queryObjectActivity('deck_card', $cardIdChunk, $userId, $limit, $cursor);
		}

		$rows = [];
		$hasMore = false;
		foreach ($pages as $page) {
			$hasMore = $hasMore || $page['hasMore'];
			foreach ($page['rows'] as $row) {
				$rows[(int)$row['activity_id']] = $row;
			}
		}
		usort($rows, static function (array $left, array $right): int {
			return [(int)$right['timestamp'], (int)$right['activity_id']]
				<=> [(int)$left['timestamp'], (int)$left['activity_id']];
		});

		$hasMore = $hasMore || count($rows) > $limit;
		$events = array_map(
			fn (array $row): array => $this->normalizeRow($row, $boardId),
			array_slice($rows, 0, $limit),
		);

		return ['events' => $events, 'hasMore' => $hasMore, 'authoritative' => true];
	}

	/**
	 * @param list<int> $objectIds
	 * @param array{timestamp?: int, id?: int}|null $cursor
	 * @return array{rows: list<array<string, mixed>>, hasMore: bool}
	 */
	protected function queryObjectActivity(string $objectType, array $objectIds, string $userId, int $limit, ?array $cursor): array {
		$qb = $this->activityConnection->getQueryBuilder();
		$qb->select('*')
			->from('activity')
			->where($qb->expr()->eq('affecteduser', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('app', $qb->createNamedParameter('deck')))
			->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter($objectType)))
			->andWhere($qb->expr()->in('object_id', $qb->createNamedParameter($objectIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('timestamp', 'DESC')
			->addOrderBy('activity_id', 'DESC')
			->setMaxResults($limit + 1);

		$timestamp = (int)($cursor['timestamp'] ?? 0);
		$activityId = (int)($cursor['id'] ?? 0);
		if ($timestamp > 0 && $activityId > 0) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->lt('timestamp', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->eq('timestamp', $qb->createNamedParameter($timestamp, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lt('activity_id', $qb->createNamedParameter($activityId, IQueryBuilder::PARAM_INT)),
				),
			));
		}

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetchAssociative()) !== false) {
			$rows[] = $row;
		}
		$result->closeCursor();

		$hasMore = count($rows) > $limit;
		if ($hasMore) {
			$rows = array_slice($rows, 0, $limit);
		}

		return ['rows' => $rows, 'hasMore' => $hasMore];
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function normalizeRow(array $row, int $boardId): array {
		$params = json_decode((string)($row['subjectparams'] ?? ''), true);
		$params = is_array($params) ? $params : [];
		$subject = (string)($row['subject'] ?? 'deck_activity');
		$actorUid = (string)($params['author'] ?? $row['user'] ?? '');
		$objectType = (string)($row['object_type'] ?? '');
		$objectId = (int)($row['object_id'] ?? 0);
		$objectName = (string)($row['object_name'] ?? '');
		$occurredAt = (new DateTimeImmutable('@' . (int)($row['timestamp'] ?? 0)))->format('c');

		$payload = [
			'nativeActivity' => true,
			'nativeSubject' => $subject,
			'boardId' => $boardId,
			'boardTitle' => $this->nestedString($params, 'board', 'title') ?: ($objectType === 'deck_board' ? $objectName : ''),
			'cardId' => $objectType === 'deck_card' ? $objectId : null,
			'cardTitle' => $this->nestedString($params, 'card', 'title') ?: ($objectType === 'deck_card' ? $objectName : ''),
			'stackTitle' => $this->nestedString($params, 'stack', 'title'),
			'previousStackTitle' => $this->nestedString($params, 'stackBefore', 'title'),
			'labelTitle' => $this->nestedString($params, 'label', 'title'),
			'attachmentName' => $this->nestedString($params, 'attachment', 'data'),
			'assignedUserUid' => is_string($params['assigneduser'] ?? null) ? $params['assigneduser'] : null,
			'dueDateBefore' => is_string($params['before'] ?? null) ? $params['before'] : null,
			'dueDateAfter' => is_string($params['after'] ?? null) ? $params['after'] : null,
		];
		if (isset($payload['assignedUserUid'])) {
			$payload['assignedUserDisplayName'] = $this->userManager->getDisplayName($payload['assignedUserUid']) ?? $payload['assignedUserUid'];
		}
		$payload = array_filter($payload, static fn (mixed $value): bool => $value !== null && $value !== '');

		$actorDisplayName = null;
		if ($actorUid !== '') {
			$actorDisplayName = $this->userManager->getDisplayName($actorUid);
			if ($actorDisplayName === null) {
				$actorUid = '';
				$actorDisplayName = 'Deleted user';
			}
		}

		return [
			'id' => 'deck-native-' . (int)($row['activity_id'] ?? 0),
			'projectId' => null,
			'actorUid' => $actorUid !== '' ? $actorUid : null,
			'actorDisplayName' => $actorDisplayName,
			'eventType' => 'deck_' . $subject,
			'source' => ProjectActivityService::SOURCE_DECK,
			'payload' => $payload,
			'occurredAt' => $occurredAt,
			'_sortTimestamp' => (int)($row['timestamp'] ?? 0),
			'_sortId' => (int)($row['activity_id'] ?? 0),
			'_stream' => 'native',
		];
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function nestedString(array $params, string $key, string $field): string {
		$value = $params[$key] ?? null;
		if (!is_array($value)) {
			return '';
		}
		$fieldValue = $value[$field] ?? null;
		return is_scalar($fieldValue) ? trim((string)$fieldValue) : '';
	}
}
