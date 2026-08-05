<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;
use Throwable;

class OrganizationPdfService
{
    private const PDF_FILE_NAME = 'default_project.pdf';
    private const PDF_DISPLAY_NAME_FILE_NAME = 'default_project_filename.txt';
    private const FALLBACK_PDF_FILE_NAME = 'Welcome_Document.pdf';

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
                if ($orgFolder->fileExists(self::PDF_FILE_NAME)) {
                    $file = $orgFolder->getFile(self::PDF_FILE_NAME);
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
     * Retrieve the display filename for the organization default PDF.
     */
    public function getOrganizationPdfFileName(?int $organizationId): string
    {
        if ($organizationId !== null && $organizationId > 0) {
            try {
                $orgFolder = $this->getOrgFolder($organizationId);
                if ($orgFolder->fileExists(self::PDF_FILE_NAME)
                    && $orgFolder->fileExists(self::PDF_DISPLAY_NAME_FILE_NAME)) {
                    $fileName = trim($orgFolder->getFile(self::PDF_DISPLAY_NAME_FILE_NAME)->getContent());
                    if ($fileName !== '') {
                        try {
                            return $this->normalizePdfFileName($fileName);
                        } catch (InvalidArgumentException) {
                            $this->logger->warning('Invalid stored organization PDF filename', [
                                'organizationId' => $organizationId,
                            ]);
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->logger->warning('Failed to load organization default PDF filename', [
                    'organizationId' => $organizationId,
                    'exception' => $e,
                ]);
            }
        }

        return self::FALLBACK_PDF_FILE_NAME;
    }

    /**
     * Normalize and validate a project-visible PDF filename.
     */
    public function normalizePdfFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new InvalidArgumentException('A filename is required.');
        }

        if ($fileName === '.' || $fileName === '..'
            || str_contains($fileName, '/')
            || str_contains($fileName, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1) {
            throw new InvalidArgumentException('The filename contains invalid characters.');
        }

        if (!preg_match('/\.pdf$/i', $fileName)) {
            $fileName .= '.pdf';
        }

        if (strlen($fileName) > 255) {
            throw new InvalidArgumentException('The filename is too long.');
        }

        return $fileName;
    }

    /**
     * Check if a custom default PDF exists for an organization.
     */
    public function hasCustomPdf(int $organizationId): bool
    {
        try {
            $orgFolder = $this->getOrgFolder($organizationId);
            return $orgFolder->fileExists(self::PDF_FILE_NAME);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Save/Update custom default PDF for an organization.
     */
    public function saveOrganizationPdf(int $organizationId, string $content, string $fileName): void
    {
        $fileName = $this->normalizePdfFileName($fileName);
        $orgFolder = $this->getOrgFolder($organizationId);
        if ($orgFolder->fileExists(self::PDF_FILE_NAME)) {
            $file = $orgFolder->getFile(self::PDF_FILE_NAME);
            $file->delete();
        }
        $file = $orgFolder->newFile(self::PDF_FILE_NAME);
        $file->putContent($content);

        if ($orgFolder->fileExists(self::PDF_DISPLAY_NAME_FILE_NAME)) {
            $displayNameFile = $orgFolder->getFile(self::PDF_DISPLAY_NAME_FILE_NAME);
            $displayNameFile->delete();
        }
        $displayNameFile = $orgFolder->newFile(self::PDF_DISPLAY_NAME_FILE_NAME);
        $displayNameFile->putContent($fileName);
    }

    /**
     * Delete custom default PDF for an organization.
     */
    public function deleteOrganizationPdf(int $organizationId): void
    {
        $orgFolder = $this->getOrgFolder($organizationId);
        if ($orgFolder->fileExists(self::PDF_FILE_NAME)) {
            $file = $orgFolder->getFile(self::PDF_FILE_NAME);
            $file->delete();
        }
        if ($orgFolder->fileExists(self::PDF_DISPLAY_NAME_FILE_NAME)) {
            $file = $orgFolder->getFile(self::PDF_DISPLAY_NAME_FILE_NAME);
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
