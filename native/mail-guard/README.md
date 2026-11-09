# Native Mail mutation guard dependency

This is a custom, reviewable source overlay for **Nextcloud Mail 5.12.5**. It is not an upstream release or a replacement IMAP implementation. It adds closed flag, single-UID MOVE, folder and exact message-tag operations inside Mail's existing Horde client and a native service that updates the exact cache identity. MCP's normal package does not install or modify Mail.

The original archive must have SHA256 `c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`. The build tool verifies that digest before reading fixed source members. It uses bounded streaming reads and writes only an overlay under this checkout's `mcp/build`; no full archive is expanded into the host temporary filesystem.

From the repository root, with that verified archive available:

```sh
mkdir -p mcp/build/native-mail-guard
php native/mail-guard/build.php /path/to/mail-5.12.5.tar.gz mcp/build/native-mail-guard/overlay
cmp mcp/build/native-mail-guard/overlay/NativeGuardManifest.php mcp/lib/Modules/Mail/Integration/NativeGuardManifest.php
tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' -czf mcp/build/native-mail-guard/mail-guard-v4-overlay.tar.gz -C mcp/build/native-mail-guard/overlay mail
```

`overlay/mail` contains the v4 additions and bounded transformations of the pinned native source. These include exact cached-message tag associations and native import/update/read/search/UI/classification integration. The manifest also fingerprints native account/cache entities and mappers, account/ownership services, event classes/registration, the factory and bundled Horde Base/Socket implementation. The generated `NativeGuardManifest.php` must match MCP's committed boundary manifest; source changes require reviewed regeneration and renewed tests. A native version/method name or configuration flag cannot override a mismatch.

The native `MutationFence` adapter exchanges only approved identifiers and DB stage transitions. It never passes a raw client to MCP. `setFlagGuarded` validates the actual selected generation and connection before each command, permits one UID STORE, does not swallow tagged NO responses, requires explicit permanent support for the selected system flag and verifies the desired state on the same generation. A missing flag or keyword wildcard alone does not prove permanence. Logout or reconnect invalidates the permit. The native cache stage locks immutable mailbox/message rows, revalidates their identity and synchronization tokens, commits the flag, then dispatches one webhook-compatible guarded flag event. The ordinary native cache listener skips the already-applied event. An uncertain listener/crash outcome is retained and never automatically redispatched.

The v2 `move` action uses the same native factory/connection and dispatch guard. It requires MOVE+UIDPLUS, verifies destination selection/generation and sticky UIDs, then arms a read-write source selection. A fresh destination STATUS cannot reselect the source. Its own single UID MOVE pipeline captures one raw COPYUID before Horde can expand UID sets or change caches: exact source UID, destination generation and destination UID only. Tagged completion and a fresh destination generation check are required before recording proof. There is no fallback COPY/delete, force_map, folder creation or Message-ID search. Destination changes after dispatch are uncertain, not atomic precondition guarantees.

Trash pre-delete hooks have a durable one-shot `preconditions_may_start` admission, then targets/roles are revalidated before final selection. The native cache transaction locks both mailbox rows in ID order and the exact source message, rechecks identities/generations/tokens and removes only that source through the native mapper. It commits before one guarded native deletion event; the ordinary cache listener skips the already-applied event. Destination caching stays with native sync and is explicitly reported pending. Cache/event uncertainty never redispatches a hook/event or retries MOVE. `reconcileCache` still refuses because safe event reconciliation is not proven. There is no SMTP, outbox, MDN, schedule or draft synchronization path.

The v3 folder service validates the personal namespace, literal bounded subtree,
known preview generations/subscriptions and actual READONLY selection on the native connection.
It admits fixed CREATE/RENAME/SUBSCRIBE/UNSUBSCRIBE commands and requires complete
readback. RENAME uses native formatting/pipeline directly because the native
rename helper implicitly closes the selected source; CLOSE/expunge is forbidden.
Subscriptions are preserved explicitly without swallowed errors. Account and exact
folder rows are locked for targeted native mapper updates. Whole-account sync and
automatic role guessing are excluded; new folders keep NULL message-sync tokens.
One guarded native folder event follows cache commit. Failures retain uncertainty
and never replay commands, events or synchronization. Role configuration is a
separate local dirty-field account update and does not require v3.

The normalized v3 overlay SHA256 is
`f22bb2c34f2d22afbdb9095fe41950a06e08c44abb076414e410574cebdffd43`;
its 29-path manifest SHA256 is
`e090436dd19702efbd378c635d4bb9208603997a86c267b3a3964257ac6fd86a`.
The immutable original v2 manifest remains accepted for flags and MOVE only;
v1 remains flags-only. C2 remote folders require v3. Never overwrite the old
artifacts when rebuilding a new contract.

The only installation performed by this repository is into its own labelled disposable integration fixture when explicitly running:

```sh
MCP_IT_MAIL_GUARD=1 MCP_IT_PHPUNIT_ARGS='--filter McpMail' composer --working-dir=mcp test:integration -- 35 mariadb fresh --mail
```

The default fixture still installs stock Mail. A shared or production installation requires a separately reviewed deployment; it is not performed by the builder or MCP startup. This custom overlay changes upstream signed application bytes and must be treated as a custom native build. Mail updates invalidate the admitted boundary until requalified. Stock Mail and unknown builds retain existing A/D behavior and refuse C1/C2/C3 remote writes. The immutable original C1 fingerprint remains accepted for flags, never moves. This artifact cannot be activated by a configuration switch or native version alone.

Tests cover Dovecot flags and moves, duplicate Message-ID, no-op/reconnect/generation checks, real HTTP approval/replay/status/cache/permission behavior, native cache generation changes and scripted native-protocol faults. The scripted IMAP endpoint is a test server on loopback, not product transport code. See the [delivery evidence](../../docs/superpowers/specs/2026-11-08-mail-c1-guard-evidence.md) for the historical C1 results, and the [C3 evidence](../../docs/superpowers/specs/2026-11-08-mail-c3-guard-evidence.md) for v2 results and limits.

See the [C2 and continuation evidence](../../docs/superpowers/specs/2026-11-08-mail-c2-continuation-evidence.md) for folder guarantees and subsequent no-send slices.

Existing folder targets and every renamed descendant require a known consistent cached UIDVALIDITY at preview. Newly created folders keep NULL sync tokens and must first be synchronized in native Mail before renaming, subscription changes or child creation. Unknown/conflicting epochs never adopt an execution-time replacement by name.


## v4 exact message tags

C5 adds `cached_message_id` to native message/tag links, a unique exact association
and a cascading foreign key. Existing header-only rows retain NULL exact keys.
No header, name or label matching migrates their identity. Messages carry nullable
generation and non-secret inbound-profile provenance; mailboxes carry a monotonic
tag snapshot revision. Existing legacy/profile-changed cache requires an explicit
native cache rebuild; ordinary flag-only refresh does not re-adopt old headers.
No automatic cache deletion or live migration is performed by MCP.

The actual selected authenticated connection brackets native fetches. A shared
pre-fetch revision follows every batch and classifier write, advancing only after
its own acknowledged transaction commits. Older snapshots cannot overwrite a
concurrent C5 cache commit or adopt a newer writer's revision. Reads/search/global
tag deletion refuse or hide unverified associations. Native UI and queued
classification consume exact cached incarnations; old header-only jobs are ignored
before AI calls. Overlong server keywords are never truncated into another tag.

The closed keyword guard requires permanent keyword support, one exact UID and one
UID STORE, with complete same-selection readback preserving every unrelated flag.
A targeted cache transaction also maintains the native Important projection.
Stock tag writes emit no event; v4 adds none. Journal uncertainty never retries,
redispatches, refreshes or unlocks a retained operation. Sending remains disabled.

Native v4 requires an unsharded, unreplicated transactional primary schema and
working FK cascades. C5 assignment is qualified only for the MySQL/MariaDB provider; other providers refuse before schema or transport work. SQLite is a unit model, not native qualification.
Installation remains a separate reviewed deployment. **Rollback requires a matched
code/database snapshot**: v4 adds native entity columns, so restoring only older
native files is not a safe downgrade. Never overwrite v1/v2/v3 artifacts.

Qualification, exact final digests and remaining limits are recorded in the
[C5 assignment evidence](../../docs/superpowers/specs/2026-11-09-mail-tag-assignment-evidence.md).

The final normalized v4 overlay SHA256 is `056233948790dfa742cc1471e1c2133c5a70551ce8177cb22faadc964bb8ad79`; its 56-path manifest SHA256 is `4385f56e2dcde49e1f3739ad91fc21c84dbf41adea224af9152abeb57d8337a5`. The overlay contains 36 source files. Qualification passed 40 native Mail tests / 1,053 assertions, including actual cached QRESYNC response reuse with an intervening C5 assignment and new-message classification. One version follows the whole combined response; already imported new UIDs do not receive its old FLAGS again.

## v5 bounded explicit refresh

C6 introduces `GuardedMailboxRefresh` and the native read-only `CacheSnapshot`
metadata helper. The provider uses a fresh private Horde client without the native
cache backend, classifier, synchronization-event, draft, outbox or SMTP paths.
It admits only one complete observed mailbox snapshot within fixed message, wire,
literal, byte and time bounds. It requires CONDSTORE and refuses ESEARCH ranges.
The command fence permits read/authentication commands only. No public protocol
options or credentials are introduced.

All native synchronization criteria now share one MySQL session-owned advisory
mutex. A private random session token, physical connection ID and exact DTO owner
fence acquisition, writes and release. Old shutdown callbacks cannot release a new
DTO's lease. Legacy lock timestamps remain display metadata. Connection loss or
process termination invalidates the lease; elapsed time never transfers it. This
custom v5 sync dependency is qualified only for an unsharded, unreplicated
MySQL/MariaDB primary. Unsupported providers refuse; they are not silently given
the old timestamp authority.

Native C5 snapshot versions retain the sync owner through import, flags, vanished
batches and classification. Cache callbacks outside an owned native sync can
update their in-memory UID list but cannot delete persistent rows. This keeps a
cache-enabled native read from applying an obsolete deletion after a refresh.

C6 takes a bounded cache fingerprint before read work, then locks and compares the
owned account, mailbox and exact cached rows before an atomic targeted commit.
Existing valid incarnations preserve their IDs and preview data. A reviewed
`allow_cache_reset` is required for unknown/profile-changed/epoch-changed cache;
replacement allocates fresh IDs and preserves legacy NULL-exact tag history.
Every success rotates an opaque token component so earlier cursors/plans cannot
adopt it. Only observed snapshot metadata is persisted; it never claims a live
remote mailbox. Thread rebuilding remains explicitly pending.

The unchanged shared MCP journal records read-stage refusal before effect, admits
cache dispatch separately and retains commit/receipt uncertainty without replay.
The independent refresh grant starts denied. Older immutable v1–v4 fingerprints
remain scoped to their original features. v5 does not deploy itself; install and
rollback require separately managed matched native code/database snapshots.
Qualification and final artifact digests are recorded in the
[C6 delivery evidence](../../docs/superpowers/specs/2026-11-10-mail-refresh-evidence.md).
