<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\Db\BoardPolicySetting;
use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasci;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasciMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicy;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverride;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverrideMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRole;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PolicyApiController extends Controller {
	private const POLICY_VERSION_DRASCI = 2;
	private const ACTIONS = ['view', 'move', 'sign', 'verify'];
	private const DRASCI_ROLES = [
		'driver' => 'Driver',
		'responsible' => 'Responsible',
		'accountable' => 'Accountable',
		'supportive' => 'Supportive',
		'consulted' => 'Consulted',
		'informed' => 'Informed',
		'verifier' => 'Verifier',
		'signer' => 'Signer',
	];
	private const STANDARD_FUNCTIONAL_ROLES = [
		'cpl' => 'CPL',
		'client_developer' => 'Client/Developer',
		'grid_operator' => 'Grid operator (Elektra)',
	];
	private const DEFAULT_DRASCI = [
		'view' => ['driver', 'responsible', 'accountable', 'supportive', 'consulted', 'informed', 'verifier', 'signer'],
		'move' => ['driver', 'responsible', 'supportive'],
		'sign' => ['signer'],
		'verify' => ['verifier'],
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BoardPolicySettingMapper $settingMapper,
		private readonly BoardPolicyRoleMapper $roleMapper,
		private readonly BoardPolicyMembershipMapper $membershipMapper,
		private readonly BoardPolicyDefaultDrasciMapper $defaultDrasciMapper,
		private readonly BoardPolicyDefaultRoleMapper $defaultRoleMapper,
		private readonly CardPolicyMapper $cardPolicyMapper,
		private readonly CardPolicyOverrideMapper $cardPolicyOverrideMapper,
		private readonly CardPolicyRoleMapper $cardPolicyRoleMapper,
		private readonly CardPolicyService $policyService,
		private readonly IUserSession $userSession,
		private readonly IDBConnection $db,
		private readonly ?object $cardMapper = null, // OCA\Deck\Db\CardMapper
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function getBoardPolicy(int $boardId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$settings = $this->settingMapper->findByBoard($boardId);
			$roles = $this->roleMapper->findByBoard($boardId);
			$memberships = $this->serializeMemberships($roles);

			if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
				return new JSONResponse($this->buildV2PolicyResponse($boardId, $settings, $roles, $memberships));
			}

			return new JSONResponse($this->buildV1PolicyResponse($boardId, $settings, $roles, $memberships));
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	/** @param BoardPolicyRole[] $roles */
	private function serializeMemberships(array $roles): array {
		$data = [];
		foreach ($roles as $role) {
			foreach ($this->membershipMapper->findByRole((int)$role->getId()) as $membership) {
				$data[] = [
					'id' => $membership->getId(),
					'roleId' => $membership->getRoleId(),
					'participant' => $membership->getParticipantId(),
					'participantType' => $membership->getParticipantType() === 'user' ? 0 : 1,
				];
			}
		}

		return $data;
	}

	/** @param BoardPolicyRole[] $roles */
	private function buildV2PolicyResponse(int $boardId, BoardPolicySetting $settings, array $roles, array $memberships): array {
		$rolesById = [];
		foreach ($roles as $role) {
			$rolesById[(int)$role->getId()] = (string)$role->getRoleKey();
		}

		$defaults = array_fill_keys(self::ACTIONS, []);
		foreach ($this->defaultDrasciMapper->findByBoard($boardId) as $default) {
			$action = (string)$default->getAction();
			$roleKey = (string)$default->getDrasciRole();
			if (isset($defaults[$action], self::DRASCI_ROLES[$roleKey])) {
				$defaults[$action][] = $roleKey;
			}
		}

		$cardsData = [];
		if ($this->cardMapper !== null) {
			foreach ($this->findBoardCards($boardId) as $card) {
				$cardId = (int)$card['id'];
				$cardPolicy = $this->cardPolicyMapper->findByCard($cardId);
				if ($cardPolicy !== null && (int)$cardPolicy->getBoardId() !== $boardId) {
					$cardPolicy = null;
				}

				$overrideActions = [];
				$roleKeysByAction = array_fill_keys(self::ACTIONS, []);
				if ($cardPolicy !== null) {
					foreach ($this->cardPolicyOverrideMapper->findByPolicy((int)$cardPolicy->getId()) as $override) {
						$overrideActions[(string)$override->getAction()] = true;
					}
					foreach ($this->cardPolicyRoleMapper->findByPolicy((int)$cardPolicy->getId()) as $relation) {
						$action = (string)$relation->getAction();
						$roleKey = $rolesById[(int)$relation->getRoleId()] ?? null;
						if (isset($roleKeysByAction[$action]) && $roleKey !== null) {
							$roleKeysByAction[$action][] = $roleKey;
						}
					}
				}

				$actions = [];
				$effectiveRules = [];
				foreach (self::ACTIONS as $action) {
					$isOverride = isset($overrideActions[$action]);
					$roleKeys = array_values(array_unique($roleKeysByAction[$action]));
					$actions[$action] = [
						'mode' => $isOverride ? 'override' : 'inherit',
						'allowedFunctionalRoleKeys' => $isOverride ? $roleKeys : [],
					];
					$effectiveRules[$action] = [
						'source' => $isOverride ? 'functional_override' : 'drasci_default',
						'roleKeys' => $isOverride ? $roleKeys : $defaults[$action],
					];
				}

				$cardsData[] = [
					'id' => $cardId,
					'title' => $card['title'],
					'stackId' => (int)$card['stack_id'],
					'actions' => $actions,
					'policy' => $actions,
					'hasAnyOverride' => $overrideActions !== [],
					'effectiveRules' => $effectiveRules,
				];
			}
		}

		$drasciRoles = [];
		foreach (self::DRASCI_ROLES as $key => $name) {
			$drasciRoles[] = ['key' => $key, 'name' => $name];
		}

		return [
			'settings' => $settings,
			'drascivsRoles' => $drasciRoles,
			'drasciRoles' => $drasciRoles,
			'functionalRoles' => $roles,
			'roles' => $roles,
			'memberships' => $memberships,
			'defaults' => $defaults,
			'cards' => $cardsData,
		];
	}

	/** @param BoardPolicyRole[] $roles */
	private function buildV1PolicyResponse(int $boardId, ?BoardPolicySetting $settings, array $roles, array $memberships): array {
		$rolesById = [];
		foreach ($roles as $role) {
			$rolesById[(int)$role->getId()] = (string)$role->getRoleKey();
		}

		$defaultRoleKeys = array_fill_keys(self::ACTIONS, []);
		foreach ($this->defaultRoleMapper->findByBoard($boardId) as $default) {
			$action = (string)$default->getAction();
			$roleKey = $rolesById[(int)$default->getRoleId()] ?? null;
			if (isset($defaultRoleKeys[$action]) && $roleKey !== null) {
				$defaultRoleKeys[$action][] = $roleKey;
			}
		}

		$cardsData = [];
		if ($this->cardMapper !== null) {
			foreach ($this->findBoardCards($boardId) as $card) {
				$cardId = (int)$card['id'];
				$cardPolicy = $this->cardPolicyMapper->findByCard($cardId);
				$hasExplicitPolicy = $cardPolicy !== null && (int)$cardPolicy->getBoardId() === $boardId;
				$policyData = array_fill_keys(self::ACTIONS, []);

				if ($hasExplicitPolicy) {
					foreach ($this->cardPolicyRoleMapper->findByPolicy((int)$cardPolicy->getId()) as $relation) {
						$action = (string)$relation->getAction();
						$roleKey = $rolesById[(int)$relation->getRoleId()] ?? null;
						if (isset($policyData[$action]) && $roleKey !== null) {
							$policyData[$action][] = $roleKey;
						}
					}
				}

				$cardsData[] = [
					'id' => $cardId,
					'title' => $card['title'],
					'stackId' => (int)$card['stack_id'],
					'hasExplicitPolicy' => $hasExplicitPolicy,
					'policy' => $policyData,
					'effectivePolicy' => $hasExplicitPolicy ? $policyData : $defaultRoleKeys,
				];
			}
		}

		return [
			'settings' => $settings,
			'roles' => $roles,
			'memberships' => $memberships,
			'defaultRoleKeys' => $defaultRoleKeys,
			'cards' => $cardsData,
		];
	}

	/**
	 * @return array<int, array{id: int|string, title: string, stack_id: int|string}>
	 */
	private function findBoardCards(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.title', 'c.stack_id')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 's.id = c.stack_id')
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('c.last_modified')
			->addOrderBy('c.id');

		$result = $qb->executeQuery();
		try {
			return $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
	}

	#[NoAdminRequired]
	public function enableCardPolicy(int $boardId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$this->db->beginTransaction();
			try {
				$settings = $this->settingMapper->findByBoard($boardId);
				if ($settings !== null
					&& $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI
					&& $settings->getPermissionMode() === 'card_policy') {
					$this->db->commit();
					return new JSONResponse($settings);
				}

				$transitioningToV2 = $settings === null || $settings->getPolicyVersion() < self::POLICY_VERSION_DRASCI;
				if ($settings === null) {
					$settings = new BoardPolicySetting();
					$settings->setBoardId($boardId);
					$settings->setPermissionMode('card_policy');
					$settings->setPolicyVersion(self::POLICY_VERSION_DRASCI);
					$settings->setRevision(1);
					$settings = $this->settingMapper->insert($settings);
				} else {
					if (!$this->settingMapper->incrementRevision($boardId)) {
						throw new \RuntimeException('Unable to increment policy revision.');
					}
					$settings = $this->settingMapper->findByBoard($boardId);
					if ($settings === null) {
						throw new \RuntimeException('Board policy settings disappeared during update.');
					}
					$settings->setPermissionMode('card_policy');
					$settings->setPolicyVersion(self::POLICY_VERSION_DRASCI);
					$this->settingMapper->update($settings);
				}

				foreach (self::STANDARD_FUNCTIONAL_ROLES as $key => $name) {
					if ($this->roleMapper->findByBoardAndKey($boardId, $key) !== null) {
						continue;
					}
					$role = new BoardPolicyRole();
					$role->setBoardId($boardId);
					$role->setRoleKey($key);
					$role->setRoleName($name);
					$this->roleMapper->insert($role);
				}

				if ($transitioningToV2 && $this->defaultDrasciMapper->findByBoard($boardId) === []) {
					foreach (self::DEFAULT_DRASCI as $action => $roleKeys) {
						foreach ($roleKeys as $roleKey) {
							$this->insertDrasciDefault($boardId, $action, $roleKey);
						}
					}
				}

				if ($transitioningToV2) {
					$this->policyService->preserveLegacyCardPolicyOverrides($boardId);
				}

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			return new JSONResponse($settings);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function updateCardPolicySettings(int $boardId, ?int $approvedStackId = null, ?int $doneStackId = null, ?string $permissionMode = null): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$settings = $this->settingMapper->findByBoard($boardId);
			if ($settings === null) {
				$settings = new BoardPolicySetting();
				$settings->setBoardId($boardId);
				$settings->setPermissionMode($permissionMode ?? 'card_policy');
				$settings->setApprovedStackId($approvedStackId);
				$settings->setDoneStackId($doneStackId);
				$settings = $this->settingMapper->insert($settings);
			} else {
				if ($permissionMode !== null) {
					$settings->setPermissionMode($permissionMode);
				}
				$settings->setApprovedStackId($approvedStackId);
				$settings->setDoneStackId($doneStackId);
				$settings = $this->settingMapper->update($settings);
			}

			return new JSONResponse($settings);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function updateCardPolicyDefaults(
		int $boardId,
		array $move = [],
		array $sign = [],
		array $verify = [],
		array $view = [],
		mixed $expectedRevision = null,
	): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);
			$actions = ['move' => $move, 'sign' => $sign, 'verify' => $verify, 'view' => $view];
			$settings = $this->settingMapper->findByBoard($boardId);

			if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
				$this->assertV2Settings($settings);
				$expectedRevision = $this->parseExpectedRevision($expectedRevision);
				foreach ($actions as $action => $roleKeys) {
					$actions[$action] = $this->normalizeDrasciRoleKeys($roleKeys);
				}

				$this->db->beginTransaction();
				try {
					$this->incrementRevision($boardId, $expectedRevision);
					foreach ($this->defaultDrasciMapper->findByBoard($boardId) as $default) {
						$this->defaultDrasciMapper->delete($default);
					}
					foreach ($actions as $action => $roleKeys) {
						foreach ($roleKeys as $roleKey) {
							$this->insertDrasciDefault($boardId, $action, $roleKey);
						}
					}
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}

				$settings = $this->settingMapper->findByBoard($boardId);
				return new JSONResponse([
					'success' => true,
					'revision' => $settings?->getRevision(),
					'defaults' => $actions,
				]);
			}

			$this->db->beginTransaction();
			try {
				foreach ($this->defaultRoleMapper->findByBoard($boardId) as $default) {
					$this->defaultRoleMapper->delete($default);
				}
				foreach ($actions as $action => $roleKeys) {
					foreach ($roleKeys as $roleKey) {
						$roleKey = trim((string)$roleKey);
						if ($roleKey === '') {
							continue;
						}
						$role = $this->roleMapper->findByBoardAndKey($boardId, $roleKey);
						if ($role === null) {
							continue;
						}
						$defaultRole = new BoardPolicyDefaultRole();
						$defaultRole->setBoardId($boardId);
						$defaultRole->setAction($action);
						$defaultRole->setRoleId((int)$role->getId());
						$this->defaultRoleMapper->insert($defaultRole);
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function createCardPolicyRole(int $boardId, string $roleKey, string $name, ?string $color = null): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$roleKey = trim(strtolower($roleKey));
			$name = trim($name);
			if ($roleKey === '' || $name === '') {
				return new JSONResponse(['error' => 'Role key and name are required'], Http::STATUS_BAD_REQUEST);
			}

			$existing = $this->roleMapper->findByBoardAndKey($boardId, $roleKey);
			if ($existing !== null) {
				return new JSONResponse($existing);
			}

			$role = new BoardPolicyRole();
			$role->setBoardId($boardId);
			$role->setRoleKey($roleKey);
			$role->setRoleName($name);

			$settings = $this->settingMapper->findByBoard($boardId);
			if ($settings === null || $settings->getPolicyVersion() < self::POLICY_VERSION_DRASCI) {
				return new JSONResponse($this->roleMapper->insert($role));
			}

			$this->db->beginTransaction();
			try {
				$this->incrementRevision($boardId, null);
				$created = $this->roleMapper->insert($role);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			return new JSONResponse(array_merge($created->jsonSerialize(), [
				'revision' => $settings?->getRevision(),
			]));
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function deleteCardPolicyRole(int $boardId, int $roleId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$role = $this->roleMapper->find($roleId);
			if ($role === null || (int)$role->getBoardId() !== $boardId) {
				return new JSONResponse(['error' => 'Role not found on this board'], Http::STATUS_NOT_FOUND);
			}

			$this->db->beginTransaction();
			try {
				$settings = $this->settingMapper->findByBoard($boardId);
				if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
					$this->incrementRevision($boardId, null);
				}

				foreach ($this->defaultRoleMapper->findByBoard($boardId) as $default) {
					if ((int)$default->getRoleId() === $roleId) {
						$this->defaultRoleMapper->delete($default);
					}
				}

				foreach ($this->membershipMapper->findByRole($roleId) as $membership) {
					$this->membershipMapper->delete($membership);
				}

				$qb = $this->db->getQueryBuilder();
				$qb->delete('pc_card_policy_roles')
					->where($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, IQueryBuilder::PARAM_INT)))
					->executeStatement();

				$this->roleMapper->delete($role);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			return new JSONResponse(['success' => true, 'revision' => $settings?->getRevision()]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function addMember(int $boardId, string $roleKey, string $participant, int $participantType): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$role = $this->roleMapper->findByBoardAndKey($boardId, $roleKey);
			if ($role === null) {
				return new JSONResponse(['error' => 'Role not found on this board'], Http::STATUS_NOT_FOUND);
			}

			$pTypeStr = $participantType === 0 ? 'user' : 'group';
			$participant = trim($participant);
			if ($participant === '') {
				return new JSONResponse(['error' => 'Invalid participant'], Http::STATUS_BAD_REQUEST);
			}

			$existing = $this->membershipMapper->findUnique((int)$role->getId(), $pTypeStr, $participant);
			if ($existing !== null) {
				return new JSONResponse([
					'id' => $existing->getId(),
					'roleId' => $existing->getRoleId(),
					'participant' => $existing->getParticipantId(),
					'participantType' => $existing->getParticipantType() === 'user' ? 0 : 1,
				]);
			}

			$membership = new BoardPolicyMembership();
			$membership->setRoleId((int)$role->getId());
			$membership->setParticipantType($pTypeStr);
			$membership->setParticipantId($participant);

			$settings = $this->settingMapper->findByBoard($boardId);
			if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
				$this->db->beginTransaction();
				try {
					$this->incrementRevision($boardId, null);
					$created = $this->membershipMapper->insert($membership);
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
				$settings = $this->settingMapper->findByBoard($boardId);
			} else {
				$created = $this->membershipMapper->insert($membership);
			}

			$response = [
				'id' => $created->getId(),
				'roleId' => $created->getRoleId(),
				'participant' => $created->getParticipantId(),
				'participantType' => $created->getParticipantType() === 'user' ? 0 : 1,
			];
			if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
				$response['revision'] = $settings->getRevision();
			}

			return new JSONResponse($response);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function removeMember(int $boardId, int $membershipId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$membership = $this->membershipMapper->find($membershipId);
			if ($membership === null) {
				return new JSONResponse(['error' => 'Membership record not found'], Http::STATUS_NOT_FOUND);
			}

			$role = $this->roleMapper->find((int)$membership->getRoleId());
			if ($role === null || (int)$role->getBoardId() !== $boardId) {
				return new JSONResponse(['error' => 'Role not found on this board'], Http::STATUS_NOT_FOUND);
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			if ($settings === null || $settings->getPolicyVersion() < self::POLICY_VERSION_DRASCI) {
				$this->membershipMapper->delete($membership);
				return new JSONResponse(['success' => true]);
			}

			$this->db->beginTransaction();
			try {
				$this->incrementRevision($boardId, null);
				$this->membershipMapper->delete($membership);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			return new JSONResponse(['success' => true, 'revision' => $settings?->getRevision()]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function updateCardPolicyAction(int $boardId, int $cardId, string $action): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);
			if (!in_array($action, self::ACTIONS, true)) {
				throw new OCSException('Unsupported policy action.', Http::STATUS_BAD_REQUEST);
			}
			$this->assertCardBelongsToBoard($boardId, $cardId);

			$settings = $this->settingMapper->findByBoard($boardId);
			$this->assertV2Settings($settings);

			$mode = $this->request->getParam('mode');
			if (!is_string($mode) || !in_array($mode, ['inherit', 'override'], true)) {
				throw new OCSException('Mode must be "inherit" or "override".', Http::STATUS_BAD_REQUEST);
			}

			$rawRoleKeys = $this->request->getParam('allowedFunctionalRoleKeys', []);
			if (!is_array($rawRoleKeys)) {
				throw new OCSException('allowedFunctionalRoleKeys must be an array.', Http::STATUS_BAD_REQUEST);
			}
			$roleKeys = $this->normalizeFunctionalRoleKeys($rawRoleKeys);
			$rolesByKey = [];
			foreach ($this->roleMapper->findByBoard($boardId) as $role) {
				$rolesByKey[(string)$role->getRoleKey()] = $role;
			}
			$unknownRoleKeys = array_diff($roleKeys, array_keys($rolesByKey));
			if ($unknownRoleKeys !== []) {
				throw new OCSException('Unknown functional roles: ' . implode(', ', $unknownRoleKeys), Http::STATUS_BAD_REQUEST);
			}

			$expectedRevision = $this->parseExpectedRevision($this->request->getParam('expectedRevision'));
			$this->db->beginTransaction();
			try {
				$this->incrementRevision($boardId, $expectedRevision);
				$cardPolicy = $this->cardPolicyMapper->findByCard($cardId);
				if ($cardPolicy !== null && (int)$cardPolicy->getBoardId() !== $boardId) {
					throw new OCSException('Card policy belongs to another board.', Http::STATUS_CONFLICT);
				}

				if ($mode === 'override' && $cardPolicy === null) {
					$cardPolicy = new CardPolicy();
					$cardPolicy->setCardId($cardId);
					$cardPolicy->setBoardId($boardId);
					$cardPolicy = $this->cardPolicyMapper->insert($cardPolicy);
				}

				if ($cardPolicy !== null) {
					$policyId = (int)$cardPolicy->getId();
					foreach ($this->cardPolicyRoleMapper->findByPolicyAndAction($policyId, $action) as $relation) {
						$this->cardPolicyRoleMapper->delete($relation);
					}
					$marker = $this->cardPolicyOverrideMapper->findByPolicyAndAction($policyId, $action);
					if ($marker !== null) {
						$this->cardPolicyOverrideMapper->delete($marker);
					}

					if ($mode === 'override') {
						$marker = new CardPolicyOverride();
						$marker->setCardPolicyId($policyId);
						$marker->setAction($action);
						$this->cardPolicyOverrideMapper->insert($marker);

						foreach ($roleKeys as $roleKey) {
							$relation = new CardPolicyRole();
							$relation->setCardPolicyId($policyId);
							$relation->setAction($action);
							$relation->setRoleId((int)$rolesByKey[$roleKey]->getId());
							$this->cardPolicyRoleMapper->insert($relation);
						}
					}
				}

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			$state = [
				'mode' => $mode,
				'allowedFunctionalRoleKeys' => $mode === 'override' ? $roleKeys : [],
			];
			return new JSONResponse([
				'revision' => $settings?->getRevision(),
				'action' => $action,
				'state' => $state,
				'mode' => $state['mode'],
				'allowedFunctionalRoleKeys' => $state['allowedFunctionalRoleKeys'],
			]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function saveCardPolicyOverrides(int $boardId, int $cardId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);
			$this->assertCardBelongsToBoard($boardId, $cardId);
			$settings = $this->settingMapper->findByBoard($boardId);
			if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
				throw new OCSException('Use action-level overrides for board policy v2.', Http::STATUS_CONFLICT);
			}

			$move = $this->request->getParam('move', []);
			$sign = $this->request->getParam('sign', []);
			$verify = $this->request->getParam('verify', []);
			$view = $this->request->getParam('view', []);

			$this->db->beginTransaction();
			try {
				$existingPolicy = $this->cardPolicyMapper->findByCard($cardId);
				if ($existingPolicy === null) {
					$policy = new CardPolicy();
					$policy->setCardId($cardId);
					$policy->setBoardId($boardId);
					$existingPolicy = $this->cardPolicyMapper->insert($policy);
				} elseif ((int)$existingPolicy->getBoardId() !== $boardId) {
					throw new OCSException('Card policy belongs to another board.', Http::STATUS_CONFLICT);
				}

				foreach ($this->cardPolicyRoleMapper->findByPolicy((int)$existingPolicy->getId()) as $role) {
					$this->cardPolicyRoleMapper->delete($role);
				}

				$actions = ['move' => $move, 'sign' => $sign, 'verify' => $verify, 'view' => $view];
				foreach ($actions as $action => $roleKeys) {
					if (!is_array($roleKeys)) {
						continue;
					}
					foreach ($roleKeys as $roleKey) {
						$roleKey = trim((string)$roleKey);
						if ($roleKey === '') {
							continue;
						}

						$role = $this->roleMapper->findByBoardAndKey($boardId, $roleKey);
						if ($role === null) {
							continue;
						}

						$cardRole = new CardPolicyRole();
						$cardRole->setCardPolicyId((int)$existingPolicy->getId());
						$cardRole->setAction($action);
						$cardRole->setRoleId((int)$role->getId());
						$this->cardPolicyRoleMapper->insert($cardRole);
					}
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function clearCardPolicy(int $boardId, int $cardId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);
			$this->assertCardBelongsToBoard($boardId, $cardId);
			$rawExpectedRevision = $this->request->getParam('expectedRevision');

			$this->db->beginTransaction();
			try {
				$settings = $this->settingMapper->findByBoard($boardId);
				if ($settings !== null && $settings->getPolicyVersion() >= self::POLICY_VERSION_DRASCI) {
					$expectedRevision = $this->parseExpectedRevision($rawExpectedRevision);
					$this->incrementRevision($boardId, $expectedRevision);
				}

				$existingPolicy = $this->cardPolicyMapper->findByCard($cardId);
				if ($existingPolicy !== null) {
					if ((int)$existingPolicy->getBoardId() !== $boardId) {
						throw new OCSException('Card policy belongs to another board.', Http::STATUS_CONFLICT);
					}
					foreach ($this->cardPolicyRoleMapper->findByPolicy((int)$existingPolicy->getId()) as $role) {
						$this->cardPolicyRoleMapper->delete($role);
					}
					foreach ($this->cardPolicyOverrideMapper->findByPolicy((int)$existingPolicy->getId()) as $override) {
						$this->cardPolicyOverrideMapper->delete($override);
					}
					$this->cardPolicyMapper->delete($existingPolicy);
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			$settings = $this->settingMapper->findByBoard($boardId);
			return new JSONResponse(['success' => true, 'revision' => $settings?->getRevision()]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	private function insertDrasciDefault(int $boardId, string $action, string $roleKey): void {
		$default = new BoardPolicyDefaultDrasci();
		$default->setBoardId($boardId);
		$default->setAction($action);
		$default->setDrasciRole($roleKey);
		$this->defaultDrasciMapper->insert($default);
	}

	/** @param mixed[] $roleKeys */
	private function normalizeDrasciRoleKeys(array $roleKeys): array {
		$normalized = [];
		foreach ($roleKeys as $roleKey) {
			if (!is_string($roleKey) || !isset(self::DRASCI_ROLES[$roleKey])) {
				throw new OCSException('DRASCIVS defaults may only contain: ' . implode(', ', array_keys(self::DRASCI_ROLES)), Http::STATUS_BAD_REQUEST);
			}
			$normalized[$roleKey] = true;
		}

		return array_keys($normalized);
	}

	/** @param mixed[] $roleKeys */
	private function normalizeFunctionalRoleKeys(array $roleKeys): array {
		$normalized = [];
		foreach ($roleKeys as $roleKey) {
			if (!is_string($roleKey) || trim($roleKey) === '') {
				throw new OCSException('Functional role keys must be non-empty strings.', Http::STATUS_BAD_REQUEST);
			}
			$normalized[trim($roleKey)] = true;
		}

		return array_keys($normalized);
	}

	private function assertV2Settings(?BoardPolicySetting $settings): void {
		if ($settings === null
			|| $settings->getPolicyVersion() < self::POLICY_VERSION_DRASCI) {
			throw new OCSException('Board policy v2 is not enabled.', Http::STATUS_CONFLICT);
		}
	}

	private function incrementRevision(int $boardId, ?int $expectedRevision): void {
		if (!$this->settingMapper->incrementRevision($boardId, $expectedRevision)) {
			throw new OCSException('Policy revision conflict.', Http::STATUS_CONFLICT);
		}
	}

	private function parseExpectedRevision(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}
		if (is_int($value) && $value >= 0) {
			return $value;
		}
		if (is_string($value) && ctype_digit($value)) {
			return (int)$value;
		}

		throw new OCSException('expectedRevision must be a non-negative integer.', Http::STATUS_BAD_REQUEST);
	}

	private function assertCardBelongsToBoard(int $boardId, int $cardId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 's.id = c.stack_id')
			->where($qb->expr()->eq('c.id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		try {
			if ($result->fetch() === false) {
				throw new OCSNotFoundException('Card not found on this board.');
			}
		} finally {
			$result->closeCursor();
		}
	}

	private function assertCanManagePolicy(int $boardId): void {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		if (!$this->policyService->isBypassUser($boardId, $currentUser->getUID())) {
			throw new OCSForbiddenException('Only board/project owners or admins can manage policies.');
		}
	}

	private function errorResponse(\Throwable $e): JSONResponse {
		if ($e instanceof OCSForbiddenException) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		if ($e instanceof OCSNotFoundException) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		if ($e instanceof OCSException && in_array($e->getCode(), [Http::STATUS_BAD_REQUEST, Http::STATUS_CONFLICT], true)) {
			return new JSONResponse(['error' => $e->getMessage()], $e->getCode());
		}

		return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
