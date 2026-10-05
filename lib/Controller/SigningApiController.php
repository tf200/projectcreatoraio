<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectSigningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

class SigningApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectSigningService $signingService,
		private readonly ProjectAccessService $access,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function listProjectRequests(int $projectId): DataResponse {
		$project = $this->requireProject($projectId);
		$this->assertCanAccessProject($project);
		return new DataResponse(['requests' => $this->signingService->listForProject($projectId)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getFileRequest(int $projectId, int $fileId): DataResponse {
		$project = $this->requireProject($projectId);
		$this->assertCanAccessProject($project);
		return new DataResponse(['request' => $this->signingService->getLatestForFile($projectId, $fileId)]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function createFileRequest(int $projectId, int $fileId): DataResponse {
		$project = $this->requireProject($projectId);
		$userId = $this->assertCanAccessProject($project);

		$params = $this->request->getParams();
		$flow = is_string($params['signature_flow'] ?? null) ? $params['signature_flow'] : 'parallel';
		$signers = is_array($params['signers'] ?? null) ? $params['signers'] : [];
		$placements = is_array($params['placements'] ?? null) ? $params['placements'] : [];
		$record = $this->signingService->createRequest($project, $fileId, $flow, $signers, $userId, $placements);

		return new DataResponse(['request' => $record], 201);
	}

	private function requireProject(int $projectId): Project {
		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSNotFoundException('Project not found');
		}
		return $project;
	}

	private function assertCanAccessProject(Project $project): string {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		$userId = $currentUser->getUID();
		$this->access->assertCanView($userId, $project);

		return $userId;
	}
}
