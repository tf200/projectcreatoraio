<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Controller;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Controller\PortfolioApiController;
use OCA\ProjectCreatorAIO\Service\ProjectPortfolioService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PortfolioApiControllerTest extends TestCase {
	public function testCompletionUsesAuthenticatedAdministratorsOrganization(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('org-admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userMapper = $this->createMock(OrganizationUserMapper::class);
		$userMapper->method('getOrganizationMembership')->with('org-admin')->willReturn([
			'organization_id' => 42,
			'role' => 'admin',
		]);
		$service = $this->createMock(ProjectPortfolioService::class);
		$service->expects($this->once())->method('getCompletion')->with(42)->willReturn(['trackedProjects' => 3]);

		$response = $this->controller($userSession, $userMapper, $service)->completion();

		self::assertSame(['trackedProjects' => 3], $response->getData());
	}

	public function testCompletionRejectsOrganizationMember(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('member');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userMapper = $this->createMock(OrganizationUserMapper::class);
		$userMapper->method('getOrganizationMembership')->willReturn([
			'organization_id' => 42,
			'role' => 'member',
		]);

		$this->expectException(OCSForbiddenException::class);
		$this->controller($userSession, $userMapper, $this->createMock(ProjectPortfolioService::class))->completion();
	}

	public function testCapacityUsesQueryParametersAndOrganizationAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('org-admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userMapper = $this->createMock(OrganizationUserMapper::class);
		$userMapper->method('getOrganizationMembership')->willReturn(['organization_id' => 42, 'role' => 'admin']);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['teamId', null, '9'],
			['weekStart', null, '2026-09-16'],
		]);
		$service = $this->createMock(ProjectPortfolioService::class);
		$service->expects($this->once())->method('getCapacity')->with(42, 9, '2026-09-16')->willReturn(['weeks' => []]);

		$controller = new PortfolioApiController('projectcreatoraio', $request, $userSession, $userMapper, $service);
		self::assertSame(['weeks' => []], $controller->capacity()->getData());
	}

	public function testTableUsesQueryParametersAndOrganizationAdmin(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('org-admin');
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$userMapper = $this->createMock(OrganizationUserMapper::class);
		$userMapper->method('getOrganizationMembership')->willReturn(['organization_id' => 42, 'role' => 'admin']);
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([
			['scope', null, 'team'],
			['teamId', null, '9'],
			['weekStart', null, '2026-09-14'],
		]);
		$service = $this->createMock(ProjectPortfolioService::class);
		$service->expects($this->once())->method('getTableOverview')->with(42, '2026-09-14', 9, 'team', null)->willReturn(['projects' => []]);

		$controller = new PortfolioApiController('projectcreatoraio', $request, $userSession, $userMapper, $service);
		self::assertSame(['projects' => []], $controller->table()->getData());
	}

	private function controller(
		IUserSession $userSession,
		OrganizationUserMapper $userMapper,
		ProjectPortfolioService $service,
	): PortfolioApiController {
		return new PortfolioApiController(
			'projectcreatoraio',
			$this->createMock(IRequest::class),
			$userSession,
			$userMapper,
			$service,
		);
	}
}
