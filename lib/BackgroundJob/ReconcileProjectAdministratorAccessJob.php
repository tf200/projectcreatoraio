<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\BackgroundJob;

use OCA\ProjectCreatorAIO\Service\ProjectAdministratorAccessService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

final class ReconcileProjectAdministratorAccessJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private ProjectAdministratorAccessService $accessService,
    ) {
        parent::__construct($time);
        $this->setInterval(5 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void
    {
        $this->accessService->reconcileAll();
    }
}
