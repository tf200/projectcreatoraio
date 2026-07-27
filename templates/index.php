<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\ProjectCreatorAIO\AppInfo\Application::APP_ID, OCA\ProjectCreatorAIO\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\ProjectCreatorAIO\AppInfo\Application::APP_ID, OCA\ProjectCreatorAIO\AppInfo\Application::APP_ID . '-main');

?>
