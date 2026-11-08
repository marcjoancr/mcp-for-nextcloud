# Safe Mail Mutation Guard — Proposed Design

Date: 2026-11-08. Status: proposed; documentation only, no runtime capability enabled.
Baseline: MCP 2.7.0 with A `90fe762d`, D `f59d5fc1`, C0 `8c619790`.
Scope: replace the unsafe proposed `MailMutationSession::run(..., Closure)` contract and establish independent admission gates for C1 and C3. Sending remains disabled.

## Intent and acceptance boundary

An approved flag change or same-account move must address the reviewed message generation, use native Mail transport and cache semantics, and leave durable evidence even if the request or worker dies. An uncertain write must never become an automatic second write. A successful guard does not provide exactly-once delivery across IMAP and the application database.

C1 means setting `seen` or `flagged` to an explicit boolean for one message. C3 means one same-account move, archive, recoverable trash or restore. Trash already containing the source is refused. Permanent delete, expunge, empty-trash, arbitrary flags, batches, cross-account moves, folder creation and sending are excluded.

## Implementation evidence

The reviewed native source is the official pinned Mail5.12.5 archive (`mcp/tests/Integration/ci/mail-pins.json`, SHA256 `c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`), including its bundled Horde clients. The [C0 native evidence](2026-11-08-mail-c0-native-evidence.md) remains the executed characterization, not a claim that this proposal passes.

| Actual implementation | Consequence for this proposal |
| --- | --- |
| `lib/Service/MailManager.php`: flags and same-account move create their own clients. | A preflight on another client cannot guard those calls. |
| Bundled `Horde_Imap_Client_Base::store/copy` implicitly open/select a mailbox; `currentMailbox()` exposes selection/mode, not a caller-enforced expected generation. | Passing a previously checked raw client to a callback is unsafe. |
| Bundled socket `_store` can catch server NO responses and return modified IDs. | No exception is insufficient evidence; desired-state readback is required. |
| Native `IMAP/MessageMapper::move` requests `force_map=true`; Base can construct a mapping by Message-ID search. | Duplicate Message-ID or a nullable UID cannot prove the moved identity. |
| Socket `_responseCode` receives destination UIDVALIDITY in `COPYUID`, but public `copy()` returns only the UID map. | C3 needs a public, command-bound result retaining that generation; before/after STATUS cannot replace it. |
| Socket `_copy` can fall back from MOVE to COPY plus delete/expunge and can retry after destination creation. | Initial C3 requires MOVE and UIDPLUS, one UID, `create=false`, `force_map=false`; no fallback. |
| Socket connection writes use the existing stream and throw on failed writes; connection setup has retries, selection and command execution have separate behavior. | Audit the complete pinned dispatch path and fault-test it; object identity alone proves nothing about reconnect/replay. |
| Native flag/deletion events update the cache; deletion addresses mailbox plus UID. Mailbox sync locks have timestamp acquisition but non-owner-fenced unlock. | Preserve events and add generation/owner fencing; existing sync locks are not an assumed complete mutex. |
| MCP `OperationJournal::checkpoint` updates its active parent context; a child has no active context and checkpoint can do nothing. Parent finish uses its in-memory refs. | The authentication-only D worker is not a safe write runner. Delegation needs authoritative fenced database phases. |

Production source anchors are `lib/IMAP/{IMAPClientFactory,HordeImapClient,MessageMapper}.php`, `lib/Listener/MessageCacheUpdaterListener.php`, `lib/Db/MailboxMapper.php`, bundled `vendor/bytestream/horde-imap-client/lib/Horde/Imap/Client/{Base,Socket}.php`, and `vendor/bytestream/horde-socket-client/lib/Horde/Socket/Client.php` in that native archive. They are native paths, not files supplied by this MCP repository.

## Approaches and recommendation

1. Recheck then call current `MailManager`: inexpensive but cannot bind the actual mutation client or recover complete COPYUID proof. Rejected.
2. Closed MCP facade using only current public native methods: a possible flags-only experiment, but not a proven boundary; it cannot recover command-bound destination UIDVALIDITY or assume cache fencing. It may enable C1 only after the same complete proof gates below, never C3 by implication.
3. **Recommended:** a closed MCP facade plus a minimal public native guarded-mutation API. Native Mail owns client setup, dispatch, protocol proof and cache/events. MCP owns permissions, preview, operation identity and bounded execution. This recommendation is the plan author's proposal, not a previously approved native change.

The third route is the complete route described here. Work in the native Mail repository and an installable native artifact require a separately authorized native task/session. No released native API, version or upstream acceptance is assumed. Stock Mail5.12.5 remains blocked. If the required native boundary cannot be delivered, keep that action unsupported and offer native Mail; do not replace it with reflection, copied transport or guessed identity.

## Closed boundaries

All names in this section are **proposed**, not existing native APIs. Final native naming may follow its maintainers' conventions, with the contract and MCP adapter changed together.

MCP `MailMutationSession` exposes only:

- `setFlag(string $uid, array $request): array`
- `move(string $uid, array $request): array`

It returns sanitized receipts, never clients, factories, native account objects, arbitrary closures or credentials. Requests are internal strict shapes, created from the approved plan, not free model-supplied native options:

- Shared: account ID, source cached message/mailbox IDs, source native mailbox identity, source UID and UIDVALIDITY as canonical strings, connection/effect binding, operation ID and delegated run fence.
- Flag: `flag` in `seen|flagged`, `value` boolean.
- Move: destination owned mailbox ID/native identity and expected UIDVALIDITY, action in `move|archive|trash|restore`; restore names a reviewed destination or uses proven previous-operation provenance, never guesses an original folder.
- Bind connection-profile revision, account owner, current permissions/selection and native mailbox role resolution. Re-resolve on execution; fresh preview is required if the effect changes. Do not store secrets or raw message content in plans or receipts.

Proposed native `OCA\Mail\Service\GuardedMessageMutations` methods are `setFlag(string $ownerUid, array $request, MutationFence $fence): array`, `move(string $ownerUid, array $request, MutationFence $fence): array`, and `reconcileCache(string $ownerUid, array $receipt, MutationFence $fence): array`. Only the internal runner calls them. Native Mail resolves credentials through its own factory; credentials never enter MCP requests, tickets or subprocess arguments.

Proposed native `MutationFence` is a narrow interface implemented by MCP's journal adapter: `beforeWire(array $binding): bool`, `recordRemote(array $proof): bool`, `beforeCache(array $binding): bool`, `recordCache(array $result): bool`. It accepts immutable allowlisted identifiers/proof, never a raw client. Calls perform only bounded DB transitions; failure aborts the next stage. They cannot log in, reselect, invoke user callbacks or dispatch workers. `beforeWire` runs before the final dispatch guard; there is no arbitrary callback between that guard and the first mutation byte. Protocol and cache proofs are stored durably before the next stage starts.

### Native execution invariant

1. Resolve current ownership, connection profile, source cached identity and generation, destination and roles; run required native precondition hooks before arming the final selection. Re-resolve after hooks. Reject unsafe debug configuration and disabled TLS verification; preserve native auth/rate limiting.
2. Use one private native client created through `IMAPClientFactory`, without cloning auth params or exposing the client. Select source read-write, prove actual UIDVALIDITY and exact UID, and verify operation capability. Recheck destination generation before MOVE on the same authenticated session without leaving a stale source selection armed.
3. At native dispatch, after every implicit login/reselection and before the first STORE/MOVE byte or literal, verify the same connection/selection epoch, source identity, read-write mode, expected generation, non-revoked fence and action allowlist. A logout, reconnect, unsolicited invalidation, reselection or unsupported server response invalidates admission. No automatic replay after command bytes may have begun. Audit native internal retries rather than assuming a high-level return proves this invariant.
4. Flags use one UID STORE add/remove, never replace or sequence-number operations. Same-generation readback must establish the requested state; distinguish already desired from an applied change. A NO response, vanished UID or wrong-generation readback is not success.
5. Moves require negotiated MOVE and UIDPLUS. Capture the single command's tagged response and complete COPYUID source UID, destination UID and destination UIDVALIDITY. Validate source and destination generations, cardinality and positive UIDs; no Message-ID search, cache metadata substitution or COPY/delete/expunge fallback. Missing or contradictory proof after wire admission is uncertain. A later destination read may corroborate, never manufacture missing command-bound proof.
6. Record remote proof before a native cache stage. Close the native client on all exits. Bound the complete process; per-read timeouts alone are insufficient.

The native implementation must prove how the server's selected-mailbox semantics prevent generation replacement from redirecting an in-flight UID command, including reset/close notifications. If this cannot be established for an admitted protocol/server path, it remains unsupported; a check followed by an unguarded command does not close the gap.

### Native cache and events

The native API must update the exact source cache identity/generation and destination evidence through supported native services, preserving required flag/deletion hooks and event semantics. It must prevent a stale worker or sync/clear-cache race from modifying a new generation. Use a native-owned short transaction/fence for cache admission, with proven participation by relevant native cache writers; never hold a DB transaction over IMAP I/O. Existing timestamp locks alone are insufficient. An owner-fenced lock must reject stale unlock after expiry.

This is an explicit native dependency, not permission for MCP to write Mail tables. Native hooks must not trigger SMTP/outbox, flush held drafts or mutate the target after the final guard. Delivering an event again can repeat webhook/listener side effects: do not blindly redispatch after a crash. If native code cannot atomically record/cache the effect or provide a safe cache-only recovery outcome, preserve `cache_pending`/uncertainty and require native review. No automatic remote retry or claim release follows.

## Delegated journal and bounded worker

Reuse the A/D operation table, account claim and non-replay keys. Extend its existing interfaces instead of creating a second journal. Keep A/D direct execution unchanged.

Proposed `OperationJournal::delegateNative(string $uid, array $descriptor): array` transfers the active operation to a single immutable run token, bound to tool, scope, connection, account and effect digest. Extend `OperationStore` and `OperationsMapper` with `checkpointNative(string $scope, string $id, string $token, string $expectedPhase, array $refs): bool` and `finishNative(string $scope, string $id, string $token, string $expectedPhase, string $state, array $refs, int $time): bool`. Transitions compare-and-swap token and phase and merge allowlisted evidence without erasing prior proof. Store only a token digest. Parent adoption reloads authoritative stored data; it must never finish a delegated row with stale in-memory refs.

The separate mutation command/runner uses signed stdin tickets, bound to owner, connection, operation, action digest, account and both generations, nonce, expiry and run token. No credentials in tickets, CLI arguments, logs or results. Recheck live grants and resource selection in the worker and before admitting wire/cache. Use an initial proposed 20-second wall-clock budget for one action and at most five admissions per owner/account per minute; these are new design limits, not existing behavior. Do not widen D's auth-only command.

Durable nested mutation phases are `prepared`, `wire_may_start`, `remote_verified`, `cache_pending`, `completed`. Keep the journal's existing root states. `beforeWire` moves prepared to wire_may_start; once there, loss of acknowledgement is uncertain even if the server later completes. `recordRemote` preserves proof; `beforeCache` fences cache_pending; `recordCache` establishes completed. A certified refusal before wire can finish failed_before_effect. Completed remote/cache evidence can finish succeeded. All other post-wire outcomes retain uncertainty and the account claim. Parent timeout/EOF handling uses CAS against the current child phase; a stale parent cannot overwrite newer remote/completed evidence.

Timeout terminates the child and invalidates further stage admission. It cannot recall an already transmitted command. Process death before durable remote proof, malformed result, database outage, delayed acknowledgement or stale worker all retain the claim. A new operation ID must not bypass it. Replays recheck current authorization, return the same durable outcome and never spawn another writer. Status remains read-only: no worker, IMAP connection, cache repair or silent retry.

C7's general reconciliation UI/tool remains separate. A restricted internal cache-only reconciliation method may consume durable complete remote proof, never resend STORE/MOVE and never infer success from source disappearance. It must fail closed when cache/event completion cannot be proven safe. Unresolved remote outcomes remain blocked rather than being automatically released.

## Capability, permissions and delivery gates

- `mutation_session` becomes verified only for an exact tested native boundary/pair; `flags` and `move` have independent evidence. Method/version detection alone is insufficient. An unknown native build remains unverified.
- C1 publishes only explicit desired-state flags after guard, journal, cache and native fault tests pass. C3 remains blocked until complete MOVE/COPYUID/cache tests pass independently.
- New tools are denied by default, independently selectable under existing account/resource policies and existing write confirmations. Old grants/selections acquire none. Status requires current read authorization; execution/replay requires its original write authorization and ownership.
- `send` remains disabled. No SMTP submission, send/enqueue/schedule/MDN tool, worker or cron activation. D authentication diagnostics and all existing A/D behavior stay intact.

## Delivery order and acceptance evidence

1. Native proof/API artifact: guarded flags and native cache fencing first; complete MOVE result separately. Record source commit/artifact digest, platform/protocol support and native test evidence. If only flags is ready, accept only C1's dependency.
2. MCP delegated journal, strict bounded mutation runner and private closed facade, with denial tests while capabilities remain blocked.
3. C1 tools, permissions and native desired-state/cache tests; publish only verified capability pairs.
4. C3 tools, role handling and full command-bound mapping/cache tests; publish separately.
5. Full A/D regression, static checks, package, no-send command traces and documentation. No deployment/push/release is authorized by this proposal.

Required fault cases include generation rollover at every selection/dispatch/readback/cache boundary; connection loss before/after first byte; duplicate Message-ID; dropped or malformed COPYUID; partial/missing mapping; destination rename/delete/recreation; source already Trash; native sync and cache clear races; stale lease release; revoked permission/profile changes; worker timeout, late writes, lost DB checkpoint and parent/child finish races. Tests must assert the wrong-generation message/cache is untouched, commands are never replayed, and uncertain claims persist.

The [implementation plan](../plans/2026-11-08-mail-mutation-guard-plan.md) assigns these gates to concrete tasks. No implementation or unblock is claimed by this document.
