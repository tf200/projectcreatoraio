<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\ProjectCreatorAIO\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\AppFramework\Http\TemplateResponse;

/**
 * @psalm-suppress UnusedClass
 */
class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private IEventDispatcher $eventDispatcher,
    ) {
        parent::__construct($appName, $request);
    }

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function project(): TemplateResponse|NotFoundResponse {
		return $this->index();
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse|NotFoundResponse {
		return $this->renderPage('index');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function newIndex(): TemplateResponse|NotFoundResponse {
		return $this->renderPage('new');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function newProject(): TemplateResponse|NotFoundResponse {
		return $this->renderPage('new');
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
