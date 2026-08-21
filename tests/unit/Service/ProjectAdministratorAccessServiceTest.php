<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectAdministratorAccessService;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ProjectAdministratorAccessServiceTest extends TestCase
{
    public function testOrganizationAdminGroupIdIsOrganizationScoped(): void
    {
        self::assertSame(
            'organization-project-admins-42',
            ProjectAdministratorAccessService::organizationAdminGroupId(42),
        );
    }

    public function testProjectsWithoutAnOrganizationAreIgnored(): void
    {
        $project = new Project();
        $project->setId(1);
        $projectMapper = $this->createMock(ProjectMapper::class);
        $projectMapper->expects($this->never())->method('findAllPrivateFoldersByProject');

        $service = new ProjectAdministratorAccessService(
            $projectMapper,
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IRootFolder::class),
            $this->createMock(IManager::class),
            $this->createMock(IDBConnection::class),
            null,
            null,
            null,
            $this->createMock(LoggerInterface::class),
        );

        $service->syncProject($project);
    }
}
