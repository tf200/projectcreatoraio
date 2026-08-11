<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;

use Psr\Log\LoggerInterface;

class ProjectQuotaService
{
    public function __construct(
        private ProjectMapper $projectMapper,
        private ?object $folderManager,
        private ?object $subscriptionMapper,
        private ?object $planMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Reapply current plan quotas to all managed project Team Folders.
     *
     * @return array{processed:int,updated:int,failed:int}
     */
    public function reconcileAll(): array
    {
        if (!$this->isAvailable()) {
            return ['processed' => 0, 'updated' => 0, 'failed' => 0];
        }

        $processed = 0;
        $updated = 0;
        $failed = 0;
        $offset = 0;

        do {
            $projects = $this->projectMapper->findQuotaManaged(100, $offset);
            $result = $this->reconcileProjects($projects);
            $processed += $result['processed'];
            $updated += $result['updated'];
            $failed += $result['failed'];
            $offset += count($projects);
        } while (count($projects) === 100);

        return ['processed' => $processed, 'updated' => $updated, 'failed' => $failed];
    }

    /**
     * @return array{processed:int,updated:int,failed:int}
     */
    public function reconcileOrganization(int $organizationId): array
    {
        if ($organizationId <= 0 || !$this->isAvailable()) {
            return ['processed' => 0, 'updated' => 0, 'failed' => 0];
        }

        return $this->reconcileProjects($this->projectMapper->findByOrganizationId($organizationId));
    }

    /**
     * @return array{processed:int,updated:int,failed:int}
     */
    public function reconcilePlan(int $planId): array
    {
        if ($planId <= 0 || !$this->isAvailable()) {
            return ['processed' => 0, 'updated' => 0, 'failed' => 0];
        }

        $result = ['processed' => 0, 'updated' => 0, 'failed' => 0];
        foreach ($this->subscriptionMapper->findOrganizationIdsByPlanId($planId) as $organizationId) {
            $organizationResult = $this->reconcileOrganization((int) $organizationId);
            $result['processed'] += $organizationResult['processed'];
            $result['updated'] += $organizationResult['updated'];
            $result['failed'] += $organizationResult['failed'];
        }

        return $result;
    }

    private function isAvailable(): bool
    {
        return $this->folderManager !== null && $this->subscriptionMapper !== null && $this->planMapper !== null;
    }

    /**
     * @param iterable<object> $projects
     * @return array{processed:int,updated:int,failed:int}
     */
    private function reconcileProjects(iterable $projects): array
    {
        $processed = 0;
        $updated = 0;
        $failed = 0;

        foreach ($projects as $project) {
            if ($project->getOrganizationId() === null || $project->getGroupFolderId() === null) {
                continue;
            }

            $processed++;
            try {
                $organizationId = (int) $project->getOrganizationId();
                $groupFolderId = (int) $project->getGroupFolderId();
                $subscription = $this->subscriptionMapper->findByOrganizationId($organizationId);
                $plan = $subscription !== null ? $this->planMapper->find($subscription->getPlanId()) : null;

                if ($organizationId <= 0 || $groupFolderId <= 0 || $plan === null) {
                    throw new \RuntimeException('Project quota entitlement or Team Folder identity is missing.');
                }

                $desiredQuota = $plan->getSharedStoragePerProject();
                if ($desiredQuota <= 0) {
                    throw new \RuntimeException('The plan project storage quota must be positive.');
                }

                $folder = $this->folderManager->getFolder($groupFolderId);
                if ($folder === null) {
                    throw new \RuntimeException('The project Team Folder does not exist.');
                }

                if ((int) $folder->quota !== $desiredQuota) {
                    $this->folderManager->setFolderQuota($groupFolderId, $desiredQuota);
                    $updated++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('Failed to reconcile project Team Folder quota', [
                    'projectId' => (int) ($project->getId() ?? 0),
                    'organizationId' => (int) ($project->getOrganizationId() ?? 0),
                    'groupFolderId' => (int) ($project->getGroupFolderId() ?? 0),
                    'exception' => $e,
                ]);
            }
        }

        return ['processed' => $processed, 'updated' => $updated, 'failed' => $failed];
    }
}
