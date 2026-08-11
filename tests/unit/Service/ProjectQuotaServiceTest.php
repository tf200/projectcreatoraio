<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectQuotaService;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectQuotaServiceTest extends TestCase
{
    public function testReconcileOrganizationUpdatesOnlyManagedProjects(): void
    {
        $managedProject = $this->project(1, 7, 11);
        $unmanagedProject = $this->project(2, 7, null);
        $projectMapper = $this->createMock(ProjectMapper::class);
        $projectMapper->expects($this->once())
            ->method('findByOrganizationId')
            ->with(7)
            ->willReturn([$managedProject, $unmanagedProject]);

        $folderManager = new class {
            /** @var array<int,int> */
            public array $quotas = [];

            public function getFolder(int $folderId): object
            {
                return (object) ['quota' => 5];
            }

            public function setFolderQuota(int $folderId, int $quota): void
            {
                $this->quotas[$folderId] = $quota;
            }
        };
        $subscriptionMapper = new class {
            public function findByOrganizationId(int $organizationId): object
            {
                return new class {
                    public function getPlanId(): int
                    {
                        return 3;
                    }
                };
            }
        };
        $planMapper = new class {
            public function find(int $planId): object
            {
                return new class {
                    public function getSharedStoragePerProject(): int
                    {
                        return 100;
                    }
                };
            }
        };

        $service = new ProjectQuotaService(
            $projectMapper,
            $folderManager,
            $subscriptionMapper,
            $planMapper,
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(['processed' => 1, 'updated' => 1, 'failed' => 0], $service->reconcileOrganization(7));
        self::assertSame([11 => 100], $folderManager->quotas);
    }

    public function testReconcilePlanTargetsSubscribedOrganizations(): void
    {
        $projectMapper = $this->createMock(ProjectMapper::class);
        $projectMapper->expects($this->exactly(2))
            ->method('findByOrganizationId')
            ->willReturnCallback(static fn (int $organizationId): array => match ($organizationId) {
                7, 8 => [],
                default => self::fail('Unexpected organization ID'),
            });

        $subscriptionMapper = new class {
            /** @return int[] */
            public function findOrganizationIdsByPlanId(int $planId): array
            {
                return [7, 8];
            }
        };

        $service = new ProjectQuotaService(
            $projectMapper,
            new class {},
            $subscriptionMapper,
            new class {},
            $this->createMock(LoggerInterface::class),
        );

        self::assertSame(['processed' => 0, 'updated' => 0, 'failed' => 0], $service->reconcilePlan(3));
    }

    private function project(int $id, int $organizationId, ?int $groupFolderId): Project
    {
        $project = new Project();
        $project->setId($id);
        $project->setOrganizationId($organizationId);
        $project->setGroupFolderId($groupFolderId);

        return $project;
    }
}
