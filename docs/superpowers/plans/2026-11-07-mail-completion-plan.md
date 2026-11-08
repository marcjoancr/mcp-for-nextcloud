# Mail Reply, Files Attachments and Write Status Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete reliable daily Mail composition, mailbox management and owned-account configuration in stages, starting with threaded reply drafts, Files attachments and observable write outcomes.
**Architecture:** Extend the existing lazy native Mail adapters. Add a small reusable MCP operation journal, a Files attachment-source contract and Mail-specific composition/sending services. Native Mail owns mail data, credentials and transmission.
**Tech Stack:** PHP 8.4+, Nextcloud 33-35, native Nextcloud Mail services, Nextcloud database abstractions, PHPUnit and disposable IMAP/SMTP fixtures.
**Spec:** ../specs/2026-11-07-mail-completion-design.md
**Status:** Release A implemented separately on 2.7.0; B/C pending. On 2026-11-08 the user requested the D planning extension and chose native Mail credential entry. This document update does not implement D.

## Delivery record and added subplan

The A worktree reported a passing complete check (9,761 PHP tests, 834 browser cases) and 8 native Mail tests/207 assertions. Preserve its reviewed changes when continuing; do not reimplement A from this old documentation checkout. The task checkboxes below remain the original acceptance checklist, not a claim that every broader native/send/forward gate has passed. The local reply hold and native UI/IMAP limitation are recorded in the design.

[Release D: accounts, signatures and connection settings](2026-11-08-mail-settings-plan.md) is a separate, implementation-ready subplan linked to its own design. It depends on A's journal/permissions but can precede B/C. It specifies native credential entry, independent grants, exact owned-account result binding and reuse of the existing status tools.

## Global Constraints

- Implementation baseline: Nextcloud MCP 2.7.0, commit 53277ea8eb27189c1a345df42ee7720c957aa96e.
- The documentation branch starts at harness main 79c5971b. Start implementation in a new native worktree at the approved release baseline; do not implement against this older tree.
- Optional native Mail must remain lazily resolved; per-capability compatibility is proven, not inferred from one minimum version.
- No direct SMTP implementation, controller-to-controller calls, native table SQL mutations or real delivery tests.
- Keep actor/connection selection, original action permission, Files scope/tags/Guests/download controls and confirmation checks.
- Proposal defaults: 10 attachments, 25 MiB raw attachment content, further bounded by native MIME/server limits; current body/recipient defaults remain unchanged.
- Preview performs no native write or attachment staging.
- All new privileges start denied. Status reads never dispatch a write. Never automatically replay uncertain writes.
- No external test delivery; preview/published Docker ports remain on 127.0.0.1.
- No blanket exactly-once SMTP guarantee.
- D uses the native Mail assistant for credentials; no secret inputs or automatic retries of native-UI setup. See the D subplan for its separate awaiting-user/completion contract.

## Review Focus

1. Native draft auto-flush can lose reply headers; verify local and IMAP/UI lifecycle in Task 1/4.
2. Native attachment import may silently omit one file; verify a complete manifest in Task 3/4.
3. A process can die after native effects but before the journal result commits; Task 2/6 must preserve uncertainty without replay.
4. Mail's worker can dispatch outside an MCP request or race cancellation; Task 1/6 gates this behavior.
5. Reply-all and alias selection can misaddress mail or expose original Bcc; Task 4 validates exact recipients and preserves security settings.

## Shared proposed interfaces

- OperationJournal.reserve(actorId, connectionId, tool, operationId, effectDigest): OperationClaim.
- OperationJournal.finish(claim, receipt): OperationReceipt.
- OperationJournal.get(actorId, connectionId, operationId): OperationReceipt.
- OperationReceipt fields: operation_id, tool, state, native_state, resource_refs, replayed, safe_to_retry, warnings, updated_at. No body/recipient payload.
- MailFilesSource.preview(actorId, paths): AttachmentManifest; stage(actorId, manifest): StagedAttachments.
- AttachmentManifest items: source_file_id, source_path, etag, name, mime_type, size, integrity_digest.
- MailDraftGateway.readOwned(actorId, draftId): DraftSnapshot; save(actorId, DraftEffect, StagedAttachments, claim): DraftSnapshot.
- DraftSnapshot includes account/alias, parent identity, exact recipients, bodies, existing native attachments, revision and security settings; it stays inside Mail services, not the journal.
- MailSubmissionGateway.enqueueOwnedDraft(actorId, draftId, revision, claim): NativeSubmissionRef.
- MailSubmissionGateway.inspect(actorId, ref): NativeSubmissionState. Inspection never sends.
- Use normal PHP parameter types and value objects when implementing these proposed signatures; keep native optional object types inside Integration.

### Task 1: Establish native contracts and close lifecycle decisions

**Files:** Extend mcp/tests/fixtures/nextcloud-api/generate-mail.php and mail-sources.json; add mcp/tests/Integration/Mail/NativeComposeContractTest.php; extend mcp/tests/Integration/ci/mail-cell.sh, mail-pins.json and mail fixture configuration. Add mcp/lib/Modules/Mail/Integration/MailCapabilities.php after evidence is available.
**Consumes:** Existing native Mail pins and owned disposable integration cell.
**Produces:** A committed capability matrix and a fixed draft/outbox lifecycle mapping for Tasks 3-6.

- [ ] Add failing native tests for parent/alias across local save, auto-flush, UI reopening and captured outgoing MIME; assert parent Message-ID survives or capability is refused.
- [ ] Verify AttachmentService cloud import, retained local attachment IDs and silent omission behavior.
- [ ] Add a pinned SMTP capture/failure fixture and count deliveries in isolation. Exercise disconnect before acceptance, disconnect after acceptance and Sent-copy failure separately.
- [ ] Inspect/verify outbox worker concurrency and queued revocation/cancellation. Decide one dispatcher and document its exact claim boundary. If unsupported, leave enqueue/send/cancel capability disabled and deliver Release A.
- [ ] Extend static fixtures only for the exact native symbols used. Run the focused native/contract tests and record supported combinations; do not bless every version in an admitted range.

### Task 2: Add durable operation receipts and one-time admission

**Files:** Create mcp/lib/Mcp/Operations/{OperationJournal,OperationClaim,OperationReceipt}.php; mcp/lib/Db/OperationsMapper.php; next ordered migration; mcp/tests/Unit/Mcp/Operations/OperationJournalTest.php; mcp/tests/Integration/Mail/MailOperationConcurrencyTest.php.
**Consumes:** Actor/connection identity and Task 1 correlation evidence.
**Produces:** OperationJournal and OperationReceipt from Shared interfaces.

- [ ] Write tests testSameKeySameEffectReturnsReceipt, testChangedEffectConflicts and testConcurrentConfirmationsAdmitOneEffect. Assert one admitted side effect. The unique key is actor/connection/operation_id, with tool and digest bound as immutable fields; a cross-tool reuse also conflicts.
- [ ] Write testCrashAfterNativeEffectDoesNotReplay and testExpiredRunningClaimRequiresReadOnlyReconciliation. Assert uncertain, safe_to_retry=false and no second adapter call.
- [ ] Implement unique actor/connection/tool/operation identity, effect fingerprint, persistent target claims, state transitions and receipt retention/tombstones.
- [ ] Write testForeignOrRevokedOperationIsNotReadable; integrate original-action authorization and minimal receipt output.
- [ ] Run the unit boundary tests and real database concurrency test. Commit this independent infrastructure slice after checks pass.

### Task 3: Add authorized Files attachment snapshots

**Files:** Create mcp/lib/Modules/Files/Contracts/MailAttachmentSource.php; mcp/lib/Modules/Mail/Files/{MailFilesSource,AttachmentManifest,StagedAttachments}.php; extend MailServices.php; add mcp/tests/Unit/Modules/Mail/Files/MailFilesSourceTest.php and native attachment cases.
**Consumes:** FilesAccessGuard, native download policy, Mail attachment API, OperationClaim.
**Produces:** MailFilesSource preview/stage and a complete verified native attachment manifest.

- [ ] Test hidden/out-of-scope/download-disabled sources and changed ETags; assert no attachment staging or sendable draft.
- [ ] Test 10-file/25-MiB limits, UTF-8 names and complete content hashes; enforce lower native/server limits too.
- [ ] Test one silently omitted native import; assert partial receipt and send eligibility=false.
- [ ] Implement bounded snapshot staging through native Mail storage, explicit source receipts and no Files public-link creation. Complete staging precedes existing draft mutation; partial artifacts are private, operation-owned resources.
- [ ] Add testPartialStagingPreservesOriginalDraft and testCleanupNeverDeletesReferencedOrUncertainArtifacts; assert the spec's 24-hour conservative cleanup boundary.
- [ ] Run focused tests plus actual byte-preservation native tests. Commit after checks pass.

### Task 4: Extend draft composition without losing state

**Files:** Modify Mail/Drafts/MailDraftOperations.php, Mail/Tools/{WriteDraft,ReadDrafts,DeleteDraft}.php, Mail/Presentation/MailPlanRenderer.php; create Mail/Composition/{ReplyComposer,DraftEffect,DraftSnapshot}.php and Mail/Integration/MailDraftGateway.php; extend mcp/tests/Unit/Modules/Mail and Integration/Mail/McpMailAttachmentsDraftsTest.php.
**Consumes:** OperationJournal, MailFilesSource and Task 1 lifecycle contracts.
**Produces:** Extended mail_write_draft/read_drafts/delete_draft and operation receipts.

- [ ] Test reply/reply-all against Reply-To, account aliases, self-sent mail, duplicate recipients and absent Message-ID. Assert exact To/Cc and no inherited Bcc.
- [ ] Test forwarding separately from replying; assert source quote is bounded and parent relation is not fabricated.
- [ ] Test edit-body-only preserves attachments, parent, alias and security properties; explicit attachment removal affects only named draft associations.
- [ ] Test a changed draft/recipient/file manifest invalidates confirmation and a repeated create yields the same native draft ID.
- [ ] Implement proposed schema extensions, snapshot revisions, repair_of_operation_id resolution, full confirmation rendering and native read-back; unsupported flush/UI lifecycle is refused rather than advertised as threaded.
- [ ] Add testFreshRepairReusesOwnedSnapshots and testPostPersistenceFailureRetainsObservedDraft; assert a new approved operation repairs only missing work and never replays or deletes the old draft.
- [ ] Run unit tests and native local-to-IMAP MIME/attachment cases. Commit after checks pass.

### Task 5: Expose operation lookup and integrate existing Mail writes

**Files:** Create Mail/Tools/{GetOperation,ListOperations}.php and Mail/Operations/MailOperationReader.php; modify MailToolCatalog.php, MailModule.php, Mail/Files/MailAttachmentSaver.php, AppInfo/Application.php, Access/Grants/{GrantMatrix,GrantPolicy}.php, Mcp/Tools/Discovery/ToolPresentation.php, Mcp/Confirmation/WriteGate.php and per-tool/connection catalogs as needed.
**Consumes:** OperationJournal and existing grant/connection filters.
**Produces:** mail_get_operation, mail_list_operations and receipts for all Release A mutations.

- [ ] Test lookup by operation_id after losing the create response; assert the same draft reference and zero extra writes.
- [ ] Test bounded listing and original-action permission revocation; assert no foreign connection or hidden draft content.
- [ ] Add the status operation to read-only classification; test both new lookup tools never enter WriteGate mutation dispatch.
- [ ] Integrate mail_save_attachment and delete/update draft receipts. Verify retries with different payloads conflict, not overwrite.
- [ ] Test defaults, discovery, guide, personal connection selection and disabled-Mail behavior. Commit after checks pass.

### Task 6: Add sending only after native dispatch gates pass

**Files:** Create Mail/Sending/{MailSubmission,NativeSubmissionRef,NativeSubmissionState,SubmissionReconciler}.php, Mail/Integration/MailSubmissionGateway.php, Mail/Tools/{SendDraft,ReadOutbox}.php; extend catalogs/permissions/presentation and Mail operation tests; add Integration/Mail/McpMailSendTest.php and SMTP-fault scenarios.
**Consumes:** Tasks 1-5, an approved draft revision and proven native dispatcher behavior.
**Produces:** Deliberate send/outbox tools, explicit native progress, no MCP replay on uncertain send.

- [ ] Test enqueue once under concurrent repeated confirms. Assert one native outgoing admission per operation.
- [ ] Test wrong alias, changed body/recipients/attachments and revoked Files origin access; assert no outgoing admission.
- [ ] Capture MIME and assert In-Reply-To/References, approved attachment hashes, envelope recipients and absence of transmitted Bcc header.
- [ ] Inject SMTP acceptance plus Sent-copy failure and crash before receipt persistence. Assert no automatic MCP resend, no false delivery claim and observable uncertain/sent_copy_failed states.
- [ ] Test native background retries and queued revocation under the Task 1 mapping. If unsafe or uncorrelatable, keep affected send capability disabled and publish the precise limitation.
- [ ] Expose cancel/schedule only if atomic native worker exclusion is proven; test that cancellation cannot claim success after sending starts.
- [ ] Run the pinned IMAP+SMTP native cell and supported compatibility matrix. Commit after checks pass.

### Task 7: Complete everyday mailbox operations as a separate release

**Files:** Create Mail/Messages/MailMessageMutations.php, Mail/Mailboxes/MailboxMutations.php and narrowly scoped Tools for flags, moves, archive/trash/restore, labels and folder management; extend MailMessageGateway.php and capabilities.
**Consumes:** Existing message locator/preflight and the journal/status contract.
**Produces:** Daily organization capabilities described in Release C.

- [ ] Add explicit desired-state flag tests: repeating seen=true is harmless; avoid toggle semantics.
- [ ] Verify mutation-time UIDVALIDITY on the actual IMAP selection/connection used for flags and moves; a successful preflight on another connection is not authorization to mutate. Test same-account moves with changed UID/UIDVALIDITY and two messages sharing Message-ID; only verified source/destination correspondence can complete a receipt.
- [ ] Test trash from a normal folder is recoverable and trashing an already-trashed item refuses instead of expunging.
- [ ] Test label capability absence, reserved folder rename and unavailable archive destination; do not guess a system folder.
- [ ] Add bounded freshness/refresh and batch receipts only with their own grants and native budgets.
- [ ] Run targeted native tests, preserve existing read contracts, and commit each independently working capability. Advanced backlog requires its own design.

### Task 8: Validate release boundaries and update user-facing contracts

**Files:** Relevant Mail/Files PHP and native tests, permissions/connection JavaScript tests, mcp/l10n catalogs, docs/en/reference/tools/mail.md and the repository translation workflow.
**Consumes:** The actual delivered capability matrix and receipts.
**Produces:** A reviewable release with exact support/limitations and backward compatibility.

- [ ] Run focused PHP cases before broad checks: composer test -- tests/Unit/Modules/Mail; composer test -- tests/Unit/Mcp/Operations; composer test:api from mcp/.
- [ ] Run native capture scenarios via the existing integration runner with Mail enabled; use its --plan output first and the pinned NC/DB/mode matrix. Never target production.
- [ ] Run devbox run check --cwd IMPLEMENTATION_WORKTREE --json once the focused checks pass.
- [ ] Verify all existing ten tools and old connection choices, absence of credentials/content in journal errors and full package inclusion.
- [ ] Update guide/permissions/localization and explicitly document queued vs SMTP accepted vs delivered, receipt retention and native-worker limits.
- [ ] Review the final implementation before a release. Pushing, PR creation and deployment require their own explicit request.

## Release D extension

Follow [the dedicated D implementation plan](2026-11-08-mail-settings-plan.md): D1 native contracts/redacted reads; D2 preferences/signatures; D3 workflow/status/CAS; D4 native assistant and connection configuration; D5 separate IMAP/SMTP diagnostics; D6 measured account removal, permissions and complete checks. Those tasks extend the current journal instead of introducing a second status mechanism. No D implementation or real account change is authorized by this planning update.

## Plan self-review

Scope, exact component ownership, shared contracts, irreversible-boundary tests and native release gates are recorded.
Release A is independently useful if native sending gates fail. Releases B/C/D must not be described as implemented until their tests pass.
The plan intentionally does not promise complete RFC References ancestry, universal Mail compatibility, exactly-once SMTP, recall after native enqueue or global account provisioning/administration. Owned-account configuration is specified in D with native credential entry.
A has been implemented separately; execution of B/C/D has not started.
