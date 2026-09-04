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
use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChat;
use OCA\ProjectCreatorAIO\Db\ProjectDirectChatMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCA\ProjectCreatorAIO\Service\FileTreeService;
use OCA\ProjectCreatorAIO\Service\OrganizationPdfService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectAdministratorAccessService;
use OCA\ProjectCreatorAIO\Service\ProjectDeckActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectMemberResolver;
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
	public function testProjectNotesListFiltersByNoteType(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$note = new ProjectNote();
		$note->setNoteType(ProjectNote::NOTE_TYPE_DECISION);
		$noteMapper = $this->createMock(ProjectNoteMapper::class);
		$noteMapper->expects($this->once())
			->method('findPublicByProject')
			->with(42, ProjectNote::NOTE_TYPE_DECISION, 12, 12)
			->willReturn([$note]);
		$noteMapper->expects($this->once())
			->method('countPublicByProject')
			->with(42, ProjectNote::NOTE_TYPE_DECISION)
			->willReturn(13);

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			noteMapper: $noteMapper,
		);

		$result = $service->getProjectNotesList(42, 'alice', 'public', ProjectNote::NOTE_TYPE_DECISION, 2, 12);

		$this->assertSame(13, $result['total']);
		$this->assertSame(ProjectNote::NOTE_TYPE_DECISION, $result['notes'][0]['noteType']);
	}

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
			'drascivsRoles' => [],
			'drascivsRoleLabels' => [],
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

	public function testCardCommentsRequireDeckBoardReadPermission(): void {
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($this->project(42, 'alice', 10));
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			new TestCardMapper([new TestCard(1, 'Secret')]),
			new TestDeckPermissionService(['alice' => [0 => false]]),
		);

		$this->expectException(\RuntimeException::class);
		$service->getCardCommentsList(42, 'alice');
	}

	public function testCardCommentsExcludeCardsHiddenByPolicy(): void {
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($this->project(42, 'alice', 10));
		$policy = $this->createMock(CardPolicyService::class);
		$policy->expects($this->once())
			->method('assertActionLogic')
			->with($this->isInstanceOf(TestCard::class), 10, 'view', 'alice')
			->willReturn(false);
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			new TestCardMapper([new TestCard(1, 'Secret')]),
			new TestDeckPermissionService(),
			$policy,
		);

		$this->assertSame(['comments' => [], 'total' => 0], $service->getCardCommentsList(42, 'alice'));
	}

	public function testIsProjectMemberReturnsTrueForOwner(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
		);

		$this->assertTrue($service->isProjectMember($project, 'alice'));
	}

	public function testIsProjectMemberUsesMemberResolver(): void {
		$project = $this->project(42, 'alice', null);
		$alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob']);
		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->expects($this->exactly(2))
			->method('getProjectMembers')
			->with($project)
			->willReturn([$alice, $bob]);

		$service = $this->service(
			$this->createMock(ProjectMapper::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectMemberResolver: $resolver,
		);

		$this->assertTrue($service->isProjectMember($project, 'bob'));
		$this->assertFalse($service->isProjectMember($project, 'charlie'));
	}

	public function testGetOrCreateDirectChatValidation(): void {
		$projectMapper = $this->createMock(ProjectMapper::class);
		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
		);

		// Same user ID
		$this->expectException(OCSException::class);
		$this->expectExceptionCode(400);
		$service->getOrCreateDirectChat(42, 'alice', 'alice');
	}

	public function testGetOrCreateDirectChatThrowsWhenProjectNotFound(): void {
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn(null);

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
		);

		$this->expectException(OCSException::class);
		$this->expectExceptionCode(404);
		$service->getOrCreateDirectChat(42, 'alice', 'bob');
	}

	public function testGetOrCreateDirectChatThrowsWhenNotProjectMember(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice', 'getDisplayName' => 'Alice']);
		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob']);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['bob', $bob],
		]);

		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$alice]); // bob not a member

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectMemberResolver: $resolver,
		);

		$this->expectException(OCSException::class);
		$this->expectExceptionCode(403);
		$service->getOrCreateDirectChat(42, 'alice', 'bob');
	}

	public function testGetOrCreateDirectChatReturnsExistingChat(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice', 'getDisplayName' => 'Alice']);
		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob']);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['bob', $bob],
		]);

		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$alice, $bob]);

		$talkService = $this->createMock(ProjectTalkIntegrationService::class);
		$talkService->method('isAvailable')->willReturn(true);
		$talkService->method('buildConversationUrl')->with('existing-token')->willReturn('https://example.test/call/existing-token');
		// createProjectDirectConversation should NOT be called
		$talkService->expects($this->never())->method('createProjectDirectConversation');

		$existingChat = new ProjectDirectChat();
		$existingChat->setId(10);
		$existingChat->setProjectId(42);
		$existingChat->setUser1Id('alice');
		$existingChat->setUser2Id('bob');
		$existingChat->setTalkConversationToken('existing-token');

		$chatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$chatMapper->expects($this->once())
			->method('findPair')
			->with(42, 'alice', 'bob')
			->willReturn($existingChat);

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectTalkIntegrationService: $talkService,
			directChatMapper: $chatMapper,
			projectMemberResolver: $resolver,
		);

		$result = $service->getOrCreateDirectChat(42, 'alice', 'bob');

		$this->assertSame(10, $result['id']);
		$this->assertSame(42, $result['projectId']);
		$this->assertSame('existing-token', $result['talkConversationToken']);
		$this->assertSame('https://example.test/call/existing-token', $result['talkUrl']);
		$this->assertSame('bob', $result['otherUser']['id']);
		$this->assertSame('Bob', $result['otherUser']['displayName']);
	}

	public function testGetOrCreateDirectChatCreatesNewChat(): void {
		$project = $this->project(42, 'alice', null);
		$project->setName('Project Alpha');
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice', 'getDisplayName' => 'Alice']);
		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob']);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['bob', $bob],
		]);

		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$alice, $bob]);

		$talkService = $this->createMock(ProjectTalkIntegrationService::class);
		$talkService->method('isAvailable')->willReturn(true);
		$talkService->expects($this->once())
			->method('createProjectDirectConversation')
			->with('Project Alpha', 42, $alice, $bob)
			->willReturn(['token' => 'new-token', 'url' => 'https://example.test/call/new-token']);

		$createdChat = new ProjectDirectChat();
		$createdChat->setId(15);
		$createdChat->setProjectId(42);
		$createdChat->setUser1Id('alice');
		$createdChat->setUser2Id('bob');
		$createdChat->setTalkConversationToken('new-token');

		$chatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$chatMapper->expects($this->once())
			->method('findPair')
			->with(42, 'alice', 'bob')
			->willReturn(null);
		$chatMapper->expects($this->once())
			->method('createChat')
			->with(42, 'alice', 'bob', 'new-token')
			->willReturn($createdChat);

		$activityService = $this->createMock(ProjectActivityService::class);
		$activityService->expects($this->once())
			->method('recordWithActorInfo')
			->with(
				$project,
				'talk_direct_chat_created',
				'talk',
				'alice',
				'Alice',
				[
					'targetUserId' => 'bob',
					'targetDisplayName' => 'Bob',
					'conversationToken' => 'new-token',
				]
			);

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectTalkIntegrationService: $talkService,
			directChatMapper: $chatMapper,
			projectMemberResolver: $resolver,
			projectActivityService: $activityService,
		);

		$result = $service->getOrCreateDirectChat(42, 'alice', 'bob');

		$this->assertSame(15, $result['id']);
		$this->assertSame(42, $result['projectId']);
		$this->assertSame('new-token', $result['talkConversationToken']);
		$this->assertSame('https://example.test/call/new-token', $result['talkUrl']);
		$this->assertSame('bob', $result['otherUser']['id']);
	}

	public function testGetOrCreateDirectChatCleansUpRoomLostToConcurrentRequest(): void {
		$project = $this->project(42, 'alice', null);
		$project->setName('Project Alpha');
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$alice = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice', 'getDisplayName' => 'Alice']);
		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob']);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['bob', $bob],
		]);

		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$alice, $bob]);

		$concurrentChat = new ProjectDirectChat();
		$concurrentChat->setId(16);
		$concurrentChat->setProjectId(42);
		$concurrentChat->setUser1Id('alice');
		$concurrentChat->setUser2Id('bob');
		$concurrentChat->setTalkConversationToken('winning-token');

		$chatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$chatMapper->expects($this->exactly(2))
			->method('findPair')
			->with(42, 'alice', 'bob')
			->willReturnOnConsecutiveCalls(null, $concurrentChat);
		$chatMapper->expects($this->once())
			->method('createChat')
			->willThrowException(new \RuntimeException('Duplicate pair'));

		$talkService = $this->createMock(ProjectTalkIntegrationService::class);
		$talkService->method('isAvailable')->willReturn(true);
		$talkService->expects($this->once())
			->method('createProjectDirectConversation')
			->willReturn(['token' => 'losing-token', 'url' => 'https://example.test/call/losing-token']);
		$talkService->expects($this->once())
			->method('deleteConversation')
			->with('losing-token');
		$talkService->method('buildConversationUrl')
			->with('winning-token')
			->willReturn('https://example.test/call/winning-token');

		$activityService = $this->createMock(ProjectActivityService::class);
		$activityService->expects($this->never())->method('recordWithActorInfo');

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectTalkIntegrationService: $talkService,
			directChatMapper: $chatMapper,
			projectMemberResolver: $resolver,
			projectActivityService: $activityService,
		);

		$result = $service->getOrCreateDirectChat(42, 'alice', 'bob');

		$this->assertSame(16, $result['id']);
		$this->assertSame('winning-token', $result['talkConversationToken']);
	}

	public function testListUserDirectChats(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob', 'getDisplayName' => 'Bob Builder']);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('bob')->willReturn($bob);

		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$bob]);

		$chat = new ProjectDirectChat();
		$chat->setId(20);
		$chat->setProjectId(42);
		$chat->setUser1Id('alice');
		$chat->setUser2Id('bob');
		$chat->setTalkConversationToken('tok-bob');

		$chatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$chatMapper->expects($this->once())
			->method('findByProjectAndUser')
			->with(42, 'alice')
			->willReturn([$chat]);

		$talkService = $this->createMock(ProjectTalkIntegrationService::class);
		$talkService->method('buildConversationUrl')->with('tok-bob')->willReturn('https://example.test/call/tok-bob');

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$userManager,
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectTalkIntegrationService: $talkService,
			directChatMapper: $chatMapper,
			projectMemberResolver: $resolver,
		);

		$chats = $service->listUserDirectChats(42, 'alice');

		$this->assertCount(1, $chats);
		$this->assertSame(20, $chats[0]['id']);
		$this->assertSame('bob', $chats[0]['otherUser']['id']);
		$this->assertSame('Bob Builder', $chats[0]['otherUser']['displayName']);
		$this->assertSame('tok-bob', $chats[0]['talkConversationToken']);
		$this->assertSame('https://example.test/call/tok-bob', $chats[0]['talkUrl']);
	}

	public function testGetDirectChatMessages(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob']);
		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$bob]);

		$chat = new ProjectDirectChat();
		$chat->setId(20);
		$chat->setProjectId(42);
		$chat->setUser1Id('alice');
		$chat->setUser2Id('bob');
		$chat->setTalkConversationToken('token-123');

		$chatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$chatMapper->expects($this->once())
			->method('findPair')
			->with(42, 'alice', 'bob')
			->willReturn($chat);

		$talkService = $this->createMock(ProjectTalkIntegrationService::class);
		$talkService->expects($this->once())
			->method('getConversationMessages')
			->with('token-123', 50, 0)
			->willReturn([
				'messages' => [
					[
						'id' => 1,
						'actorDisplayName' => 'Alice',
						'message' => 'Hello Bob',
						'timestamp' => 1700000000,
						'messageType' => 'comment',
					],
				],
				'hasMore' => false,
				'nextOffset' => 1,
			]);

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			projectTalkIntegrationService: $talkService,
			directChatMapper: $chatMapper,
			projectMemberResolver: $resolver,
		);

		$result = $service->getDirectChatMessages(42, 'alice', 'bob', 50, 0);

		$this->assertCount(1, $result['messages']);
		$this->assertSame('Hello Bob', $result['messages'][0]['message']);
		$this->assertFalse($result['hasMore']);
	}

	public function testGetDirectChatMessagesReturnsEmptyWhenNoChat(): void {
		$project = $this->project(42, 'alice', null);
		$projectMapper = $this->createMock(ProjectMapper::class);
		$projectMapper->method('find')->with(42)->willReturn($project);

		$bob = $this->createConfiguredMock(IUser::class, ['getUID' => 'bob']);
		$resolver = $this->createMock(ProjectMemberResolver::class);
		$resolver->method('getProjectMembers')->willReturn([$bob]);

		$chatMapper = $this->createMock(ProjectDirectChatMapper::class);
		$chatMapper->expects($this->once())
			->method('findPair')
			->with(42, 'alice', 'bob')
			->willReturn(null);

		$service = $this->service(
			$projectMapper,
			$this->createMock(IGroupManager::class),
			$this->createMock(IUserManager::class),
			$this->createMock(ProjectMemberRoleMapper::class),
			$this->createMock(BoardPolicyRoleMapper::class),
			$this->createMock(BoardPolicyMembershipMapper::class),
			directChatMapper: $chatMapper,
			projectMemberResolver: $resolver,
		);

		$result = $service->getDirectChatMessages(42, 'alice', 'bob');

		$this->assertSame([], $result['messages']);
		$this->assertFalse($result['hasMore']);
		$this->assertSame(0, $result['nextOffset']);
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
			'drascivsRoles' => [],
			'drascivsRoleLabels' => [],
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
		?ProjectNoteMapper $noteMapper = null,
		?ProjectTalkIntegrationService $projectTalkIntegrationService = null,
		?ProjectDirectChatMapper $directChatMapper = null,
		?ProjectMemberResolver $projectMemberResolver = null,
		?ProjectActivityService $projectActivityService = null,
	): ProjectService {
		$service = new TestableProjectService(
			userSession: $this->createMock(IUserSession::class),
			shareManager: $this->createMock(IShareManager::class),
			boardService: null,
			deckDefaultCardsService: null,
			rootFolder: $this->createMock(IRootFolder::class),
			projectMapper: $projectMapper,
			noteMapper: $noteMapper ?? $this->createMock(ProjectNoteMapper::class),
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
			projectActivityService: $projectActivityService ?? $this->createMock(ProjectActivityService::class),
			projectDeckActivityService: $this->createMock(ProjectDeckActivityService::class),
			projectTalkIntegrationService: $projectTalkIntegrationService ?? $this->createMock(ProjectTalkIntegrationService::class),
			cardMapper: $cardMapper,
			stackService: null,
			deckPermissionService: $deckPermissionService,
			logger: $this->createMock(LoggerInterface::class),
			memberRoleMapper: $memberRoleMapper,
			policyRoleMapper: $policyRoleMapper,
			policyMembershipMapper: $policyMembershipMapper,
			cardPolicyService: $cardPolicyService ?? $this->createMock(CardPolicyService::class),
			organizationPdfService: $this->createMock(OrganizationPdfService::class),
			administratorAccessService: null,
			directChatMapper: $directChatMapper,
			projectMemberResolver: $projectMemberResolver,
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
