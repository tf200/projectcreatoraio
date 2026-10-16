<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\Deck\Service\BoardService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberSourceMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands a project to another owner. The new owner must already work on the
 * project and belong to its organization; externals cannot own a project.
 * The previous owner stays on as an ordinary member.
 */
class ProjectOwnershipService {
	public const STEP_DONE = 'done';
	public const STEP_SKIPPED = 'skipped';
	public const STEP_FAILED = 'failed';

	public function __construct(
		private ProjectMapper $projectMapper,
		private ProjectAccessService $access,
		private ProjectMemberSourceMapper $memberSourceMapper,
		private ProjectTalkIntegrationService $talkIntegrationService,
		private ProjectActivityService $activityService,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private ?BoardService $boardService = null,
	) {
	}

	/**
	 * The owner changes even when the board or the conversation cannot follow;
	 * those steps are reported so the caller can say so.
	 *
	 * @return array{ownerId: string, previousOwnerId: string, board: string, talk: string}
	 */
	public function transfer(Project $project, string $newOwnerUid): array {
		$newOwnerUid = trim($newOwnerUid);
		$previousOwnerUid = trim((string)$project->getOwnerId());
		if ($newOwnerUid === '') {
			throw new OCSException('Choose the new owner.', 400);
		}
		if ($newOwnerUid === $previousOwnerUid) {
			return ['ownerId' => $newOwnerUid, 'previousOwnerId' => $previousOwnerUid, 'board' => self::STEP_SKIPPED, 'talk' => self::STEP_SKIPPED];
		}

		$newOwner = $this->userManager->get($newOwnerUid);
		if ($newOwner === null) {
			throw new OCSException('The new owner does not exist.', 404);
		}
		$groupGid = trim((string)($project->getProjectGroupGid() ?? ''));
		if ($groupGid === '' || !$this->groupManager->isInGroup($newOwnerUid, $groupGid)) {
			throw new OCSException('The new owner must already be a member of the project.', 409);
		}
		$membership = $this->access->getOrganizationMembership($newOwnerUid);
		if ($membership === null || $membership['organization_id'] !== (int)$project->getOrganizationId()) {
			throw new OCSException('Only members of the project\'s organization can own it, not external collaborators.', 409);
		}

		// The owner counts as a member without being in the group; the
		// previous owner needs the group and an origin to stay on.
		$previousOwner = $previousOwnerUid !== '' ? $this->userManager->get($previousOwnerUid) : null;
		if ($previousOwner !== null) {
			if (!$this->groupManager->isInGroup($previousOwnerUid, $groupGid)) {
				$this->groupManager->get($groupGid)?->addUser($previousOwner);
			}
			$this->memberSourceMapper->addSource((int)$project->getId(), $previousOwnerUid, ProjectMemberSourceMapper::MANUAL);
		}

		$project->setOwnerId($newOwnerUid);
		$this->projectMapper->updateProjectDetails($project);

		$board = $this->transferBoard($project, $newOwnerUid);
		$token = trim((string)($project->getTalkConversationToken() ?? ''));
		$talk = self::STEP_SKIPPED;
		if ($token !== '' && $this->talkIntegrationService->isAvailable()) {
			$talk = $this->talkIntegrationService->promoteToModerator($token, $newOwner) ? self::STEP_DONE : self::STEP_FAILED;
		}

		if ($previousOwner !== null) {
			try {
				$this->activityService->recordOwnerChanged($project, $previousOwner, $newOwner, $this->userSession->getUser());
			} catch (Throwable $e) {
				$this->logger->warning('Failed to record the project owner change', ['projectId' => $project->getId(), 'exception' => $e]);
			}
		}

		return ['ownerId' => $newOwnerUid, 'previousOwnerId' => $previousOwnerUid, 'board' => $board, 'talk' => $talk];
	}

	/** The previous owner keeps full rights on the board as a participant. */
	private function transferBoard(Project $project, string $newOwnerUid): string {
		$boardId = (int)($project->getBoardId() ?? 0);
		if ($boardId <= 0 || $this->boardService === null) {
			return self::STEP_SKIPPED;
		}
		try {
			$this->boardService->transferBoardOwnership($boardId, $newOwnerUid, false);
			return self::STEP_DONE;
		} catch (Throwable $e) {
			$this->logger->error('Failed to transfer the project board to the new owner', [
				'projectId' => $project->getId(),
				'boardId' => $boardId,
				'exception' => $e,
			]);
			return self::STEP_FAILED;
		}
	}
}
