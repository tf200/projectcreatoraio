<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\OrganizationDocumentTypeMapper;
use OCA\ProjectCreatorAIO\Db\OrganizationDocumentType;
use OCA\ProjectCreatorAIO\Db\ProjectFileProcessing;
use OCA\ProjectCreatorAIO\Db\ProjectFileProcessingMapper;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use Psr\Log\LoggerInterface;
use Throwable;
use OCP\IL10N;

class FileProcessingPipelineService
{
    private IL10N $l;

    private DefaultLanguageL10n $contentL10n;

    public function __construct(
        private readonly ProjectFileProcessingMapper $processingMapper,
        private readonly OrganizationDocumentTypeMapper $documentTypeMapper,
        private readonly IRootFolder $rootFolder,
        private readonly DocumentExtractionService $documentExtractionService,
        private readonly LoggerInterface $logger,
        ?IL10N $l = null,
        ?DefaultLanguageL10n $contentL10n = null,
    ) {
        $this->l = $l ?? DefaultLanguageL10n::userL10n();
        $this->contentL10n = $contentL10n ?? DefaultLanguageL10n::instance();
    }

    public function processPending(int $limit = 5): int
    {
        $processed = 0;
        foreach ($this->processingMapper->findProcessable($limit) as $record) {
            $this->processRecord($record);
            $processed++;
        }

        return $processed;
    }

    public function processRecord(ProjectFileProcessing $record): ProjectFileProcessing
    {
        $record = $this->markExtractionStarted($record);

        try {
            $file = $this->resolveFileNode((int) $record->getFileId());
            $documentType = $this->resolveDocumentType($record);

            try {
                $extractionResult = $this->documentExtractionService->extractFromFile($file, $documentType->getFields());
            } catch (Throwable $e) {
                return $this->markExtractionFailed($record, $e);
            }

            $record->setFileName((string) $file->getName());
            $record->setMimeType((string) $file->getMimeType());

            return $this->markExtractionCompleted($record, $extractionResult->getFields());
        } catch (Throwable $e) {
            return $this->markPipelineFailed($record, $e);
        }
    }

    public function markFileAsStale(int $fileId): void
    {
        foreach ($this->processingMapper->findByFileId($fileId) as $record) {
            $record->setOcrStatus('stale');
            $record->setErrorMessage(null);
            $record->setProcessedAt(null);
            $this->processingMapper->saveRecord($record);
        }
    }

    private function resolveFileNode(int $fileId): File
    {
        $nodes = $this->rootFolder->getById($fileId);
        foreach ($nodes as $node) {
            if ($node instanceof File) {
                return $node;
            }
        }

        throw new OCSException($this->l->t('OCR source file no longer exists.'), 404);
    }

    private function resolveDocumentType(ProjectFileProcessing $record): OrganizationDocumentType
    {
        $documentTypeId = (int) ($record->getDocumentTypeId() ?? 0);
        $documentType = $this->documentTypeMapper->find($documentTypeId);
        if ($documentType === null) {
            throw new OCSNotFoundException($this->l->t('Document type definition not found.'));
        }

        return $documentType;
    }

    private function markExtractionStarted(ProjectFileProcessing $record): ProjectFileProcessing
    {
        $this->setStatuses($record, 'processing');
        $record->setErrorMessage(null);
        $record->setProcessedAt(null);

        return $this->processingMapper->saveRecord($record);
    }

    /**
     * @param array<string, array<string, mixed>> $parsed
     */
    private function markExtractionCompleted(ProjectFileProcessing $record, array $parsed): ProjectFileProcessing
    {
        $record->setExtractedJson(json_encode($parsed, JSON_UNESCAPED_SLASHES));
        $missingFields = $this->findMissingFieldNames($parsed);
        $this->setStatuses($record, $missingFields === [] ? 'done' : 'aborted');
        $record->setErrorMessage($missingFields === [] ? null : $this->buildMissingFieldsMessage($missingFields));
        $record->setProcessedAt(new DateTime());

        return $this->processingMapper->saveRecord($record);
    }

    private function markExtractionFailed(ProjectFileProcessing $record, Throwable $e): ProjectFileProcessing
    {
        $this->setStatuses($record, 'failed');

        return $this->storeFailure($record, 'Project document extraction failed', $e);
    }

    private function markPipelineFailed(ProjectFileProcessing $record, Throwable $e): ProjectFileProcessing
    {
        $this->setStatuses($record, 'failed');

        return $this->storeFailure($record, 'Project document extraction pipeline failed', $e);
    }

    private function setStatuses(ProjectFileProcessing $record, string $status): void
    {
        $record->setOcrStatus($status);
    }

    private function storeFailure(ProjectFileProcessing $record, string $logMessage, Throwable $e): ProjectFileProcessing
    {
        $record->setErrorMessage($this->truncateMessage($e->getMessage()));
        $record->setProcessedAt(new DateTime());
        $failedRecord = $this->processingMapper->saveRecord($record);

        $this->logger->warning($logMessage, [
            'app' => 'projectcreatoraio',
            'fileId' => $record->getFileId(),
            'projectId' => $record->getProjectId(),
            'exception' => $e,
        ]);

        return $failedRecord;
    }

    private function truncateMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            // Stored on the processing record and shown to every project member.
            return $this->contentL10n->get()->t('OCR processing failed.');
        }

        return substr($message, 0, 2000);
    }

    /**
     * @param array<string, array<string, mixed>> $parsed
     * @return string[]
     */
    private function findMissingFieldNames(array $parsed): array
    {
        $missing = [];

        foreach ($parsed as $fieldName => $payload) {
            $value = is_array($payload) ? ($payload['value'] ?? null) : null;
            if (!is_scalar($value) || trim((string) $value) === '') {
                $missing[] = is_string($fieldName) && trim($fieldName) !== '' ? $fieldName : $this->contentL10n->get()->t('Unknown field');
            }
        }

        return $missing;
    }

    /**
     * @param string[] $missingFields
     */
    private function buildMissingFieldsMessage(array $missingFields): string
    {
        $preview = implode(', ', array_slice($missingFields, 0, 5));
        $suffix = count($missingFields) > 5 ? ', ...' : '';

        // Stored on the processing record and shown to every project member.
        return $this->contentL10n->get()->t('Processing aborted. Missing required fields: %s%s.', [$preview, $suffix]);
    }
}
