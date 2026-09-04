# Project-Scoped 1-to-1 Chat Feature: Architecture & Progress Tracker

## 1. Overview & Motivation

### Background
In `projectcreatoraio`, every project automatically provisions a team group chat via Nextcloud Talk (`spreed`). This allows all project members to collaborate in an open project-wide room (`<Project Name> - Chat`).

### The Problem
Project members need the ability to have **personalized, direct one-to-one conversations with other project members that are strictly contextualized to the project**.

Using Nextcloud Talk's native 1-to-1 chat is unsuitable for this purpose:
- **Instance-wide Single Room Limitation:** In Nextcloud Talk (`spreed`), a one-to-one conversation (`Room::TYPE_ONE_TO_ONE`) is uniquely identified across the entire server by the sorted pair of users (`json_encode([$user1, $user2])` in `OCA\Talk\Manager::getOne2OneRoom`).
- **Context Bleed:** If User A and User B chat via Talk's standard 1-to-1 chat, messages from Project 1, Project 2, and private/personal conversations are all merged into a single thread.
- **No Project Association or Auditing:** Normal 1-to-1 rooms have no link to project access control, project milestones, activity logging, or lifecycle retention.

### The Solution
Instead of attempting to alter Talk's global 1:1 room logic, `projectcreatoraio` will orchestrate **private group rooms (`Room::TYPE_GROUP`) with `Room::LISTABLE_NONE`** containing strictly the two participating project members:
- **Unlimited distinct rooms:** Talk allows any number of group rooms between the same two users.
- **Strict Privacy:** Setting `listable: Room::LISTABLE_NONE` ensures the room is completely invisible to all non-participants (other project members, other organization users, and guests).
- **Clear Identification:** The room is explicitly named in Talk (e.g., `[Acme Tower] Alice & Bob`) and its description links directly back to the project in `projectcreatoraio`.
- **Zero Modifications to `spreed`:** Talk functions as an unmodified platform backend, ensuring future Nextcloud and Talk updates will not break the integration.

---

## 2. Technical Architecture

### 2.1 On-Demand (Lazy) Provisioning Strategy
- **Pairwise Scalability:** For $N$ members in a project, there are $N(N - 1) / 2$ possible pairwise combinations. Creating 45 rooms upfront for a 10-person project (or 190 rooms for a 20-person project) would flood Talk with empty rooms and degrade performance.
- **Lazy Initialization:** 1-to-1 project chats are provisioned on-demand when either user clicks "Chat" next to a member or selects that member in the project chat switcher.
- **Idempotency:** An endpoint `POST /api/v1/projects/{projectId}/direct-chats/{targetUserId}` checks if a conversation record already exists; if found, it returns the existing room token and URL immediately; otherwise, it creates the room in Talk and records the mapping.

### 2.2 Canonical Participant Ordering
To guarantee that `(User A, User B)` and `(User B, User A)` always resolve to the identical record in the database regardless of who initiates the chat:
```php
$user1 = strcmp($currentUserId, $targetUserId) < 0 ? $currentUserId : $targetUserId;
$user2 = strcmp($currentUserId, $targetUserId) < 0 ? $targetUserId : $currentUserId;
```

---

## 3. Database Schema (`proj_direct_chats`)

### Migration: `Version010040Date20260904000000.php`
- **Table:** `proj_direct_chats`
- **Columns:**
  - `id`: `BIGINT` (Autoincrement, Primary Key)
  - `project_id`: `BIGINT` (Foreign Key -> `custom_projects.id`, Not Null)
  - `user1_id`: `VARCHAR(64)` (Not Null, Canonical User 1 UID)
  - `user2_id`: `VARCHAR(64)` (Not Null, Canonical User 2 UID)
  - `talk_conversation_token`: `VARCHAR(255)` (Not Null, Unique Talk room token)
  - `created_at`: `DATETIME` (Not Null)
  - `updated_at`: `DATETIME` (Not Null)
- **Indices & Constraints:**
  - `UNIQUE INDEX unq_proj_user_pair (project_id, user1_id, user2_id)`
  - `UNIQUE INDEX unq_proj_direct_token (talk_conversation_token)`
  - `INDEX idx_proj_direct_user1 (user1_id)`
  - `INDEX idx_proj_direct_user2 (user2_id)`

---

## 4. Component Design

```
projectcreatoraio/
├── lib/
│   ├── Controller/
│   │   └── ProjectApiController.php            # New endpoints: direct-chats, messages
│   ├── Db/
│   │   ├── ProjectDirectChat.php               # Entity
│   │   └── ProjectDirectChatMapper.php         # Query builder mapper
│   ├── Listener/
│   │   └── TalkEventListener.php               # Activity logging for direct project chats
│   ├── Migration/
│   │   └── Version010040Date20260904000000.php # Schema migration
│   └── Service/
│       ├── ProjectService.php                  # Direct chat business orchestration
│       ├── ProjectTalkIntegrationService.php   # Talk room creation & participant seeding
│       └── ProjectRetentionService.php         # Clean up direct chats on project purge
├── src/
│   ├── Services/
│   │   └── projects.js                         # Frontend API methods
│   └── components/
│       ├── ProjectsHome.vue                    # Chat button in Members tab
│       └── ProjectNotesList.vue                # Direct chat switcher & conversation view
└── tests/unit/
    ├── Db/ProjectDirectChatMapperTest.php
    └── Service/ProjectTalkIntegrationServiceTest.php
```

---

## 5. API Specification

| HTTP Method | Route | Description | Auth / Access |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/v1/projects/{projectId}/direct-chats` | Lists all 1:1 project chats involving the current user | Project Member or Admin |
| `POST` | `/api/v1/projects/{projectId}/direct-chats/{targetUserId}` | Gets or lazily creates a 1:1 project chat with target user | Project Member or Admin |
| `GET` | `/api/v1/projects/{projectId}/direct-chats/{targetUserId}/messages` | Fetches chat message history for the direct chat | Direct Chat Participant |

---

## 6. Implementation Progress Tracker

### Legend
- `[ ]` Not Started
- `[/]` In Progress
- `[x]` Completed

---

### Phase 1: Database & Persistence Layer
- [x] **1.1 Migration Script:** Create `lib/Migration/Version010040Date20260904000000.php` defining `proj_direct_chats` with unique compound constraints and indices.
- [x] **1.2 Entity Definition:** Create `lib/Db/ProjectDirectChat.php` with property mapping and `JsonSerializable`.
- [x] **1.3 Database Mapper:** Create `lib/Db/ProjectDirectChatMapper.php` supporting:
  - `findPair(int $projectId, string $user1, string $user2): ?ProjectDirectChat`
  - `findByProjectAndUser(int $projectId, string $userId): array`
  - `findByTalkConversationToken(string $token): ?ProjectDirectChat`
  - `deleteByProject(int $projectId): void`
  - `deleteByToken(string $token): void`
- [x] **1.4 Unit Tests for Mapper:** Add unit test coverage for `ProjectDirectChatMapper` and `ProjectDirectChat`.

---

### Phase 2: Talk Integration Service Expansion
- [ ] **2.1 Private Room Provisioning:** Add `createProjectDirectConversation(...)` to `lib/Service/ProjectTalkIntegrationService.php`:
  - Create room with `Room::TYPE_GROUP`, `Room::LISTABLE_NONE`, name formatted as `[Project] User A & User B`.
  - Set description with Markdown project reference.
  - Add both users via `ParticipantService::addUsers(...)`.
  - Return `{token, url}`.
- [ ] **2.2 Room Cleanup:** Add `deleteProjectDirectConversations(int $projectId)` to batch delete rooms when purging a project.
- [ ] **2.3 Unit Tests:** Update `tests/unit/Service/ProjectTalkIntegrationServiceTest.php` to cover direct room creation, attendee assignment, and error fallbacks.

---

### Phase 3: Project Service & Business Logic
- [ ] **3.1 Membership Validation:** Ensure both participants are verified members of the project via `ProjectMemberResolver`.
- [ ] **3.2 Get-or-Create Logic:** Implement `getOrCreateDirectChat(int $projectId, string $currentUserId, string $targetUserId)` in `ProjectService`.
- [ ] **3.3 Listing Logic:** Implement `listUserDirectChats(int $projectId, string $currentUserId)` formatting members' display info, avatar, token, and Talk link.
- [ ] **3.4 Unit Tests:** Add test cases in `tests/unit/Service/ProjectServiceTest.php`.

---

### Phase 4: API & Routing Layer
- [ ] **4.1 Route Registration:** Add routes in `appinfo/routes.php` for direct chats listing, creation, and message fetching.
- [ ] **4.2 Controller Endpoints:** Implement methods in `lib/Controller/ProjectApiController.php`:
  - `listDirectChats(int $projectId): DataResponse`
  - `getOrCreateDirectChat(int $projectId, string $targetUserId): DataResponse`
  - `getDirectChatMessages(int $projectId, string $targetUserId, int $limit, int $offset): DataResponse`
- [ ] **4.3 API Permission Enforcement:** Verify non-members cannot query or create chats for projects they do not belong to.

---

### Phase 5: Activity Logging & Lifecycle Retention
- [ ] **5.1 Talk Event Listener:** Update `lib/Listener/TalkEventListener.php` to look up `proj_direct_chats` if the conversation token does not match a main project group token.
- [ ] **5.2 Activity Recording:** Record direct chat activity (e.g. `direct_chat_message_sent`) in `project_activity_events` attributed to the project.
- [ ] **5.3 Project Retention:** Update `lib/Service/ProjectRetentionService.php` to purge `proj_direct_chats` and delete corresponding Talk rooms on project deletion.

---

### Phase 6: Frontend Integration (Vue 2.7)
- [ ] **6.1 Service Wrapper:** Add API helper functions in `src/Services/projects.js`:
  - `listDirectChats(projectId)`
  - `getOrCreateDirectChat(projectId, targetUserId)`
  - `getDirectChatMessages(projectId, targetUserId, options)`
- [ ] **6.2 Members Tab Trigger:** In `src/components/ProjectsHome.vue` (under the Members list):
  - Add a "Chat" action button on each member item (skipping current user).
  - On click, invoke `getOrCreateDirectChat` and open Talk or switch to project chat view.
- [ ] **6.3 Chat Workspace Switcher:** In `src/components/ProjectNotesList.vue` (under the Chat tab):
  - Add a conversation selector: "Team Chat" vs "Direct Chats with Members".
  - Show member avatar, display name, and last activity.
  - Render message thread and provide an "Open in Talk" deep-link button.

---

### Phase 7: Automated Testing & Verification
- [ ] **7.1 Unit Test Suite:** Run `composer test` or PHPUnit to ensure all new backend tests pass.
- [ ] **7.2 Linter & Psalm:** Verify with `composer run cs:check` and `composer run psalm`.
- [ ] **7.3 Frontend Build:** Run `npm run build` or `bun run build` to verify bundle compilation without regressions.
- [ ] **7.4 End-to-End Verification:** Test creating, listing, messaging, and retention in a running Nextcloud instance.

---

## 7. Change Log & Progress Updates

| Date | Phase / Task | Summary of Changes | Status |
| :--- | :--- | :--- | :--- |
| **2026-09-04** | Architecture Specification | Documented motivation, Talk constraint analysis, schema, and phased execution plan. | Completed |
| **2026-09-04** | Phase 1: Database & Persistence Layer | Implemented `proj_direct_chats` migration, `ProjectDirectChat` entity, `ProjectDirectChatMapper`, and unit tests (all passing). | Completed |
