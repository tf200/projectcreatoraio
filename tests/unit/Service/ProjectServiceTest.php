<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\BoardPolicyMembership;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRole;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCA\ProjectCreatorAIO\Service\FileTreeService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectDeckActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\AppFramework\OCS\OCSException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectServiceTest extends TestCase {
	public function testProjectMembersIncludeDirectAndGroupFunctionalRoles(): void {
		$project = new Project();
		$project->setId(42);
		$project->setOwnerId('alice');
		$project->setBoardId('10');

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$alphaRole = $this->role(1, 'alpha');
		$zetaRole = $this->role(2, 'zeta');
		$policyRoleMapper = $this->createMock(BoardPolicyRoleMapper::class);
		$policyRoleMapper->method('findByBoard')->with(10)->willReturn([$zetaRole, $alphaRole]);

		$policyMembershipMapper = $this->createMock(BoardPolicyMembershipMapper::class);
		$policyMembershipMapper->method('findByRoles')->willReturn([
			$this->membership(1, 'user', 'alice'),
			$this->membership(1, 'group', 'project-role-team'),
			$this->membership(2, 'group', 'project-role-team'),
			$this->membership(2, 'group', 'missing-group'),
			$this->membership(2, 'user', 'outsider'),
			$this->membership(2, 'group', 'outsider-team'),
		]);

		$alice = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'alice',
			'getDisplayName' => 'Alice',
			'getEMailAddress' => 'alice@example.test',
		]);
		$outsider = $this->createConfiguredMock(IUser::class, [
			'getUID' => 'outsider',
		]);
		$projectRoleTeam = $this->createMock(IGroup::class);
		$projectRoleTeam->method('getUsers')->willReturn([$alice]);
		$outsiderTeam = $this->createMock(IGroup::class);
		$outsiderTeam->method('getUsers')->willReturn([$outsider]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnMap([
			['project-role-team', $projectRoleTeam],
			['missing-group', null],
			['outsider-team', $outsiderTeam],
		]);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['outsider', $outsider],
		]);

		$memberRoleMapper = $this->createMock(ProjectMemberRoleMapper::class);
		$memberRoleMapper->method('findByProject')->with(42)->willReturn([]);

		$service = $this->service(
			$projectMapper,
			$groupManager,
			$userManager,
			$memberRoleMapper,
			$policyRoleMapper,
			$policyMembershipMapper,
		);

		$this->assertSame([[
			'id' => 'alice',
			'displayName' => 'Alice',
			'email' => 'alice@example.test',
			'isOwner' => true,
			'drasciRoles' => [],
			'drasciRoleLabels' => [],
			'drasciRole' => null,
			'drasciRoleLabel' => 'Unassigned',
			'functionalRoleKeys' => ['alpha', 'zeta'],
		]], $service->getProjectMembers(42));
	}

	public function testProjectMembersSkipMissingUsers(): void {
		$project = new Project();
		$project->setId(42);
		$project->setOwnerId('deleted-user');
		$project->setBoardId('10');

		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->willReturn($project);
		$memberRoleMapper = $this->createMock(ProjectMemberRoleMapper::class);
		$memberRoleMapper->method('findByProject')->willReturn([]);
		$policyRoleMapper = $this->createMock(BoardPolicyRoleMapper::class);
		$policyRoleMapper->method('findByBoard')->willReturn([$this->role(1, 'alpha')]);
		$policyMembershipMapper = $this->createMock(BoardPolicyMembershipMapper::class);
		$policyMembershipMapper->method('findByRoles')->willReturn([
			$this->membership(1, 'group', 'missing-group'),
		]);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('missing-group')->willReturn(null);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('deleted-user')->willReturn(null);

		$service = $this->service(
			$projectMapper,
			$groupManager,
			$userManager,
			$memberRoleMapper,
			$policyRoleMapper,
			$policyMembershipMapper,
		);

		$this->assertSame([], $service->getProjectMembers(42));
	}

	public function testDeckAccessSummaryUsesTeamAndSelfScopesAndRoleLabels(): void {
		$project = $this->project(42, 'alice', 10);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);
		$members = [
			$this->member('alice', 'Alice', true, ['architect']),
			$this->member('bob', 'Bob', false, ['engineer']),
		];
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			new TestCardMapper([]),
			new TestDeckPermissionService(),
			$this->createMock(CardPolicyService::class),
			$members,
			[
				['key' => 'architect', 'name' => 'Architect'],
				['key' => 'engineer', 'name' => 'Engineer'],
			],
		);

		$team = $service->getDeckAccessSummary(42, 'alice', true);
		$self = $service->getDeckAccessSummary(42, 'bob', false);

		$this->assertSame('team', $team['scope']);
		$this->assertSame(['alice', 'bob'], array_column($team['members'], 'id'));
		$this->assertSame(['Architect'], $team['members'][0]['functionalRoleLabels']);
		$this->assertSame('self', $self['scope']);
		$this->assertSame(['bob'], array_column($self['members'], 'id'));
		$this->assertSame(['Engineer'], $self['members'][0]['functionalRoleLabels']);
	}

	public function testDeckAccessSummaryCombinesNativeAclAndPolicyCounts(): void {
		$project = $this->project(42, 'alice', 10);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->willReturn($project);
		$cards = [new TestCard(1, 'One'), new TestCard(2, 'Two'), new TestCard(3, 'Three')];
		$policy = $this->createMock(CardPolicyService::class);
		$policy->method('assertActionLogic')->willReturnCallback(
			static fn (object $card, int $boardId, string $action, string $userId): bool => match ($action) {
				'view', 'verify' => true,
				'move' => $card->getId() !== 3,
				'sign' => false,
			},
		);
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			new TestCardMapper($cards),
			new TestDeckPermissionService(['alice' => [0 => true, 1 => true]]),
			$policy,
			[$this->member('alice', 'Alice', true)],
		);

		$summary = $service->getDeckAccessSummary(42, 'alice', false);
		$member = $summary['members'][0];

		$this->assertSame(10, $summary['boardId']);
		$this->assertSame(3, $summary['totalCards']);
		$this->assertSame('edit', $member['boardAccess']);
		$this->assertSame(['all', 'some', 'all', 'none'], array_column($member['actions'], 'status'));
		$this->assertSame(2, $member['actions']['move']['allowed']);
		$this->assertSame(3, $member['actions']['move']['total']);
		$this->assertSame([
			['id' => 1, 'title' => 'One'],
			['id' => 2, 'title' => 'Two'],
		], $member['actions']['move']['allowedCards']);
		$this->assertSame([], $member['actions']['sign']['allowedCards']);
	}

	public function testDeckAccessSummaryNativeAclDenialSuppressesCardsAndPolicyChecks(): void {
		$project = $this->project(42, 'alice', 10);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->willReturn($project);
		$policy = $this->createMock(CardPolicyService::class);
		$policy->expects($this->never())->method('assertActionLogic');
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			new TestCardMapper([new TestCard(1, 'Secret')]),
			new TestDeckPermissionService(['alice' => [0 => false, 1 => false]]),
			$policy,
			[$this->member('alice', 'Alice', true)],
		);

		$member = $service->getDeckAccessSummary(42, 'alice', false)['members'][0];

		$this->assertSame('none', $member['boardAccess']);
		foreach ($member['actions'] as $action) {
			$this->assertSame(0, $action['allowed']);
			$this->assertSame('none', $action['status']);
			$this->assertSame([], $action['allowedCards']);
		}
	}

	public function testDeckAccessSummaryWithoutBoardDoesNotRequireDeck(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->willReturn($project);
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			members: [$this->member('alice', 'Alice', true)],
		);

		$summary = $service->getDeckAccessSummary(42, 'alice', false);

		$this->assertNull($summary['boardId']);
		$this->assertSame(0, $summary['totalCards']);
		$this->assertSame('none', $summary['members'][0]['boardAccess']);
	}

	public function testDeckAccessSummaryWithoutDeckDependenciesIsControlled(): void {
		$project = $this->project(42, 'alice', 10);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->willReturn($project);
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			members: [$this->member('alice', 'Alice', true)],
		);

		$this->expectException(OCSException::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Deck access information is unavailable');

		$service->getDeckAccessSummary(42, 'alice', false);
	}

	private function role(int $id, string $key): BoardPolicyRole {
		$role = new BoardPolicyRole();
		$role->setId($id);
		$role->setRoleKey($key);
		return $role;
	}

	private function membership(int $roleId, string $type, string $id): BoardPolicyMembership {
		$membership = new BoardPolicyMembership();
		$membership->setRoleId($roleId);
		$membership->setParticipantType($type);
		$membership->setParticipantId($id);
		return $membership;
	}

	private function project(int $id, string $ownerId, ?int $boardId): Project {
		$project = new Project();
		$project->setId($id);
		$project->setOwnerId($ownerId);
		$project->setBoardId($boardId === null ? null : (string) $boardId);
		return $project;
	}

	/** @return array<string, mixed> */
	private function member(string $id, string $displayName, bool $isOwner, array $functionalRoleKeys = []): array {
		return [
			'id' => $id,
			'displayName' => $displayName,
			'email' => $id . '@example.test',
			'isOwner' => $isOwner,
			'drasciRoles' => [],
			'drasciRoleLabels' => [],
			'drasciRole' => null,
			'drasciRoleLabel' => 'Unassigned',
			'functionalRoleKeys' => $functionalRoleKeys,
		];
	}

	private function service(
		ProjectMapper $projectMapper,
		IGroupManager $groupManager,
		IUserManager $userManager,
		ProjectMemberRoleMapper $memberRoleMapper,
		BoardPolicyRoleMapper $policyRoleMapper,
		BoardPolicyMembershipMapper $policyMembershipMapper,
		?object $cardMapper = null,
		?object $deckPermissionService = null,
		?CardPolicyService $cardPolicyService = null,
		?array $members = null,
		array $functionalRoles = [],
	): ProjectService {
		$service = new TestableProjectService(
			userSession: $this->createMock(IUserSession::class),
			shareManager: $this->createMock(IShareManager::class),
			boardService: null,
			deckDefaultCardsService: null,
			rootFolder: $this->createMock(IRootFolder::class),
			projectMapper: $projectMapper,
			noteMapper: $this->createMock(ProjectNoteMapper::class),
			fileTreeService: $this->createMock(FileTreeService::class),
			organizationMapper: null,
			organizationUserMapper: null,
			subscriptionMapper: null,
			planMapper: null,
			groupManager: $groupManager,
			folderManager: null,
			db: $this->createMock(IDBConnection::class),
			userManager: $userManager,
			folderStorageManager: null,
			changeHelper: null,
			projectNotificationService: $this->createMock(ProjectNotificationService::class),
			projectActivityService: $this->createMock(ProjectActivityService::class),
			projectDeckActivityService: $this->createMock(ProjectDeckActivityService::class),
			projectTalkIntegrationService: $this->createMock(ProjectTalkIntegrationService::class),
			cardMapper: $cardMapper,
			stackService: null,
			deckPermissionService: $deckPermissionService,
			logger: $this->createMock(LoggerInterface::class),
			memberRoleMapper: $memberRoleMapper,
			policyRoleMapper: $policyRoleMapper,
			policyMembershipMapper: $policyMembershipMapper,
			cardPolicyService: $cardPolicyService ?? $this->createMock(CardPolicyService::class),
		);
		$service->members = $members;
		$service->functionalRoles = $functionalRoles;
		return $service;
	}
}

final class TestableProjectService extends ProjectService {
	public ?array $members = null;
	public array $functionalRoles = [];

	public function getProjectMembers(int $projectId): array {
		return $this->members ?? parent::getProjectMembers($projectId);
	}

	public function getProjectFunctionalRoles(int $projectId): array {
		return $this->members === null ? parent::getProjectFunctionalRoles($projectId) : $this->functionalRoles;
	}
}

final class TestCardMapper {
	public function __construct(private readonly array $cards) {
	}

	public function findAllByBoardId(int $boardId): array {
		return $this->cards;
	}
}

final class TestDeckPermissionService {
	public function __construct(private readonly array $permissions = []) {
	}

	public function checkPermission(?object $mapper, int $boardId, int $permission, string $userId): bool {
		if (($this->permissions[$userId][$permission] ?? true) !== true) {
			throw new \RuntimeException('Permission denied');
		}

		return true;
	}
}

final class TestCard {
	public function __construct(
		private readonly int $id,
		private readonly string $title,
	) {
	}

	public function getId(): int {
		return $this->id;
	}

	public function getTitle(): string {
		return $this->title;
	}
}
