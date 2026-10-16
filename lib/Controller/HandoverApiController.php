<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectHandoverStepService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The handover step of a project. The project owner and the organization's
 * administrators may hand a project over.
 */
class HandoverApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProjectMapper $projectMapper,
		private ProjectAccessService $access,
		private ProjectHandoverStepService $handover,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function preview(int $projectId): DataResponse {
		return $this->respond(fn (IUser $user): DataResponse => new DataResponse($this->handover->preview($this->requireHandoverProject($projectId, $user))));
	}

	/**
	 * @param array{mode?: string, teamId?: int} $team
	 * @param list<array{uid?: string, drascivsRoles?: string[], functionalRoleKeys?: string[]}> $addMembers
	 * @param list<array{uid?: string, mode?: string, date?: string}> $externals
	 */
	#[NoAdminRequired]
	public function apply(int $projectId, string $handoverDate = '', string $ownerUid = '', array $team = [], array $addMembers = [], array $externals = []): DataResponse {
		return $this->respond(fn (IUser $user): DataResponse => new DataResponse($this->handover->apply(
			$this->requireHandoverProject($projectId, $user),
			['handoverDate' => $handoverDate, 'ownerUid' => $ownerUid, 'team' => $team, 'addMembers' => $addMembers, 'externals' => $externals],
			$user,
		)));
	}

	private function requireHandoverProject(int $projectId, IUser $user): Project {
		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSNotFoundException('Project not found');
		}
		$this->access->assertCanView($user->getUID(), $project);
		$isOwner = trim((string)$project->getOwnerId()) === $user->getUID();
		if (!$isOwner && !$this->access->canAdminister($user->getUID(), $project)) {
			throw new OCSForbiddenException('Only the project owner and organization administrators can hand a project over.');
		}
		return $project;
	}

	/** @param callable(IUser): DataResponse $action */
	private function respond(callable $action): DataResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new OCSForbiddenException('Authentication required');
			}
			return $action($user);
		} catch (OCSException $e) {
			$status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
			return new DataResponse(['message' => $e->getMessage()], $status);
		}
	}
}
