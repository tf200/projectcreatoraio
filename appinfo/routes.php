<?php

return [
    "routes" => [
        [
            "name" => "project_api#create",
            "url" => "/api/v1/projects",
            "verb" => "POST",
        ],
        [
            "name" => "project_api#list",
            "url" => "/api/v1/projects/list",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#boardMappings",
            "url" => "/api/v1/projects/board-mappings",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#listMine",
            "url" => "/api/v1/projects/mine",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#context",
            "url" => "/api/v1/projects/context",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#get",
            "url" => "/api/v1/projects/{projectId}",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getCardVisibility",
            "url" => "/api/v1/projects/{projectId}/card-visibility",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#updateCardVisibility",
            "url" => "/api/v1/projects/{projectId}/card-visibility",
            "verb" => "PUT",
        ],
        [
            "name" => "project_api#listMembers",
            "url" => "/api/v1/projects/{projectId}/members",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getDeckAccessSummary",
            "url" => "/api/v1/projects/{projectId}/deck-access-summary",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#addMember",
            "url" => "/api/v1/projects/{projectId}/members",
            "verb" => "POST",
        ],
        [
            "name" => "project_api#updateMemberRole",
            "url" => "/api/v1/projects/{projectId}/members/{userId}/role",
            "verb" => "PUT",
        ],
        // Legacy single-note endpoints (for backward compatibility)
        [
            "name" => "project_api#updateNotes",
            "url" => "/api/v1/projects/{projectId}/notes",
            "verb" => "PUT",
        ],
        // New multi-note endpoints
        [
            "name" => "project_api#listNotes",
            "url" => "/api/v1/projects/{projectId}/notes/list",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#listCardComments",
            "url" => "/api/v1/projects/{projectId}/card-comments",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getNote",
            "url" => "/api/v1/projects/{projectId}/notes/{noteId}",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#createNote",
            "url" => "/api/v1/projects/{projectId}/notes",
            "verb" => "POST",
        ],
        [
            "name" => "project_api#updateNote",
            "url" => "/api/v1/projects/{projectId}/notes/{noteId}",
            "verb" => "PUT",
        ],
        [
            "name" => "project_api#deleteNote",
            "url" => "/api/v1/projects/{projectId}/notes/{noteId}",
            "verb" => "DELETE",
        ],
        [
            'name' => 'project_api#listByUser',
            'url' => '/api/v1/users/{userId}/projects',
            'verb' => 'GET'
        ],
        [
            'name' => 'project_api#searchUsers',
            'url' => '/api/v1/users/search',
            'verb' => 'GET'
        ],
        [
            "name" => "project_api#getByBoardId",
            "url" => "/api/v1/projects/board/{boardId}",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getByTalkConversationToken",
            "url" => "/api/v1/projects/talk/{token}",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getProjectChatMessages",
            "url" => "/api/v1/projects/{projectId}/chat-messages",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getProjectFiles",
            "url" => "/api/v1/projects/{projectId}/files",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#requestDownload",
            "url" => "/api/v1/projects/{projectId}/download",
            "verb" => "POST",
        ],
        [
            "name" => "project_api#downloadExport",
            "url" => "/api/v1/projects/{projectId}/download",
            "verb" => "GET",
        ],
        [
            "name" => "ocr_api#listProjectDocumentTypes",
            "url" => "/api/v1/projects/{projectId}/ocr/document-types",
            "verb" => "GET",
        ],
        [
            "name" => "ocr_api#assignFileDocumentType",
            "url" => "/api/v1/projects/{projectId}/files/{fileId}/ocr/document-type",
            "verb" => "PUT",
        ],
        [
            "name" => "ocr_api#getFileProcessing",
            "url" => "/api/v1/projects/{projectId}/files/{fileId}/ocr",
            "verb" => "GET",
        ],
        [
            "name" => "ocr_api#updateFileExtractedFields",
            "url" => "/api/v1/projects/{projectId}/files/{fileId}/ocr/extracted",
            "verb" => "PUT",
        ],
        [
            "name" => "ocr_api#reprocessFile",
            "url" => "/api/v1/projects/{projectId}/files/{fileId}/ocr/reprocess",
            "verb" => "POST",
        ],
		[
			"name" => "signing_api#listProjectRequests",
			"url" => "/api/v1/projects/{projectId}/signing/requests",
			"verb" => "GET",
		],
		[
			"name" => "signing_api#getFileRequest",
			"url" => "/api/v1/projects/{projectId}/files/{fileId}/signing",
			"verb" => "GET",
		],
		[
			"name" => "signing_api#createFileRequest",
			"url" => "/api/v1/projects/{projectId}/files/{fileId}/signing/request",
			"verb" => "POST",
		],
        [
            "name" => "ocr_api#uploadCardAttachment",
            "url" => "/api/v1/projects/{projectId}/cards/{cardId}/ocr/attachments",
            "verb" => "POST",
        ],
        [
            "name" => "ocr_api#finalizeCardAttachment",
            "url" => "/api/v1/projects/{projectId}/cards/{cardId}/ocr/attachments/finalize",
            "verb" => "POST",
        ],
        [
            "name" => "ocr_api#listOrganizationDocumentTypes",
            "url" => "/api/v1/organizations/{organizationId}/ocr/document-types",
            "verb" => "GET",
        ],
        [
            "name" => "ocr_api#createOrganizationDocumentType",
            "url" => "/api/v1/organizations/{organizationId}/ocr/document-types",
            "verb" => "POST",
        ],
        [
            "name" => "ocr_api#updateOrganizationDocumentType",
            "url" => "/api/v1/organizations/{organizationId}/ocr/document-types/{id}",
            "verb" => "PUT",
        ],
        [
            "name" => "ocr_api#deleteOrganizationDocumentType",
            "url" => "/api/v1/organizations/{organizationId}/ocr/document-types/{id}",
            "verb" => "DELETE",
        ],
        [
            "name" => "project_api#getWhiteboardInfo",
            "url" => "/api/v1/projects/{projectId}/whiteboard",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getWhiteboardActivity",
            "url" => "/api/v1/projects/{projectId}/whiteboard/activity",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#getActivity",
            "url" => "/api/v1/projects/{projectId}/activity",
            "verb" => "GET",
        ],
        [
            "name" => "project_api#update",
            "url" => "/api/v1/projects/{id}",
            "verb" => "PUT",
        ],
        [
            "name" => "project_api#delete",
            "url" => "/api/v1/projects/{projectId}",
            "verb" => "DELETE",
        ],
        // Timeline API routes
        [
            "name" => "timeline_api#index",
            "url" => "/api/v1/projects/{projectId}/timeline",
            "verb" => "GET",
        ],
        [
            "name" => "timeline_api#summary",
            "url" => "/api/v1/projects/{projectId}/timeline/summary",
            "verb" => "GET",
        ],
        [
            "name" => "timeline_api#create",
            "url" => "/api/v1/projects/{projectId}/timeline",
            "verb" => "POST",
        ],
        [
            "name" => "timeline_api#reorder",
            "url" => "/api/v1/projects/{projectId}/timeline/reorder",
            "verb" => "PUT",
        ],
        [
            "name" => "timeline_api#update",
            "url" => "/api/v1/projects/{projectId}/timeline/{id}",
            "verb" => "PUT",
        ],
        [
            "name" => "timeline_api#destroy",
            "url" => "/api/v1/projects/{projectId}/timeline/{id}",
            "verb" => "DELETE",
        ],
        // Policy API routes
        [
            "name" => "policy_api#getBoardPolicy",
            "url" => "/api/v1/boards/{boardId}/policy",
            "verb" => "GET",
        ],
        [
            "name" => "policy_api#enableCardPolicy",
            "url" => "/api/v1/boards/{boardId}/policy/enable",
            "verb" => "POST",
        ],
        [
            "name" => "policy_api#updateCardPolicySettings",
            "url" => "/api/v1/boards/{boardId}/policy/settings",
            "verb" => "PUT",
        ],
        [
            "name" => "policy_api#updateCardPolicyDefaults",
            "url" => "/api/v1/boards/{boardId}/policy/defaults",
            "verb" => "PUT",
        ],
        [
            "name" => "policy_api#createCardPolicyRole",
            "url" => "/api/v1/boards/{boardId}/policy/roles",
            "verb" => "POST",
        ],
        [
            "name" => "policy_api#deleteCardPolicyRole",
            "url" => "/api/v1/boards/{boardId}/policy/roles/{roleId}",
            "verb" => "DELETE",
        ],
        [
            "name" => "policy_api#addMember",
            "url" => "/api/v1/boards/{boardId}/policy/members",
            "verb" => "POST",
        ],
        [
            "name" => "policy_api#removeMember",
            "url" => "/api/v1/boards/{boardId}/policy/members/{membershipId}",
            "verb" => "DELETE",
        ],
        [
            "name" => "policy_api#saveCardPolicyOverrides",
            "url" => "/api/v1/boards/{boardId}/policy/cards/{cardId}",
            "verb" => "PUT",
        ],
        [
            "name" => "policy_api#updateCardPolicyAction",
            "url" => "/api/v1/boards/{boardId}/policy/cards/{cardId}/actions/{cardAction}",
            "verb" => "PUT",
        ],
        [
            "name" => "policy_api#clearCardPolicy",
            "url" => "/api/v1/boards/{boardId}/policy/cards/{cardId}",
            "verb" => "DELETE",
        ],
        ["name" => "board_permission_profile_api#listProfiles", "url" => "/api/v1/boards/{boardId}/profiles", "verb" => "GET"],
        ["name" => "board_permission_profile_api#createProfile", "url" => "/api/v1/boards/{boardId}/profiles", "verb" => "POST"],
        ["name" => "board_permission_profile_api#getProfile", "url" => "/api/v1/boards/{boardId}/profiles/{profileId}", "verb" => "GET"],
        ["name" => "board_permission_profile_api#previewProfile", "url" => "/api/v1/boards/{boardId}/profiles/{profileId}/preview", "verb" => "POST"],
        ["name" => "board_permission_profile_api#applyProfile", "url" => "/api/v1/boards/{boardId}/profiles/{profileId}/apply", "verb" => "POST"],
        ["name" => "board_permission_profile_api#deleteProfile", "url" => "/api/v1/boards/{boardId}/profiles/{profileId}", "verb" => "DELETE"],
        [
            "name" => "page#index",
            "url" => "/",
            "verb" => "GET",
        ],
        [
            "name" => "page#project",
            "url" => "/{projectId}",
            "verb" => "GET",
            "requirements" => ["projectId" => "\d+"],
        ],
    ]
];
