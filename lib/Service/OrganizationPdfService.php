<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;
use Throwable;

class OrganizationPdfService
{
    private IAppData $appData;
    private LoggerInterface $logger;

    public function __construct(IAppData $appData, LoggerInterface $logger)
    {
        $this->appData = $appData;
        $this->logger = $logger;
    }

    /**
     * Retrieve the organization default PDF binary content.
     * Falls back to system default PDF if organization custom PDF is not set.
     */
    public function getOrganizationPdfContent(?int $organizationId): ?string
    {
        if ($organizationId !== null && $organizationId > 0) {
            try {
                $orgFolder = $this->getOrgFolder($organizationId);
                if ($orgFolder->fileExists('default_project.pdf')) {
                    $file = $orgFolder->getFile('default_project.pdf');
                    return $file->getContent();
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to load organization default PDF', [
                    'organizationId' => $organizationId,
                    'exception' => $e,
                ]);
            }
        }

        // Fallback to global default template file if present
        $fallbackPath = __DIR__ . '/../../resources/templates/default.pdf';
        if (file_exists($fallbackPath)) {
            $content = file_get_contents($fallbackPath);
            if ($content !== false) {
                return $content;
            }
        }

        return null;
    }

    /**
     * Check if a custom default PDF exists for an organization.
     */
    public function hasCustomPdf(int $organizationId): bool
    {
        try {
            $orgFolder = $this->getOrgFolder($organizationId);
            return $orgFolder->fileExists('default_project.pdf');
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Save/Update custom default PDF for an organization.
     */
    public function saveOrganizationPdf(int $organizationId, string $content): void
    {
        $orgFolder = $this->getOrgFolder($organizationId);
        if ($orgFolder->fileExists('default_project.pdf')) {
            $file = $orgFolder->getFile('default_project.pdf');
            $file->delete();
        }
        $file = $orgFolder->newFile('default_project.pdf');
        $file->putContent($content);
    }

    /**
     * Delete custom default PDF for an organization.
     */
    public function deleteOrganizationPdf(int $organizationId): void
    {
        $orgFolder = $this->getOrgFolder($organizationId);
        if ($orgFolder->fileExists('default_project.pdf')) {
            $file = $orgFolder->getFile('default_project.pdf');
            $file->delete();
        }
    }

    private function getOrgFolder(int $organizationId): ISimpleFolder
    {
        $folderName = 'org_' . $organizationId;
        try {
            return $this->appData->getFolder($folderName);
        } catch (NotFoundException) {
            return $this->appData->newFolder($folderName);
        }
    }
}
