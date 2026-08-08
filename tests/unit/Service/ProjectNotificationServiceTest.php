<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Service\ProjectMemberResolver;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectNotificationServiceTest extends TestCase {
	public function testNotifyMemberAddedPublishesNotification(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);
		$notificationManager->expects($this->once())
			->method('notify')
			->with($notification);
		$projectMemberResolver = $this->createMock(ProjectMemberResolver::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);

		$project = new Project();
		$project->setId(42);
		$project->setName('Alpha');

		$member = $this->createMock(IUser::class);
		$member->method('getUID')->willReturn('new-member');

		$actor = $this->createMock(IUser::class);
		$actor->method('getUID')->willReturn('owner1');
		$actor->method('getDisplayName')->willReturn('Owner One');

		$notification->expects($this->once())
			->method('setApp')
			->with(Application::APP_ID)
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setUser')
			->with('new-member')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setObject')
			->with('project_member', '42:new-member')
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with(
				ProjectNotificationService::SUBJECT_MEMBER_ADDED,
				[
					'projectId' => '42',
					'projectName' => 'Alpha',
					'actorUid' => 'owner1',
					'actorDisplayName' => 'Owner One',
				],
			)
			->willReturnSelf();
		$notification->expects($this->once())
			->method('setDateTime')
			->with($this->isInstanceOf(\DateTime::class))
			->willReturnSelf();

		$service = new ProjectNotificationService($notificationManager, $projectMemberResolver, $cacheFactory, $logger);
		$service->notifyMemberAdded($project, $member, $actor);
	}

	public function testNotifyWhiteboardUpdatedPublishesForOtherMembersOnly(): void {
		$notifications = [];

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->expects($this->exactly(2))
			->method('createNotification')
			->willReturnCallback(function () use (&$notifications) {
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setUser')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setSubject')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notifications[] = $notification;
				return $notification;
			});
		$notificationManager->expects($this->exactly(2))
			->method('notify');

		$groupMember = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'member-2',
		]);
		$owner = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'owner1',
		]);
		$actor = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'member-1',
			'getDisplayName' => 'Member One',
		]);

		$projectMemberResolver = $this->createMock(ProjectMemberResolver::class);
		$projectMemberResolver->method('getProjectMembers')
			->with($this->isInstanceOf(Project::class), ['member-1'])
			->willReturn([
				$groupMember,
				$owner,
			]);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with('whiteboard:42')->willReturn(null);
		$cache->expects($this->once())
			->method('set')
			->with('whiteboard:42', $this->isType('int'), 120)
			->willReturn(true);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$cacheFactory->method('createLocal')->willReturn($cache);

		$logger = $this->createMock(LoggerInterface::class);

		$project = new Project();
		$project->setId(42);
		$project->setName('Alpha');
		$project->setOwnerId('owner1');
		$project->setProjectGroupGid('project-alpha');

		$notifications[0] = null;
		$notifications[1] = null;

		$service = new ProjectNotificationService($notificationManager, $projectMemberResolver, $cacheFactory, $logger);
		$service->notifyWhiteboardUpdated($project, $actor);
	}

	public function testNotifyWhiteboardUpdatedRespectsCooldown(): void {
		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->expects($this->never())->method('createNotification');
		$notificationManager->expects($this->never())->method('notify');

		$projectMemberResolver = $this->createMock(ProjectMemberResolver::class);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with('whiteboard:42')->willReturn(time());
		$cache->expects($this->never())->method('set');

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(true);
		$cacheFactory->method('createLocal')->willReturn($cache);

		$logger = $this->createMock(LoggerInterface::class);

		$project = new Project();
		$project->setId(42);
		$project->setProjectGroupGid('project-alpha');

		$actor = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'member-1',
			'getDisplayName' => 'Member One',
		]);

		$service = new ProjectNotificationService($notificationManager, $projectMemberResolver, $cacheFactory, $logger);
		$service->notifyWhiteboardUpdated($project, $actor);
	}

	public function testNotifyDeckStalePublishesToAllMembers(): void {
		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->expects($this->exactly(2))
			->method('createNotification')
			->willReturnCallback(function () {
				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setUser')->willReturnSelf();
				$notification->method('setObject')->willReturnSelf();
				$notification->method('setSubject')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				return $notification;
			});
		$notificationManager->expects($this->exactly(2))->method('notify');

		$groupMember = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'member-1',
		]);
		$owner = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'owner1',
		]);
		$projectMemberResolver = $this->createMock(ProjectMemberResolver::class);
		$projectMemberResolver->method('getProjectMembers')
			->with($this->isInstanceOf(Project::class), [])
			->willReturn([$groupMember, $owner]);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$cacheFactory->method('isAvailable')->willReturn(false);

		$logger = $this->createMock(LoggerInterface::class);

		$project = new Project();
		$project->setId(42);
		$project->setName('Alpha');
		$project->setOwnerId('owner1');
		$project->setProjectGroupGid('project-alpha');

		$service = new ProjectNotificationService($notificationManager, $projectMemberResolver, $cacheFactory, $logger);
		$service->notifyDeckStale($project);
	}

	public function testNotifyStatusChangedPublishesToOtherMembers(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->expects($this->once())->method('createNotification')->willReturn($notification);
		$notificationManager->expects($this->once())->method('notify')->with($notification);

		$recipient = $this->createConfiguredMock(IUser::class, ['getUID' => 'member-2']);
		$projectMemberResolver = $this->createMock(ProjectMemberResolver::class);
		$projectMemberResolver->expects($this->once())
			->method('getProjectMembers')
			->with($this->isInstanceOf(Project::class), ['owner1'])
			->willReturn([$recipient]);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$cacheFactory->method('isAvailable')->willReturn(false);
		$logger = $this->createMock(LoggerInterface::class);

		$project = new Project();
		$project->setId(42);
		$project->setName('Alpha');
		$actor = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'owner1',
			'getDisplayName' => 'Owner One',
		]);

		$notification->expects($this->once())->method('setUser')->with('member-2')->willReturnSelf();
		$notification->expects($this->once())->method('setObject')->with('project_status', '42')->willReturnSelf();
		$notification->expects($this->once())
			->method('setSubject')
			->with(ProjectNotificationService::SUBJECT_STATUS_CHANGED, [
				'projectId' => '42',
				'projectName' => 'Alpha',
				'oldStatus' => '1',
				'newStatus' => '4',
				'actorUid' => 'owner1',
				'actorDisplayName' => 'Owner One',
			])
			->willReturnSelf();

		$service = new ProjectNotificationService($notificationManager, $projectMemberResolver, $cacheFactory, $logger);
		$service->notifyStatusChanged($project, 1, 4, $actor);
	}
}
