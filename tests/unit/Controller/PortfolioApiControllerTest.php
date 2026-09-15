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
