<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Service\ProjectPortfolioService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUserSession;

class PortfolioApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private OrganizationUserMapper $organizationUserMapper,
		private ProjectPortfolioService $portfolioService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function completion(): DataResponse {
		$organizationId = $this->requireOrganizationAdmin();

		return new DataResponse($this->portfolioService->getCompletion($organizationId));
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function capacity(): DataResponse {
		$organizationId = $this->requireOrganizationAdmin();
		$teamId = $this->request->getParam('teamId');
		if ((!is_int($teamId) && (!is_string($teamId) || !ctype_digit($teamId))) || (int)$teamId < 1) {
			throw new OCSBadRequestException('teamId is required');
		}

		$weekStart = $this->request->getParam('weekStart');
		if ($weekStart !== null && (!is_string($weekStart) || !ProjectPortfolioService::isIsoDate($weekStart))) {
			throw new OCSBadRequestException('weekStart must be YYYY-MM-DD');
		}

		try {
			return new DataResponse($this->portfolioService->getCapacity($organizationId, (int)$teamId, $weekStart));
		} catch (\InvalidArgumentException $e) {
			throw new OCSBadRequestException($e->getMessage());
		}
	}

	private function requireOrganizationAdmin(): int {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		$membership = $this->organizationUserMapper->getOrganizationMembership($user->getUID());
		if ($membership === null || ($membership['role'] ?? null) !== 'admin') {
			throw new OCSForbiddenException('Organization administrator access required');
		}

		return (int)$membership['organization_id'];
	}
}
