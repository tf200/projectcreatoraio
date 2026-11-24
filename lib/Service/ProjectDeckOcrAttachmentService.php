<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\Deck\Db\Acl;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Service\AttachmentService;
use OCA\Deck\Service\CardFileAttachmentService;
use OCA\Deck\Service\PermissionService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectFileProcessing;
use OCA\ProjectCreatorAIO\Db\ProjectFileProcessingMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;

class ProjectDeckOcrAttachmentService
{
	private DefaultLanguageL10n $contentL10n;

	public function __construct(
		private IRequest $request,
		private IL10N $l10n,
		private IRootFolder $rootFolder,
		private CardMapper $cardMapper,
		private PermissionService $permissionService,
		private OcrDocumentService $ocrDocumentService,
		private FileProcessingPipelineService $fileProcessingPipelineService,
		private ProjectFileProcessingMapper $processingMapper,
		private ProjectMapper $projectMapper,
		private CardFileAttachmentService $cardFileAttachmentService,
		private AttachmentService $attachmentService,
		?DefaultLanguageL10n $contentL10n = null,
	) {
		$this->contentL10n = $contentL10n ?? DefaultLanguageL10n::instance();
	}

	public function uploadAndAttach(Project $project, string $userId, int $cardId, int $documentTypeId): array
	{
		$this->assertCardBelongsToProject($project, $cardId);
		$storageScope = $this->getRequestedStorageScope();

		$stagedFile = $this->createStagedFile($project, $userId);
		$record = null;

		try {
			$record = $this->ocrDocumentService->createProcessingRecordForFile($project, $stagedFile, $documentTypeId, $userId, $storageScope);
			$record = $this->fileProcessingPipelineService->processRecord($record);

			if ($record->getOcrStatus() !== 'done') {
				$missingFields = $this->extractMissingFields($record);

				return [
					'status' => 'rejected',
					'message' => $record->getErrorMessage() ?: $this->l10n->t('Upload rejected because OCR could not extract all required fields.'),
					'missing_fields' => $missingFields,
					'processing' => $record->jsonSerialize(),
				];
			}

			$finalFile = $this->moveFileToCardFolder($project, $userId, $cardId, $stagedFile, $storageScope);
			$record = $this->refreshProcessingRecordAfterFinalize($record, $finalFile, $userId);

			$attachment = $this->cardFileAttachmentService->attachExistingFileToCard($cardId, $finalFile, $userId);
			$attachment = $this->attachmentService->enrichAttachment($attachment);
			$this->attachmentService->postAttachmentCreated($attachment);

			return [
				'status' => 'accepted',
				'attachment' => $attachment,
				'processing' => $record->jsonSerialize(),
			];
		} catch (\Throwable $e) {
			if ($record instanceof ProjectFileProcessing) {
				$this->deleteProcessingRecord($record);
			}
			$this->deleteFileQuietly($stagedFile);
			throw $e;
		}
	}

	public function finalizePendingAttachment(Project $project, string $userId, int $cardId, int $processingId, array $fields): array
	{
		$this->assertCardBelongsToProject($project, $cardId);

		$record = $this->processingMapper->find($processingId);
		if (!$record instanceof ProjectFileProcessing || (int) $record->getProjectId() !== (int) $project->getId()) {
			throw new OCSException($this->l10n->t('OCR processing record not found.'), 404);
		}

		$file = $this->resolveFileNode((int) $record->getFileId());
		$record = $this->ocrDocumentService->applyExtractedFields($project, $record, $fields);
		if ($record->getOcrStatus() !== 'done') {
			return [
				'status' => 'rejected',
				'message' => $record->getErrorMessage() ?: $this->l10n->t('Upload rejected because OCR could not extract all required fields.'),
				'missing_fields' => $this->extractMissingFields($record),
				'processing' => $record->jsonSerialize(),
			];
		}

		try {
			$storageScope = $this->normalizeStorageScope((string) ($record->getStorageScope() ?? 'shared'));
			$finalFile = $this->moveFileToCardFolder($project, $userId, $cardId, $file, $storageScope);
			$record = $this->refreshProcessingRecordAfterFinalize($record, $finalFile, $userId);

			$attachment = $this->cardFileAttachmentService->attachExistingFileToCard($cardId, $finalFile, $userId);
			$attachment = $this->attachmentService->enrichAttachment($attachment);
			$this->attachmentService->postAttachmentCreated($attachment);

			return [
				'status' => 'accepted',
				'attachment' => $attachment,
				'processing' => $record->jsonSerialize(),
			];
		} catch (\Throwable $e) {
			throw $e;
		}
	}

	private function assertCardBelongsToProject(Project $project, int $cardId): void
	{
		$boardId = $this->cardMapper->findBoardId($cardId);
		if ($boardId === null || (int) $project->getBoardId() !== $boardId) {
			throw new OCSException($this->l10n->t('Card does not belong to the requested project.'), 404);
		}
		$this->permissionService->checkPermission($this->cardMapper, $cardId, Acl::PERMISSION_EDIT);
	}

	private function createStagedFile(Project $project, string $userId): File
	{
		$uploaded = $this->getUploadedFile();
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$stagingFolder = $this->ensureFolder($userFolder, sprintf('.projectcreatoraio-ocr-staging/%d', (int) $project->getId()));
		$fileName = $stagingFolder->getNonExistingName((string) $uploaded['name']);
		$target = $stagingFolder->newFile($fileName);

		$content = fopen($uploaded['tmp_name'], 'rb');
		if ($content === false) {
			throw new OCSException($this->l10n->t('Could not read uploaded file content.'), 500);
		}

		$target->putContent($content);
		if (is_resource($content)) {
			fclose($content);
		}

		return $target;
	}

	private function moveFileToCardFolder(Project $project, string $userId, int $cardId, File $file, string $storageScope = 'shared'): File
	{
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$destinationFolder = $this->ensureFolder($userFolder, $this->buildCardFolderPath($project, $cardId, $storageScope, $userId));
		$targetName = $destinationFolder->getNonExistingName($file->getName());
		$targetPath = rtrim($destinationFolder->getPath(), '/') . '/' . $targetName;
		$moved = $file->move($targetPath);
		if (!$moved instanceof File) {
			throw new OCSException($this->l10n->t('Could not finalize uploaded file.'), 500);
		}
		return $moved;
	}

	private function buildCardFolderPath(Project $project, int $cardId, string $storageScope, string $userId): string
	{
		$storageScope = $this->normalizeStorageScope($storageScope);
		if ($storageScope === 'private') {
			$privateRoot = $this->resolvePrivateRootFolderName($project, $userId);
			if ($privateRoot === '') {
				throw new OCSException($this->l10n->t('Private project folder is missing for this user.'), 400);
			}
			return $privateRoot . '/Scrumban/' . $this->resolveCardFolderName($cardId);
		}

		$projectFolderPath = trim((string) $project->getFolderPath(), '/');
		if ($projectFolderPath === '') {
			throw new OCSException($this->l10n->t('Project folder path is missing.'), 400);
		}

		return basename($projectFolderPath) . '/Scrumban/' . $this->resolveCardFolderName($cardId);
	}

	private function resolveCardFolderName(int $cardId): string
	{
		$card = $this->cardMapper->find($cardId, false);
		$cardFolderName = $this->toSafeFolderName((string) $card->getTitle());
		// Folder in the shared project folder: server default language.
		return $cardFolderName !== '' ? $cardFolderName : $this->contentL10n->get()->t('Card');
	}

	private function resolvePrivateRootFolderName(Project $project, string $userId): string
	{
		$link = $this->projectMapper->findPrivateFolderForUser((int) ($project->getId() ?? 0), $userId);
		if ($link === null) {
			return '';
		}

		$folderId = (int) ($link->getFolderId() ?? 0);
		if ($folderId > 0) {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$node = $userFolder->getFirstNodeById($folderId);
			if ($node instanceof Folder) {
				return trim((string) $userFolder->getRelativePath($node->getPath()), '/');
			}
		}

		return trim(basename((string) ($link->getFolderPath() ?? '')), '/');
	}

	private function getRequestedStorageScope(): string
	{
		return $this->normalizeStorageScope((string) $this->request->getParam('storage_scope', 'shared'));
	}

	private function normalizeStorageScope(string $storageScope): string
	{
		$storageScope = strtolower(trim($storageScope));
		return $storageScope === 'private' ? 'private' : 'shared';
	}

	private function ensureFolder(Folder $baseFolder, string $path): Folder
	{
		$parts = explode('/', trim($path, '/'));
		$currentFolder = $baseFolder;

		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}
			if (!$currentFolder->nodeExists($part)) {
				$currentFolder = $currentFolder->newFolder($part);
				continue;
			}
			$node = $currentFolder->get($part);
			if (!$node instanceof Folder) {
				throw new OCSException($this->l10n->t('A file exists where a folder was expected: %s', [$node->getPath()]), 500);
			}
			$currentFolder = $node;
		}

		return $currentFolder;
	}

	private function refreshProcessingRecordAfterFinalize(ProjectFileProcessing $record, File $file, string $userId): ProjectFileProcessing
	{
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relativePath = (string) $userFolder->getRelativePath($file->getPath());
		$record->setFileId((int) $file->getId());
		$record->setFilePath($relativePath !== '' ? $relativePath : (string) $file->getPath());
		$record->setFileName((string) $file->getName());
		$record->setMimeType((string) $file->getMimeType());
		$record->setProcessedAt(new DateTime());

		return $this->processingMapper->saveRecord($record);
	}

	private function deleteProcessingRecord(ProjectFileProcessing $record): void
	{
		try {
			$this->processingMapper->delete($record);
		} catch (\Throwable) {
		}
	}

	private function deleteFileQuietly(File $file): void
	{
		try {
			$file->delete();
		} catch (\Throwable) {
		}
	}

	private function extractMissingFields(ProjectFileProcessing $record): array
	{
		$missing = [];
		foreach ($record->jsonSerialize()['extracted'] ?? [] as $fieldName => $payload) {
			$value = is_array($payload) ? ($payload['value'] ?? null) : null;
			if (!is_scalar($value) || trim((string) $value) === '') {
				$missing[] = (string) $fieldName;
			}
		}
		return array_values(array_filter($missing, static fn (string $field): bool => trim($field) !== ''));
	}

	private function resolveFileNode(int $fileId): File
	{
		foreach ($this->rootFolder->getById($fileId) as $node) {
			if ($node instanceof File) {
				return $node;
			}
		}

		throw new OCSException($this->l10n->t('OCR source file no longer exists.'), 404);
	}

	private function getUploadedFile(): array
	{
		$file = $this->request->getUploadedFile('file');
		$error = null;
		$phpFileUploadErrors = [
			UPLOAD_ERR_OK => $this->l10n->t('The file was uploaded'),
			UPLOAD_ERR_INI_SIZE => $this->l10n->t('The uploaded file exceeds the upload_max_filesize directive in php.ini'),
			UPLOAD_ERR_FORM_SIZE => $this->l10n->t('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
			UPLOAD_ERR_PARTIAL => $this->l10n->t('The file was only partially uploaded'),
			UPLOAD_ERR_NO_FILE => $this->l10n->t('No file was uploaded'),
			UPLOAD_ERR_NO_TMP_DIR => $this->l10n->t('Missing a temporary folder'),
			UPLOAD_ERR_CANT_WRITE => $this->l10n->t('Could not write file to disk'),
			UPLOAD_ERR_EXTENSION => $this->l10n->t('A PHP extension stopped the file upload'),
		];

		if (empty($file)) {
			$error = $this->l10n->t('No file uploaded or file size exceeds maximum of %s', [\OCP\Util::humanFileSize(\OCP\Util::uploadLimit())]);
		}
		if (!empty($file) && array_key_exists('error', $file) && $file['error'] !== UPLOAD_ERR_OK) {
			$error = $phpFileUploadErrors[$file['error']] ?? $this->l10n->t('Upload failed.');
		}
		if ($error !== null) {
			throw new OCSException($error, 400);
		}

		return $file;
	}

	private function toSafeFolderName(string $name): string
	{
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = str_replace(['/', '\\'], '-', $name);
		$name = str_replace(['<', '>', ':', '"', '|', '?', '*'], '-', $name);
		$name = trim($name);
		$name = trim($name, '.');
		return $name;
	}
}
