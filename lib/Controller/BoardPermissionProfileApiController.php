<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Db\BoardPermissionProfile;
use OCA\ProjectCreatorAIO\Db\BoardPermissionProfileMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\BoardPermissionProfileService;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class BoardPermissionProfileApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BoardPermissionProfileMapper $profileMapper,
		private readonly BoardPermissionProfileService $service,
		private readonly ProjectMapper $projectMapper,
		private readonly CardPolicyService $policyService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly ?OrganizationUserMapper $organizationUserMapper = null,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function listProfiles(int $boardId): JSONResponse {
		return $this->run(function () use ($boardId): array {
			[$organizationId, $uid, $organizationAdmin] = $this->context($boardId);
			$canApply = $this->policyService->isBypassUser($boardId, $uid);
			return array_map(
				fn(BoardPermissionProfile $profile): array => $this->serializeProfile($profile, $canApply, $organizationAdmin, $uid),
				$this->profileMapper->findByOrganization($organizationId),
			);
		});
	}

	#[NoAdminRequired]
	public function createProfile(int $boardId, string $name): JSONResponse {
		return $this->run(function () use ($boardId, $name): array {
			[$organizationId, $uid, $organizationAdmin] = $this->context($boardId);
			$this->assertManager($boardId, $uid);
			$profile = $this->service->snapshot($organizationId, $uid, $name, $boardId);
			return $this->serializeProfile($profile, true, $organizationAdmin, $uid);
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	public function getProfile(int $boardId, int $profileId): JSONResponse {
		return $this->run(function () use ($boardId, $profileId): array {
			[$organizationId, $uid, $organizationAdmin] = $this->context($boardId);
			$profile = $this->profile($organizationId, $profileId);
			return $this->serializeProfile($profile, $this->policyService->isBypassUser($boardId, $uid), $organizationAdmin, $uid);
		});
	}

	#[NoAdminRequired]
	public function previewProfile(int $boardId, int $profileId): JSONResponse {
		return $this->run(function () use ($boardId, $profileId): array {
			[$organizationId, $uid] = $this->context($boardId);
			$this->assertManager($boardId, $uid);
			return $this->service->preview($this->profile($organizationId, $profileId), $boardId);
		});
	}

	#[NoAdminRequired]
	public function applyProfile(
		int $boardId,
		int $profileId,
		array $resolutions = [],
		?string $expectedPreviewToken = null,
	): JSONResponse {
		return $this->run(function () use ($boardId, $profileId, $resolutions, $expectedPreviewToken): array {
			[$organizationId, $uid] = $this->context($boardId);
			$this->assertManager($boardId, $uid);
			return $this->service->apply(
				$this->profile($organizationId, $profileId),
				$boardId,
				$uid,
				$resolutions,
				$expectedPreviewToken,
			);
		});
	}

	#[NoAdminRequired]
	public function deleteProfile(int $boardId, int $profileId): JSONResponse {
		return $this->run(function () use ($boardId, $profileId): array {
			[$organizationId, $uid, $organizationAdmin] = $this->context($boardId);
			$profile = $this->profile($organizationId, $profileId);
			if ($profile->getCreatorUid() !== $uid && !$organizationAdmin) {
				throw new \RuntimeException('FORBIDDEN: Only the creator or an organization administrator may delete this profile.');
			}
			$this->service->deleteMappings((int)$profile->getId());
			$this->profileMapper->delete($profile);
			return ['success' => true];
		});
	}

	private function context(int $boardId): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('FORBIDDEN: Authentication required.');
		}
		$project = $this->projectMapper->findByBoardId($boardId);
		$organizationId = $project?->getOrganizationId();
		if ($organizationId === null) {
			throw new \RuntimeException('NOT_FOUND: Board is not associated with an organization.');
		}
		$uid = $user->getUID();
		if ($this->groupManager->isAdmin($uid)) {
			return [(int)$organizationId, $uid, true];
		}
		$membership = $this->organizationUserMapper?->getOrganizationMembership($uid);
		if ($membership === null || (int)($membership['organization_id'] ?? 0) !== (int)$organizationId) {
			throw new \RuntimeException('NOT_FOUND: Board not found.');
		}
		return [(int)$organizationId, $uid, ($membership['role'] ?? null) === 'admin'];
	}

	private function assertManager(int $boardId, string $uid): void {
		if (!$this->policyService->isBypassUser($boardId, $uid)) {
			throw new \RuntimeException('FORBIDDEN: Board policy management permission is required.');
		}
	}

	private function profile(int $organizationId, int $profileId): BoardPermissionProfile {
		$profile = $this->profileMapper->find($profileId);
		if ($profile === null || (int)$profile->getOrganizationId() !== $organizationId) {
			throw new \RuntimeException('NOT_FOUND: Profile not found.');
		}
		return $profile;
	}

	private function serializeProfile(BoardPermissionProfile $profile, bool $canApply, bool $organizationAdmin, string $uid): array {
		$data = $profile->jsonSerialize();
		$data['createdBy'] = $profile->getCreatorUid();
		$data['canApply'] = $canApply;
		$data['canDelete'] = $organizationAdmin || $profile->getCreatorUid() === $uid;
		return $data;
	}

	private function run(callable $callback, int $status = Http::STATUS_OK): JSONResponse {
		try {
			return new JSONResponse($callback(), $status);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\DomainException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		} catch (\Throwable $e) {
			$status = Http::STATUS_INTERNAL_SERVER_ERROR;
			if (str_starts_with($e->getMessage(), 'FORBIDDEN:')) {
				$status = Http::STATUS_FORBIDDEN;
			} elseif (str_starts_with($e->getMessage(), 'NOT_FOUND:')) {
				$status = Http::STATUS_NOT_FOUND;
			}
			return new JSONResponse([
				'error' => preg_replace('/^[A-Z_]+:\s*/', '', $e->getMessage()),
			], $status);
		}
	}
}
