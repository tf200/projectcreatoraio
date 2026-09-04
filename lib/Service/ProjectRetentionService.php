<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateInterval;
use DateTime;
use OCA\ProjectCreatorAIO\Db\PrivateFolderLink;
use OCA\ProjectCreatorAIO\Db\PrivateFolderLinkMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDigestCursorMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChatMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

class ProjectRetentionService {
	private const RETENTION_INTERVAL = 'P2Y';

	public function __construct(
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectNoteMapper $projectNoteMapper,
		private readonly TimelineItemMapper $timelineItemMapper,
		private readonly PrivateFolderLinkMapper $privateFolderLinkMapper,
		private readonly ProjectActivityEventMapper $projectActivityEventMapper,
		private readonly ProjectDigestCursorMapper $projectDigestCursorMapper,
		private readonly ?ProjectMemberRoleMapper $memberRoleMapper = null,
		private readonly ?object $boardMapper = null,
		private readonly ?object $folderManager = null,
		private readonly ?object $folderStorageManager = null,
		private readonly ?IRootFolder $rootFolder = null,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?IDBConnection $db = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?ProjectDirectChatMapper $directChatMapper = null,
		private readonly ?ProjectTalkIntegrationService $talkIntegrationService = null,
	) {
	}

	/**
	 * @return array{processed: int, purged: int, dryRun: bool}
	 */
	public function purgeArchivedProjects(bool $dryRun = false, int $limit = 25): array {
		$cutoff = (new DateTime())->sub(new DateInterval(self::RETENTION_INTERVAL));
		$projects = $this->projectMapper->findArchivedBefore($cutoff, $limit);
		$purged = 0;

		foreach ($projects as $project) {
			$projectId = (int)($project->getId() ?? 0);
			if ($projectId <= 0) {
				continue;
			}

			if ($dryRun) {
				$this->logger->info('Project archived retention dry-run match', [
					'projectId' => $projectId,
					'projectName' => $project->getName(),
					'archivedAt' => $project->getArchivedAt(),
				]);
				continue;
			}

			try {
				$this->purgeProject($project);
				$purged++;
			} catch (\Throwable $e) {
				$this->logger->error('Failed to purge archived project', [
					'projectId' => $projectId,
					'projectName' => $project->getName(),
					'exception' => $e,
				]);
			}
		}

		return [
			'processed' => count($projects),
			'purged' => $purged,
			'dryRun' => $dryRun,
		];
	}

	public function deleteProject(Project $project): void {
		$this->purgeProject($project);
	}

	private function purgeProject(Project $project): void {
		$projectId = (int)($project->getId() ?? 0);
		if ($projectId <= 0) {
			return;
		}

		$privateLinks = $this->privateFolderLinkMapper->findByProject($projectId) ?? [];

		$this->deleteBoard($project);
		$this->deleteSharedFolder($project);
		$this->deletePrivateFolders($privateLinks);
		$this->deleteProjectGroup($project);
		$this->deleteTalkConversations($project);

		if ($this->db !== null) {
			$this->db->beginTransaction();
		}
		try {
			$this->projectNoteMapper->deleteByProject($projectId);
			$this->timelineItemMapper->deleteByProject($projectId);
			$this->projectActivityEventMapper->deleteByProject($projectId);
			$this->projectDigestCursorMapper->deleteByProject($projectId);
			$this->memberRoleMapper?->deleteByProject($projectId);
			$this->deleteDeckDoneSyncRows($projectId);
			$this->privateFolderLinkMapper->deleteByProject($projectId);
			$this->directChatMapper?->deleteByProject($projectId);
			$this->projectMapper->deleteProject($project);
			if ($this->db !== null) {
				$this->db->commit();
			}
		} catch (\Throwable $e) {
			if ($this->db !== null) {
				$this->db->rollBack();
			}
			throw $e;
		}

		$this->logger?->info('Purged archived project', [
			'projectId' => $projectId,
			'projectName' => $project->getName(),
		]);
	}

	private function deleteBoard(Project $project): void {
		if ($this->boardMapper === null) {
			return;
		}

		$boardId = trim((string)($project->getBoardId() ?? ''));
		if ($boardId === '' || !ctype_digit($boardId)) {
			return;
		}

		try {
			$board = $this->boardMapper->find((int)$boardId, allowDeleted: true);
			$this->boardMapper->delete($board);
		} catch (\Throwable $e) {
			$this->logger->warning('Board cleanup skipped during project purge', [
				'projectId' => $project->getId(),
				'boardId' => $boardId,
				'exception' => $e,
			]);
		}
	}

	private function deleteSharedFolder(Project $project): void {
		$rootNodeId = (int)($project->getFolderId() ?? 0);
		$groupFolderId = (int)($project->getGroupFolderId() ?? 0);
		if ($rootNodeId <= 0 && $groupFolderId <= 0) {
			return;
		}

		if ($groupFolderId > 0 && $this->folderManager !== null && $this->folderStorageManager !== null) {
			try {
				$groupFolder = $this->folderManager->getFolder($groupFolderId);
				if ($groupFolder === null) {
					$this->deleteStandardFolderFallback($project, $rootNodeId);
					return;
				}
				$this->folderStorageManager->deleteStoragesForFolder($groupFolder);
				$this->folderManager->removeFolder($groupFolderId);
			} catch (\Throwable $e) {
				$this->logger->warning('Shared folder cleanup skipped during project purge', [
					'projectId' => $project->getId(),
					'groupFolderId' => $groupFolderId,
					'exception' => $e,
				]);
			}
		} else {
			$this->deleteStandardFolderFallback($project, $rootNodeId);
		}
	}

	private function deleteStandardFolderFallback(Project $project, int $folderId): void {
		try {
			$ownerFolder = $this->rootFolder->getUserFolder($project->getOwnerId());
			$nodes = $ownerFolder->getById($folderId);
			$node = $nodes[0] ?? null;
			if ($node !== null && $node->isDeletable()) {
				$node->delete();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Fallback shared folder cleanup skipped during project purge', [
				'projectId' => $project->getId(),
				'folderId' => $folderId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * @param PrivateFolderLink[] $privateLinks
	 */
	private function deletePrivateFolders(array $privateLinks): void {
		foreach ($privateLinks as $link) {
			$folderId = (int)($link->getFolderId() ?? 0);
			if ($folderId > 0) {
				try {
					$nodes = $this->rootFolder->getById($folderId);
					foreach ($nodes as $node) {
						if ($node->isDeletable()) {
							$node->delete();
						}
					}
					continue;
				} catch (\Throwable $e) {
					$this->logger->warning('Private folder id cleanup failed during project purge; falling back to path lookup', [
						'projectId' => $link->getProjectId(),
						'folderId' => $folderId,
						'exception' => $e,
					]);
				}
			}

			$userId = trim((string)($link->getUserId() ?? ''));
			$folderPath = trim((string)($link->getFolderPath() ?? ''));
			if ($userId === '' || $folderPath === '') {
				continue;
			}

			try {
				$userFolder = $this->rootFolder->getUserFolder($userId);
				$folderName = basename($folderPath);
				if ($folderName !== '' && $userFolder->nodeExists($folderName)) {
					$userFolder->get($folderName)->delete();
				}
			} catch (\Throwable $e) {
				$this->logger->warning('Private folder path cleanup skipped during project purge', [
					'projectId' => $link->getProjectId(),
					'userId' => $userId,
					'folderPath' => $folderPath,
					'exception' => $e,
				]);
			}
		}
	}

	private function deleteProjectGroup(Project $project): void {
		$groupId = trim((string)($project->getProjectGroupGid() ?? ''));
		if ($groupId === '') {
			return;
		}

		try {
			$group = $this->groupManager->get($groupId);
			if ($group !== null) {
				$group->delete();
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Project group cleanup skipped during project purge', [
				'projectId' => $project->getId(),
				'groupId' => $groupId,
				'exception' => $e,
			]);
		}
	}

	private function deleteTalkConversations(Project $project): void {
		$tokens = [];
		$mainToken = trim((string)($project->getTalkConversationToken() ?? ''));
		if ($mainToken !== '') {
			$tokens[] = $mainToken;
		}

		$projectId = (int)($project->getId() ?? 0);
		if ($projectId > 0 && $this->directChatMapper !== null) {
			try {
				$directChats = $this->directChatMapper->findByProject($projectId);
				foreach ($directChats as $chat) {
					$token = trim((string)$chat->getTalkConversationToken());
					if ($token !== '') {
						$tokens[] = $token;
					}
				}
			} catch (\Throwable $e) {
				$this->logger?->warning('Failed to find direct chats for Talk cleanup', [
					'projectId' => $projectId,
					'exception' => $e,
				]);
			}
		}

		if (!empty($tokens) && $this->talkIntegrationService !== null) {
			try {
				$this->talkIntegrationService->deleteConversations($tokens);
			} catch (\Throwable $e) {
				$this->logger?->warning('Failed to delete Talk conversations during project purge', [
					'projectId' => $projectId,
					'exception' => $e,
				]);
			}
		}
	}

	private function deleteDeckDoneSyncRows(int $projectId): void {
		if ($this->db === null) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		if ($qb === null) {
			return;
		}

		$qb->delete('project_deck_done_sync')
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
