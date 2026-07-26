<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Controller;

use OCA\ProjectCreatorAIO\Controller\PolicyApiController;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasci;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultDrasciMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicySetting;
use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyOverrideMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PolicyApiControllerTest extends TestCase {
	private IRequest $request;
	private BoardPolicySettingMapper $settingMapper;
	private BoardPolicyRoleMapper $roleMapper;
	private BoardPolicyDefaultDrasciMapper $defaultDrasciMapper;
	private CardPolicyService $policyService;
	private IDBConnection $db;
	private PolicyApiController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->settingMapper = $this->createMock(BoardPolicySettingMapper::class);
		$this->roleMapper = $this->createMock(BoardPolicyRoleMapper::class);
		$this->defaultDrasciMapper = $this->createMock(BoardPolicyDefaultDrasciMapper::class);
		$this->policyService = $this->createMock(CardPolicyService::class);
		$this->db = $this->createMock(IDBConnection::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('owner');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$this->policyService->method('isBypassUser')->with(10, 'owner')->willReturn(true);

		$this->controller = new PolicyApiController(
			'projectcreatoraio',
			$this->request,
			$this->settingMapper,
			$this->roleMapper,
			$this->createMock(BoardPolicyMembershipMapper::class),
			$this->defaultDrasciMapper,
			$this->createMock(BoardPolicyDefaultRoleMapper::class),
			$this->createMock(CardPolicyMapper::class),
			$this->createMock(CardPolicyOverrideMapper::class),
			$this->createMock(CardPolicyRoleMapper::class),
			$this->policyService,
			$userSession,
			$this->db,
			null,
		);
	}

	public function testEnableIsIdempotentForEnabledV2Board(): void {
		$settings = $this->v2Settings();
		$this->settingMapper->expects($this->once())->method('findByBoard')->with(10)->willReturn($settings);
		$this->settingMapper->expects($this->never())->method('incrementRevision');
		$this->settingMapper->expects($this->never())->method('update');
		$this->roleMapper->expects($this->never())->method('findByBoardAndKey');
		$this->defaultDrasciMapper->expects($this->never())->method('findByBoard');
		$this->policyService->expects($this->never())->method('preserveLegacyCardPolicyOverrides');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$response = $this->controller->enableCardPolicy(10);

		$this->assertSame($settings, $response->getData());
	}

	public function testEnableWithoutSettingsPreservesLegacyCardPolicies(): void {
		$createdSettings = null;
		$this->settingMapper->expects($this->exactly(2))
			->method('findByBoard')
			->with(10)
			->willReturnCallback(static function () use (&$createdSettings): ?BoardPolicySetting {
				return $createdSettings;
			});
		$this->settingMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (BoardPolicySetting $settings) use (&$createdSettings): BoardPolicySetting {
				$createdSettings = $settings;
				return $settings;
			});

		$this->roleMapper->method('findByBoardAndKey')
			->willReturnCallback(static function (int $boardId, string $roleKey): BoardPolicyRole {
				$role = new BoardPolicyRole();
				$role->setBoardId($boardId);
				$role->setRoleKey($roleKey);
				return $role;
			});
		$existingDefault = new BoardPolicyDefaultDrasci();
		$this->defaultDrasciMapper->method('findByBoard')->with(10)->willReturn([$existingDefault]);
		$this->policyService->expects($this->once())->method('preserveLegacyCardPolicyOverrides')->with(10);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$response = $this->controller->enableCardPolicy(10);

		$this->assertSame(2, $createdSettings?->getPolicyVersion());
		$this->assertSame(1, $createdSettings?->getRevision());
		$this->assertSame($createdSettings, $response->getData());
	}

	public function testCreateFunctionalRoleIncrementsV2RevisionTransactionally(): void {
		$settings = $this->v2Settings();
		$this->settingMapper->method('findByBoard')->with(10)->willReturn($settings);
		$this->settingMapper->expects($this->once())
			->method('incrementRevision')
			->with(10, null)
			->willReturnCallback(static function () use ($settings): bool {
				$settings->setRevision(8);
				return true;
			});
		$this->roleMapper->method('findByBoardAndKey')->with(10, 'reviewer')->willReturn(null);
		$this->roleMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (BoardPolicyRole $role): BoardPolicyRole {
				$role->setId(55);
				return $role;
			});
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$data = $this->controller->createCardPolicyRole(10, 'reviewer', 'Reviewer')->getData();

		$this->assertSame(55, $data['id']);
		$this->assertSame('reviewer', $data['roleKey']);
		$this->assertSame(8, $data['revision']);
	}

	private function v2Settings(): BoardPolicySetting {
		$settings = new BoardPolicySetting();
		$settings->setBoardId(10);
		$settings->setPermissionMode('card_policy');
		$settings->setPolicyVersion(2);
		$settings->setRevision(7);
		return $settings;
	}
}
