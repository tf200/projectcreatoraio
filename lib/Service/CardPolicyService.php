<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;

class CardPolicyService {
	public function __construct(
		private readonly BoardPolicySettingMapper $settingMapper,
		private readonly BoardPolicyRoleMapper $roleMapper,
		private readonly BoardPolicyMembershipMapper $membershipMapper,
		private readonly BoardPolicyDefaultRoleMapper $defaultRoleMapper,
		private readonly CardPolicyMapper $cardPolicyMapper,
		private readonly CardPolicyRoleMapper $cardPolicyRoleMapper,
		private readonly ProjectMapper $projectMapper,
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

		// Acl::PERMISSION_READ = 1
		if ($permission === 1) {
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

		if ($targetStackId !== $stackId) {
			if (($approvedStackId !== null && ($targetStackId === $approvedStackId || $stackId === $approvedStackId))) {
				$action = 'sign';
			} elseif (($doneStackId !== null && ($targetStackId === $doneStackId || $stackId === $doneStackId))) {
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
				if ($membership !== null && ($membership['role'] ?? '') === 'admin') {
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
	 * Seed default policies when a new board/project is created
	 */
	public function seedDefaultPolicies(int $boardId, array $stacks, \OCP\IUser $owner, array $membersWithRoles): void {
		// 1. Create BoardPolicySetting
		$settings = new \OCA\ProjectCreatorAIO\Db\BoardPolicySetting();
		$settings->setBoardId($boardId);
		$settings->setPermissionMode('card_policy');

		// Find stack IDs for Approved and Done
		foreach ($stacks as $stack) {
			$title = strtolower($stack->getTitle());
			if ($title === 'approved') {
				$settings->setApprovedStackId((int)$stack->getId());
			} elseif ($title === 'done') {
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

		// 3. Set default permissions
		$defaultMappings = [
			'move' => ['client_developer', 'cpl', 'grid_operator'],
			'sign' => ['cpl', 'grid_operator'],
			'verify' => ['cpl', 'grid_operator'],
			'view' => ['client_developer', 'cpl', 'grid_operator'],
		];
		foreach ($defaultMappings as $action => $roleKeys) {
			foreach ($roleKeys as $key) {
				if (isset($createdRoles[$key])) {
					$defaultRole = new \OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRole();
					$defaultRole->setBoardId($boardId);
					$defaultRole->setAction($action);
					$defaultRole->setRoleId($createdRoles[$key]->getId());
					$this->defaultRoleMapper->insert($defaultRole);
				}
			}
		}

		// 4. Map project owner to CPL role
		$this->addMembership((int)$createdRoles['cpl']->getId(), 'user', $owner->getUID());

		// 5. Map project members to board roles based on DRASCI role
		foreach ($membersWithRoles as $userId => $drasciRole) {
			$targetRoleKey = 'client_developer';
			if (in_array($drasciRole, ['driver', 'accountable', 'responsible'], true)) {
				$targetRoleKey = 'cpl';
			}

			if (isset($createdRoles[$targetRoleKey])) {
				$this->addMembership((int)$createdRoles[$targetRoleKey]->getId(), 'user', $userId);
			}
		}

		// 6. Seed card-specific default policies
		$this->seedDefaultCardPolicies($boardId, $createdRoles);
	}

	/**
	 * Sync a project member's role dynamically into board policy roles
	 */
	public function syncProjectMemberRole(int $boardId, string $userId, ?string $drasciRole): void {
		$roles = $this->roleMapper->findByBoard($boardId);
		$createdRoles = [];
		foreach ($roles as $role) {
			$createdRoles[$role->getRoleKey()] = $role;
		}

		// Remove user from all existing board policy memberships on this board
		foreach ($createdRoles as $role) {
			$existing = $this->membershipMapper->findUnique((int)$role->getId(), 'user', $userId);
			if ($existing !== null) {
				$this->membershipMapper->delete($existing);
			}
		}

		if ($drasciRole === null || $drasciRole === '') {
			return;
		}

		// Map DRASCI role to board policy role key
		$targetRoleKey = 'client_developer';
		if (in_array($drasciRole, ['driver', 'accountable', 'responsible'], true)) {
			$targetRoleKey = 'cpl';
		}

		if (isset($createdRoles[$targetRoleKey])) {
			$this->addMembership((int)$createdRoles[$targetRoleKey]->getId(), 'user', $userId);
		}
	}

	private function seedDefaultCardPolicies(int $boardId, array $createdRoles): void {
		$matrix = [
			'Garantie overeenkomst' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'VO' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'DO' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Intake inplannen & hosten' => [
				'move' => ['cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Intakeverslag' => [
				'move' => ['cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Huisnummerbesluit' => [
				'move' => ['client_developer', 'cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Hoogbouwoverleg inplannen' => [
				'move' => ['cpl'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'VO inpandige tekeningen' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'DO inpandige tekeningen' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'Verslag inpandig overleg' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'Blokkenschema' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'Aanvraag particuliere grond' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Bodemrapport' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Saneringsevaluatierapport' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Zakelijkrecht' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Piekvermogensformulier' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Situatie tekening' => [
				'move' => ['client_developer'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
			'Intakeformulier' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'Quickscan' => [
				'move' => ['client_developer'],
				'sign' => ['cpl'],
				'verify' => ['cpl'],
			],
			'AVP' => [
				'move' => ['grid_operator'],
				'sign' => ['grid_operator'],
				'verify' => ['grid_operator'],
			],
		];

		try {
			$db = Server::get(\OCP\IDBConnection::class);
			$qb = $db->getQueryBuilder();
			$qb->select('c.id', 'c.title')
				->from('deck_cards', 'c')
				->innerJoin('c', 'deck_stacks', 's', 's.id = c.stack_id')
				->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
				->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));

			$result = $qb->executeQuery();
			try {
				$cards = $result->fetchAll();
			} finally {
				$result->closeCursor();
			}

			foreach ($cards as $card) {
				$title = trim((string)$card['title']);
				if (isset($matrix[$title])) {
					$policy = new \OCA\ProjectCreatorAIO\Db\CardPolicy();
					$policy->setCardId((int)$card['id']);
					$policy->setBoardId($boardId);
					$insertedPolicy = $this->cardPolicyMapper->insert($policy);

					$actions = $matrix[$title];
					$actions['view'] = ['client_developer', 'cpl', 'grid_operator'];

					foreach ($actions as $action => $roleKeys) {
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
		} catch (\Throwable $e) {
			// ignore and log
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
