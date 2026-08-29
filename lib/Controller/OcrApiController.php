<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectFileProcessing;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\FileProcessingPipelineService;
use OCA\ProjectCreatorAIO\Service\OcrDocumentService;
use OCA\ProjectCreatorAIO\Service\ProjectDeckOcrAttachmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCS\OCSException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class OcrApiController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly ProjectMapper $projectMapper,
        private readonly OcrDocumentService $ocrDocumentService,
        private readonly FileProcessingPipelineService $fileProcessingPipelineService,
        private readonly ProjectDeckOcrAttachmentService $projectDeckOcrAttachmentService,
        private readonly ?OrganizationUserMapper $organizationUserMapper = null,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listOrganizationDocumentTypes(int $organizationId, ?int $includeInactive = null): DataResponse
    {
        $this->assertCanManageOrganization($organizationId);
        $params = $this->request->getParams();
        $includeInactiveValue = $params['includeInactive'] ?? $params['include_inactive'] ?? $includeInactive;

        $types = $this->ocrDocumentService->listOrganizationDocumentTypes(
            $organizationId,
            $this->coerceBooleanFlag($includeInactiveValue, true),
        );

        return new DataResponse(['document_types' => $types]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function createOrganizationDocumentType(
        int $organizationId,
        string $name = '',
        array $fields = [],
        ?int $is_active = null,
    ): DataResponse {
        $this->assertCanManageOrganization($organizationId);

        $params = $this->request->getParams();
        if (is_array($params)) {
            if (array_key_exists('name', $params) && is_string($params['name'])) {
                $name = $params['name'];
            }
            if (array_key_exists('fields', $params) && is_array($params['fields'])) {
                $fields = $params['fields'];
            }
            if (array_key_exists('is_active', $params)) {
                $is_active = $params['is_active'];
            }
        }

        $documentType = $this->ocrDocumentService->createOrganizationDocumentType(
            $organizationId,
            $name,
            $fields,
            $this->coerceBooleanFlag($is_active, true),
        );

        return new DataResponse($documentType, 201);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function updateOrganizationDocumentType(int $organizationId, int $id): DataResponse
    {
        $this->assertCanManageOrganization($organizationId);

        $params = $this->request->getParams();
        $name = null;
        $fields = null;
        $isActive = null;

        if (is_array($params)) {
            if (array_key_exists('name', $params) && is_string($params['name'])) {
                $name = $params['name'];
            }
            if (array_key_exists('fields', $params) && is_array($params['fields'])) {
                $fields = $params['fields'];
            }
            if (array_key_exists('is_active', $params)) {
                $raw = $params['is_active'];
                if (is_bool($raw)) {
                    $isActive = $raw;
                } elseif (is_numeric((string) $raw)) {
                    $isActive = ((int) $raw) === 1;
                }
            }
        }

        $documentType = $this->ocrDocumentService->updateOrganizationDocumentType(
            $organizationId,
            $id,
            $name,
            $fields,
            $isActive,
        );

        return new DataResponse($documentType);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function deleteOrganizationDocumentType(int $organizationId, int $id): DataResponse
    {
        $this->assertCanManageOrganization($organizationId);
        $this->ocrDocumentService->deleteOrganizationDocumentType($organizationId, $id);

        return new DataResponse(['deleted' => true]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listProjectDocumentTypes(int $projectId): DataResponse
    {
        $project = $this->requireProject($projectId);
        $this->assertCanAccessProject($project);

        $types = $this->ocrDocumentService->listOrganizationDocumentTypes((int) $project->getOrganizationId(), false);
        return new DataResponse(['document_types' => $types]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function assignFileDocumentType(int $projectId, int $fileId, ?int $document_type_id = null): DataResponse
    {
        $project = $this->requireProject($projectId);
        $userId = $this->assertCanAccessProject($project);

        $params = $this->request->getParams();
        if (is_array($params) && array_key_exists('document_type_id', $params)) {
            $rawDocumentTypeId = $params['document_type_id'];
            if (is_int($rawDocumentTypeId)) {
                $document_type_id = $rawDocumentTypeId;
            } elseif (is_numeric((string) $rawDocumentTypeId)) {
                $document_type_id = (int) $rawDocumentTypeId;
            }
        }

        if (!is_int($document_type_id) || $document_type_id <= 0) {
            throw new OCSException('A document type ID is required.', 400);
        }

        $record = $this->ocrDocumentService->assignDocumentTypeToFile($project, $userId, $fileId, $document_type_id);
        $record = $this->fileProcessingPipelineService->processRecord($record);
        $documentType = $this->ocrDocumentService->requireOrganizationDocumentType((int) $project->getOrganizationId(), (int) $record->getDocumentTypeId());

        return new DataResponse($this->buildProcessingPayload($project, $record, $documentType));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getFileProcessing(int $projectId, int $fileId): DataResponse
    {
        $project = $this->requireProject($projectId);
        $this->assertCanAccessProject($project);

        $record = $this->ocrDocumentService->getProjectFileProcessing($projectId, $fileId);
        if ($record === null) {
            return new DataResponse([
                'processing' => null,
                'document_type' => null,
            ]);
        }

        return new DataResponse($this->buildProcessingPayload($project, $record));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function updateFileExtractedFields(int $projectId, int $fileId): DataResponse
    {
        $project = $this->requireProject($projectId);
        $this->assertCanAccessProject($project);

        $params = $this->request->getParams();
        $fields = is_array($params['fields'] ?? null) ? $params['fields'] : null;
        if ($fields === null) {
            throw new OCSException('A fields payload is required.', 400);
        }

        $record = $this->ocrDocumentService->updateExtractedFields($project, $fileId, $fields);

        return new DataResponse($this->buildProcessingPayload($project, $record));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function reprocessFile(int $projectId, int $fileId): DataResponse
    {
        $project = $this->requireProject($projectId);
        $userId = $this->assertCanAccessProject($project);

        $record = $this->ocrDocumentService->queueFileReprocess($project, $userId, $fileId);
        $record = $this->fileProcessingPipelineService->processRecord($record);

        return new DataResponse($this->buildProcessingPayload($project, $record));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function uploadCardAttachment(int $projectId, int $cardId, ?int $document_type_id = null): DataResponse
    {
        $project = $this->requireProject($projectId);
        $userId = $this->assertCanAccessProject($project);

        $params = $this->request->getParams();
        if (is_array($params) && array_key_exists('document_type_id', $params)) {
            $rawDocumentTypeId = $params['document_type_id'];
            if (is_int($rawDocumentTypeId)) {
                $document_type_id = $rawDocumentTypeId;
            } elseif (is_numeric((string) $rawDocumentTypeId)) {
                $document_type_id = (int) $rawDocumentTypeId;
            }
        }

        if (!is_int($document_type_id) || $document_type_id <= 0) {
            throw new OCSException('A document type ID is required.', 400);
        }

        $result = $this->projectDeckOcrAttachmentService->uploadAndAttach($project, $userId, $cardId, $document_type_id);
        if (($result['status'] ?? '') === 'rejected') {
            return new DataResponse($result, 422);
        }

        return new DataResponse($result, 201);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function finalizeCardAttachment(int $projectId, int $cardId): DataResponse
    {
        $project = $this->requireProject($projectId);
        $userId = $this->assertCanAccessProject($project);

        $params = $this->request->getParams();
        $processingId = is_numeric((string) ($params['processing_id'] ?? null)) ? (int) $params['processing_id'] : 0;
        $fields = is_array($params['fields'] ?? null) ? $params['fields'] : null;
        if ($processingId <= 0) {
            throw new OCSException('A processing ID is required.', 400);
        }
        if ($fields === null) {
            throw new OCSException('A fields payload is required.', 400);
        }

        $result = $this->projectDeckOcrAttachmentService->finalizePendingAttachment($project, $userId, $cardId, $processingId, $fields);
        if (($result['status'] ?? '') === 'rejected') {
            return new DataResponse($result, 422);
        }

        return new DataResponse($result, 201);
    }

    private function requireProject(int $projectId): Project
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException(sprintf('Project with ID %d not found', $projectId));
        }

        return $project;
    }

    private function assertCanManageOrganization(int $organizationId): string
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $userId = $currentUser->getUID();
        if ($this->groupManager->isAdmin($userId)) {
            return $userId;
        }

        if ($this->organizationUserMapper === null) {
            throw new OCSForbiddenException('Organization management is not available');
        }

        $membership = $this->organizationUserMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ((int) ($membership['organization_id'] ?? 0) !== $organizationId) {
            throw new OCSNotFoundException('Organization not found');
        }

        if (($membership['role'] ?? '') !== 'admin') {
            throw new OCSForbiddenException('Organization admin privilege required to manage OCR document types');
        }

        return $userId;
    }

    private function assertCanAccessProject(Project $project): string
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $userId = $currentUser->getUID();
        if ($this->groupManager->isAdmin($userId)) {
            return $userId;
        }

        if ($this->organizationUserMapper === null) {
            $projectGroupGid = trim((string) $project->getProjectGroupGid());
            if ($projectGroupGid === '' || !$this->groupManager->isInGroup($userId, $projectGroupGid)) {
                throw new OCSNotFoundException('Project not found');
            }
            return $userId;
        }

        $membership = $this->organizationUserMapper->getOrganizationMembership($userId);
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ((int) ($membership['organization_id'] ?? 0) !== (int) $project->getOrganizationId()) {
            throw new OCSNotFoundException('Project not found');
        }

        if (($membership['role'] ?? null) === 'admin') {
            return $userId;
        }

        $projectGroupGid = trim((string) $project->getProjectGroupGid());
        if ($projectGroupGid === '' || !$this->groupManager->isInGroup($userId, $projectGroupGid)) {
            throw new OCSNotFoundException('Project not found');
        }

        return $userId;
    }

    private function coerceBooleanFlag(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        return $default;
    }

    private function buildProcessingPayload(Project $project, ProjectFileProcessing $record, mixed $documentType = null): array
    {
        $payload = ['processing' => $record];
        if ($documentType !== null) {
            $payload['document_type'] = $documentType;
            return $payload;
        }

        if ($record->getDocumentTypeId() === null) {
            return $payload;
        }

        $payload['document_type'] = $this->ocrDocumentService->requireOrganizationDocumentType(
            (int) $project->getOrganizationId(),
            (int) $record->getDocumentTypeId(),
        );

        return $payload;
    }
}
