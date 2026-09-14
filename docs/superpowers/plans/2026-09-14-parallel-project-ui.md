# Parallel project interface — first slice

**Goal:** Add an opt-in, independently mounted interface while keeping the current interface default and its workflows intact.
**Architecture:** New page/template and Vite entry load a separate Vue root. Shared feature components are mounted by a new tab host. Strict read adapters preserve API errors; the server remains the permission authority.
**Tech stack:** Vue 2.7, Nextcloud 34, PHP 8.2+, existing Vite build; Node's built-in test runner.
**Spec:** `docs/design/new-interface-functionality-map.md`, first-slice section and user approval.

## Constraints

- Current root and numeric project URLs remain unchanged. New routes `/new` and `/new/projects/{projectId}` have explicit server handlers.
- No new business metrics, database schemas, privileges, creation pipeline or preference persistence.
- Legacy switches preserve project/tab, current UI remains default. New project creation and member editing use explicit legacy fallback links.
- New overview starts with actual project details and module links; eight summary calculations are a subsequent slice.
- New CSS stays scoped; existing components retain their own behavior and host theming.
- Source changes are developed in `/home/payboy/src/projectcreatoraio-new-ui`; install in the requested local Nextcloud checkout only after checks.

## Task 1 — Routes and strict read boundary

Files: `src/new/navigation.js`, `src/new/api.js`, `src/new/navigation.test.js`, `lib/Controller/PageController.php`, `appinfo/routes.php`, `templates/new.php`, `vite.config.js`, `src/new-main.js`.

- [x] Test invalid project IDs, legacy/new tab mappings, deployment-prefix URLs and unknown-tab fallback using `node --test src/new/navigation.test.js`.
- [x] Implement `readRoute(pathname, search)`, `interfaceUrl(base, {projectId, tab}, modern)` with positive numeric IDs and whitelisted tabs; no host/URL input from project data.
- [x] Add strict GET adapters for context, list, project and files using Nextcloud axios. Do not change legacy services that swallow errors.
- [x] Add authenticated new page and newProject controller handlers sharing viewer/CSP setup without changing legacy output.

## Task 2 — Isolated shell and project list

Files: `src/new/NewApp.vue`, `src/new/ProjectList.vue`, `src/new/ProjectHeader.vue`, `src/new/NewOverview.vue`, `src/new/new-ui.css`, `src/components/ProjectsHome.vue`.

- [x] Build list with context/error gating, search/status/sort, real fields and remembered in-component list state.
- [x] Build persistent project header, browser history/back/reload behavior and matching interface links. Guard stale responses with request generations, clear selected project before loading another.
- [x] Add a small legacy switch link and legacy creation query support without restructuring legacy UI.
- [x] New overview displays real project metadata and honest next-slice/fallback links; never fabricated progress, unread counts or conflicts.

## Task 3 — Full tab host

File: `src/new/ProjectModule.vue`.

- [x] Lazy-import existing modules: Deck, Notes, Gantt, Files, Intake questionnaire, Whiteboard, Activity, Calendar.
- [x] Consume `{project, context, tab, legacyUrl}`; scoped member/file reads, guarded async lifecycle and retries.
- [x] Render members read-only with legacy management fallback. Honor feature flags and Combi-only intake; preserve caller contracts for IDs, user, roots and permissions.
- [x] Key module host by project and tab; unmount obsolete listeners/editors when navigating.

## Task 4 — Verification and local delivery

- [x] Build baseline with existing locked dependencies and production build.
- [x] Run navigation and component lifecycle tests; PHP lint; production build of both entries.
- [x] Browser-check list, direct routes, Back, switch mappings, unauthorized/error responses, mobile layout and modules using intercepted read-only API fixtures where authentication is unavailable.
- [x] Request independent review of the diff against this plan; fix substantive findings.
- [ ] Commit feature work; apply the reviewed commit to the local checkout without changing migration34's history, build assets, verify route and asset responses, report remaining feature scope accurately.

## Verification notes

- 15 focused Node tests pass; PHP syntax checks pass for all three changed PHP files.
- Both frontend entries build successfully with the existing dependencies using Docker Node 24. Existing engine, Browserslist and bundle-size warnings remain.
- Independent review completed; strict list validation and organization-admin membership filtering were corrected and covered by tests.
- Browser harness and screenshots are stored outside the app at `/home/payboy/src/projectcreatoraio-ui-check`. It loads the production bundle with read-only API fixtures from the real mockup snapshot. This validates shell navigation and module mounting, not authenticated integration writes.
- Project creation and member editing remain in the legacy interface. The richer overview summaries are intentionally deferred.
