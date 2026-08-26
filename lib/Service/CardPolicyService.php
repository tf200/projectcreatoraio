<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasci;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasciMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverride;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverrideMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\IGroupManager;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;

class CardPolicyService {
	private const TYPE_COMBI = 0;
	private const POLICY_VERSION_DRASCI = 2;
	private const ACTIONS = ['view', 'move', 'sign', 'verify'];

	public function __construct(
		private readonly BoardPolicySettingMapper $settingMapper,
		private readonly BoardPolicyRoleMapper $roleMapper,
		private readonly BoardPolicyMembershipMapper $membershipMapper,
		private readonly BoardPolicyDefaultDrasciMapper $defaultDrasciMapper,
		private readonly BoardPolicyDefaultRoleMapper $defaultRoleMapper,
		private readonly CardPolicyMapper $cardPolicyMapper,
		private readonly CardPolicyOverrideMapper $cardPolicyOverrideMapper,
		private readonly CardPolicyRoleMapper $cardPolicyRoleMapper,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectMemberRoleMapper $projectMemberRoleMapper,
		private readonly IDBConnection $db,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
		private readonly ?object $cardMapper, // OCA\Deck\Db\CardMapper
		private readonly ?object $stackMapper, // OCA\Deck\Db\StackMapper
		private readonly ?object $organizationUserMapper = null, // OCA\Organization\Db\UserMapper
	) {
	}

	/**
	 * Delegated from OCA\Deck\Service\PermissionService::checkPermission
	 */
	public function checkPermission(object $mapper, $id, int $permission, ?string $userId): bool {
		$cardMapperClass = 'OCA\Deck\Db\CardMapper';
		if (!($mapper instanceof $cardMapperClass)) {
			return true;
		}

		if ($userId === null || $userId === '') {
			return true;
		}

		$cardId = (int)$id;
		$boardId = method_exists($mapper, 'findBoardId') ? $mapper->findBoardId($cardId) : null;
		if ($boardId === null) {
			return true;
		}

		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPermissionMode() !== 'card_policy') {
			return true;
		}

		try {
			$card = $this->cardMapper !== null ? $this->cardMapper->find($cardId) : null;
		} catch (\Throwable $e) {
			$card = null;
		}
		if ($card === null) {
			return true;
		}

		// Deck's ACL permission constants are indexes; read is zero.
		if ($permission === 0) {
			return $this->assertActionLogic($card, $boardId, 'view', $userId);
		}

		return true;
	}

	/**
	 * Assert if a card movement transition is allowed
	 */
	public function assertTransition(object $card, int $targetStackId, ?string $userId): void {
		if ($userId === null || $userId === '') {
			return;
		}

		$stackId = (int)$card->getStackId();
		$boardId = $this->getBoardIdFromCard($card);
		if ($boardId === null) {
			return;
		}

		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPermissionMode() !== 'card_policy') {
			return;
		}

		if ($this->isBypassUser($boardId, $userId)) {
			return;
		}

		$action = 'move';
		$approvedStackId = $settings->getApprovedStackId();
		$doneStackId = $settings->getDoneStackId();

		if ($targetStackId !== $stackId && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
			if ($doneStackId !== null && $targetStackId === $doneStackId) {
				$action = 'verify';
			} elseif ($approvedStackId !== null && $targetStackId === $approvedStackId) {
				$action = 'sign';
			}
		} elseif ($targetStackId !== $stackId) {
			if ($approvedStackId !== null && ($targetStackId === $approvedStackId || $stackId === $approvedStackId)) {
				$action = 'sign';
			} elseif ($doneStackId !== null && ($targetStackId === $doneStackId || $stackId === $doneStackId)) {
				$action = 'verify';
			}
		}

		if (!$this->assertActionLogic($card, $boardId, $action, $userId)) {
			throw new \OCA\Deck\NoPermissionException("Action '$action' not permitted on this card by card policy.");
		}
	}

	/**
	 * Assert a direct action on a card
	 */
	public function assertAction(object $card, string $action, ?string $userId): void {
		if ($userId === null || $userId === '') {
			return;
		}

		$boardId = $this->getBoardIdFromCard($card);
		if ($boardId === null) {
			return;
		}

		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPermissionMode() !== 'card_policy') {
			return;
		}

		if ($this->isBypassUser($boardId, $userId)) {
			return;
		}

		if (!$this->assertActionLogic($card, $boardId, $action, $userId)) {
			throw new \OCA\Deck\NoPermissionException("Action '$action' not permitted on this card by card policy.");
		}
	}

	public function usesStackCompletion(object $card): bool {
		$boardId = $this->getBoardIdFromCard($card);
		if ($boardId === null) {
			return false;
		}

		return $this->getBoardWorkflow($boardId)['completionByStack'];
	}

	public function isCombiProjectCard(object $card): bool {
		$boardId = $this->getBoardIdFromCard($card);
		if ($boardId === null) {
			return false;
		}

		$project = $this->projectMapper->findByBoardId($boardId);
		return $project !== null && $project->getType() === self::TYPE_COMBI;
	}

	/**
	 * @return array{canMove: bool, canSign: bool, canVerify: bool}
	 */
	public function getCapabilities(object $card, ?string $userId): array {
		$capabilities = [
			'canMove' => true,
			'canSign' => true,
			'canVerify' => true,
		];
		if ($userId === null || $userId === '') {
			return $capabilities;
		}

		$boardId = $this->getBoardIdFromCard($card);
		if ($boardId === null) {
			return $capabilities;
		}

		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPermissionMode() !== 'card_policy' || $this->isBypassUser($boardId, $userId)) {
			return $capabilities;
		}

		return [
			'canMove' => $this->isActionAllowed($card, $boardId, 'move', $userId, $settings),
			'canSign' => $this->isActionAllowed($card, $boardId, 'sign', $userId, $settings),
			'canVerify' => $this->isActionAllowed($card, $boardId, 'verify', $userId, $settings),
		];
	}

	/**
	 * @return array{completionByStack: bool, doneStackId: ?int}
	 */
	public function getBoardWorkflow(int $boardId): array {
		$project = $this->projectMapper->findByBoardId($boardId);
		$settings = $this->settingMapper->findByBoard($boardId);
		$doneStackId = $settings?->getDoneStackId();

		return [
			'completionByStack' => $project !== null
				&& $project->getType() === self::TYPE_COMBI
				&& $doneStackId !== null,
			'doneStackId' => $doneStackId,
		];
	}

	/**
	 * Filter card lists based on visibility/view permission
	 */
	public function filterVisibleCards(array $cards, ?string $userId): array {
		if ($userId === null || $userId === '') {
			return $cards;
		}

		$filtered = [];
		foreach ($cards as $card) {
			$boardId = $this->getBoardIdFromCard($card);
			if ($boardId === null) {
				$filtered[] = $card;
				continue;
			}

			if (!$this->assertActionLogic($card, $boardId, 'view', $userId)) {
				continue;
			}

			$filtered[] = $card;
		}

		return $filtered;
	}

	/**
	 * Main core assertion logic
	 */
	public function assertActionLogic(object $card, int $boardId, string $action, string $userId): bool {
		if ($this->isBypassUser($boardId, $userId)) {
			return true;
		}

		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPermissionMode() !== 'card_policy') {
			return true;
		}

		return $this->isActionAllowed($card, $boardId, $action, $userId, $settings);
	}

	private function isActionAllowed(object $card, int $boardId, string $action, string $userId, object $settings): bool {
		if ($settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
			return $this->assertV2ActionLogic($card, $boardId, $action, $userId);
		}

		$allowedRoleIds = [];
		$cardId = (int)$card->getId();
		$cardPolicy = $this->cardPolicyMapper->findByCard($cardId);
		if ($cardPolicy !== null) {
			$roles = $this->cardPolicyRoleMapper->findByPolicyAndAction($cardPolicy->getId(), $action);
			foreach ($roles as $role) {
				$allowedRoleIds[] = $role->getRoleId();
			}
		}

		if (empty($allowedRoleIds)) {
			$defaultRoles = $this->defaultRoleMapper->findByBoardAndAction($boardId, $action);
			foreach ($defaultRoles as $role) {
				$allowedRoleIds[] = $role->getRoleId();
			}
		}

		if (empty($allowedRoleIds)) {
			if ($action === 'view') {
				$allowedRoleIds = $this->getUnionRoleIdsForActions($boardId, ['move', 'sign', 'verify']);
				if (empty($allowedRoleIds)) {
					return true;
				}
			} else {
				return false;
			}
		}

		$memberships = $this->membershipMapper->findByRoles($allowedRoleIds);
		foreach ($memberships as $membership) {
			if ($membership->getParticipantType() === 'user') {
				if ($membership->getParticipantId() === $userId) {
					return true;
				}
			} elseif ($membership->getParticipantType() === 'group') {
				if ($this->groupManager->isInGroup($userId, $membership->getParticipantId())) {
					return true;
				}
			}
		}

		return false;
	}

	private function assertV2ActionLogic(object $card, int $boardId, string $action, string $userId): bool {
		if (!in_array($action, self::ACTIONS, true)) {
			return false;
		}

		$cardId = (int)$card->getId();
		$cardPolicy = $this->cardPolicyMapper->findByCard($cardId);
		if ($cardPolicy !== null && (int)$cardPolicy->getBoardId() === $boardId) {
			$override = $this->cardPolicyOverrideMapper->findByPolicyAndAction((int)$cardPolicy->getId(), $action);
			if ($override !== null) {
				$allowedRoleIds = [];
				$boardRoleIds = [];
				foreach ($this->roleMapper->findByBoard($boardId) as $role) {
					$boardRoleIds[(int)$role->getId()] = true;
				}
				foreach ($this->cardPolicyRoleMapper->findByPolicyAndAction((int)$cardPolicy->getId(), $action) as $role) {
					$roleId = (int)$role->getRoleId();
					if (isset($boardRoleIds[$roleId])) {
						$allowedRoleIds[] = $roleId;
					}
				}

				return $this->userHasFunctionalRole($userId, $allowedRoleIds);
			}
		}

		$project = $this->projectMapper->findByBoardId($boardId);
		if ($project === null) {
			return false;
		}

		$memberRoles = $this->projectMemberRoleMapper->findByProjectAndUser((int)$project->getId(), $userId);
		if ($memberRoles === []) {
			return false;
		}

		$allowedDrasciRoles = array_map(
			static fn ($default): string => (string)$default->getDrasciRole(),
			$this->defaultDrasciMapper->findByBoardAndAction($boardId, $action),
		);

		foreach ($memberRoles as $memberRole) {
			if (in_array((string)$memberRole->getDrasciRole(), $allowedDrasciRoles, true)) {
				return true;
			}
		}

		return false;
	}

	/** @param int[] $roleIds */
	private function userHasFunctionalRole(string $userId, array $roleIds): bool {
		if ($roleIds === []) {
			return false;
		}

		foreach ($this->membershipMapper->findByRoles(array_values(array_unique($roleIds))) as $membership) {
			if ($membership->getParticipantType() === 'user' && $membership->getParticipantId() === $userId) {
				return true;
			}
			if ($membership->getParticipantType() === 'group'
				&& $this->groupManager->isInGroup($userId, $membership->getParticipantId())) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if user bypasses all checks
	 */
	public function isBypassUser(int $boardId, string $userId): bool {
		if ($this->groupManager->isAdmin($userId)) {
			return true;
		}

		$boardMapperClass = 'OCA\Deck\Db\BoardMapper';
		try {
			$boardMapper = Server::get($boardMapperClass);
			$board = $boardMapper->find($boardId);
			if ($board !== null && $board->getOwner() === $userId) {
				return true;
			}
		} catch (\Throwable $e) {
			// ignore
		}

		$project = $this->projectMapper->findByBoardId($boardId);
		if ($project !== null && $project->getOwnerId() === $userId) {
			return true;
		}

		if ($this->organizationUserMapper !== null) {
			try {
				$membership = $this->organizationUserMapper->getOrganizationMembership($userId);
				if ($project !== null
					&& $membership !== null
					&& (int)($membership['organization_id'] ?? 0) === (int)$project->getOrganizationId()
					&& ($membership['role'] ?? '') === 'admin') {
					return true;
				}
			} catch (\Throwable $e) {
				// ignore
			}
		}

		return false;
	}

	private function getUnionRoleIdsForActions(int $boardId, array $actions): array {
		$roleIds = [];
		foreach ($actions as $action) {
			$defaultRoles = $this->defaultRoleMapper->findByBoardAndAction($boardId, $action);
			foreach ($defaultRoles as $role) {
				$roleIds[] = $role->getRoleId();
			}
		}
		return array_values(array_unique($roleIds));
	}

	private function getBoardIdFromCard(object $card): ?int {
		if (method_exists($card, 'getRelatedBoard')) {
			$board = $card->getRelatedBoard();
			if ($board !== null) {
				return (int)$board->getId();
			}
		}

		$stackId = (int)$card->getStackId();
		if ($stackId > 0 && $this->stackMapper !== null) {
			try {
				$stack = $this->stackMapper->find($stackId);
				if ($stack !== null) {
					return (int)$stack->getBoardId();
				}
			} catch (\Throwable $e) {
				// ignore
			}
		}

		return null;
	}

	/**
	 * Seed default policies when a new board/project is created.
	 *
	 * @param array<string, object> $seededCards
	 */
	public function seedDefaultPolicies(int $boardId, array $stacks, \OCP\IUser $owner, array $seededCards): void {
		$this->db->beginTransaction();
		try {
			$this->seedDefaultPoliciesTransaction($boardId, $stacks, $owner, $seededCards);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** @param array<string, object> $seededCards */
	private function seedDefaultPoliciesTransaction(int $boardId, array $stacks, \OCP\IUser $owner, array $seededCards): void {
		// 1. Create BoardPolicySetting
		$settings = new \OCA\ProjectCreatorAIO\Db\BoardPolicySetting();
		$settings->setBoardId($boardId);
		$settings->setPermissionMode('card_policy');
		$settings->setPolicyVersion(self::POLICY_VERSION_DRASCI);
		$settings->setRevision(1);

		// Stack identity is persisted by ID; titles are only used during initial setup.
		foreach ($stacks as $stack) {
			$title = strtolower($stack->getTitle());
			if ($title === 'approved') {
				$settings->setApprovedStackId((int)$stack->getId());
			} elseif ($stack->getIsDoneColumn() || ($settings->getDoneStackId() === null && $title === 'done')) {
				$settings->setDoneStackId((int)$stack->getId());
			}
		}
		$this->settingMapper->insert($settings);

		// 2. Create the three default roles
		$roles = [
			'cpl' => 'CPL',
			'client_developer' => 'Client/Developer',
			'grid_operator' => 'Grid operator (Elektra)',
		];
		$createdRoles = [];
		foreach ($roles as $key => $name) {
			$role = new \OCA\ProjectCreatorAIO\Db\BoardPolicyRole();
			$role->setBoardId($boardId);
			$role->setRoleKey($key);
			$role->setRoleName($name);
			$createdRoles[$key] = $this->roleMapper->insert($role);
		}

		// 3. Set DRASCIVS default permissions.
		$defaultMappings = [
			'view' => ['driver', 'responsible', 'accountable', 'supportive', 'consulted', 'informed', 'verifier', 'signer'],
			'move' => ['driver', 'responsible', 'supportive'],
			'sign' => ['signer'],
			'verify' => ['verifier'],
		];
		foreach ($defaultMappings as $action => $roleKeys) {
			foreach ($roleKeys as $roleKey) {
				$default = new BoardPolicyDefaultDrasci();
				$default->setBoardId($boardId);
				$default->setAction($action);
				$default->setDrasciRole($roleKey);
				$this->defaultDrasciMapper->insert($default);
			}
		}

		// 4. Map project owner to CPL role
		$this->addMembership((int)$createdRoles['cpl']->getId(), 'user', $owner->getUID());

		// 5. Seed card-specific functional-role overrides.
		$this->seedDefaultCardPolicies($boardId, $createdRoles, $seededCards);
	}

	/** @param string[] $drasciRoles */
	public function syncLegacyProjectMemberRole(int $boardId, string $userId, array $drasciRoles): void {
		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
			return;
		}

		$roles = [];
		foreach ($this->roleMapper->findByBoard($boardId) as $role) {
			if (in_array($role->getRoleKey(), ['cpl', 'client_developer'], true)) {
				$roles[$role->getRoleKey()] = $role;
			}
		}

		foreach ($roles as $role) {
			$membership = $this->membershipMapper->findUnique((int)$role->getId(), 'user', $userId);
			if ($membership !== null) {
				$this->membershipMapper->delete($membership);
			}
		}

		$roleKeys = [];
		foreach ($drasciRoles as $drasciRole) {
			$roleKeys[in_array($drasciRole, ['driver', 'responsible', 'accountable', 'verifier', 'signer'], true)
				? 'cpl'
				: 'client_developer'] = true;
		}
		foreach (array_keys($roleKeys) as $roleKey) {
			if (isset($roles[$roleKey])) {
				$this->addMembership((int)$roles[$roleKey]->getId(), 'user', $userId);
			}
		}
	}

	public function preserveLegacyCardPolicyOverrides(int $boardId): void {
		foreach ($this->cardPolicyMapper->findByBoard($boardId) as $cardPolicy) {
			$policyId = (int)$cardPolicy->getId();
			$actions = [];
			foreach ($this->cardPolicyRoleMapper->findByPolicy($policyId) as $relation) {
				$action = (string)$relation->getAction();
				if (in_array($action, self::ACTIONS, true)) {
					$actions[$action] = true;
				}
			}

			foreach (array_keys($actions) as $action) {
				if ($this->cardPolicyOverrideMapper->findByPolicyAndAction($policyId, $action) !== null) {
					continue;
				}

				$override = new CardPolicyOverride();
				$override->setCardPolicyId($policyId);
				$override->setAction($action);
				$this->cardPolicyOverrideMapper->insert($override);
			}
		}
	}

	/** @param array<string, object> $seededCards */
	private function seedDefaultCardPolicies(int $boardId, array $createdRoles, array $seededCards): void {
		$matrix = [
			'combi.guarantee_agreement' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.vo' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.do' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.schedule_intake' => [
				'move' => ['cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.intake_report' => [
				'move' => ['cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.house_number_decision' => [
				'move' => ['client_developer', 'cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.schedule_high_rise_consultation' => [
				'move' => ['cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.vo_internal_drawings' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'combi.do_internal_drawings' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'combi.internal_consultation_report' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'combi.block_diagram' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'combi.private_land_application' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.soil_report' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.remediation_evaluation_report' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.property_right' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.peak_power_form' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.situation_drawing' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'combi.intake_form' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.quickscan' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'combi.avp' => [
				'move' => ['grid_operator'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
		];

		foreach ($seededCards as $templateKey => $card) {
			if (isset($matrix[$templateKey])) {
				$policy = new \OCA\ProjectCreatorAIO\Db\CardPolicy();
				$policy->setCardId((int)$card->getId());
				$policy->setBoardId($boardId);
				$insertedPolicy = $this->cardPolicyMapper->insert($policy);

				foreach ($matrix[$templateKey] as $action => $roleKeys) {
					$override = new CardPolicyOverride();
					$override->setCardPolicyId((int)$insertedPolicy->getId());
					$override->setAction($action);
					$this->cardPolicyOverrideMapper->insert($override);

					foreach ($roleKeys as $roleKey) {
						if (isset($createdRoles[$roleKey])) {
							$cardRole = new \OCA\ProjectCreatorAIO\Db\CardPolicyRole();
							$cardRole->setCardPolicyId((int)$insertedPolicy->getId());
							$cardRole->setAction($action);
							$cardRole->setRoleId((int)$createdRoles[$roleKey]->getId());
							$this->cardPolicyRoleMapper->insert($cardRole);
						}
					}
				}
			}
		}
	}

	private function addMembership(int $roleId, string $type, string $id): void {
		$membership = new \OCA\ProjectCreatorAIO\Db\BoardPolicyMembership();
		$membership->setRoleId($roleId);
		$membership->setParticipantType($type);
		$membership->setParticipantId($id);
		try {
			$this->membershipMapper->insert($membership);
		} catch (\Throwable $e) {
			// ignore duplicate mapping
		}
	}
}
