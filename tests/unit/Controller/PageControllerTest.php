<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Controller;

use OCA\ProjectCreatorAIO\Controller\PageController;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase {
	public function testAppAddressesServeTheNewInterface(): void {
		$controller = $this->controller();

		self::assertSame('new', $this->template($controller->index()));
		self::assertSame('new', $this->template($controller->project()));
		self::assertSame('new', $this->template($controller->create()));
	}

	public function testOldInterfaceIsOnlyForAdmins(): void {
		self::assertSame('index', $this->template($this->controller(admin: true)->legacy()));
		self::assertSame('index', $this->template($this->controller(admin: true)->legacyProject('21')));

		self::assertSame('/apps/projectcreatoraio/', $this->redirect($this->controller()->legacy()));
		self::assertSame('/apps/projectcreatoraio/21?tab=deck', $this->redirect($this->controller(['tab' => 'deck'])->legacyProject('21')));
	}

	public function testNewAddressesRedirectToTheAppsOwn(): void {
		self::assertSame('/apps/projectcreatoraio/', $this->redirect($this->controller()->newIndex()));
		self::assertSame('/apps/projectcreatoraio/21?tab=planning', $this->redirect($this->controller(['tab' => 'planning'])->newProject('21')));
		self::assertSame('/apps/projectcreatoraio/21', $this->redirect($this->controller()->newProject('21')));
		self::assertSame('/apps/projectcreatoraio/21?tab=members', $this->redirect($this->controller()->newProjectTab('21', 'members')));
		self::assertSame('/apps/projectcreatoraio/create', $this->redirect($this->controller()->newCreate()));
	}

	public function testOldInterfaceLinksLandInTheNewOne(): void {
		self::assertSame('/apps/projectcreatoraio/create', $this->redirect($this->controller(['create' => '1'])->index()));
		self::assertSame('/apps/projectcreatoraio/7?tab=whiteboard', $this->redirect($this->controller(['popout' => 'whiteboard', 'projectId' => '7'])->index()));
		// Admins still get the old interface's whiteboard pop-out window.
		self::assertSame('index', $this->template($this->controller(['popout' => 'whiteboard', 'projectId' => '7'], admin: true)->index()));
	}

	public function testRedirectsCarryOnlyAProjectIdAndAPlainTabName(): void {
		self::assertSame('/apps/projectcreatoraio/', $this->redirect($this->controller(['popout' => 'whiteboard', 'projectId' => '7/../x'])->index()));
		self::assertSame('/apps/projectcreatoraio/', $this->redirect($this->controller(['popout' => 'whiteboard', 'projectId' => ['7']])->index()));
		self::assertSame('/apps/projectcreatoraio/21', $this->redirect($this->controller(['tab' => 'x&y=https://evil.test'])->newProject('21')));
		self::assertSame('/apps/projectcreatoraio/21', $this->redirect($this->controller(['tab' => ['deck']])->newProject('21')));
	}

	public function testSignedOutVisitorsGetNotFound(): void {
		self::assertInstanceOf(NotFoundResponse::class, $this->controller(user: false)->index());
	}

	private function controller(array $params = [], bool $admin = false, bool $user = true): PageController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static fn (string $key, $default = null) => $params[$key] ?? $default);

		$session = $this->createMock(IUserSession::class);
		if ($user) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn('alice');
			$session->method('getUser')->willReturn($account);
		} else {
			$session->method('getUser')->willReturn(null);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->with('alice')->willReturn($admin);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args = []) => match ($route) {
			'projectcreatoraio.page.index' => '/apps/projectcreatoraio/',
			'projectcreatoraio.page.create' => '/apps/projectcreatoraio/create',
			'projectcreatoraio.page.project' => '/apps/projectcreatoraio/' . $args['projectId'],
		});

		return new PageController('projectcreatoraio', $request, $session, $this->createMock(IEventDispatcher::class), $groups, $urls);
	}

	private function template(mixed $response): string {
		self::assertInstanceOf(TemplateResponse::class, $response);
		return $response->getTemplateName();
	}

	private function redirect(mixed $response): string {
		self::assertInstanceOf(RedirectResponse::class, $response);
		return $response->getRedirectURL();
	}
}
