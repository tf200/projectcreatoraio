<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class ProjectDownloadService
{
	private const EXPORT_FOLDER_NAME = '.projectcreatoraio';
	private const EXPORT_SUBFOLDER = 'exports';

	public function __construct(
		private readonly ProjectNoteMapper $noteMapper,
		private readonly TimelineItemMapper $timelineItemMapper,
		private readonly ProjectActivityEventMapper $activityEventMapper,
		private readonly StackMapper $stackMapper,
		private readonly CardMapper $cardMapper,
		private readonly IRootFolder $rootFolder,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Generate the export ZIP and store it in the user's hidden export folder.
	 *
	 * @return string|null Full path to the generated ZIP file, or null on failure.
	 */
	public function generateZip(Project $project, IUser $user): ?string
	{
		$projectId = (int) ($project->getId() ?? 0);
		if ($projectId <= 0) {
			return null;
		}

		$exportDir = $this->ensureExportDirectory($user);
		if ($exportDir === null) {
			return null;
		}

		$safeName = $this->sanitizeFilename((string) ($project->getName() ?? 'project'));
		$timestamp = (new DateTime())->format('Y-m-d_His');
		$zipFileName = "project-{$projectId}-{$safeName}-export-{$timestamp}.zip";

		$zipPath = $this->getTempZipPath($zipFileName);

		$zip = new \ZipArchive();
		if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
			$this->logger->error('Failed to create ZIP archive for project export', [
				'projectId' => $projectId,
				'zipPath' => $zipPath,
			]);
			return null;
		}

		try {
			$zip->addFromString('00-Project-Overview.md', $this->buildOverviewMarkdown($project));
			$zip->addFromString('01-Members.md', $this->buildMembersMarkdown($project));
			$zip->addFromString('02-Notes.md', $this->buildNotesMarkdown($projectId));
			$zip->addFromString('03-Deck-Board.md', $this->buildDeckMarkdown($project));
			$zip->addFromString('04-Timeline.md', $this->buildTimelineMarkdown($projectId));
			$zip->addFromString('05-Activity-Log.md', $this->buildActivityMarkdown($projectId));

			$this->addSharedFilesToZip($project, $user, $zip);

			if (!$zip->close()) {
				$this->logger->error('Failed to finalize ZIP archive for project export', [
					'projectId' => $projectId,
					'zipPath' => $zipPath,
				]);
				@unlink($zipPath);
				return null;
			}

			$exportFile = $this->writeZipToExportFolder($exportDir, $zipFileName, $zipPath, $projectId);
			@unlink($zipPath);

			return $exportFile?->getPath();
		} catch (\Throwable $e) {
			$this->logger->error('Failed to generate project export ZIP', [
				'projectId' => $projectId,
				'exception' => $e,
			]);
			$zip->close();
			@unlink($zipPath);
			return null;
		}
	}

	public static function getExportFilenamePrefix(int $projectId): string
	{
		return "project-{$projectId}-";
	}

	/**
	 * Get the export folder node for a user.
	 */
	public function getExportFolder(IUser $user): ?Folder
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$path = self::EXPORT_FOLDER_NAME . '/' . self::EXPORT_SUBFOLDER;
			$node = $userFolder->get($path);
			return $node instanceof Folder ? $node : null;
		} catch (NotFoundException) {
			return null;
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Delete old export files from a user's export folder.
	 */
	public function purgeOldExports(IUser $user, int $maxAgeSeconds = 86400): int
	{
		$exportFolder = $this->getExportFolder($user);
		if ($exportFolder === null) {
			return 0;
		}

		$cutoff = time() - $maxAgeSeconds;
		$deleted = 0;

		foreach ($exportFolder->getDirectoryListing() as $node) {
			if ($node->getMTime() < $cutoff) {
				try {
					$node->delete();
					$deleted++;
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to delete old export file', [
						'path' => $node->getPath(),
						'exception' => $e,
					]);
				}
			}
		}

		return $deleted;
	}

	private function ensureExportDirectory(IUser $user): ?Folder
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());

			if (!$userFolder->nodeExists(self::EXPORT_FOLDER_NAME)) {
				$userFolder->newFolder(self::EXPORT_FOLDER_NAME);
			}

			$hiddenFolder = $userFolder->get(self::EXPORT_FOLDER_NAME);
			if (!$hiddenFolder instanceof Folder) {
				return null;
			}

			if (!$hiddenFolder->nodeExists(self::EXPORT_SUBFOLDER)) {
				$hiddenFolder->newFolder(self::EXPORT_SUBFOLDER);
			}

			$subfolder = $hiddenFolder->get(self::EXPORT_SUBFOLDER);
			return $subfolder instanceof Folder ? $subfolder : null;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to ensure export directory', [
				'userId' => $user->getUID(),
				'exception' => $e,
			]);
			return null;
		}
	}

	private function getTempZipPath(string $fileName): string
	{
		$tmpDir = sys_get_temp_dir();
		return $tmpDir . '/projectcreatoraio_export_' . uniqid() . '_' . $fileName;
	}

	private function writeZipToExportFolder(Folder $exportDir, string $fileName, string $zipPath, int $projectId): ?Node
	{
		try {
			if ($exportDir->nodeExists($fileName)) {
				$exportDir->get($fileName)->delete();
			}

			$file = $exportDir->newFile($fileName);
			$handle = fopen($zipPath, 'rb');
			if ($handle === false) {
				$file->delete();
				return null;
			}

			try {
				$file->putContent($handle);
			} finally {
				fclose($handle);
			}

			return $file;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to write export ZIP to user folder', [
				'projectId' => $projectId,
				'fileName' => $fileName,
				'exception' => $e,
			]);
			return null;
		}
	}

	// ─── Markdown Builders ──────────────────────────────────────────

	private function buildOverviewMarkdown(Project $project): string
	{
		$typeLabel = self::typeIdToLabel((int) ($project->getType() ?? -1));
		$statusLabel = self::statusIdToLabel((int) ($project->getStatus() ?? ProjectStatus::ACTIVE));
		$createdAt = $project->getCreatedAt() instanceof DateTime
			? $project->getCreatedAt()->format('Y-m-d H:i:s')
			: '—';
		$updatedAt = $project->getUpdatedAt() instanceof DateTime
			? $project->getUpdatedAt()->format('Y-m-d H:i:s')
			: '—';

		$md = "# Project Overview\n\n";
		$md .= "| Field | Value |\n";
		$md .= "|-------|-------|\n";
		$md .= "| Name | {$this->escapeTableCell($project->getName())} |\n";
		$md .= "| Number | {$this->escapeTableCell($project->getNumber())} |\n";
		$md .= "| Type | {$typeLabel} |\n";
		$md .= "| Status | {$statusLabel} |\n";
		$md .= "| Description | {$this->escapeTableCell($project->getDescription())} |\n";
		$md .= "| Created | {$createdAt} |\n";
		$md .= "| Updated | {$updatedAt} |\n";

		$prepWeeks = $project->getRequiredPreparationWeeks();
		$md .= "| Required Prep Weeks | " . ($prepWeeks !== null ? (string) $prepWeeks : '—') . " |\n";

		$md .= "\n## Client Information\n\n";
		$md .= "| Field | Value |\n";
		$md .= "|-------|-------|\n";
		$md .= "| Name | {$this->escapeTableCell($project->getClientName())} |\n";
		$md .= "| Role | {$this->escapeTableCell($project->getClientRole())} |\n";
		$md .= "| Phone | {$this->escapeTableCell($project->getClientPhone())} |\n";
		$md .= "| Email | {$this->escapeTableCell($project->getClientEmail())} |\n";
		$md .= "| Address | {$this->escapeTableCell($project->getClientAddress())} |\n";

		$md .= "\n## Location\n\n";
		$md .= "| Field | Value |\n";
		$md .= "|-------|-------|\n";
		$md .= "| Street | {$this->escapeTableCell($project->getLocStreet())} |\n";
		$md .= "| City | {$this->escapeTableCell($project->getLocCity())} |\n";
		$md .= "| ZIP | {$this->escapeTableCell($project->getLocZip())} |\n";
		$md .= "| External Ref | {$this->escapeTableCell($project->getExternalRef())} |\n";

		return $md;
	}

	private function buildMembersMarkdown(Project $project): string
	{
		$groupGid = trim((string) ($project->getProjectGroupGid() ?? ''));
		$ownerId = trim((string) ($project->getOwnerId() ?? ''));

		$memberIds = [];
		if ($groupGid !== '') {
			$memberIds = $this->getGroupMemberIds($groupGid);
		}
		if ($ownerId !== '' && !in_array($ownerId, $memberIds, true)) {
			$memberIds[] = $ownerId;
		}

		$md = "# Project Members\n\n";
		$md .= "| Name | User ID | Email | Role |\n";
		$md .= "|------|---------|-------|------|\n";

		foreach ($memberIds as $uid) {
			$user = $this->userManager->get($uid);
			if ($user === null) {
				continue;
			}
			$displayName = $user->getDisplayName() ?: $uid;
			$email = $user->getEMailAddress() ?: '—';
			$role = ($ownerId !== '' && $uid === $ownerId) ? 'Owner' : 'Member';
			$md .= "| {$this->escapeTableCell($displayName)} | {$uid} | {$this->escapeTableCell($email)} | {$role} |\n";
		}

		return $md;
	}

	private function buildNotesMarkdown(int $projectId): string
	{
		$notes = $this->noteMapper->findPublicByProject($projectId);

		$md = "# Project Notes (Public)\n\n";

		if (empty($notes)) {
			$md .= "_No public notes._\n";
			return $md;
		}

		foreach ($notes as $note) {
			$title = trim((string) ($note->getTitle() ?? ''));
			$content = trim((string) ($note->getContent() ?? ''));
			$userId = (string) ($note->getUserId() ?? '');
			$createdAt = $note->getCreatedAt() instanceof DateTime
				? $note->getCreatedAt()->format('Y-m-d H:i:s')
				: '—';

			$heading = $title !== '' ? $title : 'Untitled';
			$md .= "## {$heading}\n\n";
			$md .= "_By {$userId} on {$createdAt}_\n\n";

			if ($content !== '') {
				$md .= $content . "\n";
			}

			$md .= "\n---\n\n";
		}

		return $md;
	}

	private function buildDeckMarkdown(Project $project): string
	{
		$boardIdRaw = $project->getBoardId();
		$boardId = (int) ($boardIdRaw ?? 0);

		$md = "# Deck Board\n\n";

		if ($boardId <= 0) {
			$md .= "_No deck board linked to this project._\n";
			return $md;
		}

		$stacks = $this->stackMapper->findAll($boardId);
		if (empty($stacks)) {
			$md .= "_No stacks found on this board._\n";
			return $md;
		}

		foreach ($stacks as $stack) {
			$stackTitle = trim((string) ($stack->getTitle() ?? 'Untitled'));
			$md .= "## {$this->escapeInlineMarkdown($stackTitle)}\n\n";

			$cards = $this->cardMapper->findAll((int) $stack->getId());
			if (empty($cards)) {
				$md .= "_No cards._\n\n";
				continue;
			}

			foreach ($cards as $card) {
				$cardTitle = trim((string) ($card->getTitle() ?? ''));
				$description = trim((string) ($card->getDescription() ?? ''));
				$owner = (string) ($card->getOwner() ?? '');
				$dueDate = $card->getDuedate();
				$dueDateStr = $dueDate instanceof DateTime ? $dueDate->format('Y-m-d') : '—';

				$md .= "### {$this->escapeInlineMarkdown($cardTitle)}\n\n";
				$md .= "- **Owner**: {$owner}\n";
				$md .= "- **Due**: {$dueDateStr}\n";

				if ($description !== '') {
					$md .= "- **Description**:\n\n  ";
					$md .= str_replace("\n", "\n  ", $description) . "\n";
				}

				$md .= "\n";
			}
		}

		return $md;
	}

	private function buildTimelineMarkdown(int $projectId): string
	{
		$items = $this->timelineItemMapper->findByProject($projectId);

		$md = "# Timeline\n\n";

		if (empty($items)) {
			$md .= "_No timeline items._\n";
			return $md;
		}

		$md .= "| # | Label | Type | Start | End |\n";
		$md .= "|---|-------|------|-------|-----|\n";

		$index = 1;
		foreach ($items as $item) {
			$label = $this->escapeTableCell($item->getLabel());
			$itemType = trim((string) ($item->getItemType() ?? 'phase'));
			$itemType = $itemType === 'milestone' ? 'Milestone' : 'Phase';

			$startDate = $item->getStartDate() instanceof DateTime
				? $item->getStartDate()->format('Y-m-d')
				: '—';
			$endDate = $item->getEndDate() instanceof DateTime
				? $item->getEndDate()->format('Y-m-d')
				: '—';

			$md .= "| {$index} | {$label} | {$itemType} | {$startDate} | {$endDate} |\n";
			$index++;
		}

		return $md;
	}

	private function buildActivityMarkdown(int $projectId): string
	{
		$events = $this->activityEventMapper->findForProject($projectId, null, 100, 0);

		$md = "# Activity Log (Last 100 events)\n\n";

		if (empty($events)) {
			$md .= "_No activity recorded._\n";
			return $md;
		}

		$md .= "| When | Who | Event | Details |\n";
		$md .= "|------|-----|-------|--------|\n";

		foreach ($events as $event) {
			$occurredAt = $event->getOccurredAt() instanceof DateTime
				? $event->getOccurredAt()->format('Y-m-d H:i')
				: '—';
			$actor = $this->escapeTableCell($event->getActorDisplayName() ?? $event->getActorUid() ?? '');
			$eventType = $this->humanizeEventType($event->getEventType() ?? '');
			$details = $this->summarizeEventPayload($event->getEventType() ?? '', $event->getPayloadArray());

			$md .= "| {$occurredAt} | {$actor} | {$eventType} | {$this->escapeTableCell($details)} |\n";
		}

		return $md;
	}

	// ─── File Tree Walker ───────────────────────────────────────────

	private function addSharedFilesToZip(Project $project, IUser $user, \ZipArchive $zip): void
	{
		try {
			$userFolder = $this->rootFolder->getUserFolder($user->getUID());
			$folderPath = (string) ($project->getFolderPath() ?? '');

			if ($folderPath === '') {
				return;
			}

			$node = $userFolder->get($folderPath);
			if (!$node instanceof Folder) {
				return;
			}

			$this->addFolderToZip($node, $zip, 'files');
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to add shared files to export ZIP', [
				'projectId' => (int) ($project->getId() ?? 0),
				'exception' => $e,
			]);
		}
	}

	private function addFolderToZip(Folder $folder, \ZipArchive $zip, string $prefix): void
	{
		foreach ($folder->getDirectoryListing() as $child) {
			$name = $child->getName();
			$zipPath = $prefix . '/' . $name;

			if ($child instanceof Folder) {
				$zip->addEmptyDir($zipPath);
				$this->addFolderToZip($child, $zip, $zipPath);
			} else {
				$content = $child->getContent();
				if (is_string($content)) {
					$zip->addFromString($zipPath, $content);
				}
			}
		}
	}

	// ─── Helpers ────────────────────────────────────────────────────

	private function getGroupMemberIds(string $groupGid): array
	{
		// Uses a direct query since IGroupManager doesn't expose a simple member list.
		try {
			$group = \OC::$server->get(\OCP\IGroupManager::class)->get($groupGid);
			if ($group === null) {
				return [];
			}
			return array_map(
				fn (\OCP\IUser $u) => $u->getUID(),
				$group->getUsers()
			);
		} catch (\Throwable) {
			return [];
		}
	}

	private function escapeTableCell(?string $value): string
	{
		if ($value === null || $value === '') {
			return '—';
		}
		// Escape pipe characters and strip newlines for table cells.
		return str_replace(["|", "\n", "\r"], ['\\|', ' ', ''], trim($value));
	}

	private function escapeInlineMarkdown(?string $value): string
	{
		if ($value === null || $value === '') {
			return '—';
		}
		// Escape markdown special characters that could break headings.
		return str_replace(['#', '*', '_', '`', '[', ']'], ['\\#', '\\*', '\\_', '\\`', '\\[', '\\]'], trim($value));
	}

	private function sanitizeFilename(string $name): string
	{
		$name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $name);
		$name = preg_replace('/\s+/', '-', trim($name));
		return $name !== '' ? substr($name, 0, 50) : 'project';
	}

	private static function typeIdToLabel(int $type): string
	{
		return match ($type) {
			0 => 'Combi',
			1 => 'Solo Elektra',
			2 => 'Solo Water',
			3 => 'Custom',
			default => 'Unknown',
		};
	}

	private static function statusIdToLabel(int $status): string
	{
		return match ($status) {
			ProjectStatus::ACTIVE => 'Active',
			ProjectStatus::WAITING_ON_CUSTOMER => 'Waiting on Customer',
			ProjectStatus::ON_HOLD => 'On Hold',
			ProjectStatus::DONE => 'Done',
			ProjectStatus::ARCHIVED => 'Archived',
			default => 'Unknown',
		};
	}

	private function humanizeEventType(string $eventType): string
	{
		return ucwords(str_replace('_', ' ', $eventType));
	}

	private function summarizeEventPayload(string $eventType, array $payload): string
	{
		return match ($eventType) {
			'project_updated' => 'Changed: ' . implode(', ', $payload['changed_fields'] ?? []),
			'member_added' => 'Added ' . ($payload['member_uid'] ?? '') . ' to project',
			'member_removed' => 'Removed ' . ($payload['member_uid'] ?? '') . ' from project',
			'note_created', 'note_updated', 'note_deleted' => ($payload['note_title'] ?? 'Untitled'),
			'deck_card_created', 'deck_card_updated', 'deck_card_deleted' => 'Card: ' . ($payload['card_title'] ?? ''),
			'file_created', 'file_updated', 'file_deleted' => ($payload['file_name'] ?? ''),
			'timeline_item_created', 'timeline_item_updated', 'timeline_item_deleted' => ($payload['item_label'] ?? ''),
			default => '',
		};
	}
}
