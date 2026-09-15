# Project landing page

The opt-in project interface now follows the approved overview mockup: compact project identity and actions, status strip, full-width tabs and eight panels in a 12-column responsive grid. English interface copy inherits the active Nextcloud theme. Stored project text is shown as entered.

## Data definitions

- Agreements & attention: latest public decisions, action points and risk/blocker notes returned by the existing authorized notes endpoint. Risk badge counts notes, not unresolved blockers.
- Since last visit: explicitly unavailable until per-user/project checkpoints exist. Links to activity; no fabricated unread count.
- Recent activity: three viewer-filtered events from the existing activity endpoint. Redacted events never display payload titles.
- My tasks: unfinished, unarchived, undeleted Deck cards assigned directly to the current user. Group assignments remain accessible in the full board.
- Process progress: `processCompleted.doneCount / totalRequired`, including missing-card notices. Unconfigured/error states do not become 0% success. Phase task counts are shown separately; the header phase is the first phase with incomplete tasks.
- Planning: server-computed request, desired and minimum start dates. Conflict is unknown without a desired date and numeric float. Next pending milestone is the earliest dated milestone with incomplete phase tasks, including overdue milestones.
- Scope: actual project description. Disciplines and separate scope/exclusions are marked unrecorded.
- Documents: newest modified authorized shared/private files, excluding generated note folders and whiteboard files. `FileTreeService` adds `mtime` to its existing metadata response; no permission or schema changes.

Each data source loads independently and can be retried. Responses from previous projects/obsolete refreshes are ignored. Returning from a project module to Overview reloads summaries. Header contact links use stored contact information; the Talk link requires an available conversation. Management continues through the legacy interface.

## Verification

23 Node tests cover navigation, list failures, lifecycle, refresh, summary calculations, assignment filtering, redaction and file sorting. Production build and PHP syntax checks pass. Browser checks cover all eight panels, contacts and mobile width using fresh responses from existing Nextcloud controllers for the organization-admin viewer and project 21. The desktop rendering was compared with `overview-firma-de-testerij.png`; captures are kept outside the app in `/home/payboy/src/projectcreatoraio-ui-check/overview-live-desktop.png` and `overview-live-mobile.png`.

Controller checks use the same read/summary methods as the UI. Existing timeline summary behavior may initialize missing phase rows. Browser checks do not cover every authenticated write workflow.
