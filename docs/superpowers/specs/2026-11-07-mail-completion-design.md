# Mail completion design

Status: A and D implemented; no-send completion plan updated on 2026-11-08. C and D follow-ups are pending. B (sending) is frozen and requires a new explicit request.
Date: 2026-11-07. Updated: 2026-11-08 after implementation review.
Implementation baseline: MCP 2.7.0, A commit `90fe762d` and D commit `f59d5fc1`, in the existing `feat/mail-release-a` worktree.
Native reference: nextcloud/mail v5.12.5, pinned by the disposable integration fixture. The manifest requires PHP >=8.4 / Nextcloud 33–35. Historical static API fixtures do not establish runtime compatibility.

## Delivery record

The original design considered A/B/C and was expanded with D. The user subsequently authorized A and D; the latest instruction requests a plan to complete Mail **with sending disabled**. The active [completion plan](../plans/2026-11-07-mail-completion-plan.md) supersedes the old execution sequence and speculative interfaces, while preserving this design's native-owner/authorization principles.

At `f59d5fc1`, the executable Mail catalog contains 23 tools. A supplies local reply/reply-all composition, owned alias selection, verified Files attachment snapshots, partial repair and durable write status. D supplies preferences/signatures, redacted connections, native credential handoffs, explicit account binding, independent IMAP/SMTP authentication results and guarded account removal. [D's design](2026-11-08-mail-settings-design.md) and [execution plan](../plans/2026-11-08-mail-settings-plan.md) now record that implementation.

Recorded final validation: full check 9,924 PHP tests / 89,235 assertions, 837 browser cases, both static scopes and package; native NC35/MariaDB/Mail5.12.5 10 tests / 319 assertions. This proves A/D at that baseline, not future capabilities or deployment. Native tests use authentication-only SMTP endpoints and verify no envelope/DATA submission.

Remaining limits established from code: reply parent preservation is local (`failed=true` holds native IMAP flush; UI edits can release it); no forward schema or alias inventory tool; no flag/move/trash/folder/tag/refresh tools; credential-only setup completion is refused for an unchanged profile; uncertain/repairing operations have no published reconciliation path. Incoming message reads remain subject to a server-reported 1 MiB preflight, not a hard streaming cap.

## Intent and definition of complete

Complete a daily **no-send** workflow: find/read messages, prepare new/reply/reply-all/forward drafts with approved identities and attachments, organize messages/folders/tags, request bounded freshness, manage owned-account configuration and inspect/reconcile outcomes without blindly repeating effects.

This does not mean parity with every Mail administration, cryptography or destructive feature. Sending, scheduling, enqueue/cancel and dispatch workers are excluded from the definition of complete. Native Mail credential entry remains the user's selected design. The latest request authorizes updating this plan, not implementing the newly proposed capabilities or changing real accounts.

The implementation is established at the native adapter/journal boundaries. The remaining same-client IMAP, cache/event, draft lifecycle and crash-recovery contracts need the explicit gates in C0–C7/D+ of the updated plan. Unsupported capabilities must be reported precisely; no plan checkbox substitutes for native evidence.

## Implementation evidence

- The current executable catalog has 23 tools: the original ten plus three A and ten D tools. `MailServices` still resolves optional native services lazily; a native method existing does not establish a complete MCP capability.
- Baseline MailDraftOperations refused edits with attachments because passing [] to native DraftsService.updateMessage removes them. Release A explicitly preserves and verifies retained native IDs.
- Native LocalMessage has aliasId and inReplyToMessageId. Native MailTransmission.sendMessage emits In-Reply-To and References from the latter; References contains the immediate parent rather than the complete ancestral chain.
- Native DraftsService.sendMessage moves a draft to IMAP; it is NOT SMTP transmission. The inspected saveLocalDraft path does not propagate the reply field into its generated headers. Local-to-IMAP draft lifecycle must be tested before promising that threading survives a later UI send.
- Native AttachmentService can import cloud files and reuse existing local attachment IDs. Some failed attachments return null and are filtered out: MCP must verify the complete expected manifest.
- OutboxService.convertDraft converts the existing native row to outgoing. Chain handles anti-abuse, SMTP, Sent copy, replied flags and cleanup. STATUS_ERROR refuses an indeterminate retry, while CopySentMessageHandler distinguishes SMTP success from Sent-copy failure.
- Native outbox workers can retry due messages. An MCP journal alone cannot guarantee exactly-once SMTP across a process crash.
- Native MailManager supports flags, same-account movement, folders and trash. deleteMessage on an item already in trash expunges it; the proposed daily trash tool must explicitly refuse this path.

Primary sources inspected:
- https://github.com/marcjoancr/mcp-for-nextcloud/tree/53277ea8eb27189c1a345df42ee7720c957aa96e/mcp/lib/Modules/Mail
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/DraftsService.php
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/Attachment/AttachmentService.php
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/MailTransmission.php
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/OutboxService.php
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Send/Chain.php
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Send/CopySentMessageHandler.php
- https://github.com/nextcloud/mail/blob/v5.12.5/lib/Service/MailManager.php

## Approaches and recommendation

1. Extend the existing native adapters, native drafts/attachments/outbox, plus an MCP operation journal. Recommended: one owner of mail data and account credentials, native behavior retained, smallest coherent boundary.
2. Build a separate SMTP sender and queue. Rejected: duplicates credentials, MIME, aliases, Sent handling, retries and native protections.
3. Keep composition unsent and let the person independently use native Mail. This is now the required transmission boundary: complete the no-send workflow without enabling B.

The user authorized the native-adapter approach for Release A. For D the user explicitly chose native Mail credential entry; MCP handles safe settings, scoped handoffs and verifiable status, without a separate credential form.

## Scope and sequence

### Release A: implemented local composition, Files attachments and receipts

The delivered implementation extends `mail_write_draft` rather than introducing a second draft writer:
- reply_to_message_id resolves an owned parent through MailMessageLocator, never an arbitrary header supplied by the model.
- reply_mode is reply or reply_all. Derive Reply-To/From and visible To/Cc; exclude own account/aliases and duplicates. Never infer original Bcc recipients. Show all final recipients in the plan.
- New-message drafts and replies are implemented. Explicit forwarding is still pending in C4: a forward must not fabricate a reply parent or copy Bcc/attachments implicitly.
- Optional owned alias identity, validated server-side.
- Attachment changes are incremental: omission preserves, add_files adds approved Files snapshots, remove_attachment_ids removes only named local draft associations. Removing a draft attachment never deletes its Files original.
- Read drafts with reply metadata, sender identity, attachment manifest, native state and an opaque revision.
- Carry operation_id/request key and result receipts for create/update/delete and mail_save_attachment.
- Add mail_get_operation and mail_list_operations, bounded and owner/connection scoped.
- This release creates no SMTP messages.

Proposal limits: at most 10 attachments and 25 MiB total raw attachment content per draft, further reduced by native/server MIME and upload limits; 50 recipients per address field and 20,000 characters per body remain the current defaults. The 1 MiB incoming-message read ceiling is separate and is not reused as the outbound Files attachment ceiling.

### Release B: frozen historical sending proposal — not active work

The bullets below are retained as historical future requirements, **not steps to execute**. The current user instruction keeps sending disabled. None is part of no-send completion; a new explicit request and native dispatch design are required first.

- Add mail_send_draft with its own denied-by-default send grant.
- Add mail_read_outbox and cancellation only while native state proves the queued item has not entered sending. If native worker locking cannot make cancellation reliable, expose a refusal rather than claiming cancellation.
- Convert a reviewed draft through native outbox services once per authorized operation. Choose one dispatcher, not simultaneous inline send plus cron dispatch.
- A changed draft, recipients, alias, attachment bytes or eligible policy invalidates the send plan.
- Map native progress into queued, sending, smtp_accepted, sent_copy_failed, completed, failed_before_send, uncertain and cancelled.
- SMTP acceptance is not delivery to the recipient. A missing native local row is not proof of sending.
- Do not reset native error states or retry uncertain sends automatically.
- Scheduled sending is enabled only after native worker claim/retry/revocation semantics pass the same gates. Otherwise defer it and report this gap.

### Release C: pending everyday mailbox completion without sending

- Explicit flag setting (seen/unseen, flagged), same-account move, archive, move to trash and restore from trash to an approved destination.
- Every IMAP mutation must bind mailbox identity and UIDVALIDITY to the actual mutation connection/selection; a separate preflight followed by a new native connection is not sufficient. Refuse a capability when the required native/client boundary cannot be proven.
- Mail labels/tags: list and assign/unassign where native IMAP support permits.
- Folder creation/rename and subscription management. Folder deletion, empty-trash and permanent deletion are separate advanced permissions/releases.
- Complete owned sender-alias discovery and forwarding through the existing **unsent** draft pipeline. D signature reads/writes and A new/reply/reply-all drafting are already implemented; do not reimplement them.
- Bounded, explicitly requested refresh and freshness reporting. Existing reads remain side-effect-free with respect to seen state; refresh has a separate permission and resource budget.
- Optional bounded batches return item receipts and stop/reconcile according to operation semantics; no new opaque all-or-nothing promise.

### Release D: implemented owned accounts, signatures and connection settings

The user selected the native Mail assistant for credentials; D is implemented in `f59d5fc1`. [Its design](2026-11-08-mail-settings-design.md) and [implementation record](../plans/2026-11-08-mail-settings-plan.md) describe the delivered boundary. D depends on A and does not enable B. D+ in the active master plan owns remaining evidence/recovery limitations.

- Implemented separate denied-by-default permissions for preference/signature/connection reads and writes, connection probes, account creation, observed-setup completion and account deletion.
- MCP directly patches allowlisted preferences/signatures with full previews, revision proofs and write receipts. Omitted auth/security fields are preserved.
- Account creation and transport/auth changes use native Mail's account form; secrets and OAuth stay outside MCP arguments, logs and URLs.
- Opening the native assistant yields awaiting-user/unverified status. A separately approved binding verifies a specific owned account and fresh non-secret configuration before observed completion; no automatic matching or exactly-once claim for independent native UI submissions.
- Reuse existing operation lookup/listing, add minimal account references, and never make status queries probe or retry. Test IMAP and SMTP separately with no message send.
- Native account removal is gated on measured local impact and current independent permission; local drafts, outgoing items and pending/uncertain account operations block it. Global provisioning, delegated-account administration and server-wide settings stay out of scope.

### Advanced backlog after the daily workflow

Account/folder scope restrictions; draft search/import from IMAP; .eml export and larger selective attachment reads; scheduled-send changes when supported; snooze; server-side filters/out-of-office; delegated accounts; S/MIME/PGP.
Credential capture remains in the native assistant under D; custom credential provisioning, global provisioning and server administration remain outside this plan. No feature may silently downgrade signing/encryption requirements.

## Architecture and ownership

Tool schema/catalog -> ToolRegistry/ToolAvailability/WriteGate -> Mail use-case service -> native Mail gateway.
Mail use cases also consume a Files attachment-source contract and the operation journal.
Transport/authentication stay in the existing MCP/OAuth layer. No controllers are called internally and no new standalone service is required.

Responsibilities:
- Mail/Composition: parent resolution, recipients, identities and immutable draft effect.
- Mail/Drafts: native draft state, revisions and preservation of all existing properties/attachments.
- Mail/Files: Files source authorization, limits and native attachment snapshot receipt.
- Mail/Sending: reserved future boundary only; no current implementation or authorization to add dispatch.
- Mail/Settings, Signatures, Accounts and Connection: owner-scoped configuration, native-assistant handoffs and independently permitted diagnostics, under the D design.
- Mcp/Operations: reusable operation state storage, key binding and concurrency; initially integrated only with Mail.
- Db/Migration: journal tables and constraints; no direct SQL edits to native Mail tables.
- Mail/Integration/MailCapabilities (delivered C0) and MailMutationSession (pending): C0 records per-capability evidence and refuses unsafe writes. The [safe mutation guard proposal](2026-11-08-mail-mutation-guard-design.md) defines closed flags/move actions, a native dispatch/cache dependency and independent C1/C3 gates; no raw-client callback is implemented or proposed for publication. Sending stays disabled.

## Write identity and status contract

Preview returns a server-generated operation_id, expiry, effect digest and plan_state without staging attachments or writing a native draft.
Confirmation repeats that operation_id and effect arguments. Persist one reservation before native effects, unique by actor, connection and operation_id. Bind both the tool and approved effect fingerprint to that identity; reusing it for another tool is a conflict.
Concurrent confirmation/replay with the same effect returns the existing operation; a changed effect under the same identity is a conflict.
The current journal claims the account when arguments include account_id, otherwise a draft/path target. New mailbox/message writes must supply and validate account_id to share D account-removal exclusion. Do not hold a database transaction during IMAP/SMTP I/O.
Use a short database transaction for reservation and for recording native local identity where proven possible. Stage or native I/O failures outside the transaction remain recorded partial/uncertain effects.

Delivered journal states include running, succeeded, partial, failed_before_effect, uncertain, awaiting_user and expired, with protected repair/cleanup transitions. D stores native_ui observation metadata and minimal per-protocol diagnostic results.
Native send progress is a separate field, not collapsed into a misleading succeeded flag.
An expired worker lease permits read-only reconciliation, never automatic replay of an uncertain effect.
If a native effect cannot be conclusively correlated, retain uncertain and require inspection. Do not match only by subject/body/time or treat missing rows as failure.
Retention: compact private terminal metadata after 30 days, retaining minimal receipts and non-replay keys. Uncertain/repairing records remain protected; account deletion does not erase history. Expired identifiers never become fresh writes.
Store minimal identifiers, keyed effect fingerprints, native statuses and redacted errors. Do not duplicate bodies, Bcc, attachment bytes, credentials or raw mail in the operation table.
Status access requires current authentication, connection selection, status permission and authorization for the original action; never expose another user's or connection's receipt.

## Attachment semantics and boundaries

The Files source adapter requires files.read, native READ/download permission, Files scope, exclusion tags, Guests restrictions and a dedicated mail.attach_files permission.
Attachments are byte snapshots in native Mail storage, not new public Files links. Require no files.share grant for this mode.
Preview binds file identity, path, ETag, size, final attachment name and recipients. Recheck at confirmation and verify the complete native stored manifest, including content integrity.
Omitted existing attachments are preserved; [] is never accidentally passed as a replacement set. Only explicit removal changes associations.
All required attachments must be verified before an unsent draft save is reported complete. Partial saves are reported and journaled. No current draft is eligible for MCP submission because sending is disabled.
A future, separately authorized B design must bind approved snapshot bytes and recheck Files origins before enqueue. A later Files policy change cannot recall bytes already legitimately staged in native Mail or control an independent native UI send.
Attachment recovery policy:
- Stage the complete new snapshot set before changing an existing draft, using native local attachment creation and receipts. Pass the verified local IDs to draft save only after every required import succeeds.
- If staging fails, keep the original draft untouched and retain successfully staged artifacts as private operation resources. Do not replay imports under the failed operation.
- If native draft persistence fails after effects, read back once and preserve the observed draft plus every pre-existing attachment. Return its minimal resource reference and exact missing/observed manifest; do not automatically roll back or delete the draft.
- A fresh mail_write_draft preview may name repair_of_operation_id, resolve only its owned artifacts internally, reuse verified snapshots and propose the missing work. Confirmation is a new operation with fresh native/file/policy checks. Reject artifacts with changed bytes, foreign ownership, expired native state or intervening draft changes.
- A partial draft is blocked from MCP submission. This does not prevent its owner from editing/sending it in the native Mail UI, which remains outside MCP enforcement.
- Unassociated staging artifacts may be cleaned after 24 hours only if native ownership/reference checks prove that this operation created them, no draft references them and no unresolved operation needs them. Otherwise retain them for native/manual cleanup. Existing or user-modified draft data is never deleted by this cleanup.
Internal snapshots use Mail storage lifecycle, not MCP backup folders or Files write exemptions.
Reject CR/LF header injection, inaccessible/oversized files and arbitrary model-provided local attachment IDs.

## Evidence gates and compatibility

Before enabling each remaining no-send capability:

1. Verify local reply/alias behavior across native UI editing and IMAP draft synchronization without transmitting a message. Keep A's local-only guarantee and hold if the native lifecycle loses the parent; do not promise it survives a later independent UI send.
2. Prove attachment import/preservation and complete byte manifests on supported NC33/34/35 + Mail pairs, including unavailable and download-disabled Files sources. Preserve current limits until a genuinely bounded incoming reader exists.
3. Verify account-level admission, exact journal/native-resource correlation and competing confirmations. Process death after a possible effect preserves uncertainty and non-replay keys.
4. Prove UIDVALIDITY on the actual read-write selection, native cache/events and trustworthy source/destination UID mapping. Message-ID equality alone and heuristic native mapping are not sufficient.
5. Prove that flags/folders/tags/refresh preserve unrelated state and held reply drafts, respect resource budgets and never enter outbox/SMTP/MDN dispatch. Keep D's independent authentication-only probes and durable sanitized results.
6. Run the pinned isolated native fixtures and preserve a per-capability/version matrix. Passing one native pair or generating static fixtures does not establish universal support. No external delivery, public ports or production credentials.

If a gate fails, leave that capability unsupported and record its native-UI fallback. A required upstream fix is separately proposed. B's original SMTP acceptance, worker ownership, retries, cancellation and crash-after-send evidence is **frozen future work** and must not be exercised or enabled by the no-send completion plan.

## Acceptance of no-send completion

- Preserve the delivered A/D guarantees and limits, including local reply holds, exact Files snapshots, actor/connection/current-source authorization, 15-minute approval and 24-hour native setup observation windows.
- C0 must prove actual selected-client UID generation and native cache/event/lifecycle behavior before publishing C1/C3. Folder/role, identity/forward, label and bounded refresh capabilities have separate acceptance tests and grants.
- D+ distinguishes observed configuration/current authentication from proof of credential modification, and only reconciles exact native identities with evidence. Unprovable outcomes remain uncertain and non-replayable.
- Run affected regressions and pinned native scenarios before the complete catalog check. Record each supported native/version pair and each unsupported blocker honestly.
- Publish no send, enqueue, schedule, cancel or transmission receipt/MDN tool. New paths never dispatch native workers/outbox or clear held reply flags. Native authentication-only probes remain allowed under their own grant.
- No remote permanent deletion, Files source deletion, credential capture, native SQL mutation or unsupported exactly-once claim.
- Advanced parity and B remain separately designed work. Updating this document authorizes no implementation, native mutation, publication or deployment.
