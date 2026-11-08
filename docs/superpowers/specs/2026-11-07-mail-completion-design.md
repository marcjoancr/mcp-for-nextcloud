# Mail completion design

Status: Staged design; Release A implemented separately, B/C pending, D planning extension requested 2026-11-08. This document does not authorize sending real mail.
Date: 2026-11-07. Updated: 2026-11-08.
Implementation baseline: Nextcloud MCP 2.7.0, commit 53277ea8eb27189c1a345df42ee7720c957aa96e.
Native reference: nextcloud/mail tag v5.12.5, the version pinned by the integration fixture.
The documentation worktree was created from the harness default main (79c5971b); implementation must start from the approved 2.7.0-or-newer revision, not that older documentation branch base.

## Delivery record

This is a proposal produced from implementation reads, with a separate builder review. That review identified partial attachment recovery; the policy below now specifies preservation, fresh repair operations and conservative orphan cleanup.
Release A was subsequently implemented in the separate `mail-release-a` worktree on the 2.7.0 baseline. Its reported checks passed: 9,761 PHP tests, 834 browser cases, 8 native Mail cases/207 assertions, both analysis scopes and packaging. This is not evidence for B/C/D. Its reply guarantee is local only: native `failed=true` holds lossy IMAP synchronization; native UI editing may release it. Forward-specific composition and the full native UI/IMAP/send lifecycle remain pending.

On 2026-11-08 the user expanded the scope to owned accounts, signatures and IMAP/SMTP settings, explicitly selecting the native Mail assistant for credentials. [Release D design](2026-11-08-mail-settings-design.md) and its [implementation plan](../plans/2026-11-08-mail-settings-plan.md) record that decision. This update changes planning documents only.

## Intent and definition of complete

Deliver a reliable daily email workflow: find/read a message, reply or reply-all in its thread, attach Files documents, review the exact draft, send deliberately, and inspect the outcome without blindly repeating writes.
Extend this into everyday mailbox management as separately testable releases. Complete includes the daily workflow and owned-account configuration in Release D, rather than parity with global Mail administration or every cryptography feature.

The original request was planning; the user later authorized Release A implementation. The current request updates the plan for Release D, not its implementation or deployment. Future tool names and limits remain proposals. Architecture is established at component boundaries; native lifecycle and crash behavior need the evidence gates below before sending is enabled.

## Implementation evidence

- The 2.7.0 baseline exposes ten Mail tools; Release A adds attachment management and two status readers for thirteen total; MailServices lazily resolves optional native services.
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
3. Keep draft-only output and let the person send in Mail. Viable fallback if the evidence gates fail; it delivers composition but does not satisfy complete sending from MCP.

The user authorized the native-adapter approach for Release A. For D the user explicitly chose native Mail credential entry; MCP handles safe settings, scoped handoffs and verifiable status, without a separate credential form.

## Scope and sequence

### Release A: reply composition, Files attachments, write receipts

Extend mail_write_draft rather than introduce a second draft writer:
- reply_to_message_id resolves an owned parent through MailMessageLocator, never an arbitrary header supplied by the model.
- reply_mode is reply or reply_all. Derive Reply-To/From and visible To/Cc; exclude own account/aliases and duplicates. Never infer original Bcc recipients. Show all final recipients in the plan.
- Support new-message and forward composition explicitly. A forward is not a reply; preserve source attribution and quote only the approved bounded source content.
- Optional owned alias identity, validated server-side.
- Attachment changes are incremental: omission preserves, add_files adds approved Files snapshots, remove_attachment_ids removes only named local draft associations. Removing a draft attachment never deletes its Files original.
- Read drafts with reply metadata, sender identity, attachment manifest, native state and an opaque revision.
- Carry operation_id/request key and result receipts for create/update/delete and mail_save_attachment.
- Add mail_get_operation and mail_list_operations, bounded and owner/connection scoped.
- This release creates no SMTP messages.

Proposal limits: at most 10 attachments and 25 MiB total raw attachment content per draft, further reduced by native/server MIME and upload limits; 50 recipients per address field and 20,000 characters per body remain the current defaults. The 1 MiB incoming-message read ceiling is separate and is not reused as the outbound Files attachment ceiling.

### Release B: deliberate sending and outbox visibility

- Add mail_send_draft with its own denied-by-default send grant.
- Add mail_read_outbox and cancellation only while native state proves the queued item has not entered sending. If native worker locking cannot make cancellation reliable, expose a refusal rather than claiming cancellation.
- Convert a reviewed draft through native outbox services once per authorized operation. Choose one dispatcher, not simultaneous inline send plus cron dispatch.
- A changed draft, recipients, alias, attachment bytes or eligible policy invalidates the send plan.
- Map native progress into queued, sending, smtp_accepted, sent_copy_failed, completed, failed_before_send, uncertain and cancelled.
- SMTP acceptance is not delivery to the recipient. A missing native local row is not proof of sending.
- Do not reset native error states or retry uncertain sends automatically.
- Scheduled sending is enabled only after native worker claim/retry/revocation semantics pass the same gates. Otherwise defer it and report this gap.

### Release C: everyday mailbox completion

- Explicit flag setting (seen/unseen, flagged), same-account move, archive, move to trash and restore from trash to an approved destination.
- Every IMAP mutation must bind mailbox identity and UIDVALIDITY to the actual mutation connection/selection; a separate preflight followed by a new native connection is not sufficient. Refuse a capability when the required native/client boundary cannot be proven.
- Mail labels/tags: list and assign/unassign where native IMAP support permits.
- Folder creation/rename and subscription management. Folder deletion, empty-trash and permanent deletion are separate advanced permissions/releases.
- List owned sender aliases, preview signatures, new mail, replies, reply-all and forwards using the same draft/send pipeline.
- Bounded, explicitly requested refresh and freshness reporting. Existing reads remain side-effect-free with respect to seen state; refresh has a separate permission and resource budget.
- Optional bounded batches return item receipts and stop/reconcile according to operation semantics; no new opaque all-or-nothing promise.

### Release D: owned accounts, signatures and connection settings

The user selected the native Mail assistant for credentials. [The D design](2026-11-08-mail-settings-design.md) and [D implementation plan](../plans/2026-11-08-mail-settings-plan.md) are the detailed contracts; D depends on A, not on sending or mailbox-management releases.

- Add separate denied-by-default permissions for preference/signature/connection reads and writes, connection probes, account creation, observed-setup completion and account deletion.
- MCP directly patches allowlisted preferences/signatures with full previews, revision proofs and write receipts. Omitted auth/security fields are preserved.
- Account creation and transport/auth changes use native Mail's account form; secrets and OAuth stay outside MCP arguments, logs and URLs.
- Opening the native assistant yields awaiting-user/unverified status. A separately approved binding verifies a specific owned account and fresh non-secret configuration before observed completion; no automatic matching or exactly-once claim for independent native UI submissions.
- Reuse existing operation lookup/listing, add minimal account references, and never make status queries probe or retry. Test IMAP and SMTP separately with no message send.
- Gate native account removal on measured local impact and current independent permission. Global provisioning, delegated-account administration and server-wide settings stay out of scope.

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
- Mail/Sending: outbox admission, native state mapping and read-only reconciliation.
- Mail/Settings, Signatures, Accounts and Connection: owner-scoped configuration, native-assistant handoffs and independently permitted diagnostics, under the D design.
- Mcp/Operations: reusable operation state storage, key binding and concurrency; initially integrated only with Mail.
- Db/Migration: journal tables and constraints; no direct SQL edits to native Mail tables.
- Mail/Integration/MailCapabilities: method/version checks per capability; hide/refuse unsupported writes rather than assuming every Mail >=5 supports them.

## Write identity and status contract

Preview returns a server-generated operation_id, expiry, effect digest and plan_state without staging attachments or writing a native draft.
Confirmation repeats that operation_id and effect arguments. Persist one reservation before native effects, unique by actor, connection and operation_id. Bind both the tool and approved effect fingerprint to that identity; reusing it for another tool is a conflict.
Concurrent confirmation/replay with the same effect returns the existing operation; a changed effect under the same identity is a conflict.
One active mutation per native draft/target is admitted through a persistent claim. Do not hold a database transaction during IMAP/SMTP I/O.
Use a short database transaction for reservation and for recording native local identity where proven possible. Stage or native I/O failures outside the transaction remain recorded partial/uncertain effects.

Proposed journal states: prepared, running, succeeded, partial, failed_before_effect, uncertain.
Native send progress is a separate field, not collapsed into a misleading succeeded flag.
An expired worker lease permits read-only reconciliation, never automatic replay of an uncertain effect.
If a native effect cannot be conclusively correlated, retain uncertain and require inspection. Do not match only by subject/body/time or treat missing rows as failure.
Retention: 30 days for completed receipts; retain uncertain records and non-replay tombstones until explicit resolution or account deletion. Expired receipt identifiers are refused; they never become fresh writes.
Store minimal identifiers, keyed effect fingerprints, native statuses and redacted errors. Do not duplicate bodies, Bcc, attachment bytes, credentials or raw mail in the operation table.
Status access requires current authentication, connection selection, status permission and authorization for the original action; never expose another user's or connection's receipt.

## Attachment semantics and boundaries

The Files source adapter requires files.read, native READ/download permission, Files scope, exclusion tags, Guests restrictions and a dedicated mail.attach_files permission.
Attachments are byte snapshots in native Mail storage, not new public Files links. Require no files.share grant for this mode.
Preview binds file identity, path, ETag, size, final attachment name and recipients. Recheck at confirmation and verify the complete native stored manifest, including content integrity.
Omitted existing attachments are preserved; [] is never accidentally passed as a replacement set. Only explicit removal changes associations.
All required attachments must be verified before the draft can become sendable. Partial saves are reported and journaled; no silent send with missing attachments.
Send uses the approved native snapshot bytes. Recheck current access to retained Files origins before MCP sending. A later Files policy change cannot recall bytes already legitimately staged in the native Mail app or control a native UI send.
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

Before enabling the corresponding capability:
1. Prove native parent/alias preservation through local draft editing, background move to IMAP, UI reopening, outgoing MIME and Sent copy. Missing parent Message-ID refuses a threaded reply; offer an explicitly new message.
2. Prove native attachment import/preservation and completeness for supported NC33/34/35 + Mail pairs, including download-disabled shares and unavailable files.
3. Prove the same-database journal/native-row correlation boundary and competing MCP confirmations.
4. Prove native outbox worker ownership, background retries, process death after SMTP acceptance and crash before journal completion. State exactly which duplicate classes MCP prevents. No exactly-once SMTP claim.
5. Prove safe cancellation and queued-permission-revocation behavior. Recheck before MCP enqueue; after native enqueue ownership of dispatch/revocation must be explicit. Native jobs do not automatically enforce MCP selections.
6. Extend the IMAP-only fixture with a pinned isolated SMTP capture/failure server; no external delivery, public ports or production credentials.

If a gate fails, keep the narrower capability disabled and record the supported fallback. A required upstream fix is a separate proposed change, not silently bypassed by custom SMTP.

## Acceptance

- A repeated or concurrent confirmation produces one correlated native draft/outbox admission.
- Reply/reply-all MIME has the correct parent; visible recipients and Bcc handling match the approved plan.
- Editing a draft with attachments preserves every untargeted attachment and reply/alias/security field.
- File/permission/draft changes require a fresh plan; no partial attachment failure is reported as complete.
- SMTP accepted plus Sent-copy failure is never reported as definitely unsent or automatically resent by MCP.
- Querying status never initiates a send and never confuses queueing, SMTP acceptance and recipient delivery.
- Baseline and Release A tools, read-only behavior and disabled-Mail installations retain their contracts.
- D keeps credential entry native, grants each configuration capability separately and never equates an opened assistant with a completed native write.
