# Mail Local Tag Creation — Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline, with TDD and one fresh final review. Harness ledger stays outside this repository.

**Goal:** Continue C5 with reviewed user-owned tag creation, exact operation correlation and zero sending; keep message assignment closed until native cache identity is corrected.
**Architecture:** Reuse native ImapFlag, Tag and TagMapper and the existing operation journal. Native tag insertion and its opaque ID checkpoint share one Nextcloud DB transaction. Do not call MailManager.createTag, whose name-based existing-record return cannot prove creation. No new journal, transport, native schema or installation is needed for this local slice.
**Spec:** [Mail completion design](../specs/2026-11-07-mail-completion-design.md), C5, and [master plan](2026-11-07-mail-completion-plan.md).
**Baseline:** 9b818f4f, MCP2.7.0, A/D/C0/C1/C2/C3/C4 preserved. The user's continuation authorizes inline completion of this next independent C5 slice. Sending, deployment and publication remain excluded.

## Decisions and constraints

- Create `mail_create_tag` / `create_tags`, denied by default, with required `name` and `color` (`#RRGGBB`) and the existing confirmation fields. Native tags are user-scoped across accounts; the preview states that scope.
- Require the qualified NC35.0.2/Mail5.12.5 native source for TagMapper/Tag/ImapFlag and the actual unique `(user_id,imap_label)` index. Database sharding is unqualified and refused. Other pairs/builds refuse creation while tag inventory remains readable.
- Name <=128 UTF-8 bytes, no controls/bidi or edge whitespace. Use native canonical encoding; refuse invalid keywords, reserved default labels and truncation beyond 63 encoded bytes. Existing canonical labels are refused, never deduplicated into a successful creation. Inventory <=1000.
- Bind the complete owned native tag inventory and canonical request into the preview revision. Confirm again before transaction and immediately before insertion with current original-tool authorization.
- Serialize MCP tag creation by a user-wide journal target, including across connections. Use existing five/minute slots with a tag-specific owner scope. Status/replay never inserts or probes.
- Atomic local transaction rejects ambient/delegated transactions. Known rollback restores in-memory checkpoint state and becomes failed_before_effect. Ambiguous commit closes the connection, rereads authoritative persisted evidence on a fresh connection, retains uncertainty/target ownership and never retries commit/insert. Do not overwrite persisted evidence with pre-transaction memory.
- Receipts expose only the opaque observed tag ID, under current create permission; uncertainty never proves that the row exists. No display name/color/native label is added to operation history.
- Fix native inventory handling of nullable color, which the pinned schema permits.

## Review focus

1. Native insert commits before a lost acknowledgement: tag ID and journal evidence must survive together, with one insert only.
2. Checkpoint or insert fails before commit: no phantom tag ID may be persisted after rollback.
3. Different names encode to the same native label, or native UI wins a concurrent insert: uniqueness refuses, never adopts an existing tag as newly created.
4. Another connection or revoked source permission queries/repeats an uncertain creation: preserve actor/connection isolation, current authorization and the user-wide non-replay claim.
5. Native tag cache associations and searches still use header Message-ID: this local tool must not silently enable message assignment or rewrite native message associations.

## Task 1 — Atomic local operation boundary

**Files:** `mcp/lib/Mcp/Operations/{OperationJournal,LocalCommitUnknown}.php`; `mcp/tests/Unit/Mcp/Operations/LocalOperationTransactionTest.php`.
**Interfaces:** `OperationJournal::atomicLocal(IDBConnection $db, Closure $write): mixed`; local creation calls it only for DB-only native mapper/checkpoint work.

- [x] Add real SQLite journal tests for commit, rollback restoring refs, acknowledgement loss before/after commit, uncertainty, retained target and no replay.
- [x] Observe RED, implement the direct-only transaction/catch boundary, rerun journal/flag/move/folder fences. Expected: no partial native/journal commit, no retry and no changed delegated semantics.

## Task 2 — Native tag creation and publication

**Files:** `Mail/Integration/{MailTagGateway,MailTagBoundary,MailServices,MailCapabilities}.php`, `Mail/Tags/MailTagCreation.php`, `Tools/CreateTag.php`; catalog/DI/grants/presentation/admin/l10n/API fixtures; tag creation/inventory tests.
**Interfaces:** `MailTagCreation::preview(array,string): array`, `write(array,string): array`; native gateway uses fixed native classes and the common DB connection; boundary validates source hashes/index.

- [x] Add RED tests for fresh/inert full preview, collisions/truncation, foreign rows, stale inventory, nullable color, rollback and readback. Add native HTTP tests with real native TagMapper and the existing owned disposable users; no credentials/new native accounts.
- [x] Implement native mapper insertion + owned ID readback, checkpoint inside atomicLocal, own grant/title/renderer and minimal receipt ID. Do not call IMAP/SMTP/sync/events.
- [x] Regenerate API surfaces from pinned local archives. Append one raw tool definition per locale intentionally; preserve all existing contracts.
- [x] Qualify on stock and v3 fixtures: one tag on replay, crash/rollback evidence, original-permission revocation, collisions, foreign isolation, same source associations/outbox/MDN/held drafts/SMTP state.

## Task 3 — Acceptance and remaining native dependency

- [x] Run focused tests, `devbox run check --cwd WORKTREE --json`, native C5 plus previous Mail regression, then one fresh read-only review. Fix Important findings RED→GREEN and rerun affected/full checks.
- [x] Document creation qualification and the remaining exact native association migration: message-ID call sites include TagMapper, MessageMapper import/update/search/related-data, MailManager UI/bulk/delete paths and classification. Assigning an exact IMAP UID alone cannot complete that migration.
- [x] Keep C6 remote refresh closed pending unique-owner native sync leases and bounded/held-draft guarantees. Record findings and practical remaining work in the master plan.
- [x] Commit locally after checks. No push/PR/deployment/worktree cleanup.

## Acceptance result

Completed locally: full project check 10,171 PHP tests / 92,755 assertions and
837 browser tests; both static scopes, syntax, docs and packaging passed. Native
v3 regression passed 28 tests / 850 assertions; stock creation qualification
passed 2 tests / 36 assertions. One Important final-review finding (30-day tag-ID
receipt retention) was fixed RED/GREEN; no remaining reported findings. See
[delivery evidence](../specs/2026-11-09-mail-tag-creation-evidence.md).
Message assignment and remote refresh remain blocked; this is not full C completion.
