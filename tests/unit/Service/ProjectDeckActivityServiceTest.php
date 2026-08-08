<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateInterval;
use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectDeckActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectDeckActivityServiceTest extends TestCase {
	public function testRecordCardMoveByBoardIdSkipsMissingDeckActivityColumns(): void {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('15');

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('findByBoardId')->with(15)->willReturn($project);
		$projectMapper->expects($this->once())
			->method('updateProjectDetails')
			->with($project)
			->willThrowException(new \RuntimeException('SQLSTATE[HY000]: General error: 1 no such column: last_deck_move_at'));

		$notificationService = $this->createMock(ProjectNotificationService::class);
		$notificationService->expects($this->never())->method('notifyStatusChanged');
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$service = new ProjectDeckActivityService($projectMapper, $notificationService, $db, $logger);
		$service->recordCardMoveByBoardId(15, new DateTime('2026-03-06 12:00:00'));

		$this->assertInstanceOf(DateTime::class, $project->getLastDeckMoveAt());
	}

	public function testRecordCardMoveByBoardIdReturnsWaitingProjectToActive(): void {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('15');
		$project->setStatus(ProjectDeckActivityService::STATUS_WAITING_ON_CUSTOMER);
		$project->setStaleNotifiedAt(new DateTime('-1 day'));

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('findByBoardId')->with(15)->willReturn($project);
		$projectMapper->expects($this->once())->method('updateProjectDetails')->with($project);

		$notificationService = $this->createMock(ProjectNotificationService::class);
		$notificationService->expects($this->once())
			->method('notifyStatusChanged')
			->with($project, ProjectDeckActivityService::STATUS_WAITING_ON_CUSTOMER, ProjectDeckActivityService::STATUS_ACTIVE);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new ProjectDeckActivityService($projectMapper, $notificationService, $db, $logger);
		$service->recordCardMoveByBoardId(15, new DateTime('2026-03-06 12:00:00'));

		$this->assertSame(ProjectDeckActivityService::STATUS_ACTIVE, $project->getStatus());
		$this->assertNull($project->getStaleNotifiedAt());
		$this->assertInstanceOf(DateTime::class, $project->getLastDeckMoveAt());
	}

	public function testRecordCardMoveByBoardIdReturnsOnHoldProjectToActive(): void {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('15');
		$project->setStatus(ProjectDeckActivityService::STATUS_ON_HOLD);
		$project->setStaleNotifiedAt(new DateTime('-30 day'));

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('findByBoardId')->with(15)->willReturn($project);
		$projectMapper->expects($this->once())->method('updateProjectDetails')->with($project);

		$notificationService = $this->createMock(ProjectNotificationService::class);
		$notificationService->expects($this->once())
			->method('notifyStatusChanged')
			->with($project, ProjectDeckActivityService::STATUS_ON_HOLD, ProjectDeckActivityService::STATUS_ACTIVE);
		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new ProjectDeckActivityService($projectMapper, $notificationService, $db, $logger);
		$service->recordCardMoveByBoardId(15, new DateTime('2026-03-06 12:00:00'));

		$this->assertSame(ProjectDeckActivityService::STATUS_ACTIVE, $project->getStatus());
		$this->assertNull($project->getStaleNotifiedAt());
		$this->assertInstanceOf(DateTime::class, $project->getLastDeckMoveAt());
	}

	public function testProcessStaleProjectsMarksOldProjectsWaitingOnCustomerOnce(): void {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('15');
		$project->setName('Alpha');
		$project->setStatus(ProjectDeckActivityService::STATUS_ACTIVE);
		$project->setCreatedAt(new DateTime('2025-10-01 09:00:00'));

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('listDeckTrackedProjects')->willReturn([$project]);
		$projectMapper->expects($this->once())->method('updateProjectDetails')->with($project);

		$notificationService = $this->createMock(ProjectNotificationService::class);
		$notificationService->expects($this->once())->method('notifyDeckStale')->with($project);
		$notificationService->expects($this->once())
			->method('notifyStatusChanged')
			->with($project, ProjectDeckActivityService::STATUS_ACTIVE, ProjectDeckActivityService::STATUS_WAITING_ON_CUSTOMER);

		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new ProjectDeckActivityService($projectMapper, $notificationService, $db, $logger);
		$service->processStaleProjects(new DateTime('2026-03-06 12:00:00'));

		$this->assertSame(ProjectDeckActivityService::STATUS_WAITING_ON_CUSTOMER, $project->getStatus());
		$this->assertInstanceOf(DateTime::class, $project->getStaleNotifiedAt());
	}

	public function testProcessStaleProjectsMarksVeryOldProjectsOnHold(): void {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('15');
		$project->setStatus(ProjectDeckActivityService::STATUS_ACTIVE);
		$project->setCreatedAt(new DateTime('2025-03-01 09:00:00'));

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('listDeckTrackedProjects')->willReturn([$project]);
		$projectMapper->expects($this->once())->method('updateProjectDetails')->with($project);

		$notificationService = $this->createMock(ProjectNotificationService::class);
		$notificationService->expects($this->never())->method('notifyDeckStale');
		$notificationService->expects($this->once())
			->method('notifyStatusChanged')
			->with($project, ProjectDeckActivityService::STATUS_ACTIVE, ProjectDeckActivityService::STATUS_ON_HOLD);

		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new ProjectDeckActivityService($projectMapper, $notificationService, $db, $logger);
		$service->processStaleProjects(new DateTime('2026-03-06 12:00:00'));

		$this->assertSame(ProjectDeckActivityService::STATUS_ON_HOLD, $project->getStatus());
	}

	public function testProcessStaleProjectsSkipsAlreadyNotifiedWaitingProjects(): void {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('15');
		$project->setStatus(ProjectDeckActivityService::STATUS_WAITING_ON_CUSTOMER);
		$project->setCreatedAt(new DateTime('2025-10-01 09:00:00'));
		$project->setStaleNotifiedAt(new DateTime('2026-03-01 10:00:00'));

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('listDeckTrackedProjects')->willReturn([$project]);
		$projectMapper->expects($this->never())->method('updateProjectDetails');

		$notificationService = $this->createMock(ProjectNotificationService::class);
		$notificationService->expects($this->never())->method('notifyDeckStale');

		$db = $this->createMock(IDBConnection::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new ProjectDeckActivityService($projectMapper, $notificationService, $db, $logger);
		$service->processStaleProjects(new DateTime('2026-03-06 12:00:00'));
	}
}
