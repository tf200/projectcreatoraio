<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class ProjectRecordingFolderProvider {
	private const RECORDINGS_FOLDER = 'Recordings';

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly IRootFolder $rootFolder,
	) {
	}

	public function getFolder(string $conversationToken, string $userId): ?Folder {
		$project = $this->projectMapper->findByTalkConversationToken($conversationToken);
		if ($project === null || $project->getFolderId() === null) {
			return null;
		}

		$nodes = $this->rootFolder->getUserFolder($userId)->getById((int)$project->getFolderId());
		$projectFolder = array_shift($nodes);
		if (!$projectFolder instanceof Folder || !$projectFolder->isCreatable()) {
			return null;
		}

		try {
			$recordingsFolder = $projectFolder->get(self::RECORDINGS_FOLDER);
			return $recordingsFolder instanceof Folder && $recordingsFolder->isCreatable()
				? $recordingsFolder
				: null;
		} catch (NotFoundException) {
			try {
				return $projectFolder->newFolder(self::RECORDINGS_FOLDER);
			} catch (\Throwable $e) {
				$recordingsFolder = $projectFolder->get(self::RECORDINGS_FOLDER);
				if ($recordingsFolder instanceof Folder && $recordingsFolder->isCreatable()) {
					return $recordingsFolder;
				}
				throw $e;
			}
		}
	}
}
