# Safe Mail Mutation Guard — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish a verified native mutation boundary and independently unlock C1 flags and C3 same-account moves without enabling sending.

**Architecture:** Replace the proposed raw-client callback with closed actions. Native Mail owns credentials, protocol dispatch/proof and generation-fenced cache/events; MCP owns permissions, approved effect identity, the existing journal and a bounded signed worker. A native public API/artifact is a prerequisite, not an existing dependency claim.

**Tech Stack:** MCP 2.7.0 + A/D/C0, PHP >=8.4, Nextcloud 33–35, optional Mail >=5.0.0; PHPUnit, existing native Mail/Dovecot fixtures and database abstractions. Only exact proven native pairs admit mutations.

**Spec:** [Safe Mail mutation guard design](../specs/2026-11-08-mail-mutation-guard-design.md).

**Status:** C1 implementation and custom native dependency delivered for validation; see [delivery evidence](../specs/2026-11-08-mail-c1-guard-evidence.md). C3 remains blocked. The proposed native repository route was replaced with a reproducible source overlay in this selected project, installed only in disposable fixtures. Stock Mail5.12.5 remains blocked. The native repository task needs separate authorization/session; do not edit another project from this session. This supersedes the master plan's raw-client `run(..., Closure)` sketch, not A/D APIs.

## Global Constraints

- Sending stays disabled. Add no send/enqueue/schedule/MDN tool, SMTP submission, outbox dispatch, cron activation or held-draft flush. Preserve D authentication-only diagnostics.
- Use native transport, credentials, MIME and cache/event services. No copied authentication parameters, custom transport, native SQL writes, production controller calls or protected reflection.
- One message, explicit account ID, current owned resources and source UIDVALIDITY/UID. Flags: `seen|flagged` plus boolean. Moves: same account, existing destination, MOVE+UIDPLUS, `create=false`, `force_map=false`. No fallback COPY/delete/expunge.
- Reject trashing an item already in Trash. No permanent delete, empty-trash, arbitrary flags, sequences, batches or folder creation in this delivery.
- Reuse one journal/account claim and existing confirmation/non-replay rules. Status never retries, connects, spawns a worker or repairs a cache.
- Initial runner budget: 20 seconds wall clock per action; at most five admissions per owner/account per minute. Tickets/results contain no credentials or message content.
- New permissions/selections are denied by default. Unknown native builds remain unverified. C1 and C3 release independently; no version-only enablement.
- Work in the selected isolated worktree, preserve A/D/C0 and unrelated edits. Local commits after checks; no push, PR, deployment or release without explicit request.

## Review Focus

1. UID generation changes after selection or within implicit reconnect/reselection: no wrong-generation wire/cache effect (Tasks 1, 3, 4).
2. Child acknowledgement/checkpoint lost while parent completes from stale memory: preserve authoritative proof/uncertainty and account claim (Task 2).
3. Native sync/clear-cache or stale unlock races mutation event handling: no deletion/update in a replacement cache generation (Tasks 1, 5).
4. Server lacks MOVE/UIDPLUS or returns incomplete COPYUID, including duplicate Message-ID: refuse before wire when detectable; uncertain after wire without heuristic repair (Tasks 1, 5).
5. Revoked permission/profile change or already-trashed source arrives at execution/replay: deny the changed effect, keep durable authorized history and never expunge (Tasks 3–5).

## File map and contracts

Paths are repository-relative unless explicitly labelled native. New files below are proposals. Follow existing service/test styles and required licensing.

| Responsibility | Files |
| --- | --- |
| Native dependency, separate native session | Proposed `lib/Service/GuardedMessageMutations.php`, `lib/Service/MutationFence.php`; existing `lib/IMAP/HordeImapClient.php`, `IMAPClientFactory.php`, `lib/Listener/MessageCacheUpdaterListener.php`, `lib/Db/MailboxMapper.php` and relevant native synchronizer/cache writers. Native tests assigned in Task 1. |
| Authoritative delegated journal | `mcp/lib/Mcp/Operations/{OperationJournal,OperationStore}.php`, `mcp/lib/Db/OperationsMapper.php`; new `mcp/lib/Mcp/Operations/NativeOperationFence.php`; migration in `mcp/lib/Migration/` using the next repository timestamp if persisted columns/indexes are required. |
| Closed Mail boundary and runner | New `mcp/lib/Modules/Mail/Integration/MailMutationSession.php`, `mcp/lib/Modules/Mail/Mutations/{MutationTicket,NativeMutationWorker,MutationResult}.php`, `mcp/lib/Command/MailMutation.php`; new `mcp/lib/Platform/Process/NativeMutationProcess.php`. Existing `NativeProbeProcess.php` is auth-result-specific and clamps execution to 10 seconds; keep it and the auth probe command unchanged. |
| C1/C3 policy/tools | New `mcp/lib/Modules/Mail/Messages/{MailMessageFlags,MailMessageMoves}.php`, `mcp/lib/Modules/Mail/Tools/{SetMessageFlag,MoveMessage,ArchiveMessage,TrashMessage,RestoreMessage}.php`. |
| Wiring and evidence | Existing `mcp/lib/Modules/Mail/{MailToolCatalog.php,Integration/MailServices.php,Integration/MailCapabilities.php}`, `mcp/lib/AppInfo/Application.php`, `mcp/lib/Access/Grants/GrantPolicy.php`, `mcp/lib/Mcp/Confirmation/WriteGate.php`, `mcp/lib/Mcp/Tools/Discovery/ToolPresentation.php`; existing admin permission/group/selection/inspection/l10n and guide contracts located by the tool's registration tests. |
| Native fixtures | `mcp/tests/Integration/Mail/{MailLifecycleActor,McpMailLifecycleTest}.php`, `mcp/tests/Integration/ci/{mail-cell.sh,mail-pins.json}`, `mcp/tests/fixtures/nextcloud-api/{generate-mail.php,mail-sources.json}`. Add native dependency artifact only with verified provenance; do not invent a version/hash. |

The spec defines all proposed native request/result and `MutationFence` semantics. Native API names can follow upstream naming, with fixture/adapter changes together. Strict schemas reject unknown fields. Remote proof includes action, source mailbox identity/generation/UID, command outcome and desired-state proof, or the complete COPYUID source/destination identity/generation/UID. Cache proof records exact native cache identity, generation and completion state. Public receipts expose only approved identifiers/statuses; private binding data never leaks through status.

## Task 1: Prove and deliver the native boundary (external dependency)

**Ownership:** Native Mail maintainer/task, in a separately authorized native session. MCP work can add characterization/denial tests meanwhile; no mutation capability is published without the artifact.
**Files:** Native files in the map; proposed native tests `tests/Unit/Service/GuardedMessageMutationsTest.php`, `tests/Integration/Service/GuardedMessageMutationsTest.php`, `tests/Integration/Service/GuardedMutationCacheTest.php`. MCP fixture/evidence files above.
**Interfaces:** Produces the proposed `GuardedMessageMutations::setFlag/move/reconcileCache` and `MutationFence::{beforeWire,recordRemote,beforeCache,recordCache}` signatures and strict shapes in the spec. No clients/credentials escape. Flags-only support is a valid first artifact; move remains unsupported until its complete proof exists.

- [ ] Write native failing tests `testGenerationChangeAtDispatchRefusesStore`, `testReconnectCannotReuseArmedMutation`, `testNoRetriesAfterFirstByte`, `testNoResponseIsNotFlagSuccess`, `testCopyUidRetainsDestinationGeneration`, `testMoveNeverFallsBackOrSearchesMessageId`; assert one exact UID command or zero, no wrong-generation target effect, no expunge/SMTP/outbox call.
- [ ] Run those focused native tests through that repository's validated test command and record the intended failure against the pinned stock implementation. Add MCP characterization assertions for the missing public contract while capabilities stay blocked.
- [ ] Implement the native closed service and internal dispatch guard after all implicit selection and before any mutation bytes/literals. Keep native factory authentication/TLS/rate limiting; refuse unsafe debug/TLS settings. Implement same-generation flag readback and command-bound COPYUID result without `force_map` heuristics. Required hooks run before final arming; no arbitrary callback in the final guard/wire window.
- [ ] Add `testCacheGenerationRolloverDoesNotDeleteNewUid`, `testNativeSyncAndClearCacheRace`, `testExpiredLeaseCannotUnlockNewOwner`, `testCrashDoesNotRedispatchEvent`. Assert owner-fenced short native cache transactions, correct required hooks/listeners and no blind event replay. Integrate every relevant native cache writer with the native fence or leave unsupported. No IMAP inside a DB transaction.
- [ ] Run native focused/integration tests and wire traces through the native repository's actual test catalog; record source commit, artifact digest and exact platform/server support in MCP evidence. If any invariant is unavailable, mark that action unsupported and stop its release path. Do not invent a released native version or patch vendor code inside MCP.
- [ ] Commit the independently testable native changes in that session, and MCP evidence/fixture changes in this one only once the artifact is available. Keep C1 and C3 dependency outcomes separate.

## Task 2: Make delegated operation state authoritative

**Files:** Journal/store/mapper/fence in the file map; `mcp/tests/Unit/Mcp/Operations/OperationJournalTest.php`, `mcp/tests/Unit/Db/OperationsMapperTest.php`, new `mcp/tests/Integration/Mail/McpMailMutationJournalTest.php`. Update every `OperationStore` implementation, including test fakes; use a migration only for required durable CAS fields.
**Consumes:** Existing reserve, account claim, confirmation and operation ownership rules.
**Produces:** `OperationJournal::delegateNative(string $uid, array $descriptor): array`; store/mapper `checkpointNative(string $scope, string $id, string $token, string $expectedPhase, array $refs): bool`, `finishNative(string $scope, string $id, string $token, string $expectedPhase, string $state, array $refs, int $time): bool`. `NativeOperationFence` implements the native interface using these CAS operations. Parent adopts stored results rather than calling direct finish on a delegated row.

- [ ] Add failing cases `testChildEvidenceSurvivesParentFinish`, `testWrongRunTokenCannotCheckpoint`, `testTimeoutCannotEraseRemoteProof`, `testDeathAfterWireKeepsAccountClaim`, `testNewOperationCannotBypassUncertainClaim`, `testReplayRechecksOriginalAuthorization`, `testDirectADExecutionUnchanged`. Assert phase/token CAS, evidence preservation and no second effect invocation.
- [ ] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'OperationJournal|OperationsMapper'`; confirm the missing delegation/CAS assertions fail.
- [ ] Implement prepared → wire_may_start → remote_verified → cache_pending → completed and the existing root-state mapping from the spec. Keep token digests private; validate monotonic allowlisted evidence, current account claim and effect binding on transitions. A parent timeout uses CAS/reload and never overwrites a later phase. Add no second journal and no reuse of auth diagnostics or attachment repair semantics.
- [ ] Exercise two real DB actors in `McpMailMutationJournalTest`: stale parent/child completion, lease revocation, late child, lost checkpoint, duplicate admission and authorized status/replay. Status spawns nothing. Verify direct A/D operation tests still pass.
- [ ] Rerun the focused PHP tests; commit `feat: fence delegated Mail operation state` only when green. Capabilities stay blocked.

## Task 3: Closed facade and bounded native writer

**Files:** Session/ticket/worker/result/command/process files in the map; new `mcp/tests/Unit/Modules/Mail/MailMutationSessionTest.php`, `MailMutationWorkerTest.php`, `MailMutationTicketTest.php`, `mcp/tests/Unit/Platform/Process/NativeMutationProcessTest.php`; existing `MailServices.php`, `Application.php`.
**Consumes:** Task 1 native service and Task 2 delegated CAS/fence.
**Produces:** `MailMutationSession::setFlag(string $uid, array $request): array`, `move(string $uid, array $request): array`; `NativeMutationWorker::run(string $uid, array $request): array`. Command `mcp:mail-mutation` consumes only a signed stdin ticket and emits the strict `MutationResult` schema. Public tools cannot obtain a raw native client or invoke the command with arbitrary native options.

- [ ] Add failing tests `testTicketBindsConnectionEffectAndBothGenerations`, `testRevokedGrantAndChangedProfileRefuseWire`, `testWorkerTimeoutKeepsClaim`, `testLateWorkerCannotStartNextStage`, `testMalformedResultDoesNotReleaseClaim`, `testStatusNeverStartsWorker`, `testSecretsNeverEnterTicketOrResult`. Include expiry, nonce reuse, signature/field tampering and protocol/debug/TLS rejection.
- [ ] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailMutation|NativeMutationProcess'`; confirm the missing runner/facade assertions fail.
- [ ] Implement the separate command/runner with the spec's 20-second total deadline, process termination and five-admission owner/account rate limit. Implement `NativeMutationProcess::run(array $command, string $cwd, array $payload, int $budgetMs = 20000): array` separately from the auth process, with bounded stdin/result sizes and deadline-aware pipe handling. Tests must prove bounds across setup, writes, trickling reads, shutdown and child lifetime; the fixed initial maximum is 20,000 ms. On EOF/timeout/DB failure after wire admission preserve uncertainty; never infer no effect from a killed process. Expose only the two closed session methods and strict native adapter. Do not copy passwords/getParam auth data, subclass Horde in MCP or alter D's auth-only command.
- [ ] Verify worker admission, native dispatch and cache stage recheck current fence/grants and exact bindings; test simultaneous revoke and already-transmitted command as uncertain. Test pure no-op desired-state success only with same-generation remote proof and cache consistency.
- [ ] Rerun focused tests plus `--filter 'MailCapabilities|MailConnectionProbe|OperationJournal'`; commit `feat: add closed bounded native Mail mutations` when green. Keep new tools unpublished until their slice passes.

## Task 4: C1 desired-state flags

**Files:** `MailMessageFlags.php`, `SetMessageFlag.php`, shared wiring in the map; new `mcp/tests/Unit/Modules/Mail/MailMessageFlagsTest.php`, `mcp/tests/Integration/Mail/McpMailMessageFlagsTest.php`; permission/selection/write-gate/guide contracts following existing Mail tests.
**Consumes:** `MailMutationSession::setFlag`; Task 2 journal and current write approval; verified flags-native artifact only.
**Produces:** `MailMessageFlags::preview(string $uid, array $args): array`, `set(string $uid, array $args): array`; tool `mail_set_message_flag` with explicit account/message, flag and boolean, existing plan_state/operation_id protocol.

- [ ] Add failing tests for zero-effect preview, stale generation/approval, seen/flagged true and false, already desired, forbidden flags, UID disappearance, server NO, readback generation rollover and cache failure after remote success. Assert no success without proof, no native second-client `MailManager.flagMessage` path, no arbitrary flags and no replayed STORE.
- [ ] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailMessageFlags|MailCapabilities|WriteGateContract|GrantPolicy'`; observe intended failures.
- [ ] Implement explicit desired-state preview/execution through the closed session, add independently denied tool permission/connection selection and required presentation/admin/l10n/guide wiring. Bind the exact source/cache generation and flag/value to the approval. Recheck write permission on execution/replay; expose redacted remote/cache status.
- [ ] Run the native flags cases with real fixtures, generation/reset and lost-ack injection; assert native cache/events and no sending/held-draft flushing. Update `flags`/`mutation_session` evidence only for the exact proven artifact/pair. Leave `move` blocked; keep stock5.12.5 and unknown builds unverified/blocked.
- [ ] Rerun focused tests and affected A/D tests; commit `feat: add generation-guarded Mail flags` when green.

## Task 5: C3 move, archive, recoverable trash and restore

**Files:** `MailMessageMoves.php` and four tools in the map; new `mcp/tests/Unit/Modules/Mail/MailMessageMovesTest.php`, `mcp/tests/Integration/Mail/McpMailMessageMovesTest.php`; shared wiring/evidence and permission contracts.
**Consumes:** `MailMutationSession::move` and the complete Task 1 MOVE proof/cache artifact, independently of C1 success.
**Produces:** `MailMessageMoves::preview(string $uid, string $action, array $args): array`, `move(string $uid, string $action, array $args): array`; four catalog tools `mail_move_message`, `mail_archive_message`, `mail_trash_message`, `mail_restore_message`, each separately granted/selected and using existing confirmation/journal protocol.

- [ ] Add failing tests for missing MOVE/UIDPLUS before wire, malformed/missing/cardinality-mismatched COPYUID after wire, duplicate Message-ID, destination generation mismatch, rename/delete/recreation, cross-account destination, source already Trash, same source/destination, role changed after preview and restore without a proven destination. Assert no guessed UID, no destination auto-create and no generic delete/expunge path.
- [ ] Run `php mcp/vendor/bin/phpunit -c mcp/phpunit.xml --filter 'MailMessageMoves|MailCapabilities|WriteGateContract|GrantPolicy'`; observe intended failures.
- [ ] Implement same-account existing-destination role resolution, including necessary native precondition hooks before final selection and revalidation afterward. Reject the already-Trash path; restore binds an explicit reviewed destination or proven prior-operation provenance. Use complete command-bound proof and generation-fenced native cache/events. No operation timeout, source disappearance, Message-ID match or read-status request can retry a move.
- [ ] Run native complete mapping and failure/race scenarios, including source/destination cache generation rollover, concurrent sync/clear-cache and process death between remote/cache/checkpoint. Assert exactly one MOVE at most, no fallback/expunge/SMTP command, cache_pending/uncertain retention and safe cache-only refusal. Enable `move` only for its proven artifact/pair; otherwise ship C1 alone.
- [ ] Rerun focused tests and affected A/D tests; commit `feat: add guarded recoverable Mail moves` when green.

## Task 6: Integration evidence and release gate

**Files:** Capability matrix/evidence, master plan and guide, native pin/API fixture files; registration/publication/permission/selection contracts discovered in Tasks 4–5.
**Consumes:** Independently passing C1/C3 slices. No requirement to pretend both pass if a native dependency remains missing.
**Produces:** Evidence-backed supported/unsupported matrix and a reviewable local branch, with sending disabled.

- [ ] Run `composer --working-dir=mcp check` (full PHP/browser/static/docs/package checks); all required checks pass. Do not reuse old A/D/C0 test totals as proof of new behavior.
- [ ] Run `composer --working-dir=mcp test:integration -- 35 mariadb fresh --mail` (the current matrix uses positional Nextcloud/database/mode arguments). Exercise the native pinned artifact with the new fault cases and existing A/D lifecycle/probe cases. Other supported platform pairs require their own evidence before activation.
- [ ] Inspect per-run command traces: no SMTP envelope/DATA, outbox dispatch, held-draft flush, fallback COPY/delete/expunge or mutation replay. Redact traces before committing evidence; no credentials or contents in logs.
- [ ] Update capability evidence and the master plan with actual source/artifact hashes, test results and remaining blockers. Keep unknown builds closed and `send=disabled`. Run `composer --working-dir=mcp docs:check`, `composer --working-dir=mcp test:docs` and `git diff --check`; self-review all five Review Focus cases against their tests.
- [ ] Commit verified evidence locally and report C1/C3 outcomes independently. Stop before push/deployment/release. General C7 recovery and remaining C2/C4–C6/D+ stay outside this plan.

## Planning self-review

The design's native dispatch, complete MOVE proof, native cache/lease and no-send invariants map to Task 1 and the per-action native tests. Parent/child authority maps to Task 2. Resource bounds/security/closed contracts map to Task 3. C1 and C3 policy, preview and proof gates map to Tasks 4–5; regression/artifact qualification maps to Task 6. The proposed native dependency remains explicit rather than being treated as a completed API. Execution status: native guarded flags/cache, delegated journal, isolated runner and C1 are implemented; see the delivery record for actual interfaces, test names and evidence. MOVE-specific steps remain open and C3 is blocked. The native source overlay replaces the proposed separate native repository route.

Design builder check: a fresh read-only reviewer reported no important unanswered planning questions. This is an assessment of the proposed contract, not verification of native support or implementation approval.
