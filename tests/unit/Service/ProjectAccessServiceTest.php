<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAccessService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;

final class ProjectAccessServiceTest extends TestCase {
	private const ORG = 5;
	private const OTHER_ORG = 9;

	/** @var array<string,array{organization_id:int,role:string}> */
	private array $memberships = [
		'orgadmin' => ['organization_id' => self::ORG, 'role' => 'admin'],
		'member' => ['organization_id' => self::ORG, 'role' => 'member'],
		'outsider' => ['organization_id' => self::ORG, 'role' => 'member'],
		'foreignadmin' => ['organization_id' => self::OTHER_ORG, 'role' => 'admin'],
		'foreignmember' => ['organization_id' => self::OTHER_ORG, 'role' => 'member'],
	];

	/** @var array<string,string[]> */
	private array $groups = [
		'project-group' => ['member', 'foreignmember'],
	];

	private ProjectMapper $projectMapper;
	private IGroupManager $groupManager;
	private OrganizationUserMapper $organizationUserMapper;

	protected function setUp(): void {
		parent::setUp();

		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin');
		$this->groupManager->method('isInGroup')->willReturnCallback(
			fn (string $uid, string $gid): bool => in_array($uid, $this->groups[$gid] ?? [], true),
		);
		$this->organizationUserMapper = $this->createMock(OrganizationUserMapper::class);
		$this->organizationUserMapper->method('getOrganizationMembership')->willReturnCallback(
			fn (string $uid): ?array => $this->memberships[$uid] ?? null,
		);
	}

	private function service(bool $withOrganizationApp = true): ProjectAccessService {
		return new ProjectAccessService(
			$this->projectMapper,
			$this->groupManager,
			$withOrganizationApp ? $this->organizationUserMapper : null,
		);
	}

	private function project(string $groupGid = 'project-group'): Project {
		$project = new Project();
		$project->setId(39);
		$project->setOrganizationId(self::ORG);
		$project->setProjectGroupGid($groupGid);
		return $project;
	}

	public static function viewCases(): array {
		return [
			'global admin' => ['admin', true],
			'org admin of the project org' => ['orgadmin', true],
			'org member in the project group' => ['member', true],
			'org member outside the project group' => ['outsider', false],
			'admin of another org' => ['foreignadmin', false],
			'other-org user in the project group' => ['foreignmember', false],
			'user without organization' => ['nobody', false],
		];
	}

	/** @dataProvider viewCases */
	public function testCanView(string $uid, bool $expected): void {
		$this->assertSame($expected, $this->service()->canView($uid, $this->project()));
	}

	public function testAssertCanViewWithoutOrganizationIsForbidden(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('You are not assigned to an organization');
		$this->service()->assertCanView('nobody', $this->project());
	}

	public function testAssertCanViewHidesProjectsOfOtherOrganizations(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->service()->assertCanView('foreignadmin', $this->project());
	}

	public function testAssertCanViewHidesProjectFromNonMembers(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->service()->assertCanView('outsider', $this->project());
	}

	public function testProjectWithoutGroupIsVisibleOnlyToAdmins(): void {
		$service = $this->service();
		$project = $this->project('');

		$this->assertTrue($service->canView('orgadmin', $project));
		$this->assertFalse($service->canView('member', $project));
	}

	public function testWithoutOrganizationAppTheProjectGroupDecides(): void {
		$service = $this->service(false);

		$this->assertTrue($service->canView('admin', $this->project()));
		$this->assertTrue($service->canView('foreignmember', $this->project()));
		$this->assertFalse($service->canView('orgadmin', $this->project()));
	}

	public static function administerCases(): array {
		return [
			'global admin' => ['admin', true],
			'org admin of the project org' => ['orgadmin', true],
			'org member in the project group' => ['member', false],
			'admin of another org' => ['foreignadmin', false],
			'user without organization' => ['nobody', false],
		];
	}

	/** @dataProvider administerCases */
	public function testCanAdminister(string $uid, bool $expected): void {
		$this->assertSame($expected, $this->service()->canAdminister($uid, $this->project()));
	}

	public function testOrganizationHelpers(): void {
		$service = $this->service();

		$this->assertTrue($service->belongsToOrganization('member', self::ORG));
		$this->assertFalse($service->belongsToOrganization('member', self::OTHER_ORG));
		$this->assertFalse($service->belongsToOrganization('nobody', self::ORG));
		$this->assertTrue($service->isOrganizationAdmin('orgadmin', self::ORG));
		$this->assertFalse($service->isOrganizationAdmin('member', self::ORG));
		$this->assertFalse($service->isOrganizationAdmin('foreignadmin', self::ORG));
		$this->assertNull($this->service(false)->getOrganizationMembership('member'));
	}

	public function testEligibleProjectMembersBelongToTheProjectOrganization(): void {
		$service = $this->service();

		$this->assertTrue($service->isEligibleProjectMember('member', $this->project()));
		$this->assertTrue($service->isEligibleProjectMember('outsider', $this->project()));
		$this->assertFalse($service->isEligibleProjectMember('foreignmember', $this->project()));
		$this->assertFalse($service->isEligibleProjectMember('nobody', $this->project()));
		$this->assertTrue($this->service(false)->isEligibleProjectMember('nobody', $this->project()));
	}

	public function testManageOrganizationAllowsGlobalAndOrganizationAdmins(): void {
		$service = $this->service();

		$service->assertCanManageOrganization('admin', self::ORG, 'admin required');
		$service->assertCanManageOrganization('orgadmin', self::ORG, 'admin required');
		$this->addToAssertionCount(2);
	}

	public function testManageOrganizationRejectsMembersWithGivenMessage(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('admin required');
		$this->service()->assertCanManageOrganization('member', self::ORG, 'admin required');
	}

	public function testManageOrganizationHidesOtherOrganizations(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->service()->assertCanManageOrganization('foreignadmin', self::ORG, 'admin required');
	}

	public function testManageOrganizationRequiresOrganizationApp(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->expectExceptionMessage('Organization management is not available');
		$this->service(false)->assertCanManageOrganization('orgadmin', self::ORG, 'admin required');
	}

	public function testAccessibleProjectsForGlobalAdmin(): void {
		$all = [$this->project()];
		$this->projectMapper->expects($this->once())->method('list')->willReturn($all);

		$this->assertSame($all, $this->service()->getAccessibleProjects('admin'));
	}

	public function testAccessibleProjectsForOrganizationAdmin(): void {
		$orgProjects = [$this->project()];
		$this->projectMapper->expects($this->once())->method('findByOrganizationId')->with(self::ORG)->willReturn($orgProjects);

		$this->assertSame($orgProjects, $this->service()->getAccessibleProjects('orgadmin'));
	}

	public function testAccessibleProjectsForMember(): void {
		$own = [$this->project()];
		$this->projectMapper->expects($this->once())->method('findByUserIdAndOrganizationId')->with('member', self::ORG)->willReturn($own);

		$this->assertSame($own, $this->service()->getAccessibleProjects('member'));
	}

	public function testAccessibleProjectsWithoutOrganizationIsForbidden(): void {
		$this->expectException(OCSForbiddenException::class);
		$this->service()->getAccessibleProjects('nobody');
	}
}
