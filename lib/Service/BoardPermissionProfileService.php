<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\Deck\Model\OptionalNullableValue;
use OCA\ProjectCreatorAIO\Db\BoardPermissionProfile;
use OCA\ProjectCreatorAIO\Db\BoardPermissionProfileMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasci;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasciMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicySetting;
use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicy;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverride;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverrideMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRole;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

class BoardPermissionProfileService {
	private const ACTIONS = ['view', 'move', 'sign', 'verify'];
	private const DRASCIVS_ROLES = ['driver', 'responsible', 'accountable', 'supportive', 'consulted', 'informed', 'verifier', 'signer'];

	private IL10N $l;

	public function __construct(
		private readonly IDBConnection $db,
		private readonly BoardPermissionProfileMapper $profileMapper,
		private readonly BoardPolicySettingMapper $settingMapper,
		private readonly BoardPolicyRoleMapper $roleMapper,
		private readonly BoardPolicyDefaultDrasciMapper $defaultMapper,
		private readonly CardPolicyMapper $cardPolicyMapper,
		private readonly CardPolicyOverrideMapper $overrideMapper,
		private readonly CardPolicyRoleMapper $cardRoleMapper,
		private readonly ?object $stackService,
		private readonly ?object $cardService,
		private readonly ?object $stackMapper,
		?IL10N $l = null,
	) {
		$this->l = $l ?? DefaultLanguageL10n::userL10n();
	}

	public function snapshot(int $organizationId, string $uid, string $name, int $boardId): BoardPermissionProfile {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > 255) {
			throw new \InvalidArgumentException($this->l->t('Profile name is required and must not exceed 255 characters.'));
		}

		$settings = $this->settingMapper->findByBoard($boardId);
		if ($settings === null || $settings->getPolicyVersion() < 2) {
			throw new \DomainException($this->l->t('Board policy v2 is not enabled.'));
		}

		$roles = $this->roleMapper->findByBoard($boardId);
		$roleKeys = [];
		foreach ($roles as $role) {
			$roleKeys[(int)$role->getId()] = (string)$role->getRoleKey();
		}

		$stacks = $this->rows('deck_stacks', ['id', 'title', 'order', 'is_done_column'], ['board_id' => $boardId, 'deleted_at' => 0], ['order', 'id']);
		$payloadStacks = [];
		$stackRefs = [];
		foreach ($stacks as $index => $stack) {
			$ref = 'stack-' . ($index + 1);
			$stackRefs[(int)$stack['id']] = $ref;
			$payloadStacks[] = [
				'ref' => $ref,
				'title' => (string)$stack['title'],
				'order' => (int)$stack['order'],
				'approved' => (int)$settings->getApprovedStackId() === (int)$stack['id'],
				'done' => (bool)$stack['is_done_column'] || (int)$settings->getDoneStackId() === (int)$stack['id'],
			];
		}

		$payloadCards = [];
		foreach ($this->boardCards($boardId) as $index => $card) {
			$actions = array_fill_keys(self::ACTIONS, ['mode' => 'inherit', 'allowedFunctionalRoleKeys' => []]);
			$policy = $this->cardPolicyMapper->findByCard((int)$card['id']);
			if ($policy !== null && (int)$policy->getBoardId() === $boardId) {
				$overrides = [];
				foreach ($this->overrideMapper->findByPolicy((int)$policy->getId()) as $marker) {
					$overrides[(string)$marker->getAction()] = true;
				}
				$allowed = array_fill_keys(self::ACTIONS, []);
				foreach ($this->cardRoleMapper->findByPolicy((int)$policy->getId()) as $relation) {
					$actionName = (string)$relation->getAction();
					$key = $roleKeys[(int)$relation->getRoleId()] ?? null;
					if ($key !== null && isset($allowed[$actionName])) {
						$allowed[$actionName][] = $key;
					}
				}
				foreach (self::ACTIONS as $actionName) {
					if (isset($overrides[$actionName])) {
						$actions[$actionName] = ['mode' => 'override', 'allowedFunctionalRoleKeys' => array_values(array_unique($allowed[$actionName]))];
					}
				}
			}
			$payloadCards[] = [
				'ref' => 'card-' . ($index + 1),
				'stackRef' => $stackRefs[(int)$card['stack_id']],
				'title' => (string)$card['title'],
				'description' => (string)$card['description'],
				'order' => (int)$card['order'],
				'functionalPolicy' => $actions,
			];
		}

		$defaults = array_fill_keys(self::ACTIONS, []);
		foreach ($this->defaultMapper->findByBoard($boardId) as $default) {
			$defaults[(string)$default->getAction()][] = (string)$default->getDrasciRole();
		}

		$payload = [
			'stacks' => $payloadStacks,
			'cards' => $payloadCards,
			'functionalRoles' => array_map(static fn(BoardPolicyRole $role): array => [
				'key' => $role->getRoleKey(),
				'name' => $role->getRoleName(),
			], $roles),
			'drascivsDefaults' => $defaults,
		];

		self::validatePayload($payload, $this->l);
		return $this->profileMapper->createProfile($organizationId, $uid, $name, $payload);
	}

	public function preview(BoardPermissionProfile $profile, int $boardId): array {
		$this->assertDeckAvailable();
		$payload = $profile->getPayload();
		self::validatePayload($payload, $this->l);
		return $this->buildPlan($profile, $boardId);
	}

	public function deleteMappings(int $profileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('pc_board_profile_cards')
			->where($qb->expr()->eq('profile_id', $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	public function apply(
		BoardPermissionProfile $profile,
		int $boardId,
		string $uid,
		array $resolutions,
		?string $expectedPreviewToken,
	): array {
		$this->assertDeckAvailable();
		$payload = $profile->getPayload();
		self::validatePayload($payload, $this->l);
		$plan = $this->buildPlan($profile, $boardId);
		if ($expectedPreviewToken === null || !hash_equals($plan['previewToken'], $expectedPreviewToken)) {
			throw new \DomainException($this->l->t('Preview is stale. Preview the profile again before applying.'));
		}

		$choices = [];
		foreach ($plan['conflicts'] as $conflict) {
			$key = $conflict['key'];
			$choice = $resolutions[$key] ?? null;
			$allowed = array_column($conflict['options'], 'value');
			if (!is_string($choice) || !in_array($choice, $allowed, true)) {
				throw new \InvalidArgumentException($this->l->t('Every conflict must have a valid resolution.'));
			}
			$choices[$key] = $choice;
		}

		$counts = [
			'createdStacks' => 0,
			'createdRoles' => 0,
			'createdCards' => 0,
			'updatedCards' => 0,
			'skippedCards' => 0,
			'appliedCardPolicies' => 0,
		];
		$stackIds = $this->applyStacks($payload, $boardId, $counts);
		$roles = $this->applyRoles($payload, $boardId, $counts);

		$cardsByRef = [];
		foreach ($payload['cards'] as $cardData) {
			$cardsByRef[$cardData['ref']] = $cardData;
		}
		foreach ($plan['cards'] as $cardPlan) {
			$cardData = $cardsByRef[$cardPlan['ref']];
			$operation = $cardPlan['operation'];
			$cardId = $cardPlan['cardId'];
			if ($operation === 'conflict') {
				$choice = $choices[$cardPlan['conflictKey']];
				if ($choice === 'skip') {
					$counts['skippedCards']++;
					continue;
				}
				if ($choice === 'create') {
					$operation = 'create';
					$cardId = null;
				} else {
					$operation = 'update';
					$cardId = (int)substr($choice, strlen('card:'));
				}
			}

			$stackId = $stackIds[$cardData['stackRef']];
			if ($operation === 'create') {
				$card = $this->cardService->create($cardData['title'], $stackId, 'plain', $cardData['order'], $uid, $cardData['description']);
				$cardId = (int)$card->getId();
				$counts['createdCards']++;
			} else {
				$card = $this->cardService->find($cardId);
				$this->cardService->update(
					$cardId,
					$cardData['title'],
					$stackId,
					(string)$card->getType(),
					(string)$card->getOwner(),
					$cardData['description'],
					$cardData['order'],
					$card->getDuedate()?->format(DATE_ATOM),
					(int)$card->getDeletedAt(),
					(bool)$card->getArchived(),
					new OptionalNullableValue($card->getDone()),
					$card->getStartdate()?->format(DATE_ATOM),
					$card->getColor(),
				);
				$counts['updatedCards']++;
			}
			$this->saveMapping((int)$profile->getId(), $boardId, $cardData['ref'], $cardId);
			$this->applyCardPolicy($boardId, $cardId, $cardData['functionalPolicy'], $roles);
			$counts['appliedCardPolicies']++;
		}

		$this->db->beginTransaction();
		try {
			$this->applyBoardPolicy($boardId, $payload, $stackIds);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return array_merge(['profileId' => $profile->getId(), 'boardId' => $boardId], $counts);
	}

	public static function validatePayload(array $payload, ?IL10N $l = null): void {
		$l ??= DefaultLanguageL10n::userL10n();
		self::assertExactKeys($payload, ['stacks', 'cards', 'functionalRoles', 'drascivsDefaults'], $l->t('profile'), $l);
		foreach (['stacks', 'cards', 'functionalRoles', 'drascivsDefaults'] as $key) {
			if (!is_array($payload[$key])) {
				throw new \InvalidArgumentException($l->t('Profile %s must be an array.', [$key]));
			}
		}

		$stackRefs = [];
		foreach ($payload['stacks'] as $stack) {
			if (!is_array($stack)) {
				throw new \InvalidArgumentException($l->t('Each profile stack must be an object.'));
			}
			self::assertExactKeys($stack, ['ref', 'title', 'order', 'approved', 'done'], $l->t('stack'), $l);
			if (!is_string($stack['ref']) || $stack['ref'] === '' || isset($stackRefs[$stack['ref']]) || !is_string($stack['title']) || trim($stack['title']) === '' || !is_int($stack['order']) || !is_bool($stack['approved']) || !is_bool($stack['done'])) {
				throw new \InvalidArgumentException($l->t('Invalid or duplicate stack definition.'));
			}
			$stackRefs[$stack['ref']] = true;
		}

		$roleKeys = [];
		foreach ($payload['functionalRoles'] as $role) {
			if (!is_array($role)) {
				throw new \InvalidArgumentException($l->t('Each functional role must be an object.'));
			}
			self::assertExactKeys($role, ['key', 'name'], $l->t('functional role'), $l);
			if (!is_string($role['key']) || trim($role['key']) === '' || isset($roleKeys[$role['key']]) || !is_string($role['name']) || trim($role['name']) === '') {
				throw new \InvalidArgumentException($l->t('Invalid or duplicate functional role.'));
			}
			$roleKeys[$role['key']] = true;
		}

		$cardRefs = [];
		foreach ($payload['cards'] as $card) {
			if (!is_array($card)) {
				throw new \InvalidArgumentException($l->t('Each profile card must be an object.'));
			}
			self::assertExactKeys($card, ['ref', 'stackRef', 'title', 'description', 'order', 'functionalPolicy'], $l->t('card'), $l);
			if (!is_string($card['ref']) || $card['ref'] === '' || isset($cardRefs[$card['ref']]) || !isset($stackRefs[$card['stackRef']]) || !is_string($card['title']) || trim($card['title']) === '' || !is_string($card['description']) || !is_int($card['order']) || !is_array($card['functionalPolicy'])) {
				throw new \InvalidArgumentException($l->t('Invalid card definition or stack reference.'));
			}
			self::assertExactKeys($card['functionalPolicy'], self::ACTIONS, $l->t('card policy'), $l);
			foreach (self::ACTIONS as $actionName) {
				$state = $card['functionalPolicy'][$actionName];
				if (!is_array($state)) {
					throw new \InvalidArgumentException($l->t('Invalid card policy action.'));
				}
				self::assertExactKeys($state, ['mode', 'allowedFunctionalRoleKeys'], $l->t('card policy action'), $l);
				if (!in_array($state['mode'], ['inherit', 'override'], true) || !is_array($state['allowedFunctionalRoleKeys'])) {
					throw new \InvalidArgumentException($l->t('Invalid card policy mode or role list.'));
				}
				foreach ($state['allowedFunctionalRoleKeys'] as $roleKey) {
					if (!is_string($roleKey) || !isset($roleKeys[$roleKey])) {
						throw new \InvalidArgumentException($l->t('Card policy references an unknown functional role.'));
					}
				}
			}
			$cardRefs[$card['ref']] = true;
		}

		self::assertExactKeys($payload['drascivsDefaults'], self::ACTIONS, $l->t('DRASCIVS defaults'), $l);
		foreach ($payload['drascivsDefaults'] as $roleList) {
			if (!is_array($roleList) || array_diff($roleList, self::DRASCIVS_ROLES) !== []) {
				throw new \InvalidArgumentException($l->t('Invalid DRASCIVS default role.'));
			}
		}
	}

	private function buildPlan(BoardPermissionProfile $profile, int $boardId): array {
		$payload = $profile->getPayload();
		$stacks = $this->boardStacksByNormalizedTitle($boardId);
		$cards = $this->boardCards($boardId);
		$mapped = $this->mappedCards((int)$profile->getId(), $boardId);
		$targetStacks = [];
		$createdStacks = 0;
		foreach ($payload['stacks'] as $stack) {
			$matches = $stacks[self::normalize($stack['title'])] ?? [];
			if (count($matches) > 1) {
				throw new \DomainException($this->l->t('Multiple target stacks have the same normalized title.'));
			}
			$targetStacks[$stack['ref']] = count($matches) === 1 ? (int)$matches[0]->getId() : null;
			if ($matches === []) {
				$createdStacks++;
			}
		}

		$cardPlans = [];
		$conflicts = [];
		$counts = ['createdStacks' => $createdStacks, 'createdRoles' => 0, 'createdCards' => 0, 'updatedCards' => 0, 'skippedCards' => 0, 'appliedCardPolicies' => count($payload['cards'])];
		foreach ($payload['functionalRoles'] as $role) {
			if ($this->roleMapper->findByBoardAndKey($boardId, $role['key']) === null) {
				$counts['createdRoles']++;
			}
		}
		foreach ($payload['cards'] as $card) {
			$mappedId = $mapped[$card['ref']] ?? null;
			if ($mappedId !== null && $this->cardInRows($mappedId, $cards)) {
				$cardPlans[] = ['ref' => $card['ref'], 'operation' => 'update', 'cardId' => $mappedId];
				$counts['updatedCards']++;
				continue;
			}
			$candidates = $this->matchingCards($cards, $targetStacks[$card['stackRef']], $card['title']);
			if (count($candidates) === 1) {
				$cardId = (int)$candidates[0]['id'];
				$cardPlans[] = ['ref' => $card['ref'], 'operation' => 'update', 'cardId' => $cardId];
				$counts['updatedCards']++;
				continue;
			}
			if (count($candidates) === 0) {
				$cardPlans[] = ['ref' => $card['ref'], 'operation' => 'create', 'cardId' => null];
				$counts['createdCards']++;
				continue;
			}

			$key = 'card:' . $card['ref'];
			$options = [
				['value' => 'create', 'label' => $this->l->t('Create a new card')],
				['value' => 'skip', 'label' => $this->l->t('Skip this card')],
			];
			foreach ($candidates as $candidate) {
				$options[] = ['value' => 'card:' . $candidate['id'], 'label' => $this->l->t('Update card #%s', [$candidate['id']]), 'cardId' => (int)$candidate['id']];
			}
			$conflicts[] = ['key' => $key, 'cardTitle' => $card['title'], 'message' => $this->l->t('Multiple cards with this title exist in the target stack.'), 'options' => $options, 'targetCardIds' => array_map(static fn(array $candidate): int => (int)$candidate['id'], $candidates)];
			$cardPlans[] = ['ref' => $card['ref'], 'operation' => 'conflict', 'cardId' => null, 'conflictKey' => $key];
		}

		$topology = [
			'stacks' => array_map(static fn($stack): array => [
				'id' => (int)$stack->getId(),
				'title' => (string)$stack->getTitle(),
				'order' => (int)$stack->getOrder(),
				'done' => (bool)$stack->getIsDoneColumn(),
			], $this->stackMapper->findAll($boardId)),
			'cards' => $cards,
			'mappings' => $mapped,
			'roles' => array_map(static fn(BoardPolicyRole $role): array => [
				'key' => (string)$role->getRoleKey(),
				'name' => (string)$role->getRoleName(),
			], $this->roleMapper->findByBoard($boardId)),
			'defaults' => array_map(static fn(BoardPolicyDefaultDrasci $default): array => [
				'action' => (string)$default->getAction(),
				'role' => (string)$default->getDrasciRole(),
			], $this->defaultMapper->findByBoard($boardId)),
		];
		$token = hash('sha256', json_encode(['profile' => $payload, 'topology' => $topology], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
		return array_merge([
			'profileId' => $profile->getId(),
			'boardId' => $boardId,
			'previewToken' => $token,
			'expectedPreviewToken' => $token,
			'counts' => $counts,
			'conflicts' => $conflicts,
			'cards' => $cardPlans,
		], $counts);
	}

	private function applyStacks(array $payload, int $boardId, array &$counts): array {
		$existing = $this->boardStacksByNormalizedTitle($boardId);
		$stackIds = [];
		foreach ($payload['stacks'] as $stack) {
			$matches = $existing[self::normalize($stack['title'])] ?? [];
			if (count($matches) > 1) {
				throw new \DomainException($this->l->t('Target stack topology is ambiguous.'));
			}
			$entity = $matches[0] ?? $this->stackService->create($stack['title'], $boardId, $stack['order']);
			if ($matches === []) {
				$counts['createdStacks']++;
			}
			$stackIds[$stack['ref']] = (int)$entity->getId();
			$this->stackMapper->setIsDoneColumn((int)$entity->getId(), $stack['done']);
		}
		return $stackIds;
	}

	private function applyRoles(array $payload, int $boardId, array &$counts): array {
		$roles = [];
		foreach ($payload['functionalRoles'] as $item) {
			$role = $this->roleMapper->findByBoardAndKey($boardId, $item['key']);
			if ($role === null) {
				$role = new BoardPolicyRole();
				$role->setBoardId($boardId);
				$role->setRoleKey($item['key']);
				$role->setRoleName($item['name']);
				$role = $this->roleMapper->insert($role);
				$counts['createdRoles']++;
			}
			$roles[$item['key']] = $role;
		}
		return $roles;
	}

	private function applyBoardPolicy(int $boardId, array $payload, array $stackIds): void {
		$settings = $this->settingMapper->findByBoard($boardId) ?? new BoardPolicySetting();
		$isNew = $settings->getId() === null;
		$settings->setBoardId($boardId);
		$settings->setPermissionMode('card_policy');
		$settings->setPolicyVersion(2);
		$settings->setApprovedStackId(null);
		$settings->setDoneStackId(null);
		foreach ($payload['stacks'] as $stack) {
			if ($stack['approved']) {
				$settings->setApprovedStackId($stackIds[$stack['ref']]);
			}
			if ($stack['done']) {
				$settings->setDoneStackId($stackIds[$stack['ref']]);
			}
		}
		if ($isNew) {
			$settings->setRevision(1);
			$this->settingMapper->insert($settings);
		} else {
			$settings->setRevision($settings->getRevision() + 1);
			$this->settingMapper->update($settings);
		}
		foreach ($this->defaultMapper->findByBoard($boardId) as $row) {
			$this->defaultMapper->delete($row);
		}
		foreach ($payload['drascivsDefaults'] as $actionName => $keys) {
			foreach ($keys as $key) {
				$row = new BoardPolicyDefaultDrasci();
				$row->setBoardId($boardId);
				$row->setAction($actionName);
				$row->setDrasciRole($key);
				$this->defaultMapper->insert($row);
			}
		}
	}

	private function applyCardPolicy(int $boardId, int $cardId, array $actions, array $roles): void {
		$this->db->beginTransaction();
		try {
			$policy = $this->cardPolicyMapper->findByCard($cardId);
			if ($policy === null) {
				$policy = new CardPolicy();
				$policy->setBoardId($boardId);
				$policy->setCardId($cardId);
				$policy = $this->cardPolicyMapper->insert($policy);
			}
			foreach ($this->overrideMapper->findByPolicy((int)$policy->getId()) as $row) {
				$this->overrideMapper->delete($row);
			}
			foreach ($this->cardRoleMapper->findByPolicy((int)$policy->getId()) as $row) {
				$this->cardRoleMapper->delete($row);
			}
			foreach (self::ACTIONS as $actionName) {
				$state = $actions[$actionName];
				if ($state['mode'] !== 'override') {
					continue;
				}
				$marker = new CardPolicyOverride();
				$marker->setCardPolicyId((int)$policy->getId());
				$marker->setAction($actionName);
				$this->overrideMapper->insert($marker);
				foreach ($state['allowedFunctionalRoleKeys'] as $key) {
					$row = new CardPolicyRole();
					$row->setCardPolicyId((int)$policy->getId());
					$row->setAction($actionName);
					$row->setRoleId((int)$roles[$key]->getId());
					$this->cardRoleMapper->insert($row);
				}
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	private function boardCards(int $boardId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('c.id', 'c.stack_id', 'c.title', 'c.description', 'c.order')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 's.id = c.stack_id')
			->where($qb->expr()->eq('s.board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->orderBy('s.order')
			->addOrderBy('c.order')
			->addOrderBy('c.id');
		$result = $qb->executeQuery();
		try {
			return $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
	}

	private function boardStacksByNormalizedTitle(int $boardId): array {
		$stacks = [];
		foreach ($this->stackMapper->findAll($boardId) as $stack) {
			$stacks[self::normalize((string)$stack->getTitle())][] = $stack;
		}
		return $stacks;
	}

	private function mappedCards(int $profileId, int $boardId): array {
		$rows = $this->rows('pc_board_profile_cards', ['portable_ref', 'card_id'], ['profile_id' => $profileId, 'board_id' => $boardId]);
		$mapped = [];
		foreach ($rows as $row) {
			$mapped[(string)$row['portable_ref']] = (int)$row['card_id'];
		}
		return $mapped;
	}

	private function saveMapping(int $profileId, int $boardId, string $ref, int $cardId): void {
		$existing = $this->mappedCards($profileId, $boardId);
		$qb = $this->db->getQueryBuilder();
		if (isset($existing[$ref])) {
			$qb->update('pc_board_profile_cards')
				->set('card_id', $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('profile_id', $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('portable_ref', $qb->createNamedParameter($ref)))
				->executeStatement();
			return;
		}
		$qb->insert('pc_board_profile_cards')->values([
			'profile_id' => $qb->createNamedParameter($profileId, IQueryBuilder::PARAM_INT),
			'board_id' => $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT),
			'portable_ref' => $qb->createNamedParameter($ref),
			'card_id' => $qb->createNamedParameter($cardId, IQueryBuilder::PARAM_INT),
		])->executeStatement();
	}

	private function matchingCards(array $cards, ?int $stackId, string $title): array {
		if ($stackId === null) {
			return [];
		}
		return array_values(array_filter($cards, static fn(array $card): bool =>
			(int)$card['stack_id'] === $stackId && self::normalize((string)$card['title']) === self::normalize($title)));
	}

	private function cardInRows(int $cardId, array $cards): bool {
		foreach ($cards as $card) {
			if ((int)$card['id'] === $cardId) {
				return true;
			}
		}
		return false;
	}

	private static function normalize(string $value): string {
		return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
	}

	/** @param string $subject Already translated name of the checked object */
	private static function assertExactKeys(array $value, array $expected, string $subject, IL10N $l): void {
		$actual = array_keys($value);
		sort($actual);
		sort($expected);
		if ($actual !== $expected) {
			throw new \InvalidArgumentException($l->t('Invalid %s schema.', [$subject]));
		}
	}

	private function assertDeckAvailable(): void {
		if ($this->stackService === null || $this->cardService === null || $this->stackMapper === null) {
			throw new \RuntimeException($this->l->t('Deck is not available.'));
		}
	}

	private function rows(string $table, array $columns, array $where, array $order = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)->from($table);
		foreach ($where as $column => $value) {
			$type = is_int($value) ? IQueryBuilder::PARAM_INT : IQueryBuilder::PARAM_STR;
			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value, $type)));
		}
		foreach ($order as $column) {
			$qb->addOrderBy($column);
		}
		$result = $qb->executeQuery();
		try {
			return $result->fetchAll();
		} finally {
			$result->closeCursor();
		}
	}
}
