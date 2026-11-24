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
use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Server;
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
		private readonly ?IFactory $l10nFactory = null,
	) {
	}

	/** The digest is a personal e-mail: written in the recipient's language. */
	private function l10nFor(IUser $recipient): IL10N {
		$factory = $this->l10nFactory ?? Server::get(IFactory::class);
		return $factory->get(Application::APP_ID, $factory->getUserLanguage($recipient));
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

		$l = $this->l10nFor($recipient);
		$subject = $l->t('Daily project activity summary (%d)', [$totalEvents]);
		$template = $this->mailer->createEMailTemplate('project_activity_digest', [
			'recipientUid' => $recipient->getUID(),
		]);
		$template->setSubject($subject);
		$template->addHeader();
		$template->addHeading($subject);
		$template->addBodyText($l->t('Here is the latest activity across your projects.'));

		foreach ($projectDigests as $projectDigest) {
			$projectName = $this->getProjectName($projectDigest['project'], $l);
			$template->addHeading(sprintf('%s (%d)', $projectName, count($projectDigest['events'])));

			foreach ($projectDigest['events'] as $event) {
				[$text, $meta] = $this->formatEvent($event, $l);
				$template->addBodyListItem($text, $meta, '', $text, $meta);
			}
		}

		$template->addBodyButton($l->t('Open Projects'), $this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.index'));
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
	private function formatEvent(ProjectActivityEvent $event, IL10N $l): array {
		$payload = $event->getPayloadArray();
		$actor = trim((string) ($event->getActorDisplayName() ?? $event->getActorUid() ?? ''));
		if ($actor === '') {
			$actor = $l->t('Someone');
		}
		$meta = $event->getOccurredAt()?->format('Y-m-d H:i') ?? '';

		switch ((string) $event->getEventType()) {
			case ProjectActivityService::EVENT_PROJECT_CREATED:
				return [$l->t('%s created the project', [$actor]), $meta];
			case ProjectActivityService::EVENT_MEMBER_ADDED:
				$member = $this->fallbackLabel($payload['memberDisplayName'] ?? null, $payload['memberUid'] ?? null, $l->t('a team member'));
				return [$l->t('%s added %s to the project', [$actor, $member]), $meta];
			case ProjectActivityService::EVENT_WHITEBOARD_UPDATED:
				return [$l->t('%s updated the whiteboard', [$actor]), $meta];
			case ProjectActivityService::EVENT_PROJECT_NOTES_UPDATED:
				$scope = [];
				if (($payload['publicUpdated'] ?? false) === true) {
					$scope[] = $l->t('public notes');
				}
				if (($payload['privateUpdated'] ?? false) === true) {
					$scope[] = $l->t('private notes');
				}
				$target = match (count($scope)) {
					0 => $l->t('project notes'),
					1 => $scope[0],
					default => $l->t('%1$s and %2$s', [$scope[0], $scope[1]]),
				};
				return [$l->t('%s updated %s', [$actor, $target]), $meta];
			case ProjectActivityService::EVENT_NOTE_CREATED:
				return [$l->t('%s created %s', [$actor, $this->formatNoteLabel($payload, $l)]), $meta];
			case ProjectActivityService::EVENT_NOTE_UPDATED:
				return [$l->t('%s updated %s', [$actor, $this->formatNoteLabel($payload, $l)]), $meta];
			case ProjectActivityService::EVENT_NOTE_DELETED:
				return [$l->t('%s deleted %s', [$actor, $this->formatNoteLabel($payload, $l)]), $meta];
			case ProjectActivityService::EVENT_TIMELINE_ITEM_CREATED:
				return [$l->t('%s created %s', [$actor, $this->formatTimelineLabel($payload, $l)]), $meta];
			case ProjectActivityService::EVENT_TIMELINE_ITEM_UPDATED:
				return [$l->t('%s updated %s', [$actor, $this->formatTimelineLabel($payload, $l)]), $meta];
			case ProjectActivityService::EVENT_TIMELINE_ITEM_DELETED:
				return [$l->t('%s deleted %s', [$actor, $this->formatTimelineLabel($payload, $l)]), $meta];
			case ProjectActivityService::EVENT_TIMELINE_REORDERED:
				$count = max(0, (int) ($payload['count'] ?? 0));
				return [$l->t('%s reordered %d timeline items', [$actor, $count]), $meta];
			default:
				return [$l->t('%s recorded activity in the project', [$actor]), $meta];
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function formatNoteLabel(array $payload, IL10N $l): string {
		$visibility = trim((string) ($payload['visibility'] ?? 'public'));
		$title = trim((string) ($payload['title'] ?? ''));
		$noteType = ProjectNote::normalizeNoteType($payload['noteType'] ?? null);
		$type = $visibility === 'private' ? $l->t('a private note') : $l->t('a public note');
		if ($noteType !== ProjectNote::NOTE_TYPE_GENERAL) {
			// The type label is used as it is translated ("a public decision"); no
			// English " note" suffix is glued on, so the sentence works in every language.
			$type = $l->t('a %s %s', [
				$visibility === 'private' ? $l->t('private') : $l->t('public'),
				mb_strtolower(ProjectNote::noteTypeLabel($noteType, $l)),
			]);
		}
		return $title === '' ? $type : sprintf('%s "%s"', $type, $title);
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function formatTimelineLabel(array $payload, IL10N $l): string {
		$itemType = trim((string) ($payload['itemType'] ?? ''));
		$label = trim((string) ($payload['label'] ?? ''));
		$type = $itemType === 'milestone' ? $l->t('timeline milestone') : $l->t('timeline item');
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

	private function getProjectName(Project $project, IL10N $l): string {
		$name = trim((string) ($project->getName() ?? ''));
		return $name !== '' ? $name : $l->t('Unnamed project');
	}

	private function getUserDisplayName(IUser $user): string {
		$displayName = trim((string) ($user->getDisplayName() ?? ''));
		return $displayName !== '' ? $displayName : (string) $user->getUID();
	}
}
