<?php
namespace OCA\Projectcreatoraio\Controller;

use OCA\ProjectCreatorAIO\BackgroundJob\GenerateProjectExportJob;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityAggregationService;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCA\ProjectCreatorAIO\Service\ProjectDownloadService;
use OCA\ProjectCreatorAIO\Service\ProjectRetentionService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectNote;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCA\Deck\NoPermissionException;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\BackgroundJob\IJobList;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\IRequest;
use OCP\App\IAppManager;
use OCP\Files\IRootFolder;
use OCP\Files\Folder;
use OCP\Files\File;
use Throwable;

class ProjectApiController extends Controller
{
    public const APP_ID = 'projectcreatoraio';

    public function __construct(
        string $appName,
        IRequest $request,
        protected IUserSession $userSession,
        protected ProjectMapper $projectMapper,
        protected ProjectNoteMapper $noteMapper,
        protected ProjectService $projectService,
        private ProjectActivityService $projectActivityService,
        private ProjectActivityAggregationService $projectActivityAggregationService,
        private ProjectNotificationService $projectNotificationService,
        private ProjectRetentionService $projectRetentionService,
        private ProjectDownloadService $downloadService,
        private ProjectTalkIntegrationService $talkIntegrationService,
        private IGroupManager $iGroupManager,
        private IRootFolder $rootFolder,
        private IJobList $jobList,
        private readonly IAppManager $appManager,
        private ?OrganizationUserMapper $organizationUserMapper = null,
    ) {
        parent::__construct($appName, $request);
        $this->request = $request;
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function get(int $projectId)
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $notes = $this->projectService->getProjectNotes($projectId);
        $payload = $this->projectService->buildProjectPayload($project);
        $payload['public_note'] = $notes['public'] ?? '';
        $payload['private_note'] = $notes['private'] ?? '';
        $payload['private_note_available'] = (bool) ($notes['private_available'] ?? true);

        return new DataResponse($payload);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getCardVisibility(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $payload = $this->projectService->getProjectCardVisibility($projectId);
        $payload['can_edit'] = $this->canEditPreparationWeeks($project);

        return new DataResponse($payload);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function updateCardVisibility(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        if (!$this->canEditPreparationWeeks($project)) {
            throw new OCSForbiddenException('Only project managers can update form settings');
        }

        $params = $this->request->getParams();
        $payload = [];
        $fields = [
            'cv_object_ownership',
            'cv_trace_ownership',
            'cv_building_type',
            'cv_avp_location',
        ];

        if (is_array($params)) {
            foreach ($fields as $field) {
                if (array_key_exists($field, $params)) {
                    $payload[$field] = $params[$field];
                }
            }
        }

        $result = $this->projectService->updateProjectCardVisibility($projectId, $payload);
        $result['can_edit'] = $this->canEditPreparationWeeks($project);

        return new DataResponse($result);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listMembers(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $members = $this->projectService->getProjectMembers($projectId);

        return new DataResponse([
            'members' => $members,
            'functionalRoles' => $this->projectService->getProjectFunctionalRoles($projectId),
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getDeckAccessSummary(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        return new DataResponse($this->projectService->getDeckAccessSummary(
            $projectId,
            $currentUser->getUID(),
            $this->canEditPreparationWeeks($project),
        ));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function addMember(int $projectId, string $userId = ''): DataResponse
    {
        $params = $this->request->getParams();
        if (is_array($params) && array_key_exists('userId', $params) && is_string($params['userId'])) {
            $userId = $params['userId'];
        }

        $drasciRoles = [];
        if (is_array($params) && array_key_exists('drascivsRoles', $params) && is_array($params['drascivsRoles'])) {
            $drasciRoles = $params['drascivsRoles'];
        } elseif (is_array($params) && array_key_exists('drasciRoles', $params) && is_array($params['drasciRoles'])) {
            $drasciRoles = $params['drasciRoles'];
        } elseif (is_array($params) && array_key_exists('drasciRole', $params) && is_string($params['drasciRole'])) {
            $drasciRoles = [$params['drasciRole']];
        }

        $functionalRoleKeys = null;
        if (is_array($params) && array_key_exists('functionalRoleKeys', $params) && is_array($params['functionalRoleKeys'])) {
            $functionalRoleKeys = $params['functionalRoleKeys'];
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);
        if (!$this->canEditPreparationWeeks($project)) {
            throw new OCSForbiddenException('Only project owners and organization administrators can manage project members.');
        }

        $result = $this->projectService->addMemberToProject($projectId, $userId, $drasciRoles, $functionalRoleKeys);

        return new DataResponse($result, $result['added'] ? 201 : 200);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function updateMemberRole(int $projectId, string $userId): DataResponse
    {
        $params = $this->request->getParams();
        $drasciRoles = null;
        if (is_array($params) && array_key_exists('drascivsRoles', $params) && is_array($params['drascivsRoles'])) {
            $drasciRoles = $params['drascivsRoles'];
        } elseif (is_array($params) && array_key_exists('drasciRoles', $params) && is_array($params['drasciRoles'])) {
            $drasciRoles = $params['drasciRoles'];
        } elseif (is_array($params) && array_key_exists('drasciRole', $params) && is_string($params['drasciRole'])) {
            $drasciRoles = [$params['drasciRole']];
        }

        $functionalRoleKeys = null;
        if (is_array($params) && array_key_exists('functionalRoleKeys', $params) && is_array($params['functionalRoleKeys'])) {
            $functionalRoleKeys = $params['functionalRoleKeys'];
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);
        if (!$this->canEditPreparationWeeks($project)) {
            throw new OCSForbiddenException('Only project owners and organization administrators can manage project members.');
        }

        $result = $this->projectService->updateProjectMemberRoles($projectId, $userId, $drasciRoles, $functionalRoleKeys);

        return new DataResponse($result);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function updateNotes(
        int $projectId,
        ?string $public_note = null,
        ?string $private_note = null,
    ): DataResponse {
        // Some setups don't map snake_case JSON keys reliably to method args.
        // Fall back to raw request params while still allowing empty-string updates.
        $params = $this->request->getParams();
        if (is_array($params)) {
            if (array_key_exists('public_note', $params)) {
                $public_note = is_string($params['public_note']) ? $params['public_note'] : '';
            }
            if (array_key_exists('private_note', $params)) {
                $private_note = is_string($params['private_note']) ? $params['private_note'] : '';
            }
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $notes = $this->projectService->updateProjectNotes($projectId, $public_note, $private_note);

        return new DataResponse([
            'public_note' => $notes['public'] ?? '',
            'private_note' => $notes['private'] ?? '',
            'private_note_available' => (bool) ($notes['private_available'] ?? true),
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listNotes(int $projectId, string $visibility = 'public', string $noteType = '', int $page = 1, int $limit = 12): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $visibility = in_array($visibility, ['public', 'private', 'cards'], true) ? $visibility : 'public';
        $noteType = ProjectNote::isValidNoteType($noteType) ? $noteType : '';
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        if ($visibility === 'cards') {
            try {
                $result = $this->projectService->getCardNotesList($projectId, $currentUser->getUID(), $page, $limit);
            } catch (NoPermissionException) {
                throw new OCSForbiddenException('You do not have permission to view this Deck board');
            }
        } else {
            $result = $this->projectService->getProjectNotesList($projectId, $currentUser->getUID(), $visibility, $noteType, $page, $limit);
        }

        return new DataResponse([
            'notes' => $result['notes'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
            'private_available' => $result['private_available'],
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listCardComments(int $projectId, int $page = 1, int $limit = 20): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        try {
            $result = $this->projectService->getCardCommentsList($projectId, $currentUser->getUID(), $page, $limit);
        } catch (NoPermissionException) {
            throw new OCSForbiddenException('You do not have permission to view this Deck board');
        }

        return new DataResponse([
            'comments' => $result['comments'],
            'total' => $result['total'],
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getNote(int $projectId, int $noteId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $note = $this->noteMapper->find($noteId);
        if ($note === null) {
            throw new OCSNotFoundException("Note with ID $noteId not found");
        }

        if ($note->getProjectId() !== $projectId) {
            throw new OCSNotFoundException("Note not found for this project");
        }

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        // Private notes can only be accessed by their creator
        if ($note->getVisibility() === 'private' && $note->getUserId() !== $currentUser->getUID()) {
            throw new OCSForbiddenException('You do not have permission to view this note');
        }

        return new DataResponse($note->jsonSerialize());
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function createNote(
        int $projectId,
        string $title,
        string $content,
        string $visibility = 'public',
        mixed $noteType = ProjectNote::NOTE_TYPE_GENERAL,
    ): DataResponse {
        $params = $this->request->getParams();
        if (is_array($params)) {
            if (array_key_exists('title', $params)) {
                $title = is_string($params['title']) ? $params['title'] : '';
            }
            if (array_key_exists('content', $params)) {
                $content = is_string($params['content']) ? $params['content'] : '';
            }
            if (array_key_exists('visibility', $params)) {
                $visibility = is_string($params['visibility']) ? $params['visibility'] : 'public';
            }
            if (array_key_exists('noteType', $params)) {
                if (!is_string($params['noteType'])) {
                    return new DataResponse(['message' => 'Invalid note type'], 400);
                }
                $noteType = $params['noteType'];
            }
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        // Validate visibility
        if ($visibility !== 'public' && $visibility !== 'private') {
            return new DataResponse(['message' => 'Invalid visibility. Use "public" or "private"'], 400);
        }

        if (!ProjectNote::isValidNoteType($noteType)) {
            return new DataResponse(['message' => 'Invalid note type'], 400);
        }

        // Check if private notes are available
        if ($visibility === 'private') {
            $hasPrivateFolder = $this->projectService->hasPrivateFolderForUser($projectId, $currentUser->getUID());
            if (!$hasPrivateFolder) {
                return new DataResponse(['message' => 'Private notes are not available for this user'], 403);
            }
        }

        $content = $this->sanitizeNoteHtml($content);

        $note = $this->noteMapper->createNote(
            $projectId,
            $currentUser->getUID(),
            $title,
            $content,
            $visibility,
            $noteType,
        );

        $this->projectActivityService->recordNoteCreated($project, $note, $currentUser);

        return new DataResponse($note->jsonSerialize(), 201);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function updateNote(
        int $projectId,
        int $noteId,
        ?string $title = null,
        ?string $content = null,
        mixed $noteType = null,
    ): DataResponse {
        $params = $this->request->getParams();
        if (is_array($params)) {
            if (array_key_exists('title', $params)) {
                $title = is_string($params['title']) ? $params['title'] : '';
            }
            if (array_key_exists('content', $params)) {
                $content = is_string($params['content']) ? $params['content'] : '';
            }
            if (array_key_exists('noteType', $params)) {
                if (!is_string($params['noteType'])) {
                    return new DataResponse(['message' => 'Invalid note type'], 400);
                }
                $noteType = $params['noteType'];
            }
        }

        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $note = $this->noteMapper->find($noteId);
        if ($note === null) {
            throw new OCSNotFoundException("Note with ID $noteId not found");
        }

        if ($note->getProjectId() !== $projectId) {
            throw new OCSNotFoundException("Note not found for this project");
        }

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        // Only the creator can update the note
        if ($note->getUserId() !== $currentUser->getUID()) {
            throw new OCSForbiddenException('You do not have permission to update this note');
        }

        if ($title !== null) {
            $note->setTitle($title);
        }
        if ($content !== null) {
            $note->setContent($this->sanitizeNoteHtml($content));
        }
        if ($noteType !== null) {
            if (!ProjectNote::isValidNoteType($noteType)) {
                return new DataResponse(['message' => 'Invalid note type'], 400);
            }
            $note->setNoteType($noteType);
        }

        $updatedNote = $this->noteMapper->updateNote($note);

        $this->projectActivityService->recordNoteUpdated($project, $updatedNote, $currentUser);

        return new DataResponse($updatedNote->jsonSerialize());
    }

    /**
     * Sanitize HTML note content to avoid XSS.
     *
     * We keep a small allowlist to support basic formatting, links and lists.
     */
    private function sanitizeNoteHtml(string $html): string {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Prefer the Nextcloud sanitizer when available.
        if (class_exists('\\OCP\\Util') && method_exists('\\OCP\\Util', 'sanitizeHTML')) {
            try {
                return (string) \OCP\Util::sanitizeHTML($html);
            } catch (Throwable $e) {
                // fall back to local allowlist sanitizer
            }
        }

        return $this->sanitizeHtmlAllowlist($html);
    }

    private function sanitizeHtmlAllowlist(string $html): string {
        $allowedTags = [
            'div', 'span',
            'p', 'br',
            'strong', 'b',
            'em', 'i',
            'u',
            's', 'strike',
            'ul', 'ol', 'li',
            'blockquote',
            'h1', 'h2', 'h3', 'h4',
            'pre', 'code',
            'a',
        ];

        $allowedByTag = [
            'a' => ['href', 'target', 'rel', 'title'],
        ];

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        // Wrap to handle fragments and preserve multiple root nodes
        $wrapped = '<div id="__wrap">' . $html . '</div>';
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $wrap = $doc->getElementById('__wrap');
        if ($wrap === null) {
            return '';
        }

        $this->sanitizeDomNode($wrap, $allowedTags, $allowedByTag);

        $out = '';
        foreach (iterator_to_array($wrap->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * @param array<string> $allowedTags
     * @param array<string, array<string>> $allowedByTag
     */
    private function sanitizeDomNode(\DOMNode $node, array $allowedTags, array $allowedByTag): void {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (!in_array($tag, $allowedTags, true)) {
                // sanitize children first, then unwrap unknown element
                foreach (iterator_to_array($node->childNodes) as $child) {
                    $this->sanitizeDomNode($child, $allowedTags, $allowedByTag);
                }

                $parent = $node->parentNode;
                if ($parent !== null) {
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                }
                return;
            }

            // Remove dangerous/unused attributes
            if ($node->hasAttributes()) {
                $allowedAttrs = $allowedByTag[$tag] ?? [];
                /** @var \DOMNamedNodeMap $attrs */
                $attrs = $node->attributes;
                // Iterate backwards because we'll remove attributes
                for ($i = $attrs->length - 1; $i >= 0; $i--) {
                    $attr = $attrs->item($i);
                    if ($attr === null) {
                        continue;
                    }
                    $name = strtolower($attr->nodeName);
                    if (str_starts_with($name, 'on') || $name === 'style') {
                        $node->removeAttributeNode($attr);
                        continue;
                    }
                    if (!in_array($name, $allowedAttrs, true)) {
                        $node->removeAttributeNode($attr);
                    }
                }
            }

            if ($tag === 'a') {
                $href = $node->attributes?->getNamedItem('href')?->nodeValue ?? '';
                if (!$this->isSafeHref($href)) {
                    $node->removeAttribute('href');
                }
                // Force safe defaults
                if ($node->hasAttribute('href')) {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $node->removeAttribute('target');
                    $node->removeAttribute('rel');
                }
            }
        }

        // Recurse into children
        $child = $node->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;
            $this->sanitizeDomNode($child, $allowedTags, $allowedByTag);
            $child = $next;
        }
    }

    private function isSafeHref(string $href): bool {
        $href = trim($href);
        if ($href === '') {
            return false;
        }
        if (str_starts_with($href, '#') || str_starts_with($href, '/')) {
            return true;
        }
        $parsed = parse_url($href);
        if (!is_array($parsed)) {
            return false;
        }
        if (!isset($parsed['scheme'])) {
            // relative URL
            return true;
        }
        $scheme = strtolower((string) $parsed['scheme']);
        return in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function deleteNote(int $projectId, int $noteId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $note = $this->noteMapper->find($noteId);
        if ($note === null) {
            throw new OCSNotFoundException("Note with ID $noteId not found");
        }

        if ($note->getProjectId() !== $projectId) {
            throw new OCSNotFoundException("Note not found for this project");
        }

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        // Only the creator can delete the note
        if ($note->getUserId() !== $currentUser->getUID()) {
            throw new OCSForbiddenException('You do not have permission to delete this note');
        }

        $success = $this->noteMapper->deleteNote($noteId);

        if ($success) {
            $this->projectActivityService->recordNoteDeleted($project, $note, $currentUser);
        }

        return new DataResponse(['deleted' => $success]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function create(
        string $name,
        string $number,
        int $type,
        array $members = [],
        string $groupId = '',
        ?int $organizationId = null,
        string $description = '',
        ?string $client_name = null,
        mixed $client_role = null,
        ?string $client_phone = null,
        ?string $client_email = null,
        ?string $client_address = null,
        ?string $loc_street = null,
        ?string $loc_city = null,
        ?string $loc_zip = null,
        ?string $request_date = null,
        ?string $desired_execution_date = null,
        ?int $required_preparation_weeks = null,
        ?int $required_preparation_days = null,
    ): DataResponse {

        $params = $this->request->getParams();
        if (is_array($params)) {
            if (array_key_exists('request_date', $params)) {
                $request_date = is_string($params['request_date']) ? $params['request_date'] : null;
            }
            if (array_key_exists('desired_execution_date', $params)) {
                $desired_execution_date = is_string($params['desired_execution_date']) ? $params['desired_execution_date'] : null;
            }
            if (array_key_exists('required_preparation_weeks', $params)) {
                $raw = $params['required_preparation_weeks'];
                if (is_int($raw)) {
                    $required_preparation_weeks = $raw;
                } elseif (is_string($raw) && $raw !== '' && is_numeric($raw)) {
                    $required_preparation_weeks = (int) $raw;
                }
            }
            if (array_key_exists('required_preparation_days', $params)) {
                $raw = $params['required_preparation_days'];
                if (is_int($raw)) {
                    $required_preparation_days = $raw;
                } elseif (is_string($raw) && $raw !== '' && is_numeric($raw)) {
                    $required_preparation_days = (int) $raw;
                }
            }
        }

        if ($required_preparation_weeks === null && $required_preparation_days !== null) {
            $days = max(0, (int) $required_preparation_days);
            $required_preparation_weeks = (int) ceil($days / 7);
        }
        if ($required_preparation_weeks !== null && $required_preparation_weeks < 0) {
            $required_preparation_weeks = 0;
        }

        if ($organizationId === null && $groupId !== '' && ctype_digit($groupId)) {
            $organizationId = (int) $groupId;
        }

        try {
            $project = $this->projectService->createProject(
                $name,
                $number,
                $type,
                $members,
                $description,
                $organizationId,
                $client_name,
                $client_role,
                $client_phone,
                $client_email,
                $client_address,
                $loc_street,
                $loc_city,
                $loc_zip,
                $required_preparation_weeks,
            );

            return new DataResponse([
                'message' => 'Project created successfully',
                'projectId' => $project->getId(),
            ]);

        } catch (Throwable $e) {
            $statusCode = (int) $e->getCode();
            if ($statusCode < 400 || $statusCode > 599) {
                $statusCode = 500;
            }

            return new DataResponse([
                'message' => 'Failed to create project: ' . $e->getMessage()
            ], $statusCode);
        }
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function searchUsers(
        string $search = '',
        ?int $organizationId = null,
        int $limit = 25,
        int $offset = 0,
    ): DataResponse {
        $users = $this->projectService->searchUsers($search, $organizationId, $limit, $offset);
        return new DataResponse(['users' => $users]);
    }


    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function list(): DataResponse
    {
        return new DataResponse($this->projectService->buildProjectPayloads($this->getAccessibleProjects()));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function boardMappings(): DataResponse
    {
        $mappings = [];
        foreach ($this->getAccessibleProjects() as $project) {
            $boardId = $project->getBoardId();
            if ($boardId === null || $boardId === '') {
                continue;
            }

            $mappings[] = [
                'boardId' => $boardId,
                'name' => $project->getName(),
                'number' => $project->getNumber(),
                'type' => $project->getType(),
                ...$this->projectService->getBoardWorkflow((int)$boardId),
            ];
        }

        return new DataResponse($mappings);
    }

    private function getAccessibleProjects(): array
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        if ($this->iGroupManager->isAdmin($currentUser->getUID())) {
            return $this->projectMapper->list();
        }

        $membership = $this->organizationUserMapper->getOrganizationMembership($currentUser->getUID());
        if ($membership === null) {
            throw new OCSForbiddenException('You are not assigned to an organization');
        }

        if ($membership['role'] === 'admin') {
            return $this->projectMapper->findByOrganizationId((int) $membership['organization_id']);
        }

        return $this->projectMapper->findByUserIdAndOrganizationId(
            $currentUser->getUID(),
            (int) $membership['organization_id'],
        );
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listMine(): DataResponse
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $projects = $this->projectMapper->findByUserId($currentUser->getUID());

        return new DataResponse($this->projectService->buildProjectPayloads($projects));
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function context(): DataResponse
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $userId = $currentUser->getUID();
        $isGlobalAdmin = $this->iGroupManager->isAdmin($userId);
        $membership = $this->organizationUserMapper !== null ? $this->organizationUserMapper->getOrganizationMembership($userId) : null;

        return new DataResponse([
            'userId' => $userId,
            'isGlobalAdmin' => $isGlobalAdmin,
            'organizationRole' => $membership['role'] ?? null,
            'organizationId' => isset($membership['organization_id']) ? (int) $membership['organization_id'] : null,
            'features' => [
                'deck' => $this->appManager->isEnabledForUser('deck'),
                'talk' => $this->appManager->isEnabledForUser('spreed'),
                'calendar' => $this->appManager->isEnabledForUser('calendar'),
                'libresign' => $this->appManager->isEnabledForUser('signatures') || $this->appManager->isEnabledForUser('libresign'),
                'signatures' => $this->appManager->isEnabledForUser('signatures') || $this->appManager->isEnabledForUser('libresign'),
            ]
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getProjectFiles(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);
        $files = $this->projectService->getProjectFiles($projectId);
        return new DataResponse(['files' => $files]);
    }

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getWhiteboardInfo(int $projectId): DataResponse {
		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSNotFoundException("Project with ID $projectId not found");
		}

		$this->assertCanAccessProject($project);
		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		$whiteboardIdRaw = $project->getWhiteBoardId();
		$whiteboardId = ($whiteboardIdRaw !== null && $whiteboardIdRaw !== '') ? (int)$whiteboardIdRaw : 0;
		if ($whiteboardId <= 0) {
			throw new OCSNotFoundException('Whiteboard not linked');
		}

		$userFolder = $this->rootFolder->getUserFolder($currentUser->getUID());
		$file = $this->resolveWhiteboardFile($project, $userFolder, $whiteboardId);

		if ($file === null) {
			throw new OCSNotFoundException('Whiteboard file not found');
		}

		$relative = $userFolder->getRelativePath($file->getPath());
		if (!is_string($relative) || $relative === '') {
			throw new OCSNotFoundException('Whiteboard path not accessible');
		}

		return new DataResponse([
			'fileId' => $file->getId(),
			'name' => $file->getName(),
			'mimetype' => $file->getMimeType(),
			'size' => $file->getSize(),
			'mtime' => $file->getMTime(),
			'path' => '/' . ltrim($relative, '/'),
		]);
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getActivity(int $projectId, int $limit = 20, int $offset = 0, ?string $source = null, ?string $cursor = null): DataResponse {
		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSNotFoundException("Project with ID $projectId not found");
		}

		$this->assertCanAccessProject($project);

		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);
		if ($cursor === null && $offset >= 500) {
			throw new OCSBadRequestException('Use cursor pagination for activity offsets of 500 or greater');
		}

		$currentUser = $this->userSession->getUser();
		if ($currentUser === null) {
			throw new OCSForbiddenException('Authentication required');
		}

		return new DataResponse($this->projectActivityAggregationService->getActivity(
			$project,
			$currentUser->getUID(),
			$limit,
			$source,
			$cursor,
			$offset,
		));
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function getWhiteboardActivity(int $projectId, int $limit = 20, int $offset = 0): DataResponse {
		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			throw new OCSNotFoundException("Project with ID $projectId not found");
		}

		$this->assertCanAccessProject($project);

		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);

		$events = $this->projectActivityService->getWhiteboardActivity($projectId, $limit + 1, $offset);
		$hasMore = count($events) > $limit;
		if ($hasMore) {
			$events = array_slice($events, 0, $limit);
		}

		return new DataResponse([
			'events' => array_map(fn ($e) => $e->jsonSerialize(), $events),
			'hasMore' => $hasMore,
		]);
	}

	private function resolveWhiteboardFile(Project $project, Folder $userFolder, int $whiteboardId): ?File {
		$node = $userFolder->getFirstNodeById($whiteboardId);
		if ($node instanceof File) {
			return $node;
		}

		foreach ($this->rootFolder->getById($whiteboardId) as $rootNode) {
			if ($rootNode instanceof File) {
				return $rootNode;
			}
		}

		$folderId = (int) ($project->getFolderId() ?? 0);
		if ($folderId > 0) {
			foreach ($this->rootFolder->getById($folderId) as $folderNode) {
				if ($folderNode instanceof Folder) {
					$file = $this->findWhiteboardInFolder($folderNode, $project->getName());
					if ($file instanceof File) {
						return $file;
					}
				}
			}
		}

		$folderPath = trim((string) $project->getFolderPath());
		if ($folderPath !== '') {
			try {
				$folderNode = $userFolder->get($folderPath);
				if ($folderNode instanceof Folder) {
					return $this->findWhiteboardInFolder($folderNode, $project->getName());
				}
			} catch (Throwable) {
			}
		}

		return null;
	}

	private function findWhiteboardInFolder(Folder $folder, string $projectName): ?File {
		$preferred = trim($projectName) !== '' ? $projectName . '.whiteboard' : null;
		foreach ($folder->getDirectoryListing() as $child) {
			if ($child instanceof File) {
				$name = $child->getName();
				if (!is_string($name) || $name === '') {
					continue;
				}
				$lower = strtolower($name);
				if ($preferred !== null && $name === $preferred) {
					return $child;
				}
				if (str_ends_with($lower, '.whiteboard') || str_ends_with($lower, '.excalidraw')) {
					return $child;
				}
			}
		}

		return null;
	}

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getByBoardId(int $boardId): DataResponse
    {
        $project = $this->projectMapper->findByBoardId($boardId);
        if ($project === null) {
            throw new OCSNotFoundException("Project not found for board $boardId");
        }

        $this->assertCanAccessProject($project);
        return new DataResponse($project);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getByTalkConversationToken(string $token): DataResponse
    {
        $project = $this->projectMapper->findByTalkConversationToken($token);
        if ($project === null) {
            throw new OCSNotFoundException("Project not found for conversation $token");
        }

        $this->assertCanAccessProject($project);
        return new DataResponse($project);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getProjectChatMessages(int $projectId, int $limit = 50, int $offset = 0): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $token = trim((string)($project->getTalkConversationToken() ?? ''));
        if ($token === '') {
            return new DataResponse(['messages' => [], 'hasMore' => false, 'nextOffset' => 0]);
        }

        $result = $this->talkIntegrationService->getConversationMessages($token, $limit, $offset);
        return new DataResponse($result);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listDirectChats(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $chats = $this->projectService->listUserDirectChats($projectId, $currentUser->getUID());
        return new DataResponse($chats);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getOrCreateDirectChat(int $projectId, string $targetUserId = ''): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $targetUserId = trim($targetUserId);
        if ($targetUserId === '') {
            $targetUserId = trim((string) ($this->request->getParam('targetUserId', '')));
        }

        if ($targetUserId === '') {
            throw new OCSBadRequestException('Target user ID is required');
        }

        if ($targetUserId === $currentUser->getUID()) {
            throw new OCSBadRequestException('Cannot create a direct chat with yourself');
        }

        $chat = $this->projectService->getOrCreateDirectChat($projectId, $currentUser->getUID(), $targetUserId);
        return new DataResponse($chat);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function createDirectChat(int $projectId, string $targetUserId = ''): DataResponse
    {
        return $this->getOrCreateDirectChat($projectId, $targetUserId);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function getDirectChatMessages(int $projectId, string $targetUserId, int $limit = 50, int $offset = 0): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $targetUserId = trim($targetUserId);
        if ($targetUserId === '') {
            throw new OCSBadRequestException('Target user ID is required');
        }

        $messages = $this->projectService->getDirectChatMessages(
            $projectId,
            $currentUser->getUID(),
            $targetUserId,
            $limit,
            $offset
        );
        return new DataResponse($messages);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function listByUser(string $userId): DataResponse
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $isGlobalAdmin = $this->iGroupManager->isAdmin($currentUser->getUID());
        if (!$isGlobalAdmin) {
            $currentMembership = $this->organizationUserMapper->getOrganizationMembership($currentUser->getUID());
            if ($currentMembership === null) {
                throw new OCSForbiddenException('You are not assigned to an organization');
            }

            if ($currentMembership['role'] !== 'admin') {
                if ($currentUser->getUID() !== $userId) {
                    throw new OCSForbiddenException('Members can only view their own projects');
                }

                $projects = $this->projectMapper->findByUserIdAndOrganizationId(
                    $userId,
                    (int) $currentMembership['organization_id'],
                );

                return new DataResponse($projects);
            }

            $targetMembership = $this->organizationUserMapper->getOrganizationMembership($userId);
            if ($targetMembership === null || (int) $targetMembership['organization_id'] !== (int) $currentMembership['organization_id']) {
                throw new OCSNotFoundException('User not found in your organization');
            }

            $projects = $this->projectMapper->findByUserIdAndOrganizationId(
                $userId,
                (int) $currentMembership['organization_id'],
            );

            return new DataResponse($projects);
        }

        $projects = $this->projectMapper->findByUserId($userId);
        return new DataResponse($projects);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $name = null,
        ?string $number = null,
        ?int $type = null,
        ?string $description = null,
        ?string $client_name = null,
        mixed $client_role = null,
        ?string $client_phone = null,
        ?string $client_email = null,
        ?string $client_address = null,
        ?string $loc_street = null,
        ?string $loc_city = null,
        ?string $loc_zip = null,
        ?string $external_ref = null,
        ?int $status = null,
        ?int $required_preparation_weeks = null
    ): DataResponse {
        $params = $this->request->getParams();
        if (is_array($params) && array_key_exists('required_preparation_weeks', $params)) {
            $raw = $params['required_preparation_weeks'];
            if (is_int($raw)) {
                $required_preparation_weeks = $raw;
            } elseif (is_string($raw) && $raw !== '' && is_numeric($raw)) {
                $required_preparation_weeks = (int) $raw;
            }
        }

        $existingProject = $this->projectMapper->find($id);
        if ($existingProject === null) {
            throw new OCSNotFoundException("Project with ID $id not found");
        }

        $this->assertCanAccessProject($existingProject);

        $isAdminForProject = $this->canAdministerProject($existingProject);
        $canEditPreparationWeeks = $this->canEditPreparationWeeks($existingProject);
        $isProjectOwner = false;
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null) {
            $ownerId = trim((string) $existingProject->getOwnerId());
            $isProjectOwner = $ownerId !== '' && $ownerId === $currentUser->getUID();
        }
        if (!$isAdminForProject) {
            $restrictedFields = [
                'name',
                'number',
                'type',
                'description',
                'external_ref',
                'status',
                'required_preparation_weeks',
            ];

            $providedFields = array_keys($this->request->getParams());
            $attemptedRestrictedFields = array_values(array_intersect($restrictedFields, $providedFields));
            if ($attemptedRestrictedFields !== []) {
                if (
                    $isProjectOwner
                    && array_values(array_diff($attemptedRestrictedFields, ['name', 'status', 'required_preparation_weeks'])) === []
                ) {
                    // Project owners may only edit project name, status and required preparation weeks.
                } elseif (
                    $canEditPreparationWeeks
                    && count($attemptedRestrictedFields) === 1
                    && $attemptedRestrictedFields[0] === 'required_preparation_weeks'
                ) {
                    // Non-admin users with prep-weeks permission may only edit this field.
                } else {
                    throw new OCSForbiddenException('Project members can only update client and location details');
                }
            }
        }

        $oldValues = [
            'name' => $existingProject->getName(),
            'number' => $existingProject->getNumber(),
            'type' => $existingProject->getType(),
            'description' => $existingProject->getDescription(),
            'status' => $existingProject->getStatus(),
            'client_name' => $existingProject->getClientName(),
            'client_role' => $existingProject->getClientRole(),
            'client_phone' => $existingProject->getClientPhone(),
            'client_email' => $existingProject->getClientEmail(),
            'client_address' => $existingProject->getClientAddress(),
            'loc_street' => $existingProject->getLocStreet(),
            'loc_city' => $existingProject->getLocCity(),
            'loc_zip' => $existingProject->getLocZip(),
            'external_ref' => $existingProject->getExternalRef(),
            'required_preparation_weeks' => $existingProject->getRequiredPreparationWeeks(),
        ];

        $updatedProject = $this->projectService->updateProjectDetails(
            $id,
            $name,
            $number,
            $type,
            $description,
            $client_name,
            $client_role,
            $client_phone,
            $client_email,
            $client_address,
            $loc_street,
            $loc_city,
            $loc_zip,
            $external_ref,
            $status,
            $required_preparation_weeks,
        );

        $changedFields = [];
        $newValues = [
            'name' => $updatedProject->getName(),
            'number' => $updatedProject->getNumber(),
            'type' => $updatedProject->getType(),
            'description' => $updatedProject->getDescription(),
            'status' => $updatedProject->getStatus(),
            'client_name' => $updatedProject->getClientName(),
            'client_role' => $updatedProject->getClientRole(),
            'client_phone' => $updatedProject->getClientPhone(),
            'client_email' => $updatedProject->getClientEmail(),
            'client_address' => $updatedProject->getClientAddress(),
            'loc_street' => $updatedProject->getLocStreet(),
            'loc_city' => $updatedProject->getLocCity(),
            'loc_zip' => $updatedProject->getLocZip(),
            'external_ref' => $updatedProject->getExternalRef(),
            'required_preparation_weeks' => $updatedProject->getRequiredPreparationWeeks(),
        ];
        foreach ($newValues as $field => $newVal) {
            if ($oldValues[$field] !== $newVal) {
                $changedFields[] = $field;
            }
        }

        if (!empty($changedFields)) {
            if (in_array('status', $changedFields, true) && (int) $newValues['status'] === ProjectStatus::ARCHIVED) {
                $this->projectActivityService->recordProjectArchived($existingProject, $currentUser);
            } elseif (in_array('status', $changedFields, true) && (int) $oldValues['status'] === ProjectStatus::ARCHIVED) {
                $this->projectActivityService->recordProjectRestored($existingProject, $currentUser);
            } else {
                $this->projectActivityService->recordProjectUpdated($existingProject, $currentUser, $changedFields);
            }

            if (in_array('status', $changedFields, true)) {
                $oldStatus = (int) $oldValues['status'];
                $newStatus = (int) $newValues['status'];
                if ($oldStatus !== $newStatus) {
                    $actorDisplayName = trim((string) ($currentUser->getDisplayName() ?? ''));
                    $actorUid = $currentUser->getUID();
                    $actorLabel = $actorDisplayName !== '' && $actorDisplayName !== $actorUid
                        ? $actorDisplayName . ' (' . $actorUid . ')'
                        : $actorUid;
                    $this->noteMapper->createStatusChangeNote(
                        $id,
                        $actorUid,
                        $oldStatus,
                        $newStatus,
                        'User action (' . $actorLabel . ')',
                    );
                }

                $this->projectNotificationService->notifyStatusChanged(
                    $updatedProject,
                    (int) $oldValues['status'],
                    (int) $newValues['status'],
                    $currentUser,
                );
            }
        }

        return new DataResponse($updatedProject->jsonSerialize());
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function delete(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        if (!$this->canDeleteProject($project)) {
            throw new OCSForbiddenException('Only organization admins or the project owner can delete this project');
        }

        $this->projectRetentionService->deleteProject($project);

        return new DataResponse([
            'deleted' => true,
            'projectId' => $projectId,
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function requestDownload(int $projectId): DataResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $argument = [
            'projectId' => $projectId,
            'userId' => $currentUser->getUID(),
        ];

        $this->jobList->add(GenerateProjectExportJob::class, $argument);

        return new DataResponse([
            'status' => 'queued',
            'message' => 'Export is being prepared. You will be notified when it is ready.',
        ]);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function downloadExport(int $projectId): StreamResponse
    {
        $project = $this->projectMapper->find($projectId);
        if ($project === null) {
            throw new OCSNotFoundException("Project with ID $projectId not found");
        }

        $this->assertCanAccessProject($project);

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        $exportFolder = $this->downloadService->getExportFolder($currentUser);
        if ($exportFolder === null) {
            throw new OCSNotFoundException('No export available. Please request a download first.');
        }

        $filenamePrefix = ProjectDownloadService::getExportFilenamePrefix($projectId);

        $zipFile = null;
        foreach ($exportFolder->getDirectoryListing() as $node) {
            if ($node instanceof File && str_starts_with($node->getName(), $filenamePrefix)) {
                if ($zipFile === null || $node->getMTime() > $zipFile->getMTime()) {
                    $zipFile = $node;
                }
            }
        }

        if ($zipFile === null) {
            throw new OCSNotFoundException('No export file found for this project. Please request a download first.');
        }

        $response = new StreamResponse($zipFile->fopen('rb'));
        $response->addHeader('Content-Type', 'application/zip');
        $response->addHeader('Content-Disposition', 'attachment; filename="' . $zipFile->getName() . '"');
        $response->addHeader('Content-Length', (string) $zipFile->getSize());

        return $response;
    }

    private function canAdministerProject(Project $project): bool
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return false;
        }

        if ($this->iGroupManager->isAdmin($currentUser->getUID())) {
            return true;
        }

        $membership = $this->organizationUserMapper->getOrganizationMembership($currentUser->getUID());
        if ($membership === null) {
            return false;
        }

        if ((int) $membership['organization_id'] !== (int) $project->getOrganizationId()) {
            return false;
        }

        return $membership['role'] === 'admin';
    }

    private function canEditPreparationWeeks(Project $project): bool
    {
        if ($this->canAdministerProject($project)) {
            return true;
        }

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return false;
        }

        $ownerId = trim((string) $project->getOwnerId());
        return $ownerId !== '' && $ownerId === $currentUser->getUID();
    }

    private function canDeleteProject(Project $project): bool
    {
        if ($this->canAdministerProject($project)) {
            return true;
        }

        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            return false;
        }

        $ownerId = trim((string) $project->getOwnerId());
        return $ownerId !== '' && $ownerId === $currentUser->getUID();
    }

    private function assertCanAccessProject(Project $project): void
    {
        $currentUser = $this->userSession->getUser();
        if ($currentUser === null) {
            throw new OCSForbiddenException('Authentication required');
        }

        if ($this->iGroupManager->isAdmin($currentUser->getUID())) {
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

        if (!$this->isProjectGroupMember($currentUser->getUID(), $project->getProjectGroupGid())) {
            throw new OCSNotFoundException('Project not found');
        }
    }

    private function isProjectGroupMember(string $userId, string $projectGroupGid): bool
    {
        if ($projectGroupGid === '') {
            return false;
        }

        return $this->iGroupManager->isInGroup($userId, $projectGroupGid);
    }
}
