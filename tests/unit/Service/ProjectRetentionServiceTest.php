<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\Deck\Db\BoardMapper;
use OCA\ProjectCreatorAIO\Db\PrivateFolderLinkMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDigestCursorMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChat;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChatMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Service\ProjectRetentionService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\FolderStorageManager;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectRetentionServiceTest extends TestCase
{
	public function testPurgeArchivedProjectsDryRunDoesNotDelete(): void
	{
		$project = new Project();
		$project->setId(42);
		$project->setName('Alpha');
		$project->setArchivedAt(new \DateTime('2024-01-01 00:00:00'));

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->expects($this->once())
			->method('findArchivedBefore')
			->willReturn([$project]);
		$projectMapper->expects($this->never())->method('deleteProject');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');

		$service = new ProjectRetentionService(
			$projectMapper,
			$this->createMock(ProjectNoteMapper::class),
			$this->createMock(TimelineItemMapper::class),
			$this->createMock(PrivateFolderLinkMapper::class),
			$this->createMock(ProjectActivityEventMapper::class),
			$this->createMock(ProjectDigestCursorMapper::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardMapper::class),
			$this->createMock(FolderManager::class),
			$this->createMock(FolderStorageManager::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IDBConnection::class),
			$logger,
		);

		$result = $service->purgeArchivedProjects(true, 10);

		$this->assertSame([
			'processed' => 1,
			'purged' => 0,
			'dryRun' => true,
		], $result);
	}

	public function testDeleteProjectPurgesDirectChatsAndTalkRooms(): void
	{
		$project = new Project();
		$project->setId(42);
		$project->setName('Beta');
		$project->setTalkConversationToken('main-talk-tok');

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->expects($this->once())
			->method('deleteProject')
			->with($project);

		$chat = new ProjectDirectChat();
		$chat->setId(10);
		$chat->setProjectId(42);
		$chat->setUser1Id('alice');
		$chat->setUser2Id('bob');
		$chat->setTalkConversationToken('direct-chat-tok');

		$directChatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$directChatMapper->expects($this->once())
			->method('findByProject')
			->with(42)
			->willReturn([$chat]);
		$directChatMapper->expects($this->once())
			->method('deleteByProject')
			->with(42);

		$talkService = $this->createMock(ProjectTalkIntegrationService::class);
		$talkService->expects($this->once())
			->method('deleteConversations')
			->with(['main-talk-tok', 'direct-chat-tok']);

		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->once())->method('beginTransaction');
		$db->expects($this->once())->method('commit');

		$service = new ProjectRetentionService(
			$projectMapper,
			$this->createMock(ProjectNoteMapper::class),
			$this->createMock(TimelineItemMapper::class),
			$this->createMock(PrivateFolderLinkMapper::class),
			$this->createMock(ProjectActivityEventMapper::class),
			$this->createMock(ProjectDigestCursorMapper::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			null,
			null,
			null,
			$this->createMock(IRootFolder::class),
			$this->createMock(IGroupManager::class),
			$db,
			$this->createMock(LoggerInterface::class),
			$directChatMapper,
			$talkService,
		);

		$service->deleteProject($project);
	}
}
