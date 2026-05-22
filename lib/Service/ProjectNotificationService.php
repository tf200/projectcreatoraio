<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCA\ProjectCreatorAIO\Db\Project;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class ProjectNotificationService {
	public const SUBJECT_MEMBER_ADDED = 'project_member_added';
	public const SUBJECT_WHITEBOARD_UPDATED = 'project_whiteboard_updated';
	public const SUBJECT_DECK_STALE = 'project_deck_stale';
	public const SUBJECT_EXPORT_READY = 'project_export_ready';
	private const OBJECT_TYPE_PROJECT_MEMBER = 'project_member';
	private const OBJECT_TYPE_PROJECT_WHITEBOARD = 'project_whiteboard';
	private const OBJECT_TYPE_PROJECT_DECK_STALE = 'project_deck_stale';
	private const OBJECT_TYPE_PROJECT_EXPORT = 'project_export';
	private const WHITEBOARD_COOLDOWN_SECONDS = 120;
	private ?ICache $cooldownCache;

	public function __construct(
		private readonly INotificationManager $notificationManager,
		private readonly ProjectMemberResolver $projectMemberResolver,
		ICacheFactory $cacheFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->cooldownCache = $cacheFactory->isLocalCacheAvailable()
			? $cacheFactory->createLocal(Application::APP_ID . '_notifications')
			: ($cacheFactory->isAvailable()
				? $cacheFactory->createDistributed(Application::APP_ID . '_notifications')
				: null);
	}

	public function notifyMemberAdded(Project $project, IUser $member, ?IUser $actor = null): void {
		$projectId = (int) ($project->getId() ?? 0);
		if ($projectId <= 0) {
			return;
		}

		$projectName = trim((string) ($project->getName() ?? ''));
		$actorUid = $actor?->getUID() ?? '';
		$actorDisplayName = trim((string) ($actor?->getDisplayName() ?? ''));

		if ($actorDisplayName === '' && $actorUid !== '') {
			$actorDisplayName = $actorUid;
		}

		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser($member->getUID())
			->setObject(self::OBJECT_TYPE_PROJECT_MEMBER, $projectId . ':' . $member->getUID())
			->setSubject(
				self::SUBJECT_MEMBER_ADDED,
				[
					'projectId' => (string) $projectId,
					'projectName' => $projectName,
					'actorUid' => $actorUid,
					'actorDisplayName' => $actorDisplayName,
				],
			)
			->setDateTime(new \DateTime());

		try {
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send project membership notification', [
				'app' => Application::APP_ID,
				'exception' => $e,
				'projectId' => $projectId,
				'memberUid' => $member->getUID(),
			]);
		}
	}

	public function notifyWhiteboardUpdated(Project $project, IUser $actor): void {
		$projectId = (int) ($project->getId() ?? 0);
		if ($projectId <= 0) {
			return;
		}

		$actorUid = trim((string) $actor->getUID());
		if ($actorUid === '') {
			return;
		}

		if (!$this->acquireWhiteboardCooldown($projectId)) {
			return;
		}

		$projectName = trim((string) ($project->getName() ?? ''));
		$actorDisplayName = trim((string) ($actor->getDisplayName() ?? ''));
		if ($actorDisplayName === '') {
			$actorDisplayName = $actorUid;
		}

		foreach ($this->getProjectRecipientUserIds($project, [$actorUid]) as $recipientUid) {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($recipientUid)
				->setObject(
					self::OBJECT_TYPE_PROJECT_WHITEBOARD,
					$projectId . ':' . $this->getWhiteboardNotificationWindowId(),
				)
				->setSubject(
					self::SUBJECT_WHITEBOARD_UPDATED,
					[
						'projectId' => (string) $projectId,
						'projectName' => $projectName,
						'actorUid' => $actorUid,
						'actorDisplayName' => $actorDisplayName,
					],
				)
				->setDateTime(new \DateTime());

			try {
				$this->notificationManager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->error('Failed to send project whiteboard notification', [
					'app' => Application::APP_ID,
					'exception' => $e,
					'projectId' => $projectId,
					'recipientUid' => $recipientUid,
				]);
			}
		}
	}

	public function notifyDeckStale(Project $project): void {
		$projectId = (int) ($project->getId() ?? 0);
		if ($projectId <= 0) {
			return;
		}

		$projectName = trim((string) ($project->getName() ?? ''));

		foreach ($this->getProjectRecipientUserIds($project) as $recipientUid) {
			$notification = $this->notificationManager->createNotification();
			$notification
				->setApp(Application::APP_ID)
				->setUser($recipientUid)
				->setObject(self::OBJECT_TYPE_PROJECT_DECK_STALE, (string) $projectId)
				->setSubject(
					self::SUBJECT_DECK_STALE,
					[
						'projectId' => (string) $projectId,
						'projectName' => $projectName,
					],
				)
				->setDateTime(new \DateTime());

			try {
				$this->notificationManager->notify($notification);
			} catch (\Throwable $e) {
				$this->logger->error('Failed to send project stale notification', [
					'app' => Application::APP_ID,
					'exception' => $e,
					'projectId' => $projectId,
					'recipientUid' => $recipientUid,
				]);
			}
		}
	}

	public function notifyExportReady(Project $project, IUser $user): void {
		$projectId = (int) ($project->getId() ?? 0);
		if ($projectId <= 0) {
			return;
		}

		$projectName = trim((string) ($project->getName() ?? ''));

		$notification = $this->notificationManager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser($user->getUID())
			->setObject(self::OBJECT_TYPE_PROJECT_EXPORT, (string) $projectId)
			->setSubject(
				self::SUBJECT_EXPORT_READY,
				[
					'projectId' => (string) $projectId,
					'projectName' => $projectName,
				],
			)
			->setDateTime(new \DateTime());

		try {
			$this->notificationManager->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send export ready notification', [
				'app' => Application::APP_ID,
				'exception' => $e,
				'projectId' => $projectId,
				'userId' => $user->getUID(),
			]);
		}
	}

	private function acquireWhiteboardCooldown(int $projectId): bool {
		if ($this->cooldownCache === null) {
			return true;
		}

		$key = 'whiteboard:' . $projectId;
		$now = time();
		$lastSentAt = $this->cooldownCache->get($key);

		if (is_int($lastSentAt) && ($now - $lastSentAt) < self::WHITEBOARD_COOLDOWN_SECONDS) {
			return false;
		}

		$this->cooldownCache->set($key, $now, self::WHITEBOARD_COOLDOWN_SECONDS);
		return true;
	}

	private function getWhiteboardNotificationWindowId(): string {
		return (string) intdiv(time(), self::WHITEBOARD_COOLDOWN_SECONDS);
	}

	/**
	 * @return string[]
	 */
	private function getProjectRecipientUserIds(Project $project, array $excludedUids = []): array {
		$recipientIds = [];
		foreach ($this->projectMemberResolver->getProjectMembers($project, $excludedUids) as $member) {
			$uid = trim((string) $member->getUID());
			if ($uid !== '') {
				$recipientIds[] = $uid;
			}
		}

		return $recipientIds;
	}
}
