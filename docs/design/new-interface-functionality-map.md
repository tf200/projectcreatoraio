# Mockup → existing functionality

Reviewed 14 September 2026 against `migration34`, commit `637e520`. Scope: the separate new interface, keeping the current interface operational. This is a source-code mapping, not a runtime permission audit or implementation. No app files or data were changed. The mockup still uses its 11 September snapshot.

## Main conclusion

Build a new frontend shell around existing services and feature components. Most full tabs already exist. New work is concentrated in the list presentation, overview summaries, contacts arrangement, user preferences, and creation wizard. Do not migrate the mockup's embedded database snapshot or its simplified calculations into the app.

**Classification:** Reuse = existing implementation; Adapt = existing behavior with a new wrapper or data transformation; New = missing state, API, or business rule; Decision = proposed behavior differs from the current implementation.

## 1. Entry point, layout and switching

| Mockup element | Current source | Mapping / required work |
|---|---|---|
| App shell | `src/main.js`, `src/App.vue`, `templates/index.php`, `vite.config.js` | **New:** separate new entry/component tree and template. Keep current `main` and `ProjectsHome` rendering intact. Load only the selected interface's bundle. |
| Theme | In Zicht theme `themes/inzicht/core/css/server.css` | **Reuse:** Nextcloud theme variables, Inter, Space Grotesk, shared radii. Scope new layout CSS beneath its root. Do not copy the mockup's fake Nextcloud header, review toolbar, embedded fonts or literal colors over the live host theme. |
| List → project → tab | `appinfo/routes.php`; `ProjectsHome.vue:2040–2140` | **Adapt:** current URLs are `/apps/projectcreatoraio/` and `/{projectId}?tab=…`; history/popstate already handled. New dedicated routes must be registered, not merely pushed into browser history. |
| Switch interfaces | No existing switch | **New:** explicit links between both interfaces, preserving project ID and corresponding tab. Keep current UI default initially. User preference persistence is a separate, optional rollout step. |
| Deep links / popout | `App.vue` whiteboard popout; `ProjectsHome.vue` URL helpers | **Adapt:** preserve `popout=whiteboard&projectId=…`, existing links from Talk/Deck/notifications, and old bookmarks. Do not change current path parsing to accommodate new routes implicitly. |
| Back to list | Current selection/history methods | **Adapt:** independent new list state, preserving search/filter/sort and scroll position. Switching project clears project-specific caches, forms and pending requests. |

Suggested new route family (proposal, not registered): `/new`, `/new/create`, `/new/projects/{projectId}?tab=tasks` beneath the existing app URL. Legacy routes remain unchanged. Explicit interface links should override any remembered preference. New list and creation do not use project-specific tabs.

## 2. Project list

| Element | Existing functionality | Mapping / gap |
|---|---|---|
| Accessible projects | `ProjectsService.list()` → `GET /api/v1/projects/list`; controller `getAccessibleProjects()` | **Reuse:** global admins see all, organization admins see their organization, ordinary users see their project memberships within their organization. Never start from the mockup's unfiltered snapshot. |
| Name, number, type, client, location, status | Project payload/model; `project-statuses.js`, `project-types.js` | **Reuse / Adapt:** new table presentation. Keep the established status enum; mockup Concept is not a persisted project status. |
| Search and status filter | `ProjectsHome.filteredProjects`, sort state | **Adapt:** existing frontend search covers name/number. Extend to client/location for mockup parity. Existing API returns a list rather than a server-paginated search result. |
| Sort | Existing name/number sorting | **Reuse / Adapt:** preserve this existing capability even though the mockup list does not expose sorting. |
| Organization filter | Context endpoint + organization service/fetcher | **Adapt:** global-admin option only; ordinary users cannot gain access by selecting another organization. Resolve authorized organization names rather than assuming they are on each project payload. |
| My projects / all organization | Existing organization-admin scope and `fetchProjectsByUser()` | **Reuse:** retain the existing scope selector. Prefer the access-scoped list as baseline; do not assume `/mine` has identical organization filtering. |
| Favorites | No project favorite state/API found in `src`, `lib`, `appinfo` | **New:** choose account-scoped persistent preference or explicitly browser-local state. Mockup favorites are memory-only. Use the same state for list star and project-header star. |
| Completion and next milestone columns | Deck data, timeline summary/phases/items | **Adapt / New summary:** not included as ready-made KPIs in `buildProjectPayloads()`. Avoid one full board and timeline fetch per row. Add authorized batch summaries or defer expensive columns while lists load. |
| Empty vs error | `ProjectsService.list()` catches errors and returns `[]` | **Adapt:** new interface must distinguish failed loading from an empty list. Use an additive error-preserving adapter without silently changing legacy behavior. |

## 3. Project header and actions

| Element | Existing source | Mapping / gap |
|---|---|---|
| Project identity and client/location | `GET /api/v1/projects/{id}`, existing profile editor | **Reuse:** normalize existing response naming at one boundary; create uses snake_case plus `organizationId`, while the current UI consumes fields such as `ownerId`, `boardId`, `folderPath`. |
| Location link | `ProjectLocationMap.vue` and stored address | **Adapt:** OpenStreetMap search link using escaped/encoded address; keep map detail capability. No request to geocode every list row. |
| Status and edit action | `PUT /api/v1/projects/{id}`, current dropdown | **Reuse:** actual project status; preserve field-specific permissions. |
| Current phase | Timeline phase hierarchy supplies phase/task status, especially Combi projects | **Decision / Adapt:** no single verified universal current-phase property. Define phase-selection semantics, multiple active phases and non-Combi fallback. Do not hard-code “Voorbereidingsfase”. |
| Completion badge | Deck cards / timeline required checklist | **Decision:** choose the named metric. All-card completion, required-checklist completion and main-phase completion are different. The mockup shows all active tasks, not the reference's phase percentage. |
| Conflicts / blockers | Planning conflict calculation; `risk_blocker` notes; delay analysis | **Adapt / Decision:** planning conflict is derived; a risk note is not necessarily an active blocker. Distinguish these counts. |
| Next milestone | Timeline items / hierarchy milestone data | **Adapt:** choose future, actionable milestones and define overdue handling; do not use past system Request Date as the next milestone. |
| Notifications | `ProjectNotificationService` emits notifications through Nextcloud | **New adapter:** no project-specific unread-count/list endpoint found. Reuse host notification behavior initially or deliberately build a filtered authorized view. Do not show global unread count as project unread count. |
| Project contacts | Stored client contact, member endpoints, Talk URLs/direct-chat methods | **Adapt:** primary client first, team next. Client contact is not automatically a Nextcloud user/Talk participant. Phone/email actions and team Talk actions have different availability. |
| More menu | Status editing, details editing, download/export, deletion in `ProjectsHome` | **Adapt:** move existing actions into overflow with current permission checks and destructive-action confirmation. Preserve export capability without adding the separate header Export button rejected in the review. |

## 4. Eight overview panels

| Panel | Data and source | Mapping / required work |
|---|---|---|
| Afspraken & aandacht | `GET /projects/{id}/notes/list`; `ProjectNotesList.vue`; note types `decision`, `risk_blocker`, `action_point`, etc. | **Adapt:** bounded summary of relevant authorized notes. **New** if agreements require contact, communication channel, open/resolved state or due date: these are not current note fields. Start with honestly labeled note types. |
| Sinds je laatste bezoek | Activity aggregation already exists | **New:** per-user/per-project visit checkpoint and permission-filtered counts. Current activity pagination cursor and digest-delivery cursor are not last-visit state. Define when visits become read; first visit has no comparison. |
| Recente activiteit | `GET /projects/{id}/activity?limit=…`; `ProjectActivityAggregationService` | **Reuse / Adapt:** show a small page and link to full activity. Service merges native Deck and custom events, handles cursor pagination, and prepares events for the viewer. Do not reproduce SQL ordering from the mockup. |
| Mijn taken | Deck board/stacks/cards and assignments; `DeckService`, existing Deck integration | **Adapt / possible summary API:** use the logged-in viewer and their authorized assignments, not the project owner persona used by the mockup. Define group assignments and completion rules. Do not infer assignment from card ownership or DRASCI role. |
| Procesvoortgang | `TimelinePlanningService.buildSummary()`, `processCompleted.doneCount/totalRequired/missingTitles`; phase hierarchy; `DeckAnalytics.vue` | **Adapt / Decision:** genuine required-card and phase/task data exists. Build one agreed presentation; column occupancy is not phase completion. Preserve missing-required-card and not-configured distinctions. |
| Planning | `GET /projects/{id}/timeline/summary`, phase/delay endpoints; `TimelineKpiBar.vue` | **Reuse / Adapt:** request date, preparation, desired start, computed minimum date, float/status. Use server computation rather than mockup's stored end-date + weeks shortcut. No desired date means conflict unknown, not “zero conflicts” by assertion. |
| Projectdoel & scope | Project description/type; editable profile | **Reuse** description. **New / Decision** separately maintained disciplines, in-scope and out-of-scope fields; project type “Combi” does not identify a specific chosen discipline set. |
| Meest recente documenten | `GET /projects/{id}/files`, `FileTreeService`; signing/OCR services | **Adapt:** flatten authorized metadata, sort, limit to 3. Respect shared/private scope; identify how generated notes and whiteboard files are excluded. Signing status must come from signing API, not file extension. Consider lightweight summary rather than loading the complete tree. |

Overview should mount summaries, not full feature modules hidden inside cards. Give each panel independent loading, unavailable, empty and retry states. A proposed overview endpoint must preserve per-feature access and private-note/file rules; project access alone does not grant every Deck/Talk/file permission.

## 5. Full-page tabs and component reuse

| New tab | Current component / integration | Required adapter / parity concerns |
|---|---|---|
| Overzicht | Currently built inline in `ProjectsHome.vue` | New eight-panel component. Do not mount the old all-modules overview beneath it. |
| Taken | `ProjectDeck/DeckBoard.vue`, `DeckAnalytics.vue`, `CardDetailModal.vue`, `DeckCardPolicyManager.vue`, `MemberAccessSummary.vue` | `DeckBoard` accepts `boardId`, `projectId` and has its own permission/embed lifecycle. Reuse Deck editing, attachments, policies, visibility and access controls; the mockup's static board is not a replacement. Legacy parent passes `can-manage-profiles`, but the inspected `DeckBoard` declares only the two ID props—verify before copying that contract. |
| Notities | `ProjectNotesList.vue`, `CreateNoteModal.vue`, `WysiwygEditor.vue` | Needs `projectId`, members, current user, Talk token/URL, optional direct-chat target. Includes public/private notes, card notes/comments and chat: preserve these, even if chat entry moves to Projectcontacten. |
| Planning | `ProjectTimeline/GanttChart.vue` and header, summary, impact/recovery components | `projectId`, `isAdmin` input; inspect actual capability rather than interpreting that prop name as the entire authorization policy. Preserve editing, simulation, dependencies, recovery and schedule changes. |
| Documenten | `ProjectFiles/ProjectFilesBrowser.vue`, PDF placement/signing/OCR integrations | Parent supplies `projectId`, shared/private roots, loading/error and refresh handler. WebDAV handles file operations. Preserve private files, upload, folders, preview, signing/OCR and download. |
| Intake formulier | **`ProjectCardVisibilityTab.vue`** | Critical mismatch: existing `cardVisibility` questionnaire determines applicable Deck cards from object/trace ownership, building type and AVP location. It accepts `projectId`, `canEdit` and uses GET/PUT `/card-visibility`. The mockup shows general project details instead. Keep the actual questionnaire, optionally alongside a project-details section; retain conditional type behavior. |
| Whiteboard | `ProjectWhiteboard/WhiteboardBoard.vue`, `WhiteboardPopout.vue` | `projectId`; viewer integration, WebDAV source, whiteboard token/backend and popout lifecycle. Mockup did not export board content. Reuse live editor rather than recreate a canvas. |
| Leden | Member management currently inline in `ProjectsHome.vue` | Extract/wrap for new UI: `listMembersWithRoles`, user search, `addMember`, `updateMemberRole`, deck-access summary. Preserve multiple DRASCI and functional roles, invite feedback and permissions. |
| Activiteit | `ProjectActivity/ProjectActivity.vue` | `projectId`; existing source filters and pagination. Reuse and restyle scoped wrapper. |
| Agenda | `ProjectCalendar.vue` → `CalendarService.getProjectEvents()` | Real endpoint is `/ocs/v2.php/apps/calendar/proposal/project/{projectId}` via `generateOcsUrl('/calendar/proposal/project/…')`. It shows proposals and confirmed meetings. Verify installed Calendar integration. Mockup milestones/deadlines are additional potential content, not a replacement for meetings. |

Suggested tab translation for interface switching: tasks ↔ deck, planning ↔ timeline, documents ↔ files, intake ↔ cardVisibility, agenda ↔ calendar; overview, notes, whiteboard, members, activity map directly. Unknown/unsupported tab falls back to the project's overview, with explicit legacy link for unfinished features.

## 6. Creation wizard

| Step/control | Current support | Mapping / gap |
|---|---|---|
| Step 1: identity, type, organization | `ProjectCreator.vue`, `Models/project.js`, `OrganizationsFetcher.vue` | **Adapt:** reuse values/validation; global admin chooses organization, ordinary user context supplies their organization. Keep optional vs required distinction. |
| Client details | Current form and model | **Reuse:** client roles are an array/multi-select today. Mockup's single role selector must not remove multiple roles. |
| Location/description | Current form and `ProjectLocationMap.vue` | **Reuse:** preserve map behavior and editable profile fields. |
| Step 2: members | Model/create API accepts `members`; `searchUsers` is organization-scoped | **Adapt:** new step UI using live authorized search. Do not derive membership from existing projects as the mockup does. |
| Step 2: owner picker | Service uses `$this->userSession->getUser()` as owner; create API has no owner parameter | **Decision / New:** initially display creator as owner, or explicitly extend backend authorization and creation semantics to assign another owner. A frontend picker alone does nothing. |
| Step 3: review | No standalone review step | **New UI:** hold entered state across steps; final submission uses `ProjectsService.create(project)` and existing model `toJson()`. |
| Submit/provision | `POST /api/v1/projects`; `ProjectService.createProject()` | **Reuse:** one operation creates project group, folders, optional Deck board/default cards/policies, whiteboard, default PDF and available Talk conversation. There is cleanup on failure; do not assume transaction-wide atomicity across these systems. |
| Success | Response `{message, projectId}`; `handleProjectCreated()` | **Adapt:** fetch/open returned ID directly; actual new project may already have template cards/docs, unlike the mockup's empty draft. |
| Drafts | No persisted draft API/status found | **New only if wanted:** wizard memory is enough for first release. Disable duplicate submission; preserve input on failure. Persistent drafts need separate storage/retention semantics, not a made-up project status. |
| Initial dates | Controller accepts `request_date`, `desired_execution_date`, preparation inputs | **Contract caveat:** request/desired dates are read but not forwarded into `createProject()` in this code. Do not expose them as saved creation inputs without wiring and validation. Preparation weeks are forwarded. Existing update/planning endpoints handle desired start separately. |

Current creation does **not** uniformly require organization-admin role: `createProject()` calls `resolveOrganizationForCurrentUser(..., false)`, and current New button is not gated by `canManageProjects`. Members/creator are checked against the resolved organization when integrations are present, subscription limits apply, and Team Folders are required. A global admin may still fail the creator-membership check for a selected organization. Preserve observed behavior until an intentional permission decision is made; older `AGENT.md` prose is not authoritative evidence of current enforcement.

## 7. Permission and lifecycle constraints

- Use `context()` for viewer, organization role and feature availability; actual endpoints remain authoritative.
- Read access: global admin; same-organization admin; otherwise same-organization project membership. Cross-organization projects generally return not found.
- Profile changes are field-specific: non-admin project members can update client/location; owners have additional name/status/planning permissions; other restricted fields are checked in `ProjectApiController.update()`.
- Timeline mutation helper currently calls only project access checks; it is not an admin-only gate. Do not silently tighten or broaden it in the new UI based on a component prop name.
- Member/role changes, deletion, Deck permissions and private notes have their own checks. Reuse endpoints, and verify each persona through integration tests before rollout; this mapping does not certify all policy paths.
- `GET /timeline` synchronizes system items; summary calls phase hierarchy which may seed phases. These endpoints are not guaranteed mutation-free reads. Avoid requesting them for every list row or merely to render a skeleton.
- Unmounting/remounting existing editors must clean up listeners/embedded handles and preserve unsaved edits appropriately. Cancel stale requests on project switches; never show another project's previous data while fetching.

## 8. Recommended first slice and decisions

First slice: separate new route/template/bundle, switch links, new list backed by accessible-project/context APIs, persistent project header, full-page tabs wrapping existing modules, and legacy fallback links. Keep current UI default. Do not add database changes just to deliver this shell.

Before wiring the complete overview/creation, resolve these explicit decisions:

1. Progress: use required-checklist completion for process progress, with workflow counts as detail; label all-task completion separately if retained. Define current/main-phase semantics rather than assuming five fixed phases.
2. Intake: actual conditional-card questionnaire plus optional project details; not project-details-only.
3. Owner: creator-only first release is compatible; selectable owner is an intentional backend extension.
4. Agreements/blockers: reuse typed notes first; structured agreements and resolved blocker counts require additional fields.
5. Favorites/UI choice: per-user preference persistence; last-visit is distinct per-user/per-project state.
6. Agenda: retain meetings/proposals; decide whether milestones/deadlines are an additional source/filter.
7. Creation permissions: document and test current organization-member creation policy before changing it.
8. Scope: use description initially; separate scope/discipline fields require an agreed schema.

Acceptance checks for the first slice: switch both ways on the same project/tab; old URLs and whiteboard popout still work; direct new URLs survive reload and Back; access denied never becomes empty-success; unrelated project state does not leak; list filters survive return; feature-disabled states have a useful fallback; existing module workflows still function for global admin, organization admin, owner and ordinary member.

## Source index

Paths below are relative to the app repository `/home/payboy/src/inzicht-stack/nextcloud/custom_apps/projectcreatoraio`.

- `appinfo/routes.php`; `src/main.js`; `src/App.vue`; `templates/index.php`; `vite.config.js`
- `src/components/ProjectsHome.vue` (navigation, inline members/profile and module wiring)
- `src/components/ProjectCreator.vue`; `src/Models/project.js`; `src/Services/projects.js`
- `src/Services/deck.js`; `src/Services/calendar.js`; `src/constants/project-statuses.js`; `src/constants/note-types.js`
- `lib/Controller/ProjectApiController.php` (create 722, context 899, activity 981, update 1265, access 1589)
- `lib/Controller/TimelineApiController.php` (index 46, summary 62, timeline mutation access 622)
- `lib/Service/ProjectService.php` (create 103, user search 335, payloads 1149, organization resolution 1166)
- `lib/Service/TimelinePlanningService.php`; `lib/Service/TimelinePhaseService.php`; `lib/Service/TimelineImpactService.php`
- `lib/Service/ProjectActivityAggregationService.php`; `lib/Service/ProjectNotificationService.php`

The mockup remains a design artifact. Its direct database export, owner persona, in-memory preferences/drafts, sample data and simplified date computations are not production contracts.
