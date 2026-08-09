<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Notification;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCA\ProjectCreatorAIO\Notification\Notifier;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase {
	public function testPrepareProjectMemberAddedNotification(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('projectcreatoraio.page.index')
			->willReturn('https://cloud.test/apps/projectcreatoraio');
		$urlGenerator->method('imagePath')
			->with(Application::APP_ID, 'app.svg')
			->willReturn('/apps/projectcreatoraio/img/app.svg');
		$urlGenerator->method('getAbsoluteURL')
			->with('/apps/projectcreatoraio/img/app.svg')
			->willReturn('https://cloud.test/apps/projectcreatoraio/img/app.svg');

		$manager = \OC::$server->get(INotificationManager::class);
		$notification = $manager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser('new-member')
			->setObject('project_member', '42:new-member')
			->setSubject(
				ProjectNotificationService::SUBJECT_MEMBER_ADDED,
				[
					'projectId' => '42',
					'projectName' => 'Alpha',
					'actorUid' => 'owner1',
					'actorDisplayName' => 'Owner One',
				],
			)
			->setDateTime(new \DateTime());

		$prepared = (new Notifier($l10nFactory, $urlGenerator))->prepare($notification, 'en');

		$this->assertSame('You were added to project {project} by {actor}', $prepared->getRichSubject());
		$this->assertSame('https://cloud.test/apps/projectcreatoraio', $prepared->getLink());
		$this->assertSame('https://cloud.test/apps/projectcreatoraio/img/app.svg', $prepared->getIcon());
		$this->assertSame('Alpha', $prepared->getRichSubjectParameters()['project']['name']);
		$this->assertSame('Owner One', $prepared->getRichSubjectParameters()['actor']['name']);
	}

	public function testPrepareProjectWhiteboardUpdatedNotification(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('projectcreatoraio.page.index')
			->willReturn('https://cloud.test/apps/projectcreatoraio');
		$urlGenerator->method('imagePath')
			->with(Application::APP_ID, 'app.svg')
			->willReturn('/apps/projectcreatoraio/img/app.svg');
		$urlGenerator->method('getAbsoluteURL')
			->with('/apps/projectcreatoraio/img/app.svg')
			->willReturn('https://cloud.test/apps/projectcreatoraio/img/app.svg');

		$manager = \OC::$server->get(INotificationManager::class);
		$notification = $manager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser('member-2')
			->setObject('project_whiteboard', '42:10')
			->setSubject(
				ProjectNotificationService::SUBJECT_WHITEBOARD_UPDATED,
				[
					'projectId' => '42',
					'projectName' => 'Alpha',
					'actorUid' => 'owner1',
					'actorDisplayName' => 'Owner One',
				],
			)
			->setDateTime(new \DateTime());

		$prepared = (new Notifier($l10nFactory, $urlGenerator))->prepare($notification, 'en');

		$this->assertSame('{actor} updated the whiteboard for {project}', $prepared->getRichSubject());
		$this->assertSame('https://cloud.test/apps/projectcreatoraio', $prepared->getLink());
		$this->assertSame('https://cloud.test/apps/projectcreatoraio/img/app.svg', $prepared->getIcon());
		$this->assertSame('Alpha', $prepared->getRichSubjectParameters()['project']['name']);
		$this->assertSame('Owner One', $prepared->getRichSubjectParameters()['actor']['name']);
	}

	public function testPrepareProjectDeckStaleNotification(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(static fn (string $text): string => $text);

		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('projectcreatoraio.page.index')
			->willReturn('https://cloud.test/apps/projectcreatoraio');
		$urlGenerator->method('imagePath')
			->with(Application::APP_ID, 'app.svg')
			->willReturn('/apps/projectcreatoraio/img/app.svg');
		$urlGenerator->method('getAbsoluteURL')
			->with('/apps/projectcreatoraio/img/app.svg')
			->willReturn('https://cloud.test/apps/projectcreatoraio/img/app.svg');

		$manager = \OC::$server->get(INotificationManager::class);
		$notification = $manager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser('member-2')
			->setObject('project_deck_stale', '42')
			->setSubject(
				ProjectNotificationService::SUBJECT_DECK_STALE,
				[
					'projectId' => '42',
					'projectName' => 'Alpha',
				],
			)
			->setDateTime(new \DateTime());

		$prepared = (new Notifier($l10nFactory, $urlGenerator))->prepare($notification, 'en');

		$this->assertSame('Project {project} is waiting on customer because no cards moved for 90 days', $prepared->getRichSubject());
		$this->assertSame('Alpha', $prepared->getRichSubjectParameters()['project']['name']);
	}

	public function testPrepareProjectStatusChangedNotification(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);
		$l10nFactory = $this->createMock(IFactory::class);
		$l10nFactory->method('get')->willReturn($l10n);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->with('projectcreatoraio.page.index')
			->willReturn('https://cloud.test/apps/projectcreatoraio');
		$urlGenerator->method('imagePath')
			->with(Application::APP_ID, 'app.svg')
			->willReturn('/apps/projectcreatoraio/img/app.svg');
		$urlGenerator->method('getAbsoluteURL')
			->with('/apps/projectcreatoraio/img/app.svg')
			->willReturn('https://cloud.test/apps/projectcreatoraio/img/app.svg');

		$manager = \OC::$server->get(INotificationManager::class);
		$notification = $manager->createNotification();
		$notification
			->setApp(Application::APP_ID)
			->setUser('member-2')
			->setObject('project_status', '42')
			->setSubject(ProjectNotificationService::SUBJECT_STATUS_CHANGED, [
				'projectId' => '42',
				'projectName' => 'Alpha',
				'oldStatus' => '1',
				'newStatus' => '4',
				'actorUid' => 'owner1',
				'actorDisplayName' => 'Owner One',
			])
			->setDateTime(new \DateTime());

		$prepared = (new Notifier($l10nFactory, $urlGenerator))->prepare($notification, 'en');

		$this->assertSame('{actor} changed {project} status from {oldStatus} to {newStatus}', $prepared->getRichSubject());
		$this->assertSame('Active', $prepared->getRichSubjectParameters()['oldStatus']['name']);
		$this->assertSame('Done', $prepared->getRichSubjectParameters()['newStatus']['name']);
		$this->assertSame('Owner One', $prepared->getRichSubjectParameters()['actor']['name']);
	}
}
