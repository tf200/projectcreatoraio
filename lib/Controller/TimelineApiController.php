<?php

namespace OCA\ProjectCreatorAIO\Controller;

use DateTime;
use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class TimelineApiController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private TimelineItemMapper $mapper,
		private ProjectMapper $projectMapper,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ProjectActivityService $projectActivityService,
		private TimelinePlanningService $planningService,
		private ?OrganizationUserMapper $organizationUserMapper = null,
	) {
		parent::__construct($appName, $request);
	}

    #[NoAdminRequired]
    public function index(int $projectId): JSONResponse
    {
        try {
            $project = $this->requireProject($projectId);
            $this->assertCanAccessProject($project);

            $this->syncSystemTimelineItems($project);

            $items = $this->mapper->findByProject($projectId);
            return new JSONResponse($items);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function summary(int $projectId): JSONResponse
    {
        try {
            $project = $this->requireProject($projectId);
            $this->assertCanAccessProject($project);

            return new JSONResponse($this->planningService->buildSummary($project));
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

	#[NoAdminRequired]
	public function create(
		int $projectId,
		string $label,
        ?string $startDate = null,
        ?string $endDate = null,
        string $color = '#3b82f6',
        string $itemType = 'phase',
    ): JSONResponse {
        try {
            $project = $this->requireProject($projectId);
            $this->assertCanManageTimelineProject($project);

            // Ensure derived/system items exist so order indices don't collide.
            $this->syncSystemTimelineItems($project);

            $label = trim($label);
            if ($label === '') {
                return new JSONResponse(['error' => 'Missing label'], Http::STATUS_BAD_REQUEST);
            }

            $startDate = $this->normalizeDateParamToNull($startDate);
            $endDate = $this->normalizeDateParamToNull($endDate);

            $itemType = strtolower(trim((string) $itemType));
            if ($itemType === '') {
                $itemType = 'phase';
            }
            if (!in_array($itemType, ['phase', 'milestone'], true)) {
                return new JSONResponse(['error' => 'Invalid item type'], Http::STATUS_BAD_REQUEST);
            }

            if ($startDate === null) {
                return new JSONResponse(['error' => 'Missing start date'], Http::STATUS_BAD_REQUEST);
            }

            if ($itemType === 'milestone') {
                $endDate = $startDate;
            }

            if ($endDate !== null) {
                $start = new DateTime($startDate);
                $end = new DateTime($endDate);
                if ($end < $start) {
                    return new JSONResponse(['error' => 'End date cannot be before start date'], Http::STATUS_BAD_REQUEST);
                }
            }

            $item = $this->mapper->createItem(
                $projectId,
                $label,
                $startDate,
                $endDate,
                $color,
                $this->mapper->getNextOrderIndex($projectId),
                null,
                $itemType,
            );

            $this->projectActivityService->recordTimelineItemCreated($project, $item, $this->userSession->getUser());

            return new JSONResponse($item, Http::STATUS_CREATED);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    #[NoAdminRequired]
    public function update(
        int $projectId,
        int $id,
        ?string $label = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $color = null,
        ?string $itemType = null,
        ?int $orderIndex = null,
    ): JSONResponse {
        try {
            $project = $this->requireProject($projectId);
            $this->assertCanManageTimelineProject($project);

            $item = $this->mapper->find($id);
            if ($item === null) {
                return new JSONResponse(['error' => 'Timeline item not found'], Http::STATUS_NOT_FOUND);
            }

            if ($item->getProjectId() !== $projectId) {
                return new JSONResponse(['error' => 'Item does not belong to this project'], Http::STATUS_FORBIDDEN);
            }

            $isSystemItem = (string) ($item->getSystemKey() ?? '') !== '';

            if (!$isSystemItem && $label !== null) {
                $nextLabel = trim($label);
                if ($nextLabel === '') {
                    return new JSONResponse(['error' => 'Missing label'], Http::STATUS_BAD_REQUEST);
                }
                $item->setLabel($nextLabel);
            }

            $clearStart = $startDate !== null && trim($startDate) === '';
            $clearEnd = $endDate !== null && trim($endDate) === '';

            $startDate = $this->normalizeDateParamToNull($startDate);
            $endDate = $this->normalizeDateParamToNull($endDate);

            if ($startDate !== null) {
                $item->setStartDate(new DateTime($startDate));
            } elseif ($clearStart) {
                $item->setStartDate(null);
            }

            if ($endDate !== null) {
                $item->setEndDate(new DateTime($endDate));
            } elseif ($clearEnd) {
                $item->setEndDate(null);
            }
            if ($color !== null) {
                $item->setColor($color);
            }

            if (!$isSystemItem && $itemType !== null) {
                $nextType = strtolower(trim((string) $itemType));
                if ($nextType === '') {
                    $nextType = 'phase';
                }
                if (!in_array($nextType, ['phase', 'milestone'], true)) {
                    return new JSONResponse(['error' => 'Invalid item type'], Http::STATUS_BAD_REQUEST);
                }
                $item->setItemType($nextType);
            }

            if (!$isSystemItem && $orderIndex !== null) {
                $item->setOrderIndex($orderIndex);
            }

            $effectiveType = strtolower(trim((string) ($item->getItemType() ?? 'phase')));
            if ($effectiveType === 'milestone') {
                $start = $item->getStartDate();
                if ($start instanceof DateTime) {
                    $item->setEndDate(clone $start);
                }
            }

            $start = $item->getStartDate();
            $end = $item->getEndDate();
            if ($start instanceof DateTime && $end instanceof DateTime && $end < $start) {
                return new JSONResponse(['error' => 'End date cannot be before start date'], Http::STATUS_BAD_REQUEST);
            }

            $updatedItem = $this->mapper->updateItem($item);
            $this->projectActivityService->recordTimelineItemUpdated($project, $updatedItem, $this->userSession->getUser());

            return new JSONResponse($updatedItem);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function normalizeDateParamToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        return $value;
    }

    /**
     * Batch reorder timeline items to avoid N API calls on drag-and-drop.
     */
    #[NoAdminRequired]
    public function reorder(int $projectId, array $ids = []): JSONResponse
    {
        try {
            $project = $this->requireProject($projectId);
            $this->assertCanManageTimelineProject($project);

            if ($ids === []) {
                return new JSONResponse(['error' => 'Missing ids'], Http::STATUS_BAD_REQUEST);
            }

            $items = $this->mapper->reorderItems($projectId, $ids);
            $this->projectActivityService->recordTimelineReordered($project, count($items), $this->userSession->getUser());
            return new JSONResponse($items);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

	private function syncSystemTimelineItems(Project $project): void
	{
		try {
			$summary = $this->planningService->buildSummary($project);
			$deckDateBounds = $this->planningService->getDeckDateBounds($project);
			$projectId = (int) $project->getId();

			$requestDate = trim((string) ($summary['requestDate'] ?? ''));
			$process = $summary['processCompleted'] ?? [];
			$processStatus = trim((string) ($process['status'] ?? ''));
			$processDate = trim((string) ($process['date'] ?? ''));
			$earliest = trim((string) ($summary['earliestExecutionDate'] ?? ''));

			if ($requestDate !== '') {
				$this->upsertSystemItem(
					$projectId,
					'request_date',
					'Request Date',
					$requestDate,
					$requestDate,
					'#0f172a',
					'milestone',
					0,
				);
			}

			if ($requestDate !== '') {
				$isComplete = $processStatus === 'complete' && $processDate !== '';
				$this->upsertSystemItem(
					$projectId,
					'process_completed',
					'Process Completed',
					$requestDate,
					$isComplete ? $processDate : null,
					$isComplete ? '#10b981' : '#f59e0b',
					'phase',
					1,
				);
			}

			if ($processStatus === 'complete' && $processDate !== '' && $earliest !== '') {
				$this->upsertSystemItem(
					$projectId,
					'prep_time',
					'Prep Time',
					$processDate,
					$earliest,
					'#3b82f6',
					'phase',
					2,
				);
			} else {
				$this->mapper->deleteByProjectAndSystemKey($projectId, 'prep_time');
			}

			if ($deckDateBounds !== null) {
				$this->upsertSystemItem(
					$projectId,
					'deck_schedule',
					'Project Timeline',
					$deckDateBounds['startDate'],
					$deckDateBounds['endDate'],
					'#8b5cf6',
					'phase',
					3,
				);
			} else {
				$this->mapper->deleteByProjectAndSystemKey($projectId, 'deck_schedule');
			}
		} catch (\Throwable $e) {
			// Non-blocking: timeline should still load even if derived system items fail.
		}
	}

	private function upsertSystemItem(
		int $projectId,
		string $systemKey,
		string $label,
		?string $startDate,
		?string $endDate,
		string $color,
		string $itemType,
		int $defaultOrderIndex,
	): void {
		$existing = $this->mapper->findByProjectAndSystemKey($projectId, $systemKey);
		if ($existing === null) {
			$this->mapper->createItem(
				$projectId,
				$label,
				$startDate,
				$endDate,
				$color,
				$defaultOrderIndex,
				$systemKey,
				$itemType,
			);
			return;
		}

		$existing->setLabel($label);
		$existing->setItemType($itemType);
		if ($startDate !== null) {
			$existing->setStartDate(new DateTime($startDate));
		} else {
			$existing->setStartDate(null);
		}
		if ($endDate !== null) {
			$existing->setEndDate(new DateTime($endDate));
		} else {
			$existing->setEndDate(null);
		}
		$existing->setColor($color);
		$this->mapper->updateItem($existing);
	}

    #[NoAdminRequired]
    public function destroy(int $projectId, int $id): JSONResponse
    {
        try {
            $project = $this->requireProject($projectId);
            $this->assertCanManageTimelineProject($project);

            $item = $this->mapper->find($id);
            if ($item === null) {
                return new JSONResponse(['error' => 'Timeline item not found'], Http::STATUS_NOT_FOUND);
            }

            if ($item->getProjectId() !== $projectId) {
                return new JSONResponse(['error' => 'Item does not belong to this project'], Http::STATUS_FORBIDDEN);
            }

            if ((string) ($item->getSystemKey() ?? '') !== '') {
                return new JSONResponse(['error' => 'System timeline items cannot be deleted'], Http::STATUS_FORBIDDEN);
            }

            $this->mapper->delete($item);
            $this->projectActivityService->recordTimelineItemDeleted($project, $item, $this->userSession->getUser());
            return new JSONResponse(['success' => true]);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    private function requireProject(int $projectId): Project
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException('Project not found');
        }

        return $project;
    }

    private function assertCanAccessProject(Project $project): void
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        if ($this->groupManager->isAdmin($currentUser->getUID())) {
            return;
        }

        if ($this->organizationUserMapper === null) {
            if (!$this->isProjectGroupMember($currentUser->getUID(), (string) $project->getProjectGroupGid())) {
                throw new OCSNotFoundException('Project not found');
            }
            return;
        }

        $membership = $this->organizationUserMapper->getOrganizationMembership($currentUser->getUID());
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ((int) $membership['organization_id'] !== (int) $project->getOrganizationId()) {
            throw new OCSNotFoundException('Project not found');
        }

        if ($membership['role'] === 'admin') {
            return;
        }

        if (!$this->isProjectGroupMember($currentUser->getUID(), (string) $project->getProjectGroupGid())) {
            throw new OCSNotFoundException('Project not found');
        }
    }

    private function assertCanManageTimelineProject(Project $project): void
    {
        $this->assertCanAccessProject($project);
    }

    private function isProjectGroupMember(string $userId, string $projectGroupGid): bool
    {
        if ($projectGroupGid === '') {
            return false;
        }

        return $this->groupManager->isInGroup($userId, $projectGroupGid);
    }

    private function errorResponse(\Throwable $e): JSONResponse
    {
        if ($e instanceof OCSForbiddenException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        }

        if ($e instanceof OCSNotFoundException) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
    }
}
