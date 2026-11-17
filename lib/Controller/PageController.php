<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The project pages. The new interface ('new' template) is served at the app's
 * own addresses; the old interface ('index' template) only at /legacy, for
 * Nextcloud admins. Addresses from before the switch redirect to their new place.
 *
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IEventDispatcher $eventDispatcher,
		private IGroupManager $groupManager,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse|NotFoundResponse|RedirectResponse {
		// The old interface's links: its create form and its whiteboard pop-out window.
		if ((string) $this->request->getParam('create', '') === '1') {
			return $this->redirectTo('projectcreatoraio.page.create');
		}
		if ((string) $this->request->getParam('popout', '') === 'whiteboard') {
			if ($this->isAdmin()) {
				return $this->renderPage('index');
			}
			return $this->redirectToProject($this->request->getParam('projectId'), 'whiteboard');
		}
		return $this->renderPage('new');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function project(): TemplateResponse|NotFoundResponse {
		return $this->renderPage('new');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function create(): TemplateResponse|NotFoundResponse {
		return $this->renderPage('new');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacy(): TemplateResponse|NotFoundResponse|RedirectResponse {
		if (!$this->isAdmin()) {
			return $this->redirectTo('projectcreatoraio.page.index');
		}
		return $this->renderPage('index');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function legacyProject(string $projectId): TemplateResponse|NotFoundResponse|RedirectResponse {
		if (!$this->isAdmin()) {
			return $this->redirectToProject($projectId, $this->request->getParam('tab'));
		}
		return $this->renderPage('index');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function newIndex(): RedirectResponse {
		return $this->redirectTo('projectcreatoraio.page.index');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function newProject(string $projectId): RedirectResponse {
		return $this->redirectToProject($projectId, $this->request->getParam('tab'));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function newProjectTab(string $projectId, string $tab): RedirectResponse {
		return $this->redirectToProject($projectId, $tab);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function newCreate(): RedirectResponse {
		return $this->redirectTo('projectcreatoraio.page.create');
	}

	private function isAdmin(): bool {
		$user = $this->userSession->getUser();
		return $user !== null && $this->groupManager->isAdmin($user->getUID());
	}

	private function redirectTo(string $route): RedirectResponse {
		return new RedirectResponse($this->urlGenerator->linkToRoute($route));
	}

	/**
	 * A project's page, or the project list when the id isn't a project id.
	 * Only a plain tab name is carried over; the page maps old tab names itself.
	 */
	private function redirectToProject(mixed $projectId, mixed $tab = null): RedirectResponse {
		$id = is_scalar($projectId) ? (string) $projectId : '';
		if (preg_match('/^[1-9]\d{0,17}$/', $id) !== 1) {
			return $this->redirectTo('projectcreatoraio.page.index');
		}
		$url = $this->urlGenerator->linkToRoute('projectcreatoraio.page.project', ['projectId' => $id]);
		$tabName = is_string($tab) ? $tab : '';
		if (preg_match('/^[A-Za-z]{1,32}$/', $tabName) === 1) {
			$url .= '?tab=' . $tabName;
		}
		return new RedirectResponse($url);
	}

	private function renderPage(string $template): TemplateResponse|NotFoundResponse {
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			return new NotFoundResponse();
		}

		// Load Viewer scripts so apps (like Whiteboard) can register handlers.
		$viewerEventClass = '\\OCA\\Viewer\\Event\\LoadViewer';
		if (class_exists($viewerEventClass)) {
			$this->eventDispatcher->dispatchTyped(new $viewerEventClass());
		}

		$response = new TemplateResponse(
			Application::APP_ID,
			$template,
		);

		// Allow embedding same-origin pages (Files/Viewer) in an iframe.
		// Needed for the embedded project whiteboard editor.
		$response->getContentSecurityPolicy()->addAllowedFrameDomain("'self'");
		$response->getContentSecurityPolicy()->addAllowedFrameDomain('https://www.openstreetmap.org');
		$response->getContentSecurityPolicy()->addAllowedConnectDomain('https://nominatim.openstreetmap.org');
		// Allow service workers/workers used by Viewer/Whiteboard.
		$response->getContentSecurityPolicy()->addAllowedWorkerSrcDomain("'self'");

		return $response;
	}
}
