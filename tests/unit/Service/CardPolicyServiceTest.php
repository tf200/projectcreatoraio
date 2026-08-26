<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasci;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasciMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
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
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRole;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCP\IGroupManager;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class CardPolicyServiceTest extends TestCase {
	private BoardPolicySettingMapper $settingMapper;
	private BoardPolicyRoleMapper $roleMapper;
	private BoardPolicyMembershipMapper $membershipMapper;
	private BoardPolicyDefaultDrasciMapper $defaultDrasciMapper;
	private CardPolicyMapper $cardPolicyMapper;
	private CardPolicyOverrideMapper $overrideMapper;
	private CardPolicyRoleMapper $cardPolicyRoleMapper;
	private ProjectMapper $projectMapper;
	private ProjectMemberRoleMapper $memberRoleMapper;
	private IGroupManager $groupManager;
	private CardPolicyService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->settingMapper = $this->createMock(BoardPolicySettingMapper::class);
		$this->roleMapper = $this->createMock(BoardPolicyRoleMapper::class);
		$this->membershipMapper = $this->createMock(BoardPolicyMembershipMapper::class);
		$this->defaultDrasciMapper = $this->createMock(BoardPolicyDefaultDrasciMapper::class);
		$this->cardPolicyMapper = $this->createMock(CardPolicyMapper::class);
		$this->overrideMapper = $this->createMock(CardPolicyOverrideMapper::class);
		$this->cardPolicyRoleMapper = $this->createMock(CardPolicyRoleMapper::class);
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->memberRoleMapper = $this->createMock(ProjectMemberRoleMapper::class);

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $userId): bool => $userId === 'admin');

		$this->service = new CardPolicyService(
			$this->settingMapper,
			$this->roleMapper,
			$this->membershipMapper,
			$this->defaultDrasciMapper,
			$this->createMock(BoardPolicyDefaultRoleMapper::class),
			$this->cardPolicyMapper,
			$this->overrideMapper,
			$this->cardPolicyRoleMapper,
			$this->projectMapper,
			$this->memberRoleMapper,
			$this->createMock(IDBConnection::class),
			$this->groupManager,
			$this->createMock(IUserManager::class),
			null,
			null,
			null,
		);
	}

	public function testV2UsesDrasciDefaultWhenActionHasNoOverride(): void {
		$this->configureV2Board();
		$this->cardPolicyMapper->method('findByCard')->willReturn(null);

		$default = new BoardPolicyDefaultDrasci();
		$default->setDrasciRole('signer');
		$this->defaultDrasciMapper->expects($this->once())
			->method('findByBoardAndAction')
			->with(10, 'sign')
			->willReturn([$default]);

		$memberRole = new ProjectMemberRole();
		$memberRole->setDrasciRole('signer');
		$this->memberRoleMapper->method('findByProjectAndUser')->with(20, 'alice')->willReturn([$memberRole]);

		$this->assertTrue($this->service->assertActionLogic($this->card(30), 10, 'sign', 'alice'));
	}

	public function testV2CardPolicyWithoutActionMarkerInheritsDrasciDefault(): void {
		$this->configureV2Board();

		$policy = new CardPolicy();
		$policy->setId(40);
		$policy->setBoardId(10);
		$this->cardPolicyMapper->method('findByCard')->with(30)->willReturn($policy);
		$this->overrideMapper->method('findByPolicyAndAction')->with(40, 'sign')->willReturn(null);

		$default = new BoardPolicyDefaultDrasci();
		$default->setDrasciRole('accountable');
		$this->defaultDrasciMapper->method('findByBoardAndAction')->with(10, 'sign')->willReturn([$default]);

		$memberRole = new ProjectMemberRole();
		$memberRole->setDrasciRole('accountable');
		$this->memberRoleMapper->method('findByProjectAndUser')->with(20, 'alice')->willReturn([$memberRole]);

		$this->assertTrue($this->service->assertActionLogic($this->card(30), 10, 'sign', 'alice'));
	}

	public function testV2FunctionalOverrideReplacesDrasciDefault(): void {
		$this->configureV2Board();
		$this->configureOverride([]);
		$this->defaultDrasciMapper->expects($this->never())->method('findByBoardAndAction');

		$this->assertFalse($this->service->assertActionLogic($this->card(30), 10, 'sign', 'alice'));
	}

	public function testV2DrasciDefaultAllowsAnyMatchingMemberRole(): void {
		$this->configureV2Board();
		$this->cardPolicyMapper->method('findByCard')->willReturn(null);

		$default = new BoardPolicyDefaultDrasci();
		$default->setDrasciRole('accountable');
		$this->defaultDrasciMapper->method('findByBoardAndAction')->with(10, 'sign')->willReturn([$default]);

		$consulted = new ProjectMemberRole();
		$consulted->setDrasciRole('consulted');
		$accountable = new ProjectMemberRole();
		$accountable->setDrasciRole('accountable');
		$this->memberRoleMapper->method('findByProjectAndUser')
			->with(20, 'alice')
			->willReturn([$consulted, $accountable]);

		$this->assertTrue($this->service->assertActionLogic($this->card(30), 10, 'sign', 'alice'));
	}

	public function testV2FunctionalOverrideAllowsMatchingMembership(): void {
		$this->configureV2Board();

		$membership = new BoardPolicyMembership();
		$membership->setRoleId(50);
		$membership->setParticipantType('user');
		$membership->setParticipantId('alice');
		$this->configureOverride([$membership]);

		$this->assertTrue($this->service->assertActionLogic($this->card(30), 10, 'sign', 'alice'));
	}

	public function testV2ExplicitEmptyOverrideDeniesEveryone(): void {
		$this->configureV2Board();
		$this->configureOverride([], false);

		$this->assertFalse($this->service->assertActionLogic($this->card(30), 10, 'sign', 'alice'));
	}

	public function testCapabilitiesDefaultToAllowedWithoutPolicy(): void {
		$this->assertSame([
			'canMove' => true,
			'canSign' => true,
			'canVerify' => true,
		], $this->service->getCapabilities($this->cardOnBoard(30, 10), 'alice'));
	}

	public function testCapabilitiesEvaluateEachPolicyAction(): void {
		$this->configureV2Board();
		$this->cardPolicyMapper->method('findByCard')->willReturn(null);

		$defaults = [];
		foreach (['move' => 'responsible', 'sign' => 'accountable', 'verify' => 'responsible'] as $action => $roleKey) {
			$default = new BoardPolicyDefaultDrasci();
			$default->setDrasciRole($roleKey);
			$defaults[$action] = [$default];
		}
		$this->defaultDrasciMapper->method('findByBoardAndAction')
			->willReturnCallback(static fn (int $boardId, string $action): array => $defaults[$action]);

		$memberRole = new ProjectMemberRole();
		$memberRole->setDrasciRole('accountable');
		$this->memberRoleMapper->method('findByProjectAndUser')->with(20, 'alice')->willReturn([$memberRole]);

		$this->assertSame([
			'canMove' => false,
			'canSign' => true,
			'canVerify' => false,
		], $this->service->getCapabilities($this->cardOnBoard(30, 10), 'alice'));
	}

	public function testCapabilitiesAllowBypassUser(): void {
		$settings = new BoardPolicySetting();
		$settings->setPermissionMode('card_policy');
		$settings->setPolicyVersion(2);
		$this->settingMapper->method('findByBoard')->with(10)->willReturn($settings);
		$this->defaultDrasciMapper->expects($this->never())->method('findByBoardAndAction');

		$this->assertSame([
			'canMove' => true,
			'canSign' => true,
			'canVerify' => true,
		], $this->service->getCapabilities($this->cardOnBoard(30, 10), 'admin'));
	}

	public function testIdentifiesCombiProjectCard(): void {
		$project = new Project();
		$project->setType(0);
		$this->projectMapper->method('findByBoardId')->with(10)->willReturn($project);

		$this->assertTrue($this->service->isCombiProjectCard($this->cardOnBoard(30, 10)));
	}

	public function testRejectsOtherProjectTypesAsCombi(): void {
		$project = new Project();
		$project->setType(1);
		$this->projectMapper->method('findByBoardId')->with(10)->willReturn($project);

		$this->assertFalse($this->service->isCombiProjectCard($this->cardOnBoard(30, 10)));
	}

	public function testLegacyUpgradeCreatesOneMarkerPerPopulatedValidAction(): void {
		$policyWithRoles = new CardPolicy();
		$policyWithRoles->setId(40);
		$policyWithRoles->setBoardId(10);
		$emptyPolicy = new CardPolicy();
		$emptyPolicy->setId(41);
		$emptyPolicy->setBoardId(10);
		$this->cardPolicyMapper->expects($this->once())
			->method('findByBoard')
			->with(10)
			->willReturn([$policyWithRoles, $emptyPolicy]);

		$relations = [];
		foreach (['move', 'move', 'sign', 'archive'] as $action) {
			$relation = new CardPolicyRole();
			$relation->setCardPolicyId(40);
			$relation->setAction($action);
			$relation->setRoleId(50);
			$relations[] = $relation;
		}
		$this->cardPolicyRoleMapper->expects($this->exactly(2))
			->method('findByPolicy')
			->willReturnMap([
				[40, $relations],
				[41, []],
			]);

		$existingSignMarker = new CardPolicyOverride();
		$existingSignMarker->setCardPolicyId(40);
		$existingSignMarker->setAction('sign');
		$this->overrideMapper->expects($this->exactly(2))
			->method('findByPolicyAndAction')
			->willReturnMap([
				[40, 'move', null],
				[40, 'sign', $existingSignMarker],
			]);
		$this->overrideMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static fn (CardPolicyOverride $override): bool =>
				$override->getCardPolicyId() === 40 && $override->getAction() === 'move'));

		$this->service->preserveLegacyCardPolicyOverrides(10);
	}

	private function configureV2Board(): void {
		$settings = new BoardPolicySetting();
		$settings->setPermissionMode('card_policy');
		$settings->setPolicyVersion(2);
		$this->settingMapper->method('findByBoard')->with(10)->willReturn($settings);

		$project = new Project();
		$project->setId(20);
		$project->setOwnerId('owner');
		$this->projectMapper->method('findByBoardId')->with(10)->willReturn($project);
	}

	/** @param BoardPolicyMembership[] $memberships */
	private function configureOverride(array $memberships, bool $withRole = true): void {
		$policy = new CardPolicy();
		$policy->setId(40);
		$policy->setBoardId(10);
		$this->cardPolicyMapper->method('findByCard')->with(30)->willReturn($policy);

		$override = new CardPolicyOverride();
		$override->setCardPolicyId(40);
		$override->setAction('sign');
		$this->overrideMapper->method('findByPolicyAndAction')->with(40, 'sign')->willReturn($override);

		$role = new BoardPolicyRole();
		$role->setId(50);
		$role->setBoardId(10);
		$role->setRoleKey('grid_operator');
		$this->roleMapper->method('findByBoard')->with(10)->willReturn([$role]);

		$relations = [];
		if ($withRole) {
			$relation = new CardPolicyRole();
			$relation->setCardPolicyId(40);
			$relation->setAction('sign');
			$relation->setRoleId(50);
			$relations[] = $relation;
			$this->membershipMapper->method('findByRoles')->with([50])->willReturn($memberships);
		}
		$this->cardPolicyRoleMapper->method('findByPolicyAndAction')->with(40, 'sign')->willReturn($relations);
	}

	private function card(int $id): object {
		return new class($id) {
			public function __construct(private readonly int $id) {
			}

			public function getId(): int {
				return $this->id;
			}
		};
	}

	private function cardOnBoard(int $id, int $boardId): object {
		return new class($id, $boardId) {
			public function __construct(private readonly int $id, private readonly int $boardId) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getRelatedBoard(): object {
				return new class($this->boardId) {
					public function __construct(private readonly int $id) {
					}

					public function getId(): int {
						return $this->id;
					}
				};
			}
		};
	}
}
