<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectRecordingFolderProvider;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;

class ProjectRecordingFolderProviderTest extends TestCase {
	public function testCreatesRecordingsFolderInUserProjectMount(): void {
		$project = new Project();
		$project->setFolderId(42);
		$mapper = $this->createMock(ProjectMapper::class);
		$mapper->method('findByTalkConversationToken')->with('room')->willReturn($project);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$projectFolder = $this->createMock(Folder::class);
		$recordingsFolder = $this->createMock(Folder::class);
		$rootFolder->method('getUserFolder')->with('owner')->willReturn($userFolder);
		$userFolder->method('getById')->with(42)->willReturn([$projectFolder]);
		$projectFolder->method('isCreatable')->willReturn(true);
		$projectFolder->method('get')->with('Recordings')->willThrowException(new NotFoundException());
		$projectFolder->expects($this->once())->method('newFolder')->with('Recordings')->willReturn($recordingsFolder);

		$provider = new ProjectRecordingFolderProvider($mapper, $rootFolder);

		self::assertSame($recordingsFolder, $provider->getFolder('room', 'owner'));
	}

	public function testReturnsExistingWritableRecordingsFolder(): void {
		$project = new Project();
		$project->setFolderId(42);
		$mapper = $this->createMock(ProjectMapper::class);
		$mapper->method('findByTalkConversationToken')->willReturn($project);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$projectFolder = $this->createMock(Folder::class);
		$recordingsFolder = $this->createMock(Folder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('getById')->willReturn([$projectFolder]);
		$projectFolder->method('isCreatable')->willReturn(true);
		$projectFolder->method('get')->with('Recordings')->willReturn($recordingsFolder);
		$recordingsFolder->method('isCreatable')->willReturn(true);
		$projectFolder->expects($this->never())->method('newFolder');

		$provider = new ProjectRecordingFolderProvider($mapper, $rootFolder);

		self::assertSame($recordingsFolder, $provider->getFolder('room', 'owner'));
	}

	public function testRecoversWhenRecordingsFolderIsCreatedConcurrently(): void {
		$project = new Project();
		$project->setFolderId(42);
		$mapper = $this->createMock(ProjectMapper::class);
		$mapper->method('findByTalkConversationToken')->willReturn($project);
		$rootFolder = $this->createMock(IRootFolder::class);
		$userFolder = $this->createMock(Folder::class);
		$projectFolder = $this->createMock(Folder::class);
		$recordingsFolder = $this->createMock(Folder::class);
		$rootFolder->method('getUserFolder')->willReturn($userFolder);
		$userFolder->method('getById')->willReturn([$projectFolder]);
		$projectFolder->method('isCreatable')->willReturn(true);
		$lookupCount = 0;
		$projectFolder->method('get')->with('Recordings')->willReturnCallback(
			static function () use (&$lookupCount, $recordingsFolder): Folder {
				if ($lookupCount++ === 0) {
					throw new NotFoundException();
				}
				return $recordingsFolder;
			},
		);
		$projectFolder->method('newFolder')->with('Recordings')->willThrowException(new \RuntimeException('already exists'));
		$recordingsFolder->method('isCreatable')->willReturn(true);

		$provider = new ProjectRecordingFolderProvider($mapper, $rootFolder);

		self::assertSame($recordingsFolder, $provider->getFolder('room', 'owner'));
	}

	public function testReturnsNullWithoutMatchingProject(): void {
		$mapper = $this->createMock(ProjectMapper::class);
		$mapper->method('findByTalkConversationToken')->willReturn(null);
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->never())->method('getUserFolder');

		$provider = new ProjectRecordingFolderProvider($mapper, $rootFolder);

		self::assertNull($provider->getFolder('room', 'owner'));
	}
}
