<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use DateTime;
use DateTimeZone;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Invites, lists and revokes external collaborators on a project. The
 * organization app stores them; this controller decides who may manage them.
 */
class ExternalApiController extends Controller {
	private const DEFAULT_GRANT_DAYS = 90;

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProjectMapper $projectMapper,
		private ProjectService $projectService,
		private ProjectAccessService $access,
		private ?ExternalCollaboratorService $externals = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function index(int $projectId): DataResponse {
		$project = $this->requireManageableProject($projectId);
		return new DataResponse(['externals' => $this->externals()->listForProject((int)$project->getId())]);
	}

	/**
	 * @param string[] $drascivsRoles
	 * @param string[] $functionalRoleKeys
	 */
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function invite(
		int $projectId,
		string $email = '',
		string $displayName = '',
		?string $company = null,
		?string $phone = null,
		?string $expiresAt = null,
		array $drascivsRoles = [],
		array $functionalRoleKeys = [],
	): DataResponse {
		$project = $this->requireManageableProject($projectId);
		$roles = $this->projectService->validateMemberRoles($project, $drascivsRoles, $functionalRoleKeys);

		$result = $this->externals()->invite(
			(int)$project->getOrganizationId(),
			(int)$project->getId(),
			$this->currentUserId(),
			$email,
			$displayName,
			$company,
			$phone,
			$this->resolveEndDate($project, $expiresAt),
			$roles['functionalRoleKeys'],
			$roles['drasciRoles'],
		);

		return new DataResponse([
			'external' => $result['external'],
			'grant' => $result['grant'],
			'activated' => $result['activated'],
			'emailSent' => $result['emailSent'],
			'inviteUrl' => $result['inviteUrl'],
		], 201);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function revoke(int $projectId, string $userId): DataResponse {
		$project = $this->requireManageableProject($projectId);
		$grant = $this->externals()->revoke(
			(int)$project->getOrganizationId(),
			(int)$project->getId(),
			$userId,
			$this->currentUserId(),
		);

		return new DataResponse(['grant' => $grant]);
	}

	/**
	 * Org admins of the project's organization and the project owner.
	 */
	private function requireManageableProject(int $projectId): Project {
		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSNotFoundException('Project not found');
		}

		$userId = $this->currentUserId();
		$this->access->assertCanView($userId, $project);

		$isOwner = trim((string)$project->getOwnerId()) === $userId;
		if (!$isOwner && !$this->access->canAdminister($userId, $project)) {
			throw new OCSForbiddenException('Only project owners and organization administrators can manage external collaborators.');
		}

		return $project;
	}

	/**
	 * A given date ends at the end of that day. Without one the grant runs until
	 * the planned handover, or for 90 days when no handover is planned.
	 */
	private function resolveEndDate(Project $project, ?string $expiresAt): DateTime {
		$utc = new DateTimeZone('UTC');
		$now = new DateTime('now', $utc);

		if ($expiresAt !== null && trim($expiresAt) !== '') {
			$date = DateTime::createFromFormat('!Y-m-d', substr(trim($expiresAt), 0, 10), $utc);
			if ($date === false) {
				throw new OCSBadRequestException('The end date must be a date like 2026-12-31.');
			}
			return $date->setTime(23, 59, 59);
		}

		$start = $project->getActualStartDate();
		$handover = $project->getActualHandoverDate();
		$planned = TimelinePlanningService::plannedHandoverDate(
			$start instanceof DateTime ? $start->format('Y-m-d') : null,
			$project->getExecutionWeeks(),
			$handover instanceof DateTime ? $handover->format('Y-m-d') : null,
		);
		if ($planned !== null) {
			$date = DateTime::createFromFormat('!Y-m-d', $planned, $utc);
			if ($date !== false && $date->setTime(23, 59, 59) > $now) {
				return $date;
			}
		}

		return $now->modify('+' . self::DEFAULT_GRANT_DAYS . ' days');
	}

	private function externals(): ExternalCollaboratorService {
		if ($this->externals === null) {
			throw new OCSException('External collaborators need the organization app.', 503);
		}
		return $this->externals;
	}

	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}
		return $user->getUID();
	}
}
