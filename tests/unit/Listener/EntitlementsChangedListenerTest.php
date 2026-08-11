<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Listener;

use OCP\BackgroundJob\IJobList;

use OCA\Organization\Event\EntitlementsChangedEvent;
use OCA\ProjectCreatorAIO\BackgroundJob\ReconcileChangedProjectQuotasJob;
use OCA\ProjectCreatorAIO\Listener\EntitlementsChangedListener;

use PHPUnit\Framework\TestCase;

class EntitlementsChangedListenerTest extends TestCase
{
    public function testQueuesOrganizationReconciliationOnce(): void
    {
        $argument = ['organizationId' => 7, 'planId' => null];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())
            ->method('has')
            ->with(ReconcileChangedProjectQuotasJob::class, $argument)
            ->willReturn(false);
        $jobList->expects($this->once())
            ->method('add')
            ->with(ReconcileChangedProjectQuotasJob::class, $argument);

        (new EntitlementsChangedListener($jobList))->handle(EntitlementsChangedEvent::forOrganization(7));
    }

    public function testDoesNotDuplicatePlanReconciliation(): void
    {
        $argument = ['organizationId' => null, 'planId' => 3];
        $jobList = $this->createMock(IJobList::class);
        $jobList->expects($this->once())
            ->method('has')
            ->with(ReconcileChangedProjectQuotasJob::class, $argument)
            ->willReturn(true);
        $jobList->expects($this->never())->method('add');

        (new EntitlementsChangedListener($jobList))->handle(EntitlementsChangedEvent::forPlan(3));
    }
}
