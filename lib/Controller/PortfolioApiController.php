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
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		$membership = $this->organizationUserMapper->getOrganizationMembership($user->getUID());
		if ($membership === null || ($membership['role'] ?? null) !== 'admin') {
			throw new OCSForbiddenException('Organization administrator access required');
		}

		return new DataResponse(
			$this->portfolioService->getCompletion((int)$membership['organization_id']),
		);
	}
}
