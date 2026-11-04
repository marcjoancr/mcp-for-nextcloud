<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Mail attachments, drafts and Talk search implementation plan

> Implementation follows the project's tool boundaries, test-driven development and independent review. The user has requested execution of this scope.

**Goal:** Read and save Mail attachments, manage unsent drafts, and find Talk messages with readable surrounding context.

**Architecture:** Optional native application services remain lazy and user scoped. Each tool declares an independent grant where its authority differs; writes use the existing preview, confirmation and plan-state framework. Native ownership and resource visibility are rechecked on each call.

**Tech stack:** PHP 8.2+, Nextcloud native application APIs, PHPUnit and the pinned disposable integration harness.

## Requirements and constraints

- English branch, commits, code and canonical documentation; localize user-facing messages.
- No implicit sending, mail synchronization, read marking or notifications from reads.
- Attachments are addressed through an owned message and its MIME part, with bounded content and generation checks. Saving requires Mail attachment-save and Files create authority and an allowed destination. Never overwrite an existing file.
- Draft listing/reading, writing and deletion have separate permissions. Mutations require a matching preview and confirmation, reject foreign accounts/drafts and detect stale edits. Preserve native draft attachments or refuse unsupported edits explicitly.
- Talk search is limited to an authorized conversation and bounded pages. Context anchors must belong to that same conversation; membership, lobby and history restrictions apply. Do not change read markers.
- New sensitive operations start denied; updating must not silently expand existing connection selections.
- Use native supported APIs confirmed against pinned source. No production mutations in implementation tests.

## Review focus

1. A foreign identifier must reveal no content and perform no mutation.
2. Changed or revoked access between preview and confirmation must refuse the write.
3. A stale draft or a different attachment must not be overwritten or saved under old approval.
4. Large or malformed content and unavailable optional applications must fail safely.
5. Search/context must preserve unread counters and exclude restricted file references.

## Task 1: Mail capabilities

Own `mcp/lib/Modules/Mail/` and corresponding module tests, plus new Mail integration tests. Add attachment list/read/save tools and draft list/read/save/delete tools. Expose new classes through `MailToolCatalog`; the parent integrator owns `Application`, grants, translations, shared fixtures and documentation.

- [x] Inspect pinned Mail source and select native attachment/draft adapters with no send side effects.
- [x] Write and run failing capability tests for ownership, bounded reads, confirmation, stale drafts and destination policy.
- [x] Implement adapters, capabilities, tool metadata and preview dispatch. Follow existing `PlanState` and safe Files write contracts.
- [x] Run focused tests and report exact composition dependencies and localization messages to the integrator.

## Task 2: Talk search and context

Own `mcp/lib/Modules/Talk/` and corresponding module tests, plus new Talk integration tests. Add `talk_search_messages` (operation `search`) and `talk_read_message_context` (operation `read`) to `TalkToolCatalog`. The parent owns `Application`, grants, translations and shared fixtures.

- [x] Inspect pinned Talk source to select native search and bidirectional history APIs.
- [x] Write and run failing tests for room/user isolation, context anchor ownership, paging, lobby restrictions and unchanged read state.
- [x] Implement bounded search/context with existing conversation resolution and reference filtering.
- [x] Run focused tests and report composition dependencies and consumed API contracts.

## Task 3: Integration and qualification

Own `mcp/lib/AppInfo/Application.php`, grant policy, publication metadata, shared fixtures/contracts, translations and documentation.

- [x] Add failing registration and permission tests; wire the new capabilities without widening existing grants or profiles.
- [x] Update English user/tool guides, localized tool titles/messages and native API contracts for newly consumed surfaces.
- [x] Run focused tests, static analysis and the full `devbox run check` task.
- [x] Run native Mail/Talk tests in a disposable Nextcloud fixture, including two users, unchanged read state and actual saved drafts/attachments.
- [x] Obtain independent review, fix actionable findings, rerun affected checks and commit in English.

## Verification record

- The full catalog check passed documentation checks, syntax, both PHPStan scopes and 9,377 PHP tests (81,694 assertions). Its remaining JavaScript catalog mismatch was corrected; the complete JavaScript/browser suite then passed all 813 tests, followed by successful production packaging.
- A disposable Nextcloud 35.0.2 / MariaDB 10.11.19 / Mail 5.12.5 / Talk 25.0.6 run exercised the integration suite. After correcting the Talk unread-state fixture, the four focused Mail/Talk native scenarios passed with 79 assertions, including owner isolation, binary attachment persistence, local draft mutations and unchanged read state. This is targeted native evidence, not a full supported-version matrix qualification.
- Native API contract fixtures cover the consumed surfaces across Nextcloud 31–35. Independent review findings and the final pagination/metadata bounds were resolved and rechecked.
- New permissions and connection selections remain opt-in. This implementation does not deploy or enable production profiles.
