<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Dashboard;

use OCP\AppFramework\Services\IInitialState;
use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

class ProjectsWidget implements IWidget {
    public const APP_ID = 'projectcreatoraio';

    public function __construct(
        private readonly IInitialState $initialState,
        private readonly IL10N $l10n,
        private readonly IURLGenerator $urlGenerator,
        private readonly IUserSession $userSession,
    ) {
    }

    /**
     * @return string Unique id that identifies the widget, e.g. the app id
     * @since 20.0.0
     */
    public function getId(): string {
        return self::APP_ID . 'projects';
    }

    /**
     * @return string User facing title of the widget
     * @since 20.0.0
     */
    public function getTitle(): string {
        return $this->l10n->t('Projects');
    }

    /**
     * @return int Initial order for widget sorting
     *   in the range of 10-100, 0-9 are reserved for shipped apps
     * @since 20.0.0
     */
    public function getOrder(): int {
        return 0;
    }

    /**
     * @return string css class that displays an icon next to the widget title
     * @since 20.0.0
     */
    public function getIconClass(): string {
        return 'icon-files-dark';
    }

    /**
     * @return string|null The absolute url to the apps own view
     * @since 20.0.0
     */
    public function getUrl(): ?string {
        return $this->urlGenerator->linkToRouteAbsolute(self::APP_ID . '.page.index');
    }

    /**
     * Execute widget bootstrap code like loading scripts and providing initial state
     */
    public function load(): void {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $this->initialState->provideInitialState('currentUser', [
            'id' => $user->getUID(),
            'displayName' => $user->getDisplayName()
        ]);

        \OCP\Util::addScript(self::APP_ID, self::APP_ID . '-dashboard');
        \OCP\Util::addStyle(self::APP_ID, self::APP_ID . '-dashboard');
    }
}
