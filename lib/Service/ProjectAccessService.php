<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\Organization\Service\ExternalCollaboratorService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;

/**
 * Single source of truth for "who may see or manage which project".
 *
 * Every access decision that depends on organization membership goes through here,
 * so that new kinds of access (e.g. project-scoped external collaborators) only need
 * to be taught in one place.
 */
class ProjectAccessService {
	public function __construct(
		private ProjectMapper $projectMapper,
		private IGroupManager $groupManager,
		private ?OrganizationUserMapper $organizationUserMapper = null,
		private ?ExternalCollaboratorService $externals = null,
	) {
	}

	/**
	 * Projects an external collaborator may open right now, keyed by project ID
	 * with the granting organization as value.
	 *
	 * @return array<int,int>
	 */
	public function getExternalProjectGrants(string $userId): array {
		if ($this->externals === null) {
			return [];
		}

		$grants = [];
		foreach ($this->externals->getUsableGrants($userId) as $grant) {
			$grants[$grant->getProjectId()] = $grant->getOrganizationId();
		}
		return $grants;
	}

	public function hasExternalGrant(string $userId, Project $project): bool {
		$grants = $this->getExternalProjectGrants($userId);
		$projectId = (int)$project->getId();
		return isset($grants[$projectId]) && $grants[$projectId] === (int)$project->getOrganizationId();
	}

	public function hasOrganizations(): bool {
		return $this->organizationUserMapper !== null;
	}

	public function isGlobalAdmin(string $userId): bool {
		return $this->groupManager->isAdmin($userId);
	}

	/**
	 * @return array{organization_id:int,role:string}|null
	 */
	public function getOrganizationMembership(string $userId): ?array {
		if ($this->organizationUserMapper === null) {
			return null;
		}

		$membership = $this->organizationUserMapper->getOrganizationMembership($userId);
		if ($membership === null) {
			return null;
		}

		return [
			'organization_id' => (int)($membership['organization_id'] ?? 0),
			'role' => (string)($membership['role'] ?? ''),
		];
	}

	public function belongsToOrganization(string $userId, int $organizationId): bool {
		$membership = $this->getOrganizationMembership($userId);
		return $membership !== null && $membership['organization_id'] === $organizationId;
	}

	public function isOrganizationAdmin(string $userId, int $organizationId): bool {
		$membership = $this->getOrganizationMembership($userId);
		return $membership !== null
			&& $membership['organization_id'] === $organizationId
			&& $membership['role'] === 'admin';
	}

	/**
	 * Whether the user may hold a seat in the project. Without the organization app
	 * anyone may; otherwise members of the project's organization and external
	 * collaborators with a grant on the project.
	 */
	public function isEligibleProjectMember(string $userId, Project $project): bool {
		if ($this->organizationUserMapper === null) {
			return true;
		}

		return $this->belongsToOrganization($userId, (int)$project->getOrganizationId())
			|| $this->hasExternalGrant($userId, $project);
	}

	public function isProjectGroupMember(string $userId, Project $project): bool {
		$groupGid = trim((string)$project->getProjectGroupGid());
		return $groupGid !== '' && $this->groupManager->isInGroup($userId, $groupGid);
	}

	/**
	 * Global admin, or organization admin of the project's organization.
	 */
	public function canAdminister(string $userId, Project $project): bool {
		if ($this->isGlobalAdmin($userId)) {
			return true;
		}

		return $this->isOrganizationAdmin($userId, (int)$project->getOrganizationId());
	}

	public function canView(string $userId, Project $project): bool {
		try {
			$this->assertCanView($userId, $project);
			return true;
		} catch (OCSForbiddenException|OCSNotFoundException) {
			return false;
		}
	}

	/**
	 * Global admin; organization admin of the project's organization; a member of
	 * the project's organization who is in the project group; or an external
	 * collaborator with an active grant on the project.
	 *
	 * @throws OCSForbiddenException when the user has no organization
	 * @throws OCSNotFoundException when the project is hidden from the user
	 */
	public function assertCanView(string $userId, Project $project): void {
		if ($this->isGlobalAdmin($userId)) {
			return;
		}

		if ($this->organizationUserMapper === null) {
			if (!$this->isProjectGroupMember($userId, $project)) {
				throw new OCSNotFoundException('Project not found');
			}
			return;
		}

		$membership = $this->getOrganizationMembership($userId);
		if ($membership === null) {
			$externalGrants = $this->getExternalProjectGrants($userId);
			if ($externalGrants === []) {
				throw new OCSForbiddenException('You are not assigned to an organization');
			}
			if (($externalGrants[(int)$project->getId()] ?? null) !== (int)$project->getOrganizationId()) {
				throw new OCSNotFoundException('Project not found');
			}
			return;
		}

		if ($membership['organization_id'] !== (int)$project->getOrganizationId()) {
			throw new OCSNotFoundException('Project not found');
		}

		if ($membership['role'] === 'admin') {
			return;
		}

		if (!$this->isProjectGroupMember($userId, $project)) {
			throw new OCSNotFoundException('Project not found');
		}
	}

	/**
	 * Global admin, or organization admin of the given organization.
	 *
	 * @throws OCSForbiddenException
	 * @throws OCSNotFoundException when the user belongs to another organization
	 */
	public function assertCanManageOrganization(string $userId, int $organizationId, string $adminRequiredMessage): void {
		if ($this->isGlobalAdmin($userId)) {
			return;
		}

		if ($this->organizationUserMapper === null) {
			throw new OCSForbiddenException('Organization management is not available');
		}

		$membership = $this->getOrganizationMembership($userId);
		if ($membership === null) {
			throw new OCSForbiddenException('You are not assigned to an organization');
		}

		if ($membership['organization_id'] !== $organizationId) {
			throw new OCSNotFoundException('Organization not found');
		}

		if ($membership['role'] !== 'admin') {
			throw new OCSForbiddenException($adminRequiredMessage);
		}
	}

	/**
	 * Projects the user can open: all projects for a global admin, all organization
	 * projects for an organization admin, the granted projects for an external
	 * collaborator, otherwise the projects they are a member of.
	 *
	 * @return Project[]
	 * @throws OCSForbiddenException when the user has no organization and no grant
	 */
	public function getAccessibleProjects(string $userId): array {
		if ($this->isGlobalAdmin($userId)) {
			return $this->projectMapper->list();
		}

		$membership = $this->getOrganizationMembership($userId);
		if ($membership === null) {
			$externalGrants = $this->getExternalProjectGrants($userId);
			if ($externalGrants === []) {
				throw new OCSForbiddenException('You are not assigned to an organization');
			}

			$projects = [];
			foreach ($externalGrants as $projectId => $organizationId) {
				$project = $this->projectMapper->find($projectId);
				if ($project !== null && (int)$project->getOrganizationId() === $organizationId) {
					$projects[] = $project;
				}
			}
			return $projects;
		}

		if ($membership['role'] === 'admin') {
			return $this->projectMapper->findByOrganizationId($membership['organization_id']);
		}

		return $this->projectMapper->findByUserIdAndOrganizationId($userId, $membership['organization_id']);
	}
}
