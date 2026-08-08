<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateInterval;
use DateTime;
use DateTimeInterface;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class ProjectDeckActivityService {
	public const STATUS_ARCHIVED = ProjectStatus::ARCHIVED;
	public const STATUS_ACTIVE = ProjectStatus::ACTIVE;
	public const STATUS_WAITING_ON_CUSTOMER = ProjectStatus::WAITING_ON_CUSTOMER;
	public const STATUS_ON_HOLD = ProjectStatus::ON_HOLD;
	public const STATUS_DONE = ProjectStatus::DONE;
	private const WAITING_ON_CUSTOMER_AFTER_DAYS = 90;
	private const ON_HOLD_AFTER_DAYS = 330;

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectNotificationService $projectNotificationService,
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}

	public function recordCardMoveByBoardId(int $boardId, ?DateTimeInterface $movedAt = null): void {
		if ($boardId <= 0) {
			return;
		}

		$project = $this->projectMapper->findByBoardId($boardId);
		if ($project === null) {
			return;
		}

		$this->recordCardMove($project, $movedAt);
	}

	public function recordCardMove(Project $project, ?DateTimeInterface $movedAt = null): void {
		$oldStatus = (int) ($project->getStatus() ?? self::STATUS_ACTIVE);
		$moveTime = $this->toMutableDateTime($movedAt ?? new DateTime());
		$project->setLastDeckMoveAt($moveTime);
		$project->setStaleNotifiedAt(null);

		if (in_array((int) ($project->getStatus() ?? self::STATUS_ACTIVE), [
			self::STATUS_WAITING_ON_CUSTOMER,
			self::STATUS_ON_HOLD,
		], true)) {
			$project->setStatus(self::STATUS_ACTIVE);
		}

		if ($this->persistProjectDetails($project)) {
			$this->projectNotificationService->notifyStatusChanged($project, $oldStatus, (int) $project->getStatus());
		}
	}

	public function processLifecycleStatuses(?DateTimeInterface $now = null): void {
		$currentTime = $this->toMutableDateTime($now ?? new DateTime());
		$waitingCutoff = (clone $currentTime)->sub(new DateInterval('P' . self::WAITING_ON_CUSTOMER_AFTER_DAYS . 'D'));
		$onHoldCutoff = (clone $currentTime)->sub(new DateInterval('P' . self::ON_HOLD_AFTER_DAYS . 'D'));

		foreach ($this->projectMapper->listDeckTrackedProjects() as $project) {
			try {
				$this->processProject($project, $currentTime, $waitingCutoff, $onHoldCutoff);
			} catch (\Throwable $e) {
				$this->logger->error('Failed to process project inactivity lifecycle state', [
					'exception' => $e,
					'projectId' => $project->getId(),
				]);
			}
		}
	}

	public function processStaleProjects(?DateTimeInterface $now = null): void {
		$this->processLifecycleStatuses($now);
	}

	private function processProject(Project $project, DateTime $now, DateTime $waitingCutoff, DateTime $onHoldCutoff): void {
		$status = (int) ($project->getStatus() ?? self::STATUS_ACTIVE);
		if ($status === self::STATUS_ARCHIVED) {
			return;
		}

		if ($status === self::STATUS_DONE) {
			if (!$this->getDoneReadiness($project)['ready']) {
				$project->setStatus(self::STATUS_ACTIVE);
				if ($this->persistProjectDetails($project)) {
					$this->projectNotificationService->notifyStatusChanged($project, $status, self::STATUS_ACTIVE);
				}
			}
			return;
		}

		$anchor = $project->getLastDeckMoveAt() ?? $project->getCreatedAt();
		if (!$anchor instanceof DateTimeInterface) {
			return;
		}

		$anchorDate = $this->toMutableDateTime($anchor);
		if ($anchorDate > $waitingCutoff) {
			if (in_array($status, [self::STATUS_WAITING_ON_CUSTOMER, self::STATUS_ON_HOLD], true)) {
				$project->setStatus(self::STATUS_ACTIVE);
				$project->setStaleNotifiedAt(null);
				if ($this->persistProjectDetails($project)) {
					$this->projectNotificationService->notifyStatusChanged($project, $status, self::STATUS_ACTIVE);
				}
			}
			return;
		}

		if ($anchorDate <= $onHoldCutoff) {
			if ($status !== self::STATUS_ON_HOLD) {
				$project->setStatus(self::STATUS_ON_HOLD);
				if ($this->persistProjectDetails($project)) {
					$this->projectNotificationService->notifyStatusChanged($project, $status, self::STATUS_ON_HOLD);
				}
			}
			return;
		}

		$needsUpdate = false;
		if ($status !== self::STATUS_WAITING_ON_CUSTOMER) {
			$project->setStatus(self::STATUS_WAITING_ON_CUSTOMER);
			$needsUpdate = true;
		}

		if ($project->getStaleNotifiedAt() === null) {
			$project->setStaleNotifiedAt(clone $now);
			$needsUpdate = true;
			$this->projectNotificationService->notifyDeckStale($project);
		}

		if ($needsUpdate) {
			if ($this->persistProjectDetails($project)) {
				$this->projectNotificationService->notifyStatusChanged($project, $status, (int) $project->getStatus());
			}
		}
	}

	private function toMutableDateTime(DateTimeInterface $dateTime): DateTime {
		if ($dateTime instanceof DateTime) {
			return clone $dateTime;
		}

		return DateTime::createFromInterface($dateTime);
	}

	private function persistProjectDetails(Project $project): bool {
		try {
			$this->projectMapper->updateProjectDetails($project);
			return true;
		} catch (\Throwable $e) {
			if (!$this->isMissingDeckActivityColumnError($e)) {
				throw $e;
			}

			$this->logger->warning('Skipping project deck activity persistence until schema migration is applied', [
				'exception' => $e,
				'projectId' => $project->getId(),
			]);
			return false;
		}
	}

	private function isMissingDeckActivityColumnError(\Throwable $e): bool {
		$message = $e->getMessage();

		return str_contains($message, 'last_deck_move_at')
			|| str_contains($message, 'stale_notified_at');
	}

	/**
	 * @return array{ready: bool, totalCards: int, doneCards: int, doneStackId: ?int, doneStackTitle: ?string}
	 */
	public function getDoneReadiness(Project $project): array {
		$boardId = $this->parseBoardId($project);
		if ($boardId <= 0) {
			return [
				'ready' => false,
				'totalCards' => 0,
				'doneCards' => 0,
				'doneStackId' => null,
				'doneStackTitle' => null,
			];
		}

		$stacks = $this->getBoardStacks($boardId);
		if ($stacks === []) {
			return [
				'ready' => false,
				'totalCards' => 0,
				'doneCards' => 0,
				'doneStackId' => null,
				'doneStackTitle' => null,
			];
		}

		$doneStack = $this->findDoneStack($stacks);
		if ($doneStack === null) {
			return [
				'ready' => false,
				'totalCards' => 0,
				'doneCards' => 0,
				'doneStackId' => null,
				'doneStackTitle' => null,
			];
		}

		$counts = $this->countActiveCardsByBoardAndStack($boardId, (int) $doneStack['id']);

		return [
			'ready' => $counts['totalCards'] > 0 && $counts['doneCards'] === $counts['totalCards'],
			'totalCards' => $counts['totalCards'],
			'doneCards' => $counts['doneCards'],
			'doneStackId' => (int) $doneStack['id'],
			'doneStackTitle' => (string) ($doneStack['title'] ?? ''),
		];
	}

	private function parseBoardId(Project $project): int {
		$boardId = trim((string) ($project->getBoardId() ?? ''));
		if ($boardId === '' || !ctype_digit($boardId)) {
			return 0;
		}

		return (int) $boardId;
	}

	/**
	 * @return array<int, array{id: int, title: string, order: int}>
	 */
	private function getBoardStacks(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'title', 'order')
			->from('deck_stacks')
			->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('order', 'ASC');

		$result = $qb->executeQuery();
		$stacks = [];
		while (($row = $result->fetch()) !== false) {
			$stackId = (int) ($row['id'] ?? 0);
			if ($stackId <= 0) {
				continue;
			}

			$stacks[] = [
				'id' => $stackId,
				'title' => trim((string) ($row['title'] ?? '')),
				'order' => (int) ($row['order'] ?? 0),
			];
		}
		$result->closeCursor();

		return $stacks;
	}

	/**
	 * @param array<int, array{id: int, title: string, order: int}> $stacks
	 * @return array{id: int, title: string, order: int}|null
	 */
	private function findDoneStack(array $stacks): ?array {
		$normalizedDoneTitles = ['done', 'afgerond', 'gereed'];
		foreach ($stacks as $stack) {
			$title = strtolower(trim((string) ($stack['title'] ?? '')));
			if (in_array($title, $normalizedDoneTitles, true)) {
				return $stack;
			}
		}

		if ($stacks === []) {
			return null;
		}

		usort($stacks, static fn (array $left, array $right): int => $left['order'] <=> $right['order']);
		return $stacks[array_key_last($stacks)] ?? null;
	}

	/**
	 * @return array{totalCards: int, doneCards: int}
	 */
	private function countActiveCardsByBoardAndStack(int $boardId, int $doneStackId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.stack_id')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)));

		$result = $qb->executeQuery();
		$totalCards = 0;
		$doneCards = 0;
		while (($row = $result->fetch()) !== false) {
			$totalCards++;
			if ((int) ($row['stack_id'] ?? 0) === $doneStackId) {
				$doneCards++;
			}
		}
		$result->closeCursor();

		return [
			'totalCards' => $totalCards,
			'doneCards' => $doneCards,
		];
	}
}
