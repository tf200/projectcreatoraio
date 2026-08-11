<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\BackgroundJob;

use OCA\ProjectCreatorAIO\Service\ProjectQuotaService;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

class ReconcileProjectQuotasJob extends TimedJob
{
    public function __construct(
        ITimeFactory $time,
        private ProjectQuotaService $projectQuotaService,
    ) {
        parent::__construct($time);

        $this->setInterval(60 * 60);
        $this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void
    {
        $this->projectQuotaService->reconcileAll();
    }
}
