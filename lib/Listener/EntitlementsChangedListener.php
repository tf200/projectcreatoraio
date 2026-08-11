<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Listener;

use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

use OCA\Organization\Event\EntitlementsChangedEvent;
use OCA\ProjectCreatorAIO\BackgroundJob\ReconcileChangedProjectQuotasJob;

/** @template-implements IEventListener<EntitlementsChangedEvent> */
class EntitlementsChangedListener implements IEventListener
{
    public function __construct(private IJobList $jobList)
    {
    }

    public function handle(Event $event): void
    {
        if (!$event instanceof EntitlementsChangedEvent) {
            return;
        }

        $argument = [
            'organizationId' => $event->getOrganizationId(),
            'planId' => $event->getPlanId(),
        ];

        if (!$this->jobList->has(ReconcileChangedProjectQuotasJob::class, $argument)) {
            $this->jobList->add(ReconcileChangedProjectQuotasJob::class, $argument);
        }
    }
}
