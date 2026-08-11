<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

use OCA\ProjectCreatorAIO\Service\ProjectQuotaService;

class ReconcileChangedProjectQuotasJob extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private ProjectQuotaService $projectQuotaService,
    ) {
        parent::__construct($time);
    }

    protected function run($argument): void
    {
        $organizationId = (int) ($argument['organizationId'] ?? 0);
        $planId = (int) ($argument['planId'] ?? 0);

        if ($organizationId > 0) {
            $this->projectQuotaService->reconcileOrganization($organizationId);
        } elseif ($planId > 0) {
            $this->projectQuotaService->reconcilePlan($planId);
        }
    }
}
