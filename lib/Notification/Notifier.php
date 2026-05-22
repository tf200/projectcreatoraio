<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Notification;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Projects');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);

		switch ($notification->getSubject()) {
			case ProjectNotificationService::SUBJECT_MEMBER_ADDED:
				$params = $notification->getSubjectParameters();
				$projectName = trim((string) ($params['projectName'] ?? ''));
				$actorDisplayName = trim((string) ($params['actorDisplayName'] ?? ''));

				if ($projectName === '') {
					$projectName = $l->t('Unnamed project');
				}

				if ($actorDisplayName === '') {
					$actorDisplayName = $l->t('Someone');
				}

				$notification
					->setRichSubject(
						$l->t('You were added to project {project} by {actor}'),
						[
							'project' => [
								'type' => 'highlight',
								'id' => (string) ($params['projectId'] ?? ''),
								'name' => $projectName,
							],
							'actor' => [
								'type' => 'highlight',
								'id' => (string) ($params['actorUid'] ?? ''),
								'name' => $actorDisplayName,
							],
						],
					)
					->setLink($this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.index'))
					->setIcon($this->urlGenerator->getAbsoluteURL(
						$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
					));

				return $notification;

			case ProjectNotificationService::SUBJECT_WHITEBOARD_UPDATED:
				$params = $notification->getSubjectParameters();
				$projectName = trim((string) ($params['projectName'] ?? ''));
				$actorDisplayName = trim((string) ($params['actorDisplayName'] ?? ''));

				if ($projectName === '') {
					$projectName = $l->t('Unnamed project');
				}

				if ($actorDisplayName === '') {
					$actorDisplayName = $l->t('Someone');
				}

				$notification
					->setRichSubject(
						$l->t('{actor} updated the whiteboard for {project}'),
						[
							'actor' => [
								'type' => 'highlight',
								'id' => (string) ($params['actorUid'] ?? ''),
								'name' => $actorDisplayName,
							],
							'project' => [
								'type' => 'highlight',
								'id' => (string) ($params['projectId'] ?? ''),
								'name' => $projectName,
							],
						],
					)
					->setLink($this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.index'))
					->setIcon($this->urlGenerator->getAbsoluteURL(
						$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
					));

				return $notification;

		case ProjectNotificationService::SUBJECT_DECK_STALE:
			$params = $notification->getSubjectParameters();
			$projectName = trim((string) ($params['projectName'] ?? ''));

			if ($projectName === '') {
				$projectName = $l->t('Unnamed project');
			}

			$notification
				->setRichSubject(
					$l->t('Project {project} is waiting on customer because no cards moved for 90 days'),
					[
						'project' => [
							'type' => 'highlight',
							'id' => (string) ($params['projectId'] ?? ''),
							'name' => $projectName,
						],
					],
				)
				->setLink($this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.index'))
				->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
				));

			return $notification;

		case ProjectNotificationService::SUBJECT_EXPORT_READY:
			$params = $notification->getSubjectParameters();
			$projectName = trim((string) ($params['projectName'] ?? ''));

			if ($projectName === '') {
				$projectName = $l->t('Unnamed project');
			}

			$notification
				->setRichSubject(
					$l->t('Your export for project {project} is ready to download'),
					[
						'project' => [
							'type' => 'highlight',
							'id' => (string) ($params['projectId'] ?? ''),
							'name' => $projectName,
						],
					],
				)
				->setLink($this->urlGenerator->linkToRouteAbsolute('projectcreatoraio.page.index'))
				->setIcon($this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
				));

			return $notification;

		default:
				throw new UnknownNotificationException();
		}
	}
}
