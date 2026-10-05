<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Listener;

use OCA\Organization\Event\OrganizationMemberRemovedEvent;
use OCA\Organization\Event\ProjectTeamChangedEvent;
use OCA\Organization\Event\TeamDeletedEvent;
use OCA\Organization\Event\TeamMemberAddedEvent;
use OCA\Organization\Event\TeamMemberRemovedEvent;
use OCA\ProjectCreatorAIO\Service\ProjectMembershipService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Keeps project membership in line with organization and team changes.
 *
 * @template-implements IEventListener<Event>
 */
class OrganizationMembershipListener implements IEventListener {
	public function __construct(
		private ProjectMembershipService $membershipService,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof OrganizationMemberRemovedEvent) {
			$this->membershipService->removeFromOrganizationProjects($event->getOrganizationId(), $event->getUserId());
		} elseif ($event instanceof ProjectTeamChangedEvent) {
			$this->membershipService->syncProjectTeam($event->getProjectId(), $event->getPreviousTeamId(), $event->getTeamId());
		} elseif ($event instanceof TeamMemberAddedEvent) {
			$this->membershipService->addTeamMember($event->getTeamId(), $event->getUserId());
		} elseif ($event instanceof TeamMemberRemovedEvent) {
			$this->membershipService->removeTeamMember($event->getTeamId(), $event->getUserId());
		} elseif ($event instanceof TeamDeletedEvent) {
			$this->membershipService->removeTeam($event->getTeamId());
		}
	}
}
