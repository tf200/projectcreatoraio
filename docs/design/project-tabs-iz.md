# Documents, Intake, Activity and overview tasks

Built from the In Zicht theme's own design system (`themes/inzicht/core/css/server.css`):
its `iz-` components and its `--iz-*` spacing, radius, type and colour tokens. The
current interface is untouched — no file under `src/components/` changed.

## How each tab is built

- **Intake** — `src/new/NewIntake.vue` `extends` `ProjectCardVisibilityTab`: the
  questions, answers, dirty tracking and save cycle are that component's own; only the
  markup is new. Each question is an `iz-row` in one `iz-panel--list`, answers are an
  `iz-segment` of labels around real radios, and the header carries progress
  (answered of total, from the answers already loaded) and Save.
- **Activity** — `src/new/NewActivity.vue` `extends` `ProjectActivity`, keeping its
  grouping, descriptions (including redacted private notes and native Deck events) and
  source labels. Its reads are replaced with `api.activity` because the shared service
  turns every failure into an empty list; here "no access" reads as a failure. Newer
  requests win over slower ones, paging follows the cursor and falls back to offset,
  and a failed later page keeps what is already on screen. Filters are `iz-chip`s,
  sources use the theme's category colours, and day headers stick (`overflow: clip` on
  the panel, since `hidden` would stop them).
- **Documents** — keeps `ProjectFilesBrowser` (OCR, signing, placement and WebDAV upload
  are too much to rebuild safely) with one scoped stylesheet,
  `src/new/documents-theme.css`, whose values are `--iz-*` tokens. Only the first
  accent button keeps the accent; labels are the component's own. The OCR status shows
  the component's own `title` (Ready, Queued, Partial, Failed…) through
  `content: attr(title)`. Row actions stay visible except where a precise pointer can
  hover, and keyboard focus reveals them.
- **Overview tasks** — the panel has My tasks / All tasks `iz-tab`s over the Deck stacks
  the overview already loads. `openTasks` defines open once (not archived, deleted or
  done) and `assignedTasks` filters it, so My tasks is always a subset of All tasks.
- **Members** — `src/new/NewMembers.vue`, a new view over the endpoints the current
  interface already calls (list, add, update roles, organisation search), read strictly
  so a failure is not shown as an empty team. A table of members with DRASCIVS pills and
  project-role pills (one theme category colour per role, `members.js`), a DRASCIVS
  coverage aside that marks unheld responsibilities, an inline add panel and an inline
  role editor, both built from `RoleChips` toggles and both stating the server's rules
  (a person, one DRASCIVS role, one project role) before sending. Add and Edit show only
  to global admins, organisation admins and the owner; Chat (not with yourself) opens
  Notes with that member as the direct-chat target. There is no remove, because the API
  has none.

The module host drops its card for these tabs (`.pc-module--{tab}`), so their
panels sit on the page background instead of inside another card.

## Verification

62 Node tests, including the members rules (add/edit payloads, server messages, stale
search answers), the extends contracts (answered and unsaved counts, read-only
answers, strict failure, stale responses, cursor/offset paging, failed later pages,
clock times) and the task split. `tests/browser/views/` renders the real components
with the real theme stylesheet and fixtures, and asserts the OCR labels, the single
accent button, segment/radio behaviour, chip filtering, the access-failure state, the
task tabs, keyboard-visible row actions and 390px width, plus an unthemed Documents copy
that must keep its own look. It does not load Nextcloud core CSS; the theme's `iz-`
selectors are written to out-rank core's bare-element rules.
