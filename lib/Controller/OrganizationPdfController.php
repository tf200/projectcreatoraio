<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Controller;

use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\ProjectCreatorAIO\Service\OrganizationPdfService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class OrganizationPdfController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly OrganizationPdfService $organizationPdfService,
        private readonly ?OrganizationUserMapper $organizationUserMapper = null,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getPdfInfo(int $organizationId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId);
        $hasCustom = $this->organizationPdfService->hasCustomPdf($organizationId);

        return new DataResponse([
            'organization_id' => $organizationId,
            'has_custom_pdf' => $hasCustom,
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function uploadPdf(int $organizationId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId);

        $uploadedFile = $this->request->getUploadedFile('pdf');
        if ($uploadedFile === null || !isset($uploadedFile['tmp_name']) || $uploadedFile['tmp_name'] === '') {
            return new DataResponse(['error' => 'No PDF file uploaded.'], 400);
        }

        $fileContent = file_get_contents($uploadedFile['tmp_name']);
        if ($fileContent === false || $fileContent === '') {
            return new DataResponse(['error' => 'Empty PDF file.'], 400);
        }

        // Basic magic byte check for PDF header %PDF-
        if (!str_starts_with($fileContent, '%PDF-')) {
            return new DataResponse(['error' => 'The uploaded file is not a valid PDF document.'], 400);
        }

        $this->organizationPdfService->saveOrganizationPdf($organizationId, $fileContent);

        return new DataResponse([
            'success' => true,
            'organization_id' => $organizationId,
            'has_custom_pdf' => true,
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function deletePdf(int $organizationId): DataResponse
    {
        $this->assertCanManageOrganization($organizationId);

        $this->organizationPdfService->deleteOrganizationPdf($organizationId);

        return new DataResponse([
            'success' => true,
            'organization_id' => $organizationId,
            'has_custom_pdf' => false,
        ]);
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
            throw new OCSForbiddenException('Organization admin privilege required to manage default PDF templates');
        }

        return $userId;
    }
}
