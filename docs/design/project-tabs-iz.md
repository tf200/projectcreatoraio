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
  so a failure is not shown as an empty team. The team is a DRASCIVS matrix: one column
  per responsibility (letter and name), a tick where a member holds it, and a holders
  row that counts each column and tints the ones nobody holds, so any number of people
  can share a responsibility. Project roles are pills (one theme category colour per
  role, `members.js`). In Edit, that row's ticks become the DRASCIVS toggles and the
  project-role chips open below; an inline add panel uses the same `RoleChips`. Both
  state the server's rules (a person, one DRASCIVS role, one project role) before
  sending. Add and Edit show only to global admins, organisation admins and the owner;
  Chat (not with yourself) opens Notes with that member as the direct-chat target. There
  is no remove, because the API has none. Below 1040px of table width (a container
  query, so the sidebar counts) the matrix falls back to one card per member with
  DRASCIVS pills.

- **Tasks progress** — `src/new/NewTasks.vue` extends `DeckBoard` (header, sub-tabs,
  embedded Deck board and card permissions unchanged) and puts `TaskProgress.vue` above
  the board in place of Deck's own dashboard row, which `tasks-theme.css` hides in the
  new layout only. It reads Deck's `/apps/deck/stacks/{board}` strictly and reads it
  again shortly after the embedded board's store commits anything (a moved card), and
  on Reload. There is no separate KPI row: the Progress panel puts all-task completion
  (with the overdue count, counted as Deck's dashboard does in `tasks-progress.js`) and
  the critical process steps side by side:
  a step is a card labelled "Kritieke Processtap" (or its former name "Belangrijk"),
  ordered by the board's card dependencies, then the Combi timeline order (kept equal to
  `ProjectTypeDeckDefaults` by a test), then due date. Open steps come first, done ones
  last, each with a track over the board's columns and its due date (late in red). The
  list scrolls inside a box of eight rows with a fade; on a narrow screen it runs full
  length with a column pill instead of the track.

- **Who can do what** — Deck's Permissions Overview (`ProjectMemberAccessSummary` in the
  Deck fork) is hidden in the new layout. In its place `BoardAccessLine.vue` puts one
  line under the Progress panel that names who is limited ("Everyone can see all 20
  cards. EMPLOYEE_ORG01 and test1 can't move, verify or sign every card."), and its
  "Who can do what" opens Card Permissions at `MemberAccess.vue`: one row per member,
  View/Move/Verify/Sign as "All 20", "19 of 20" or "None", and per member the cards
  they can't act on. An action on no card at all (a read-only member) is said once
  instead of on every card. Both read this app's `deck-access-summary`, once in
  `NewTasks.vue`, again on every sub-tab switch (the card rules may have changed) and on
  Reload; `board-access.js` holds the counts and sentences. Card Permissions is now
  there for every member: the server gives someone who doesn't manage the project only
  their own row ("You can see all 20 cards, move 19, verify 14 and sign 14."), and the
  card rules below the table stay for those who manage the board. On a narrow screen
  each member becomes a block with the four counts two by two. The board's wrapper gets
  `min-width: 0` so the card rules' wide table scrolls inside its own frame instead of
  stretching the tab past the page.

- **Calendar** — `src/new/NewCalendar.vue` extends `ProjectCalendar` (its loading, Load
  older events paging and All / Proposals / Meetings filter, now `iz-tab`s with counts)
  with one agenda grouped by what needs doing (`calendar.js`): date proposals ("Needs a
  date", saying what each waits for: "Waiting for admin2", "Waiting for 3 people",
  "Everyone answered · pick a date in Calendar", or "No date left to pick" with a warning
  once every offered date has passed; opened, it says how a proposal moves on and links
  to the Calendar app, which has no link to a single proposal), then meetings still to come, soonest first, then past meetings. Each row opens
  for the description, the date options (passed ones struck) or the meeting time, and
  every participant with a plain status (Not answered yet, Answered, Accepted, Maybe,
  Declined).
  Times show in the viewer's zone. An empty calendar says what would appear there. What
  anyone sees is decided by the Calendar app: proposals for their organizer, meetings
  that are in the viewer's own calendar.

- **New project** — `src/new/NewCreate.vue`, a page of the new layout at `/new/create`
  (a new page route, `page#newCreate`) instead of the current interface's modal. The same
  fields, rules and request (`create.js` builds the same body as `Project.toJson()`; a
  test keeps the keys in step): name, number and type required, an organisation for
  administrators (the existing `OrganizationsFetcher`), client, location with the
  existing map preview, description. Project types are cards that say what each sets up.
  Beside the form, "Create sets up" names what the request makes after the project
  (board, shared and private folders, whiteboard, chat) and the plan line comes from a
  new read, `GET /api/v1/projects/allowance` (`ProjectService::getCreationAllowance`,
  the same organisation, plan and count the create request checks). A full plan
  disables Create; a refusal shows the server's own sentence, never a trace, and keeps
  the form. Created projects open on their overview with a one-line notice; Cancel and
  Back return to the project the page was opened from. The shelf, list and empty-state
  buttons open it in place; "Back to current interface" opens the old create form.

- **Header actions** — `src/new/ProjectActions.vue` replaces the three tiles (Project
  updates, Project contacts, Manage project) with `iz-btn`s: Chat (when the project has a
  conversation), Contacts (the client card, Email the client, View project team, Edit
  client & address) and More, which does in the new layout what Manage project used to
  leave for: edit details, client and address, status, export and delete (confirmed by
  typing the project's name). `project-actions.js` follows the server's rules: every
  member edits the client and address and can export; the owner also renames, changes
  status and deletes; organisation administrators also edit the description. Only
  changed, allowed fields are sent. After a change the app reads the project and the
  shelf again; after a delete it opens another project with a notice. Project updates
  is gone; the Activity tab is right below. Dialogs are native `<dialog>`s, hidden when
  closed even though Nextcloud's own styles show every `dialog`.

Inactive `iz-chip`s in the new interface sit on the card surface with a hairline
(`new-ui.css`). The theme fills them with the subtle surface, which is also the page,
a row being edited and, in the dark theme, darker than a panel, so they vanished in the
member editor and in the dark Activity filters.

The module host drops its card for these tabs (Calendar included) (`.pc-module--{tab}`), so their
panels sit on the page background instead of inside another card.

## Verification

62 Node tests, including the members rules (add/edit payloads, server messages, stale
search answers), the extends contracts (answered and unsaved counts, read-only
answers, strict failure, stale responses, cursor/offset paging, failed later pages,
clock times) and the task split. `tests/browser/views/` renders the real components
with the real theme stylesheet and fixtures, and asserts the OCR labels, the single
accent button, segment/radio behaviour, chip filtering, the access-failure state, the
task tabs, keyboard-visible row actions and 390px width, and in both the light and dark In Zicht
palettes that no control's fill blends into the surface under it and no enabled
control's label falls under 3:1, plus an unthemed Documents copy
that must keep its own look. It does not load Nextcloud core CSS; the theme's `iz-`
selectors are written to out-rank core's bare-element rules.
