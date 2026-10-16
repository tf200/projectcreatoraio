<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectOwnershipService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Hands a project to another owner. The current owner and the organization's
 * administrators may do so.
 */
class ProjectOwnerApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProjectMapper $projectMapper,
		private ProjectAccessService $access,
		private ProjectOwnershipService $ownership,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function update(int $projectId, string $userId = ''): DataResponse {
		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				throw new OCSForbiddenException('Authentication required');
			}
			$project = $this->projectMapper->find($projectId);
			if ($project === null) {
				throw new OCSNotFoundException('Project not found');
			}
			$this->access->assertCanView($user->getUID(), $project);
			$isOwner = trim((string)$project->getOwnerId()) === $user->getUID();
			if (!$isOwner && !$this->access->canAdminister($user->getUID(), $project)) {
				throw new OCSForbiddenException('Only the project owner and organization administrators can change the owner.');
			}

			return new DataResponse($this->ownership->transfer($project, $userId));
		} catch (OCSException $e) {
			$status = $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 500;
			return new DataResponse(['message' => $e->getMessage()], $status);
		}
	}
}
