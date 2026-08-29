<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTimeInterface;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEvent;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;

class ProjectActivityAggregationService {
	private const CURSOR_VERSION = 1;

	public function __construct(
		private readonly ProjectActivityEventMapper $eventMapper,
		private readonly NativeDeckActivityReader $nativeDeckReader,
	) {
	}

	/**
	 * @return array{events: list<array<string, mixed>>, hasMore: bool, nextCursor: ?string}
	 */
	public function getActivity(Project $project, string $userId, int $limit, ?string $source = null, ?string $cursor = null, int $offset = 0): array {
		if ($cursor === null && $offset >= 500) {
			throw new \InvalidArgumentException('Use cursor pagination for activity offsets of 500 or greater');
		}

		$projectId = (int)$project->getId();
		$decodedCursor = $this->decodeCursor($cursor, $projectId, $userId, $source);
		$fetchLimit = $cursor === null && $offset > 0 ? min(500, $limit + $offset + 1) : $limit + 1;
		$customCursor = is_array($decodedCursor['custom'] ?? null) ? $decodedCursor['custom'] : null;
		$nativeCursor = is_array($decodedCursor['native'] ?? null) ? $decodedCursor['native'] : null;

		$nativePage = ['events' => [], 'hasMore' => false, 'authoritative' => false];
		$boardId = (int)($project->getBoardId() ?? 0);
		if (($source === null || $source === '' || $source === ProjectActivityService::SOURCE_DECK) && $boardId > 0) {
			$nativePage = $this->nativeDeckReader->read($boardId, $userId, $fetchLimit, $nativeCursor);
			foreach ($nativePage['events'] as &$nativeEvent) {
				$nativeEvent['projectId'] = $projectId;
			}
			unset($nativeEvent);
		}

		$customEvents = [];
		if (!$nativePage['authoritative'] || $source !== ProjectActivityService::SOURCE_DECK) {
			$excludeSource = $nativePage['authoritative'] ? ProjectActivityService::SOURCE_DECK : null;
			$customEvents = array_map(
				fn (ProjectActivityEvent $event): array => $this->normalizeCustomEvent(ProjectActivityService::prepareEventForUser($event, $userId)),
				$this->eventMapper->findForProjectBefore($projectId, $fetchLimit, $source, $customCursor, $excludeSource),
			);
		}
		$customHasMore = count($customEvents) >= $fetchLimit;

		$events = array_merge($customEvents, $nativePage['events']);
		usort($events, static function (array $left, array $right): int {
			return [$right['_sortTimestamp'], $right['_sortId'], $right['_stream']]
				<=> [$left['_sortTimestamp'], $left['_sortId'], $left['_stream']];
		});

		if ($cursor === null && $offset > 0) {
			$events = array_slice($events, $offset);
		}

		$pageEvents = array_slice($events, 0, $limit);
		$hasMore = count($events) > $limit || $customHasMore || $nativePage['hasMore'];
		$nextCursor = $hasMore ? $this->buildNextCursor($decodedCursor, $pageEvents, $projectId, $userId, $source) : null;
		foreach ($pageEvents as &$event) {
			unset($event['_sortTimestamp'], $event['_sortId'], $event['_stream']);
		}
		unset($event);

		return [
			'events' => array_values($pageEvents),
			'hasMore' => $hasMore,
			'nextCursor' => $nextCursor,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private function normalizeCustomEvent(ProjectActivityEvent $event): array {
		$data = $event->jsonSerialize();
		$occurredAt = $event->getOccurredAt();
		$data['_sortTimestamp'] = $occurredAt instanceof DateTimeInterface ? $occurredAt->getTimestamp() : 0;
		$data['_sortId'] = (int)($event->getId() ?? 0);
		$data['_stream'] = 'custom';
		return $data;
	}

	/**
	 * @param array<string, mixed> $previous
	 * @param list<array<string, mixed>> $events
	 */
	private function buildNextCursor(array $previous, array $events, int $projectId, string $userId, ?string $source): ?string {
		$cursor = [
			'version' => self::CURSOR_VERSION,
			'projectId' => $projectId,
			'userId' => $userId,
			'source' => $source ?? '',
			'custom' => $previous['custom'] ?? null,
			'native' => $previous['native'] ?? null,
		];

		foreach ($events as $event) {
			$stream = $event['_stream'];
			if ($stream === 'custom') {
				$cursor['custom'] = [
					'timestamp' => $event['occurredAt'],
					'id' => $event['_sortId'],
				];
			} else {
				$cursor['native'] = [
					'timestamp' => $event['_sortTimestamp'],
					'id' => $event['_sortId'],
				];
			}
		}

		$json = json_encode($cursor, JSON_UNESCAPED_SLASHES);
		return $json === false ? null : rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decodeCursor(?string $cursor, int $projectId, string $userId, ?string $source): array {
		if ($cursor === null || trim($cursor) === '') {
			return [];
		}

		$decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
		$data = $decoded === false ? null : json_decode($decoded, true);
		if (!is_array($data)
			|| ($data['version'] ?? null) !== self::CURSOR_VERSION
			|| ($data['projectId'] ?? null) !== $projectId
			|| ($data['userId'] ?? null) !== $userId
			|| ($data['source'] ?? null) !== ($source ?? '')
			|| !$this->validStreamCursor($data['custom'] ?? null, true)
			|| !$this->validStreamCursor($data['native'] ?? null, false)) {
			return [];
		}
		return $data;
	}

	private function validStreamCursor(mixed $cursor, bool $dateTimestamp): bool {
		if ($cursor === null) {
			return true;
		}
		if (!is_array($cursor) || !is_int($cursor['id'] ?? null) || $cursor['id'] <= 0) {
			return false;
		}
		if (!$dateTimestamp) {
			return is_int($cursor['timestamp'] ?? null) && $cursor['timestamp'] > 0;
		}

		try {
			return is_string($cursor['timestamp'] ?? null)
				&& (new \DateTimeImmutable($cursor['timestamp']))->getTimestamp() > 0;
		} catch (\Throwable) {
			return false;
		}
	}
}
