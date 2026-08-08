<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateInterval;
use DateTime;
use DateTimeInterface;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEvent;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDigestCursorMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCP\IURLGenerator;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\IUser;
use Psr\Log\LoggerInterface;

class ProjectDigestService {
	private const INITIAL_LOOKBACK = 'P1D';

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectActivityEventMapper $eventMapper,
		private readonly ProjectDigestCursorMapper $cursorMapper,
		private readonly ProjectMemberResolver $memberResolver,
		private readonly IMailer $mailer,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
	) {
	}

	public function sendDailyDigests(?DateTimeInterface $now = null): void {
		$currentTime = $now instanceof DateTimeInterface ? DateTime::createFromInterface($now) : new DateTime();
		$initialNotBefore = (clone $currentTime)->sub(new DateInterval(self::INITIAL_LOOKBACK));
		$digestsByRecipient = [];

		foreach ($this->projectMapper->list() as $project) {
			$projectId = (int) ($project->getId() ?? 0);
			if ($projectId <= 0) {
				continue;
			}

			foreach ($this->memberResolver->getProjectMembers($project) as $recipient) {
				$this->collectRecipientProjectDigest($digestsByRecipient, $project, $recipient, $initialNotBefore);
			}
		}

		foreach ($digestsByRecipient as $digest) {
			$this->sendRecipientDigest($digest, $currentTime);
		}
	}

	/**
	 * @param array<string, array{
	 *   recipient: IUser,
	 *   projects: array<int, array{
	 *     project: Project,
	 *     events: ProjectActivityEvent[],
	 *     lastEventId: int
	 *   }>
	 * }> $digestsByRecipient
	 */
	private function collectRecipientProjectDigest(array &$digestsByRecipient, Project $project, IUser $recipient, DateTimeInterface $initialNotBefore): void {
		$projectId = (int) ($project->getId() ?? 0);
		$recipientUid = trim((string) $recipient->getUID());
		if ($projectId <= 0 || $recipientUid === '') {
			return;
		}

		$cursor = $this->cursorMapper->findByProjectAndUser($projectId, $recipientUid);
		$afterEventId = (int) ($cursor?->getLastEventId() ?? 0);
		$events = $this->eventMapper->findForDigest(
			$projectId,
			$afterEventId,
			$cursor === null ? $initialNotBefore : null,
		);
		$events = array_map(
			static fn (ProjectActivityEvent $event): ProjectActivityEvent => ProjectActivityService::prepareEventForUser($event, $recipientUid),
			$events,
		);
		if ($events === []) {
			return;
		}

		$lastEvent = end($events);
		if (!$lastEvent instanceof ProjectActivityEvent) {
			return;
		}

		if (!isset($digestsByRecipient[$recipientUid])) {
			$digestsByRecipient[$recipientUid] = [
				'recipient' => $recipient,
				'projects' => [],
			];
		}

		$digestsByRecipient[$recipientUid]['projects'][] = [
			'project' => $project,
			'events' => $events,
			'lastEventId' => (int) $lastEvent->getId(),
		];
	}

	/**
	 * @param array{
	 *   recipient: IUser,
	 *   projects: array<int, array{
	 *     project: Project,
	 *     events: ProjectActivityEvent[],
	 *     lastEventId: int
	 *   }>
	 * } $digest
	 */
	private function sendRecipientDigest(array $digest, DateTimeInterface $currentTime): void {
		$recipient = $digest['recipient'];
		$recipientUid = trim((string) $recipient->getUID());
		if ($recipientUid === '' || $digest['projects'] === []) {
			return;
		}

		$email = trim((string) ($recipient->getEMailAddress() ?? ''));
		if ($email === '') {
			$this->logger->warning('Skipping project digest because recipient has no email address', [
				'userUid' => $recipientUid,
				'projectIds' => array_map(
					static fn (array $projectDigest): int => (int) (($projectDigest['project']->getId()) ?? 0),
					$digest['projects'],
				),
			]);
			return;
		}

		try {
			$this->sendDigest($recipient, $digest['projects']);
			foreach ($digest['projects'] as $projectDigest) {
				$projectId = (int) (($projectDigest['project']->getId()) ?? 0);
				if ($projectId <= 0) {
					continue;
				}

				$this->cursorMapper->advanceCursor($projectId, $recipientUid, $projectDigest['lastEventId'], $currentTime);
			}
		} catch (\Throwable $e) {
			$this->logger->error('Failed to send project digest email', [
				'exception' => $e,
				'userUid' => $recipientUid,
				'projectIds' => array_map(
					static fn (array $projectDigest): int => (int) (($projectDigest['project']->getId()) ?? 0),
					$digest['projects'],
				),
			]);
		}
	}

	/**
	 * @param array<int, array{
	 *   project: Project,
	 *   events: ProjectActivityEvent[],
	 *   lastEventId: int
	 * }> $projectDigests
	 */
	private function sendDigest(IUser $recipient, array $projectDigests): void {
		$totalEvents = 0;
		foreach ($projectDigests as $projectDigest) {
			$totalEvents += count($projectDigest['events']);
		}

		$subject = sprintf('Daily project activity summary (%d)', $totalEvents);
		$template = $this->mailer->createEMailTemplate('project_activity_digest', [
			'recipientUid' => $recipient->getUID(),
		]);
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$template->addBodyText('Here is the latest activity across your projects.');

		foreach ($projectDigests as $projectDigest) {
			$projectName = $this->getProjectName($projectDigest['project']);
			$template->addHeading(sprintf('%s (%d)', $projectName, count($projectDigest['events'])));

			foreach ($projectDigest['events'] as $event) {
				[$text, $meta] = $this->formatEvent($event);
				$template->addBodyListItem($text, $meta, '', $text, $meta);
			}
		}

		$template->addBodyButton('Open Projects', $this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.index'));
		$template->addFooter();

		$message = $this->mailer->createMessage();
		$message
			->setTo([$recipient->getEMailAddress() => $this->getUserDisplayName($recipient)])
			->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED)
			->useTemplate($template);

		$failedRecipients = $this->mailer->send($message);
		if ($failedRecipients !== []) {
			throw new \RuntimeException('Digest delivery failed for: ' . implode(', ', $failedRecipients));
		}
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function formatEvent(ProjectActivityEvent $event): array {
		$payload = $event->getPayloadArray();
		$actor = trim((string) ($event->getActorDisplayName() ?? $event->getActorUid() ?? 'Someone'));
		if ($actor === '') {
			$actor = 'Someone';
		}
		$meta = $event->getOccurredAt()?->format('Y-m-d H:i') ?? '';

		switch ((string) $event->getEventType()) {
			case ProjectActivityService::EVENT_PROJECT_CREATED:
				return [sprintf('%s created the project', $actor), $meta];
			case ProjectActivityService::EVENT_MEMBER_ADDED:
				$member = $this->fallbackLabel($payload['memberDisplayName'] ?? null, $payload['memberUid'] ?? null, 'a team member');
				return [sprintf('%s added %s to the project', $actor, $member), $meta];
			case ProjectActivityService::EVENT_WHITEBOARD_UPDATED:
				return [sprintf('%s updated the whiteboard', $actor), $meta];
			case ProjectActivityService::EVENT_PROJECT_NOTES_UPDATED:
				$scope = [];
				if (($payload['publicUpdated'] ?? false) === true) {
					$scope[] = 'public notes';
				}
				if (($payload['privateUpdated'] ?? false) === true) {
					$scope[] = 'private notes';
				}
				$target = $scope === [] ? 'project notes' : implode(' and ', $scope);
				return [sprintf('%s updated %s', $actor, $target), $meta];
			case ProjectActivityService::EVENT_NOTE_CREATED:
				return [sprintf('%s created %s', $actor, $this->formatNoteLabel($payload)), $meta];
			case ProjectActivityService::EVENT_NOTE_UPDATED:
				return [sprintf('%s updated %s', $actor, $this->formatNoteLabel($payload)), $meta];
			case ProjectActivityService::EVENT_NOTE_DELETED:
				return [sprintf('%s deleted %s', $actor, $this->formatNoteLabel($payload)), $meta];
			case ProjectActivityService::EVENT_TIMELINE_ITEM_CREATED:
				return [sprintf('%s created %s', $actor, $this->formatTimelineLabel($payload)), $meta];
			case ProjectActivityService::EVENT_TIMELINE_ITEM_UPDATED:
				return [sprintf('%s updated %s', $actor, $this->formatTimelineLabel($payload)), $meta];
			case ProjectActivityService::EVENT_TIMELINE_ITEM_DELETED:
				return [sprintf('%s deleted %s', $actor, $this->formatTimelineLabel($payload)), $meta];
			case ProjectActivityService::EVENT_TIMELINE_REORDERED:
				$count = max(0, (int) ($payload['count'] ?? 0));
				return [sprintf('%s reordered %d timeline items', $actor, $count), $meta];
			default:
				return [sprintf('%s recorded activity in the project', $actor), $meta];
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function formatNoteLabel(array $payload): string {
		$visibility = trim((string) ($payload['visibility'] ?? 'public'));
		$title = trim((string) ($payload['title'] ?? ''));
		$noteType = ProjectNote::normalizeNoteType($payload['noteType'] ?? null);
		$type = $visibility === 'private' ? 'a private note' : 'a public note';
		if ($noteType !== ProjectNote::NOTE_TYPE_GENERAL) {
			$noteTypeLabel = strtolower(ProjectNote::noteTypeLabel($noteType));
			if (!str_ends_with($noteTypeLabel, 'note')) {
				$noteTypeLabel .= ' note';
			}
			$type = sprintf(
				'a %s %s',
				$visibility === 'private' ? 'private' : 'public',
				$noteTypeLabel,
			);
		}
		return $title === '' ? $type : sprintf('%s "%s"', $type, $title);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function formatTimelineLabel(array $payload): string {
		$itemType = trim((string) ($payload['itemType'] ?? 'timeline item'));
		$label = trim((string) ($payload['label'] ?? ''));
		$type = $itemType === 'milestone' ? 'timeline milestone' : 'timeline item';
		return $label === '' ? $type : sprintf('%s "%s"', $type, $label);
	}

	private function fallbackLabel(mixed $preferred, mixed $fallback, string $default): string {
		foreach ([$preferred, $fallback] as $value) {
			$label = trim((string) $value);
			if ($label !== '') {
				return $label;
			}
		}

		return $default;
	}

	private function getProjectName(Project $project): string {
		$name = trim((string) ($project->getName() ?? ''));
		return $name !== '' ? $name : 'Unnamed project';
	}

	private function getUserDisplayName(IUser $user): string {
		$displayName = trim((string) ($user->getDisplayName() ?? ''));
		return $displayName !== '' ? $displayName : (string) $user->getUID();
	}
}
