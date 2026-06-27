<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\Db\BoardPolicySetting;
use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicy;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRole;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

class PolicyApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BoardPolicySettingMapper $settingMapper,
		private readonly BoardPolicyRoleMapper $roleMapper,
		private readonly BoardPolicyMembershipMapper $membershipMapper,
		private readonly BoardPolicyDefaultRoleMapper $defaultRoleMapper,
		private readonly CardPolicyMapper $cardPolicyMapper,
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
			if ($settings === null) {
				$settings = new BoardPolicySetting();
				$settings->setBoardId($boardId);
				$settings->setPermissionMode('legacy');
				$settings = $this->settingMapper->insert($settings);
			}

			$roles = $this->roleMapper->findByBoard($boardId);

			$membershipsData = [];
			foreach ($roles as $role) {
				$memberships = $this->membershipMapper->findByRole((int)$role->getId());
				foreach ($memberships as $membership) {
					$membershipsData[] = [
						'id' => $membership->getId(),
						'roleId' => $membership->getRoleId(),
						'participant' => $membership->getParticipantId(),
						'participantType' => $membership->getParticipantType() === 'user' ? 0 : 1,
					];
				}
			}

			$defaultRoleKeys = [
				'move' => [],
				'sign' => [],
				'verify' => [],
				'view' => [],
			];
			$defaults = $this->defaultRoleMapper->findByBoard($boardId);
			foreach ($defaults as $default) {
				$role = $this->roleMapper->find((int)$default->getRoleId());
				if ($role !== null) {
					$defaultRoleKeys[$default->getAction()][] = $role->getRoleKey();
				}
			}

			$cardsData = [];
			if ($this->cardMapper !== null) {
				$cards = $this->findBoardCards($boardId);
				foreach ($cards as $card) {
					$cardId = (int)$card['id'];
					$cardPolicy = $this->cardPolicyMapper->findByCard($cardId);
					$hasExplicitPolicy = $cardPolicy !== null;
					$policyData = [
						'move' => [],
						'sign' => [],
						'verify' => [],
						'view' => [],
					];

					if ($hasExplicitPolicy) {
						$rolesForCard = $this->cardPolicyRoleMapper->findByPolicy((int)$cardPolicy->getId());
						foreach ($rolesForCard as $roleRelation) {
							$role = $this->roleMapper->find((int)$roleRelation->getRoleId());
							if ($role !== null) {
								$policyData[$roleRelation->getAction()][] = $role->getRoleKey();
							}
						}
					}

					$effectivePolicy = [];
					foreach (['move', 'sign', 'verify', 'view'] as $act) {
						if ($hasExplicitPolicy) {
							$effectivePolicy[$act] = $policyData[$act];
						} else {
							$effectivePolicy[$act] = $defaultRoleKeys[$act];
						}
					}

					$cardsData[] = [
						'id' => $cardId,
						'title' => $card['title'],
						'stackId' => (int)$card['stack_id'],
						'hasExplicitPolicy' => $hasExplicitPolicy,
						'policy' => $policyData,
						'effectivePolicy' => $effectivePolicy,
					];
				}
			}

			return new JSONResponse([
				'settings' => $settings,
				'roles' => $roles,
				'memberships' => $membershipsData,
				'defaultRoleKeys' => $defaultRoleKeys,
				'cards' => $cardsData,
			]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
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

			$settings = $this->settingMapper->findByBoard($boardId);
			if ($settings === null) {
				$settings = new BoardPolicySetting();
				$settings->setBoardId($boardId);
				$settings->setPermissionMode('card_policy');
				$settings = $this->settingMapper->insert($settings);
			} else {
				$settings->setPermissionMode('card_policy');
				$settings = $this->settingMapper->update($settings);
			}

			$roles = $this->roleMapper->findByBoard($boardId);
			if (empty($roles)) {
				$rolesList = [
					'cpl' => 'CPL',
					'client_developer' => 'Client/Developer',
					'grid_operator' => 'Grid operator (Elektra)',
				];
				$createdRoles = [];
				foreach ($rolesList as $key => $name) {
					$role = new BoardPolicyRole();
					$role->setBoardId($boardId);
					$role->setRoleKey($key);
					$role->setRoleName($name);
					$createdRoles[$key] = $this->roleMapper->insert($role);
				}

				$defaultMappings = [
					'move' => ['client_developer', 'cpl', 'grid_operator'],
					'sign' => ['cpl', 'grid_operator'],
					'verify' => ['cpl', 'grid_operator'],
					'view' => ['client_developer', 'cpl', 'grid_operator'],
				];
				foreach ($defaultMappings as $action => $roleKeys) {
					foreach ($roleKeys as $key) {
						if (isset($createdRoles[$key])) {
							$defaultRole = new BoardPolicyDefaultRole();
							$defaultRole->setBoardId($boardId);
							$defaultRole->setAction($action);
							$defaultRole->setRoleId((int)$createdRoles[$key]->getId());
							$this->defaultRoleMapper->insert($defaultRole);
						}
					}
				}
			}

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
		array $view = []
	): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$existingDefaults = $this->defaultRoleMapper->findByBoard($boardId);
			foreach ($existingDefaults as $default) {
				$this->defaultRoleMapper->delete($default);
			}

			$actions = ['move' => $move, 'sign' => $sign, 'verify' => $verify, 'view' => $view];
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

			$created = $this->roleMapper->insert($role);
			return new JSONResponse($created);
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

			$defaults = $this->defaultRoleMapper->findByBoard($boardId);
			foreach ($defaults as $default) {
				if ((int)$default->getRoleId() === $roleId) {
					$this->defaultRoleMapper->delete($default);
				}
			}

			$memberships = $this->membershipMapper->findByRole($roleId);
			foreach ($memberships as $membership) {
				$this->membershipMapper->delete($membership);
			}

			$qb = $this->db->getQueryBuilder();
			$qb->delete('pc_card_policy_roles')
				->where($qb->expr()->eq('role_id', $qb->createNamedParameter($roleId, IQueryBuilder::PARAM_INT)))
				->executeStatement();

			$this->roleMapper->delete($role);

			return new JSONResponse(['success' => true]);
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

			$created = $this->membershipMapper->insert($membership);
			return new JSONResponse([
				'id' => $created->getId(),
				'roleId' => $created->getRoleId(),
				'participant' => $created->getParticipantId(),
				'participantType' => $created->getParticipantType() === 'user' ? 0 : 1,
			]);
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

			$this->membershipMapper->delete($membership);
			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function saveCardPolicyOverrides(int $boardId, int $cardId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$move = $this->request->getParam('move', []);
			$sign = $this->request->getParam('sign', []);
			$verify = $this->request->getParam('verify', []);
			$view = $this->request->getParam('view', []);

			$existingPolicy = $this->cardPolicyMapper->findByCard($cardId);
			if ($existingPolicy === null) {
				$policy = new CardPolicy();
				$policy->setCardId($cardId);
				$policy->setBoardId($boardId);
				$existingPolicy = $this->cardPolicyMapper->insert($policy);
			}

			$existingRoles = $this->cardPolicyRoleMapper->findByPolicy((int)$existingPolicy->getId());
			foreach ($existingRoles as $role) {
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

			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
		}
	}

	#[NoAdminRequired]
	public function clearCardPolicy(int $boardId, int $cardId): JSONResponse {
		try {
			$this->assertCanManagePolicy($boardId);

			$existingPolicy = $this->cardPolicyMapper->findByCard($cardId);
			if ($existingPolicy !== null) {
				$existingRoles = $this->cardPolicyRoleMapper->findByPolicy((int)$existingPolicy->getId());
				foreach ($existingRoles as $role) {
					$this->cardPolicyRoleMapper->delete($role);
				}
				$this->cardPolicyMapper->delete($existingPolicy);
			}

			return new JSONResponse(['success' => true]);
		} catch (\Throwable $e) {
			return $this->errorResponse($e);
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

		return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
	}
}
