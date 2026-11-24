<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class ProjectRecordingFolderProvider {
	/** Name of the folder in projects created before the folder name was translated. */
	private const RECORDINGS_FOLDER = 'Recordings';

	private DefaultLanguageL10n $contentL10n;

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly IRootFolder $rootFolder,
		?DefaultLanguageL10n $contentL10n = null,
	) {
		$this->contentL10n = $contentL10n ?? DefaultLanguageL10n::instance();
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

		// Existing projects keep the folder they already have (English name or a translated
		// one); a new folder gets the name in the server default language.
		$folderName = $this->contentL10n->get()->t('Recordings');
		foreach (array_unique([self::RECORDINGS_FOLDER, $folderName]) as $name) {
			try {
				$recordingsFolder = $projectFolder->get($name);
			} catch (NotFoundException) {
				continue;
			}
			return $recordingsFolder instanceof Folder && $recordingsFolder->isCreatable()
				? $recordingsFolder
				: null;
		}

		try {
			return $projectFolder->newFolder($folderName);
		} catch (\Throwable $e) {
			$recordingsFolder = $projectFolder->get($folderName);
			if ($recordingsFolder instanceof Folder && $recordingsFolder->isCreatable()) {
				return $recordingsFolder;
			}
			throw $e;
		}
	}
}
