# Mail D+ Completion Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline, TDD and one fresh final reviewer. Keep harness state outside the repository.

**Goal:** Finish the approved D+1/D+2/D+3 scope on MCP2.7.0 while sending stays disabled.
**Architecture:** Extend native adapters and the existing operation journal. Reconciliation changes journal metadata only after exact durable completion proof; native credential entry remains in Mail; alias changes use native entities/mappers on the journal transaction connection.
**Tech Stack:** PHP8.4+, current Nextcloud/Mail adapters, PHPUnit, disposable NC35/MariaDB/Mail cells, Node24.
**Spec:** D+ sections and global constraints in [master completion plan](2026-11-07-mail-completion-plan.md).

## Global constraints and decisions

- Preserve the selected worktree, existing A/D/C0–C7 behavior and exact v5 artifact. No send/schedule/enqueue/MDN/outbox API, credential input or transport.
- Use existing reviewed plan_state/operation_id and independent denied permissions for reconcile_operations, create_identities, edit_identities, delete_identities. Four new tools, 41 total. Status stays read-only.
- No proof from missing rows, names, email, Message-ID, age or human acknowledgment. Unprovable outcomes retain claims and require native/admin inspection.
- Reconciliation admits only a completed native phase, a committed local completion marker with exact resource ID, completed setup binding, or a durably succeeded repair explicitly referencing its source. One separate approved metadata operation, full-receipt CAS, same actor/connection and current original/source permissions. Old non-replay keys survive. Active/nonterminal workers never gain permission to repeat effects.
- D+1 correlates an explicitly reviewed authentication probe with a pending workflow, exact account, original intent and current non-secret profile. Expose authentication_observed and credential_change=unverified, never infer credential change or consent from successful old credentials. Native Mail has no qualified non-secret authorization revision: unchanged-profile completion remains refused.
- D+3 accepts only alias name/email fields. Preserve signatures/modes/certificate binding/account fields using native dirty-field tracking. Refuse provisioned account identities, active/uncertain account operations, and deletion when any local message references the alias. Account admission serializes MCP actions; independent native UI activity can race, as with existing account removal, and requires native review. Do not claim a global native UI lifecycle lock.
- Source/schema-qualified alias mapper on NC35.0.2/Mail5.12.5; require transactional primary storage without sharding/replicas. No native SQL writes. Native installation is separate.

## Review focus

1. Crash after effect/commit but before receipt, competing reconciliation and stale parent: proof and CAS must prevent loss or repeated effects.
2. Partial repair/setup binding without final proof: retain protection; do not reinterpret absence as failure.
3. Credential-only edits, old successful credentials, OAuth/no profile change and abandoned UI: no false completion, no probe on status/replay.
4. Foreign/provisioned alias, omitted signature/certificate fields, referenced drafts/outgoing and unknown keys: reject or preserve exactly.
5. Current source permission/connection revocation across confirm, replay and status; keep remote mutation gates and no-send intact.

### Task 1 — D+2 reconciliation

- [x] RED: SQLite mapper/journal tests for atomic completion marker, proven completion CAS, stale snapshot/racing reconciler refusal, no-proof claims retained and non-replay tombstones.
- [x] Implement internal journal marker/repair link, metadata CAS and MailOperationReconciler with explicit target/evidence IDs, current original/source access and owned account.
- [x] Add mail_reconcile_operation schema/permission/renderer/DI; preserve status purity. GREEN focused operation/reconciliation tests.

### Task 2 — D+1 authentication observation

- [x] RED: pending workflow context accepts unchanged profile for diagnostics only; intent/account/expiry/source revocation refuse; unchanged-profile completion still refuses.
- [x] Extend mail_test_connection optional setup_operation_id + intent as a required pair; bind reviewed context/protocol results/profile revision/time to private receipt and expose minimal authentication observation without secrets. Recheck context around probe; replay/status never probes.
- [x] GREEN setup/probe/receipt tests, including old credentials and absent consent evidence.

### Task 3 — D+3 alias administration

- [x] RED: native alias CRUD contracts, foreign/provisioned membership, unknown keys, retained signature/certificate, referenced message/operation exclusion, rollback/commit-loss/delete replay.
- [x] Add source/storage qualified alias gateway, native mapper/entity construction sharing journal DB, reviewed alias service and three tools; atomic resource checkpoint and completion marker. Existing signature APIs remain separate.
- [x] Add grants, publication/guide/rendering/localization and intentional catalog/schema contract updates. GREEN focused Mail tests.

### Task 4 — Native acceptance, complete check and record

- [x] Native actual HTTP D+ tests: alias CRUD/readback/held draft exclusion and no-send; correlated authentication with no false credential-change completion; native interrupted/reconciled local/setup/completed phases and no-proof refusal/replay.
- [x] Preview and run disposable NC35/MariaDB fresh Mail with exact v5 and stock, serially. NC33/34/other provider pairs remain unverified; no automatic native installation.
- [x] Run full devbox check with Node24 and project-local TMPDIR; update master/public docs/evidence with actual remaining native blockers. One fresh final read-only review and one material fix pass if needed. Local commit and clean-source package; no push/PR/deployment or worktree cleanup.

## Completion record

The supported D+1/D+2/D+3 scope is complete. One full catalog passed with
10,326 PHP tests / 94,908 assertions, 837 browser tests, both static scopes,
documentation/release/client checks and packaging. Native stock/v5 qualification,
the fresh final review and suite-maintenance corrections are recorded in
[D+ evidence](../specs/2026-11-09-mail-d-plus-evidence.md). The local commit is the
source for the final package identity; neither native installation nor publication
is part of this delivery. Unchanged-profile credential-change attestation and
NC33/34/other native pairs remain unverified. Sending stays disabled.
