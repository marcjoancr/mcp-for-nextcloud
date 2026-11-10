# Mail Completion Without Sending — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete owned-account Mail reading, unsent composition and everyday organization on top of A/D, with sending disabled.
**Architecture:** Extend the existing native Mail adapters, composition services and shared operation journal. Native Mail remains the owner of accounts, credentials, MIME, folders and cached messages. Reuse the existing typed PHP boundaries and bounded arrays; do not rebuild A/D, introduce a second journal or implement a transport.
**Tech Stack:** Manifest PHP >=8.4 / Nextcloud 33–35, optional Mail >=5.0.0, Nextcloud DB abstractions, PHPUnit and existing Playwright/native fixtures. Historical NC31/32 API fixtures do not extend the manifest's runtime support.
**Spec:** [Mail completion design](../specs/2026-11-07-mail-completion-design.md), as updated by the user's 2026-11-08 no-send instruction.
**Status:** A/D/C0 and C1–C6 are implemented on MCP2.7.0, with 41 tools including D+ and independent native/source/schema/provider gates. C7 acceptance and final review are complete under the [C7 execution plan](2026-11-09-mail-c7-acceptance-plan.md). Stock Mail remains blocked for guarded remote writes. D+1/D+2/D+3 are complete for the supported scope: linked authentication observations, proof-based reconciliation and alias CRUD. The [D+ evidence](../specs/2026-11-09-mail-d-plus-evidence.md) records native acceptance and complete regression. Native v7 implements manual credential-write evidence for unchanged profiles; its originating-page attribution correction passes native RED→GREEN and the existing guarantees; final enabled-source acceptance and complete regression pass. It never attests secret inequality, current authentication or delivery. B sending/scheduling/dispatch is frozen and excluded from completion. The v8 continuation below qualifies the exact NC33.0.10, NC34.0.5 and NC35.0.2 pairs; other combinations remain unverified.

## Current continuation: PostgreSQL deployment and B foundation

Production deployment is complete on NC35.0.2.2 / PostgreSQL16.15 / primary S3:
MCP2.7.0 commit `49b00cc1f9d4285e8ee14530247a8fefcd46ad37`, native Mail5.12.5.9.
The [deployment evidence](../specs/2026-11-10-mail-postgresql-deployment-evidence.md)
records exact hashes, 59 native tests / 1,433 assertions, complete browser
acceptance, 10,394 PHP / 95,843 assertions and 837 browser regression tests.
Migrations, grants/connections, HTTPS and disabled sending are verified.
The [PostgreSQL continuation plan](2026-11-10-mail-postgresql-deployment-plan.md)
now starts separate local bounded outbox inspection and cached metadata batches.
The first two metadata tools and their native v10 dependency pass the real PostgreSQL path; see the [B0 evidence](../specs/2026-11-10-mail-bounded-metadata-evidence.md). Complete regression and installation of this separate continuation remain tracked independently. This authorizes no transmission. Older sections below retain their historical
provider/artifact limits; v9 adds only its specifically qualified PostgreSQL pair.

## Current continuation: native draft lifecycle

The [2026-11-10 draft plan](2026-11-10-mail-draft-lifecycle-plan.md) extends the
pinned native dependency with exact reply/alias serialization, an owner-only
native editor entry and persisted draft-only protection. Final guarded native acceptance passes 58 tests / 1,458 assertions and real
plain/HTML browser workflows. The consolidated final-review correction adds
shared owner-session coordination and complete snapshot checks; seven native
contention/session-loss cases pass. Stock regression passes (19 executed / 39 expected skips / 530 assertions).
The full catalog passes 10,341 PHP tests / 95,113 assertions, 837 browser tests,
both static scopes and packaging; see the [delivery evidence](../specs/2026-11-10-mail-draft-lifecycle-evidence.md). This continuation does
not enable sending. Native credential-write evidence follows in the continuation below. Broader NC33/34
qualification remains separate work, not inferred from the draft tests.

## Current continuation: native credential-write evidence

The [credential plan](2026-11-10-mail-native-credential-evidence-plan.md) extends
the pinned dependency to v7 / Mail5.12.5.7. Native credentials and consent remain
in the original assistant. An owner/source/scope-bound handoff, hidden account
revision and one-shot proof permit unchanged-profile completion after actual
native persistence. Failed OAuth with old tokens, refresh, stale state, page
visits and diagnostics cannot provide new manual evidence.

Final enabled acceptance passes 56 native Mail tests / 1,412 assertions, real
plain/HTML draft workflows and password/Google/Microsoft MCP completion,
consumption, status recovery and replay. Stock regression and immutable v6
source-gate compatibility pass. The [delivery record](../specs/2026-11-10-mail-native-credentials-evidence.md)
distinguishes native write observation from secret change/authentication/delivery
and tracks final validation/review/artifacts. Sending remains disabled; no
installation is performed. The final review found cross-tab evidence attribution in three native admission hooks. Per-page request binding and all three browser regressions pass RED→GREEN with the existing guarantees; full enabled-source acceptance and the complete catalog pass (10,371 PHP / 95,499 assertions, 837 browser tests), as recorded in the delivery evidence. Exact NC33/34 qualification is completed independently in the v8 continuation below.

## Current continuation: exact runtime qualification

The [runtime plan](2026-11-10-mail-runtime-qualification-plan.md) preserves v7
and adds a separate v8 installation identity with identical native implementation
bytes. One exact runtime policy gates capabilities, draft entry, credentials and
aliases. NC33.0.10 and NC34.0.5 each pass candidate guarantees and full enabled
acceptance (57 native / 1,424 assertions plus all browser workflows). Stock
NC33/34, current v8/NC35 regression, immutable compatibility, fresh review and the
complete catalog pass (10,379 PHP / 95,640 assertions and 837 browser tests).
The [runtime evidence](../specs/2026-11-10-mail-runtime-qualification-evidence.md)
records exact hashes, review correction and limits. All delivery stays local;
sending remains disabled. Unknown pairs, upgrades and deployment need separate
acceptance.

## Follow-up: safe mutation guard and C1 delivery

The [2026-11-08 guard design](../specs/2026-11-08-mail-mutation-guard-design.md) and [implementation plan](2026-11-08-mail-mutation-guard-plan.md) propose the native dependency, closed actions, delegated journal and independent C1/C3 gates. They supersede the raw-client callback sketch below. This is a planning update, not a capability unblock: stock Mail5.12.5 remains blocked and sending stays disabled.

The [C1 delivery record](../specs/2026-11-08-mail-c1-guard-evidence.md) records the implemented closed flag boundary, custom native dependency and delegated journal. C1 is conditional on that verified native artifact; stock Mail remains blocked. The [C3 delivery record](../specs/2026-11-08-mail-c3-guard-evidence.md) adds four qualified single-message movement actions on the same journal/runner; stock and C1-only Mail builds still refuse moves. Sending remains closed. The baseline table below records the preceding A/D tool set.

## Current follow-up: C2, C4, C5, C6 and C7 acceptance

The C7 baseline published **37 Mail tools**; D+ below adds four separately granted actions. At that baseline, C2 adds create, same-parent
rename, subscription and special-role configuration; C4 adds owned identity
inventory and forwarding fields to the existing draft writer; C5 adds read-only
tag inventory, qualified local creation and conditional exact message assignment. C6 adds account/subject filters to local draft reads and explicit qualified mailbox refresh. Each new tool
has its own denied-by-default permission and connection selection. Sending remains
disabled. Qualification and exact artifact hashes are recorded in the
[C2 and continuation evidence](../specs/2026-11-08-mail-c2-continuation-evidence.md).

C2 remote actions require the independently fingerprinted **mcp-mail-mutations-v3**
source dependency on NC35.0.2/Mail5.12.5. Flags-only v1 and movement v2 remain
accepted for their original scopes. Folder changes use native commands/mappers
with targeted cache updates, preserving omitted roles and unrelated cache; they
never invoke the whole-account MailboxSync or automatic special-role guessing.
New folder message freshness stays unknown until normal native synchronization or a separately reviewed qualified C6 refresh/reset.
Role configuration itself is local and remains available without v3.

C4 guarantees new local forward drafts, a complete reviewed quote or explicit
final body, no inherited recipients/Bcc/parent/attachments, explicit MIME/Files
snapshots and the existing journal/repair/status behavior. Alias IMAP conversion
and reply hold limitations from C0 still apply. Alias CRUD is D+3, outside C4.

C5 inventory/local creation are complete. Exact message assignment is implemented
under the [assignment plan](2026-11-09-mail-tag-assignment-plan.md), qualified on the exact native v4 dependency. It adds `mail_set_message_tag` / `tag_messages`, independent
permission/connection selection and the existing approval/journal/status workflow.
Stock and older native overlays remain blocked. The v4 native dependency replaces
header Message-ID associations with exact cached IDs plus fresh selected-generation
and non-secret inbound identity provenance, preserving all old rows as unknown.
Native import/update/read/search/UI/bulk/delete/classification share that identity.
Pre-fetch mailbox revision chains prevent stale sync/UI cache writes from adopting
concurrent changes. Legacy/profile-changed cache requires an explicit native rebuild.
Only the native MySQL/MariaDB provider is eligible; other backends remain closed.
[Assignment evidence](../specs/2026-11-09-mail-tag-assignment-evidence.md) records
qualification and rollback requirements. C6 below now admits qualified refresh; sending remains closed.

Local C5 creation acceptance: 10,171 PHP tests / 92,755 assertions, 837 browser
tests, both static scopes and packaging; native v3 regression 28 tests / 850
assertions. Exact transaction/fault/receipt evidence is in the
[C5 creation delivery](../specs/2026-11-09-mail-tag-creation-evidence.md).

C6 is delivered conditionally on native v5: closed bounded refresh and physical session ownership. Its
repository qualifier was enabled only after source/schema/native/whole checks
passed. Read-only collection, cache CAS, explicit reset/new IDs and historical
freshness replace the unsafe general-sync route. See the
[C6 plan](2026-11-10-mail-refresh-plan.md) and
[evidence](../specs/2026-11-10-mail-refresh-evidence.md).
C7 acceptance covered the preceding delivered slices; D+ is accepted separately below. Optional native parity remains outside that acceptance.
D+ linked authentication observations, proof-based reconciliation and alias
administration are implemented under the [D+ plan](2026-11-09-mail-d-plus-plan.md);
native acceptance and full regression are complete. Unchanged-profile credential changes remain unverified. No force-unlock, folder deletion or send tool is added.

## Implementation baseline and evidence

The A/D baseline `MailToolCatalog::definitions()` contained **23 tools** at `f59d5fc1`; C1 added one flag tool and C3 adds four move/archive/trash/restore tools, for **28 tools at the C3 baseline**. The historical baseline follows:

| Area | Implemented tools / boundary |
| --- | --- |
| Accounts, folders and cached header search | `mail_list_accounts`, `mail_list_mailboxes`, `mail_search_messages` |
| Message/thread/attachment reading and Files saving | `mail_read_message`, `mail_read_thread`, `mail_read_attachment`, `mail_save_attachment` |
| Local unsent composition | `mail_read_drafts`, `mail_write_draft`, `mail_delete_draft`, `mail_attach_files` |
| Durable status | `mail_get_operation`, `mail_list_operations` |
| Preferences/signatures | `mail_read_account_settings`, `mail_write_account_settings`, `mail_read_signature`, `mail_write_signature` |
| Native account setup/diagnostics/removal | `mail_read_connection`, `mail_configure_connection`, `mail_test_connection`, `mail_add_account`, `mail_complete_account_setup`, `mail_delete_account` |

Implementation anchors, rather than the old checklist, establish what is delivered:

- `mcp/lib/Modules/Mail/Composition/ReplyComposer.php` derives reply/reply-all recipients and validates owned aliases. `Tools/WriteDraft.php` had no forwarding fields in the C3 baseline; C4 below now adds forwarding and owned identity discovery. D+3 now adds separately granted alias administration.
- `Drafts/MailDraftOperations.php` saves a real native parent locally and sets `failed=true` to hold lossy native draft-to-IMAP synchronization. Native UI edits can release that hold. This is **local threading**, not a proven native UI/IMAP lifecycle guarantee.
- `Files/MailFilesSource.php` snapshots authorized Files bytes, preserves retained attachments and supports reviewed partial repair. Limits remain 10 attachments / 25 MiB raw, 20,000 characters per body and 50 recipients per address field.
- `Integration/MailBodyPreflight.php` checks server-reported whole-message size (1 MiB). It is **not a hard streaming byte cap** against an IMAP server that lies about size. Search is cached header search; draft listing is local-only, with account/subject filters now added by the C6 local slice.
- `Settings/MailSettings.php` supports exactly `editor_mode`, `signature_above_quote`, `show_subscribed_only`. C2 assigns special folders; D+ adds separately granted alias CRUD.
- `Accounts/MailAccountSetup.php` admits a 24-hour native UI workflow and binds a specifically selected account. An unchanged connection profile refuses completion, including credential-only changes. The fixed native link does not prefill fields or capture credentials.
- `Connection/MailConnection.php` checkpoints sanitized per-protocol results. `Mcp/Operations/OperationJournal.php` exposes them in `operation.protocols`, rechecks guarded completion replays and serializes writes by account where `account_id` is supplied.
- `Operations/MailOperationCleanup.php` preserves uncertain/repairing claims. D+ adds reviewed reconciliation only for exact durable completion proof; no force-unlock or resolution by absence is published. Native account deletion does not erase the journal.
- At the historical A/D baseline there were no flag/move/archive/trash/restore, tag or folder-mutation tools. C1/C3 and the current follow-up add the qualified actions above. Remote refresh now requires qualified v5; sending, scheduling and outbox dispatch remain unavailable. Message tag assignment requires qualified native v4 or v5.

Recorded implementation validation at `f59d5fc1`: full check 9,924 PHP tests / 89,235 assertions, 837 browser tests, both static scopes and packaging; native NC35/MariaDB/Mail5.12.5: 10 tests / 319 assertions. These are baseline results, **not evidence that the pending capabilities work**, nor a deployment record.

## Global constraints and definition of complete

- Keep all 23 tools and A/D behavior. Read the worktree identity/status before changes and preserve the existing implementation line.
- **Sending stays disabled:** add no send, enqueue, schedule, cancellation or receipt/MDN transmission tool. Do not call native `OutboxService.convertDraft/sendMessage`, a send chain, or any SMTP submission/envelope/DATA API. D authentication diagnostics remain allowed.
- New refresh/organization paths must not flush held reply drafts, dispatch outbox workers or activate cron jobs. Native Mail actions independently performed by the person remain outside MCP control.
- Every new capability is denied by default and explicitly selected per connection. Current ownership, source permissions, Files scope/download/tags/Guests controls and approval proofs remain enforced.
- Each mutation previews without side effects, uses a fresh `plan_state` plus the existing `operation_id`, reserves before effects and returns a minimal durable receipt. Status never writes, connects to IMAP/SMTP, opens UI or retries.
- Require explicit `account_id` on new mutation schemas, validate all referenced messages/folders against it and reuse account-level serialization. Do not weaken A/D account-removal exclusions.
- Bind IMAP mutations to the **actual selected native client**, account, mailbox identity, UIDVALIDITY and UID. A successful preflight followed by `MailManager.flagMessage/moveMessage` creating another client is insufficient. Preserve native cache/events using supported native boundaries; otherwise report unsupported and direct the person to Mail.
- No new IMAP/SMTP implementation, native SQL writes, controller calls in production code, protected-method reflection or secret schema/URL/log/receipt fields. Native controllers may be exercised by disposable tests only.
- Retain non-replay keys and uncertain history. A timeout, missing local row, matching Message-ID or vanished source is not proof of success or safe retry.
- No remote permanent deletion: reject trashing an already-trashed item, `\\Deleted` as a general flag, expunge, empty-trash and folder deletion.
- Preserve current body/attachment/recipient limits until a separately verified bounded native reader supports a change. Never silently truncate an effect or weaken S/MIME/signing/encryption settings.
- "Complete without sending" means A/D plus verified unsent composition/identity discovery, daily message/folder/tag organization, explicit bounded freshness and understandable operation recovery. Optional advanced parity is listed separately below. Unsupported native capabilities remain visible as explicit limitations, not false completion claims.

## Native findings that constrain the next deliveries

Read from the pinned official Mail5.12.5 archive, not inferred from documentation:

- `MailManager.flagMessage` creates its own client, delegates to `IMAP/MessageMapper.addFlag/removeFlag`, then dispatches a cache-related native event. The IMAP mapper accepts an existing client; using it alone without cache/event semantics is incomplete.
- `MailManager.moveMessage` allows only same-account moves and returns a nullable new UID. Native `IMAP/MessageMapper.move` uses `copy(..., move=true, force_map=true)` and can return no mapping. A repeated move or a Message-ID match cannot resolve that uncertainty.
- `MailManager.deleteMessageWithClient` can **expunge inside Trash**. Its existence does not authorize a generic safe-delete wrapper.
- Folder rename/subscription methods perform native synchronization and rename may return a different cached folder ID. Preview must account for descendants, roles and outstanding operations rather than binding only the displayed name.
- `AliasesService.findAll/create/update/delete` exists, but alias updates also accept an S/MIME certificate ID and protect provisioned addresses. Identity discovery is the first slice; mutation needs its own preservation contract.
- Existing `MailBodyPreflight` is a **read** guard, not a validated read-write selection/mutation primitive. Existing signed `mcp:mail-connection-probe` is an **authentication-only** child, not a generic writer/refresh runner.

## Review focus

1. A native UI edit/auto-flush releases A's reply hold: C0/C4 must preserve or explicitly refuse the unsupported lifecycle; never silently clear `failed`.
2. UID generation changes between preview and actual mutation, or two messages share Message-ID: C0/C1/C3 must refuse the wrong generation and never mutate a guessed target.
3. A move succeeds remotely before process death, lacks a destination UID mapping, or races an external folder rename: C3/C7 must retain uncertainty and prevent a second move.
4. A credential-only native change leaves the profile unchanged, or permission/selection is revoked before status/replay: D+ must distinguish current authentication from proof of changed credentials and preserve authorization checks.
5. Refresh/tag/folder helpers have hidden write/worker side effects or incomplete resource budgets: C0/C2/C5/C6 must prove zero send/held-draft flushing and refuse unsafe native paths.

## Execution pattern and shared interfaces

Paths beginning `Mail/` are relative to `mcp/lib/Modules/`; bare `Tools/`, `Accounts/`, `Composition/`, `Connection/`, `Drafts/`, `Integration/`, `Operations/`, `Presentation/`, `Settings/`, `Signatures/` and `Search/` are relative to `mcp/lib/Modules/Mail/`. `Mcp/Operations/` is under `mcp/lib/`; unprefixed test paths are under `mcp/`. Existing shared registration files retain their repository locations. Each numbered task is an independent delivery slice. For **each slice**: write the named regression/native tests first; run the narrow command below and observe the intended failure; implement the named boundaries; rerun the slice and affected A/D tests; update capability evidence, then make a local commit. No future phase is implicitly authorized for implementation by this document update.

Retain actual interfaces rather than the superseded speculative DTO API:

- `OperationJournal::plan(string $tool, string $uid, array $args, array $plan): array`.
- `OperationJournal::execute(string $tool, string $uid, array $args, Closure $effect, ?Closure $authorizeReplay = null): array`; `checkpoint(array $refs): void`; `get(string $uid, string $id): ?array`.
- `MailMessageLocator::locate(string $uid, int $messageId): array` returns owned native `message`, `mailbox`, `account` objects internally. Extend `MailBodyPreflight` only where its existing read contract remains intact.
- Existing Files `MailFilesSource::preview/stage`, `MailDraftOperations::previewWrite/write`, D `preview/begin/previewCompletion/complete` and `MailOperationReader` remain the service boundaries. No second receipt database.
- C0 delivers `MailCapabilities::forUser(string $uid): array` and `requireSupported(string $uid, string $capability): void`. The original raw-client `MailMutationSession::run(..., Closure)` proposal is superseded, not implemented. The [guard plan](2026-11-08-mail-mutation-guard-plan.md) proposes closed `setFlag(string $uid, array $request): array` and `move(string $uid, array $request): array`, backed by a verified native dispatch/proof/cache boundary and authoritative delegated journal. Both closed methods are now implemented on their independently qualified native artifacts; C2 adds a closed mailbox action on v3.
- Future receipt additions are explicit allowlisted identifiers/statuses: source/destination mailbox references, destination cached message ID only when verified, and per-item results if a later batch is admitted. Opaque revisions/correlation data stay private; no message content or heuristic account matching.
- Every new tool additionally updates `MailToolCatalog.php`, `AppInfo/Application.php`, `GrantPolicy.php`, `WriteGate.php`, `ToolPresentation.php`, admin access/group/inspection labels and tiers, l10n, guide and relevant permission/selection/publication contracts. Existing grants/selections must grant **none** of the new tools.

### C0 — Capability and lifecycle foundation (characterized; mutations gated)

**Implementation result:** See [the C0 native evidence/matrix](../specs/2026-11-08-mail-c0-native-evidence.md). The 23 A/D tools remain unchanged. The actual native client can reselect a different UID generation, so no raw-client callback runner is published or implemented. C1/C3 require a verified guard/contract revision; version or method presence is insufficient. Existing reply/alias guarantees remain local; native UI editing and IMAP flush do not extend them.

**Files:** Create `mcp/lib/Modules/Mail/Integration/MailCapabilities.php`; implement `MailMutationSession.php` only after its native execution contract is proven; extend `MailServices.php`, `MailBodyPreflight.php` only as necessary, `mcp/tests/fixtures/nextcloud-api/{generate-mail.php,mail-sources.json}`, `mcp/tests/Integration/ci/mail-cell.sh`; create `mcp/tests/Unit/Modules/Mail/MailCapabilitiesTest.php` and `mcp/tests/Integration/Mail/McpMailLifecycleTest.php`.
**Consumes:** A/D native adapters, account claims, existing disposable IMAP and authentication-only SMTP fixtures.
**Produces:** A tested per-native-pair capability matrix and same-client selection contract for C1/C3; no new user mutation tool yet.

- [x] Add `testReplyParentAcrossNativeUiEditAndImapFlush`: check local parent, native UI update, hold release and actual IMAP draft headers. Keep the existing local-only guarantee until the complete path is proven; a required native Mail fix is a separately authorized upstream task.
- [x] Add `testUidValidityChangeBeforeReadWriteSelectionRefusesMutation`, same-Message-ID/different-UID and reconnect/reselection cases in the same native characterization, `testUnsupportedVersionDoesNotHideExistingReadTools`, `testNoLifecycleProbeDispatchesOutboxOrSmtpSubmission`.
- [x] Verify public native client/mapper/event/cache APIs, including implicit reconnect/reselection behavior, and record whether each of flags/move/labels/folder actions is supported. A reused client object alone is not proof if it silently reconnects before mutation. Do not create unused mutation wrappers to satisfy a diagram. If the same-client/event contract fails, publish an explicit native-UI fallback for that capability.
- [x] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailCapabilities|MailCacheGeneration|MailBodyPreflight'` (expected: focused cases green); run the native Mail cell with lifecycle scenarios (expected: no SMTP delivery and no wrong-generation mutation).

### C1 — Explicit message flags (implemented; requires verified native dependency)

**Files:** Create `Mail/Messages/MailMessageFlags.php`, `Mail/Tools/SetMessageFlag.php`, `tests/Unit/Modules/Mail/MailMessageFlagsTest.php`, `tests/Integration/Mail/McpMailMessageFlagsTest.php` under `mcp/`; integrate shared registration/presentation files above.
**Consumes:** C0 validated selection, existing message locator, journal/account claim.
**Produces:** Implemented `mail_set_message_flag`, independent operation `set_flags`, arguments `account_id`, `message_id`, `flag` (`seen`/`flagged`), `value` (boolean), plus standard confirmation proofs. No toggle or arbitrary raw flag.

- [x] Cover desired seen/flagged state, ownership/revocation, changed cache generations, replay refusal and exact native cache/unread results in the unit suite, `McpMailMessageFlagsTest` and `McpMailNativeMutationTest`; see the C1 delivery evidence above.
- [x] Preview the single requested field and current state; mutate/read back through C0 and supported native cache/event behavior. Checkpoint evidence so an interrupted write never becomes an automatic replay. Distinguish proven pre-effect refusal from possible IMAP effects.
- [x] Run focused `--filter MailMessageFlags` unit/native tests (expected: only the selected UID/flag changes; neighboring flags and SMTP capture unchanged), then commit.

### C2 — Folder organization and special-folder settings (implemented on qualified v3)

**Files:** Create `Mail/Mailboxes/MailboxMutations.php`, `Tools/{CreateMailbox,RenameMailbox,SetMailboxSubscription,ConfigureMailboxes}.php`, `Mail/Mailboxes/MailboxRoles.php`, related unit/native cases; extend `Integration/MailSettingsGateway.php` only for explicit loaded-entity special-folder patches.
**Consumes:** C0 capabilities, owned account/folder inventory, A/D journal and revision proofs.
**Produces:** Proposed `mail_create_mailbox` / `create_mailboxes`, `mail_rename_mailbox` / `rename_mailboxes`, `mail_set_mailbox_subscription` / `subscribe_mailboxes`; `mail_configure_mailboxes` / `configure_mailboxes` with `account_id`, nonempty `roles` containing only positive owned `drafts_mailbox_id`, `sent_mailbox_id`, `trash_mailbox_id`, `archive_mailbox_id` values, and confirmation proofs. `MailboxRoles::preview/write(array $args, string $uid): array` validates same-account native folders and delegates a dirty-field patch/read-back. Omitted roles are preserved; clearing roles is not part of this slice. Do not expand existing `write_settings` implicitly.

- [x] Test account/role ownership, hierarchy delimiters, invalid names, rename collisions, protected/reserved folders, changed descendant sets, active-operation references and already-correct subscription. Bound previews to 100 descendants; refuse larger renames.
- [x] Test `testRenameReturnsVerifiedNewFolderIdentity` and `testInterruptedCreateIsNotRepeatedByNameGuessing`. Re-resolve native IDs/roles after read-back; do not assume rename preserves IDs. Preserve omitted account/auth/security fields.
- [x] Use the qualified v3 targeted native boundary instead of whole-account sync; verify no held-draft flush, SMTP enqueue or implicit synchronization. Existing targets and all descendants require consistent preview generations; unknown epochs need native synchronization before another remote action. Folder deletion/emptying remains absent.
- [x] Run `--filter 'MailMailboxMutations|MailSettings'` unit/native tests (expected: only approved hierarchy/subscription/role changes; existing A/D checks green), then commit.

### C3 — Same-account move, archive, recoverable trash and restore (implemented on qualified v2+)

**Files:** Create `Mail/Messages/MailMessageMoves.php`, `Tools/{MoveMessage,ArchiveMessage,TrashMessage,RestoreMessage}.php`, `tests/Unit/Modules/Mail/MailMessageMovesTest.php` and native equivalent; extend `Mcp/Operations` receipt allowlists / `OperationsMapper.php` only for explicit correlation fields.
**Consumes:** C0 session, C2 verified role destinations (or previously configured native roles), existing account claims/status.
**Produces:** Implemented `mail_move_message` / `move_messages`, `mail_archive_message` / `archive_messages`, `mail_trash_message` / `trash_messages`, `mail_restore_message` / `restore_messages` tools with `account_id`, `message_id`, and explicit destination ID where needed. A restore always previews its destination; it does not guess the historical folder.

- [x] Cover both bound generations, cross-account and missing-role refusal, already-trashed refusal and explicit owned restore destinations in the unit suite and `McpMailMessageMovesTest`.
- [x] Cover missing/malformed COPYUID, post-command faults, interrupted cache updates, duplicate Message-IDs and old native cache IDs in `McpMailNativeMovesTest`; durable proof and uncertainty prevent replay. See the C3 delivery evidence above.
- [x] Use only proven native move primitives. Reject heuristic `force_map` correspondence: require native UIDPLUS/COPYUID or an equivalently proven UID mapping, otherwise refuse the capability before mutation. A missing mapping despite admitted support still remains uncertain. Bind both mailbox generations; verify returned mapping and destination where available, preserve cache/events and persist minimal evidence. A nullable mapping or vanished source remains uncertain; source disappearance alone is insufficient.
- [x] Run `--filter MailMessageMoves` unit/native cases (expected: one approved move, recoverable trash, no expunge or SMTP command), then commit.

### C4 — Sender discovery and complete unsent composition (implemented locally)

**Files:** Create `Mail/Accounts/MailIdentities.php`, `Tools/ListAliases.php`, `Composition/ForwardComposer.php`; extend `Tools/WriteDraft.php`, `Drafts/MailDraftOperations.php`, `Presentation/MailPlanRenderer.php`; add unit/native forwarding/identity cases.
**Consumes:** A reply/Files/draft pipeline and D signatures; C0 lifecycle findings.
**Produces:** Proposed `mail_list_aliases` with independent `read_identities`; explicit forwarding through the existing draft writer, not a second draft store or sender.

- [x] List bounded (maximum 100/page) owned account/alias IDs, names and addresses; reject foreign/delegated identities. Return no certificate private material. Existing alias selection and signature reads must continue to work.
- [x] Propose `forward_message_id` for **new** local drafts only, mutually exclusive with `reply_to_message_id` and `draft_id`; source requires current `mail_read_message`. Show the exact bounded attributed quote, subject, recipients and sender before confirmation. Refuse unavailable/truncated source material unless the person explicitly supplies the final body; never invent a reply parent or inherit Bcc.
- [x] Copy no original MIME attachment implicitly. An explicit `forward_attachment_ids` selection additionally requires `mail_read_attachment`, verified source bytes, combined A limits and native snapshot/read-back evidence. Unsupported/oversized parts remain refused, with Files/manual native Mail as the fallback.
- [x] Add `testForwardIsNotReply`, `testNoImplicitRecipientsBccOrSignature`, `testForwardAttachmentSelectionIsExact`, `testForwardRepeatCreatesOneDraft`, `testNativeEditDoesNotBecomeAnAdvertisedThreadGuarantee`.
- [x] Keep signature insertion an explicit reviewed composition choice; default behavior remains the exact approved body. Preserve existing aliases/attachments/reply metadata and S/MIME state during ordinary edits.
- [x] Run `--filter 'MailIdentities|MailForward|MailDraftComposition|ReplyRecipients'` unit/native tests (expected: one unsent draft, approved bytes/identities, no outgoing admission), then commit.

### C5 — Mail labels/tags (inventory, local creation and conditional assignment complete)

**Files:** `Integration/{MailTagGateway,MailTagTarget,MailTagBoundary,MailNativeBoundary}.php`, `Tags/MailMessageTags.php`, `Tools/{ListTags,CreateTag,SetMessageTag}.php`, the shared journal/worker and the native v4 dependency. Native tag search uses exact verified cached IDs; no new MCP search filter is claimed.
**Consumes:** C0 selection/cache semantics, message ownership and native Mail tag APIs; no relation to Files SystemTags.
**Produces:** Published `mail_list_tags` / `read_tags`, `mail_create_tag` / `create_tags`, `mail_set_message_tag` / `tag_messages` capabilities. Assignment uses an owned native tag ID and desired boolean state; no arbitrary IMAP command/flag input.

- [x] Test native PERMANENTFLAGS/keyword support, unsupported server refusal, owned tag/message checks, stable canonical IMAP label, idempotent assignment and private cache consistency. Reject system flags masquerading as tags.
- [x] Test local creation interrupted before/after native ID recording, rollback and commit acknowledgement loss; retain uncertainty, never deduplicate/create again merely by display name. Keep opaque tag IDs through receipt compaction. Tag deletion/global renaming and bulk effects need a later reviewed design.
- [x] Qualification passed 40 native Mail tests / 1,053 assertions, the whole check (10,205 PHP tests / 93,247 assertions, 837 browser tests), and the final PHP catalog (10,206 / 93,249), with actual C5 enabled in the packaged source. Only selected associations change; held replies and no-send state are preserved.

### C6 — Explicit freshness and practical draft reading (conditional v5 implemented)

**Files:** Create `Mail/Sync/MailRefresh.php`, `Tools/RefreshMailbox.php`; extend `Presentation/MailRows.php` / existing Mailbox presentation, `Tools/ReadDrafts.php`, `Drafts/MailDraftOperations.php`, `Search/MailMessagePager.php` as needed; add unit/native freshness cases.
**Consumes:** Qualified native v5 `GuardedMailboxRefresh` (not the general synchronizer), C0 capability evidence and A/D status; ordinary reads remain cache-only or read-only peek.
**Produces:** Proposed `mail_refresh_mailbox` / `refresh`, freshness metadata and local draft account/subject filters. No automatic refresh inside status, search or listing.

- [x] Test `testReadNeverRefreshes`, `testFreshnessUnknownIsNotFresh`, `testExplicitRefreshPreservesSeenAndReplyHolds`, `testRefreshCannotDispatchWorkersOrOutbox`, `testRepeatedRefreshOperationDoesNotRunAgain`.
- [x] Admit one owned mailbox per operation, five operations/user/account/minute maximum and a 20-second wall-clock budget. Support this only if the native boundary/isolated runner can enforce the budget without unsafe replay; a socket timeout alone is insufficient. Do not repurpose D's authentication-only helper for arbitrary effects. Persist any partial/uncertain synchronization result and do not promise an unsupported native message-count cap.
- [x] Filter local drafts by owned `account_id` / subject while retaining max 50/page and full-read truncation flags. A cached IMAP Drafts mailbox is **not** a local draft; import needs its own future reviewed operation.
- [x] Run `--filter 'MailRefresh|MailDraftOperations|MailMessagePager'` unit/native tests (expected: bounded requested work only; old cursor/cache-generation tests and SMTP capture unchanged), then commit.

C6 acceptance: 10,242 PHP tests / 93,716 assertions, 837 browser tests, both
static scopes and packaging; final normal enabled-source native regression
47 tests / 1,157 assertions. All source/schema/provider gates remain mandatory.
Sending is disabled. See [C6 evidence](../specs/2026-11-10-mail-refresh-evidence.md).

### D+1 — Observe credential-only native work accurately

**Files:** Extended `Accounts/MailAccountSetup.php`, `Connection/{MailConnection,NativeConnectionProbeWorker}.php`, `Tools/TestConnection.php` and the operation receipt/permission chain; unit setup/connection cases and native `McpMailDPlusTest.php`.
**Consumes:** Delivered D workflows, scoped diagnostic receipts, immutable setup intent and A repair claims.
**Produces:** Optional reviewed native authorization evidence within existing ownership/permission boundaries, or a precisely documented blocker. This extends the delivered D; unchanged-profile refusal stays in place until evidence is proven.

- [x] Test credential-only native edits, OAuth consent without profile change, unchanged old credentials still authenticating and native UI abandonment. Evaluate a correlated, fresh successful per-protocol diagnostic or native authorization revision under current probe/source/read permissions. Describe it as **observed current authentication**, never proof that a password changed. If no trustworthy native change evidence exists, keep credential-change completion unverified; successful connectivity alone must not claim a credential update.
- [x] Require explicit workflow/account IDs, same actor/connection, current source permissions, intent equality and fresh revision for any evidence link. Status must not initiate the probe. Expose unsupported consent/deep-link capabilities honestly; never add secret inputs or fake prefill parameters.
- [x] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailAccountSetup|MailConnection|MailSetupOperation'` plus native scenarios (expected: original source revocation honored on status/replay, no false credential-change proof and no extra probes), then commit supported improvements or record the native blocker.

### D+2 — Explicit operation reconciliation

**Files:** Added `Mail/Operations/MailOperationReconciler.php` and `Tools/ReconcileOperation.php`; extended `Db/OperationsMapper.php`, `Mcp/Operations/OperationJournal.php` and `Mail/Operations/MailOperationReader.php`. The `mail_reconcile_operation` tool has its own denied-by-default `reconcile_operations` grant and requires original/source-action access and connection selection. Tests: `Unit/Modules/Mail/MailOperationReconcilerTest.php`, `Unit/Mcp/Operations/LocalOperationTransactionTest.php`, the existing mapper/receipt regression and native `McpMailDPlusTest.php`.
**Consumes:** A/D exact stored resource identities, keyed integrity proofs, account claims and protected repair/workflow phases; no dependence on D+1.
**Produces:** A separately reviewed, approved metadata reconciliation path or an operator inspection procedure where a safe result cannot be proven.

- [x] Test a crash during partial repair/setup binding and a native write succeeding before receipt persistence. Reconcile only exact stored native IDs/integrity evidence in a **separate approved operation**. Status continues to make no native call or metadata transition.
- [x] Never claim the effect failed, unlock an uncertain target or clean artifacts from absence/age/subject/Message-ID matching. Preserve the old non-replay tombstone and use compare-and-set transitions. A human acknowledgment is not proof of the native outcome. If safe resolution cannot be proven, document native/admin inspection rather than ship a force-unlock tool.
- [x] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailOperationReconciler|MailOperation|OperationsMapper'` plus native interruption cases (expected: zero repeated effects, no unauthorized receipt, unresolved cases retained), then commit.

### D+3 — Optional alias administration and additional settings

**Files:** Added `Mail/Accounts/MailIdentityAdministration.php`, `Integration/{MailAliasBoundary,MailAliasGateway,MailJournalStorage}.php`, `Tools/{AddAlias,UpdateAlias,DeleteAlias}.php`, unit alias/storage/tool tests and native `McpMailDPlusTest.php`. C4 inventory remains in `Accounts/MailIdentities.php`. Other account preferences require a separate explicit allowlist after their native contracts are inspected.
**Consumes:** C4 owned alias inventory, D signature/native account adapters and journal; C2 already owns special-folder roles.
**Produces:** Independent `create_identities`, `edit_identities`, `delete_identities` grants for `mail_add_alias`, `mail_update_alias`, `mail_delete_alias`; not generic native object patching. This is a later parity slice, not a blocker for daily no-send composition.

- [x] Preview account/alias IDs, exact address/name and current revision. Preserve omitted signatures, account membership and S/MIME certificate bindings; refuse edits to provisioned addresses and removal while a local draft or active/uncertain operation references the alias. Never accept crypto private material.
- [x] Test unknown-key rejection, foreign alias membership, provisioned identities, retained certificate/signature fields, interrupted creation and lost delete responses. No guessed deduplication by email/name.
- [x] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailIdentities|MailSettings|MailSignatures'` plus native identity cases (expected: only approved fields change; no outgoing admission), then commit. Ordering/background sync or other preferences require separately named fields, preservation tests and appropriate grants before admission.

D+ acceptance: **10,326 PHP tests / 94,908 assertions**, **837 browser tests**,
both static scopes, documentation/release/client checks and packaging passed in
one complete catalog run. Native full Mail regression passed on v5 (51 tests /
1,409 assertions) and stock (19 executed / 32 expected guard skips / 529
assertions), with affected transaction paths rerun after the marker correction.
The fresh final review found no actionable issues. Credential-change attestation
with an unchanged profile remains the explicit native blocker allowed by D+1;
observed authentication does not resolve it. See [D+ evidence](../specs/2026-11-09-mail-d-plus-evidence.md).
Sending remains disabled and native installation is separate.

### C7 — Acceptance of the no-send module (complete on tested pair)

**Files:** Affected unit/native/browser tests, `MailToolCatalog.php`, guide/presentation/l10n, `docs/en/reference/tools/mail.md`, translation workflow and capability evidence from C0.
**Consumes:** Only actually implemented slices, not every proposed tool in this document.
**Produces:** A reviewable no-send release with precise remaining limitations.

- [x] Run affected feature tests first and existing A/D regression cases. Pin read/write/restore receipt authorization, concurrency, native cache effects, partial failures, ownership and unchanged Files originals.
- [x] Preview then execute the existing disposable native runner: `PATH=/home/dev/.local/share/mise/installs/node/24.22.1/bin:$PATH sh mcp/tests/Integration/ci/run-local.sh --plan 35 mariadb fresh --mail`, then `PATH=/home/dev/.local/share/mise/installs/node/24.22.1/bin:$PATH MCP_IT_PHPUNIT_ARGS='--filter Mail' sh mcp/tests/Integration/ci/run-local.sh 35 mariadb fresh --mail`. Add NC33/34 native cells or label those capability/native-version pairs unverified; static fixtures alone are insufficient.
- [x] Run `devbox run check --cwd IMPLEMENTATION_WORKTREE --json` after focused checks pass. Expected: syntax, both static scopes, PHP/browser/docs tests and package all pass. Append new raw catalog entries intentionally; preserve prior tool/protocol/results contracts except reviewed schema extensions.
- [x] Assert no send/schedule/enqueue tool or grant, no SMTP envelope/DATA, no outbox/MDN dispatch and no release of held reply drafts through new paths. Existing D auth probes remain separately permitted.
- [x] Update user docs with actual tool names, budgets, local-vs-IMAP draft limits, explicit freshness, reversible-trash behavior, unsupported pairs and manual inspection steps. Review the final implementation; publishing/PR/deployment requires its own explicit request.

C7 acceptance results: **49 native v5 tests / 1,302 assertions**, no skips;
stock Mail **17 executed / 32 expected skips**, 422 assertions. Complete catalog:
**10,242 PHP tests / 93,724 assertions**, **837 browser tests**, both static
scopes, documentation and package. NC33/34 and other native/provider pairs remain
unverified. The native v5 source/manifest is unchanged; sending stays disabled.
See the [C7 evidence](../specs/2026-11-09-mail-c7-acceptance-evidence.md) and
[execution plan](2026-11-09-mail-c7-acceptance-plan.md). One fresh final review
found no actionable Critical, Important or Minor finding.

## B transmission remains disabled; optional later parity

B transmission remains **unimplemented and disabled**, even if a future native API makes sending easier. The separately approved PostgreSQL continuation now implements the first read-only outbox inspection slice; it is not SMTP delivery or dispatch. Sending/scheduling/cancellation, dispatch workers and SMTP acceptance/fault testing need a separately approved design and explicit reauthorization. A read-only outbox viewer may be designed independently but must never dispatch or infer successful delivery from missing rows.

Optional later slices, not blockers for the daily no-send workflow: IMAP draft import/search, raw `.eml` export, genuinely bounded larger incoming attachment reads, account/folder resource scopes, bounded item batches, full-body indexed search, snooze/filter/out-of-office management and advanced alias administration. Delegated/global account management, cryptographic key handling and destructive deletion remain separately designed work. No implicit S/MIME/PGP downgrade.

## Plan self-review

A/D completed capabilities map to their real files and 23 executable definitions; speculative A-era DTOs/outbox infrastructure are removed from active tasks. Each missing daily capability has one owner, a concrete boundary, failure-oriented tests and an acceptance command. C0 gates native same-client/cache/lifecycle safety before C1/C3; C2 establishes explicit role destinations; C4 reuses A rather than introducing another writer; D+ cannot use connectivity as proof of credential modification. All five review risks are assigned tests. Sending is excluded from both implementation tasks and the definition of complete. This planning update changes no product code or privileges and authorizes no new native action.
