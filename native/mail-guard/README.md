# Native Mail mutation guard dependency

This is a custom, reviewable source overlay against **Nextcloud Mail 5.12.5**. The deployed **5.12.5.9** artifact remains qualified for NC35.0.2 with PostgreSQL. The immutable **5.12.5.8** artifact retains its qualified NC33.0.10, NC34.0.5 and NC35.0.2 MariaDB pairs. The immutable **5.12.5.11** artifact introduced the following features; current **5.12.5.13** additionally preserves embedded raster signature images and the v12 effective MIME body selection. Its v11 predecessor added bounded content, immutable delivery, protected draft import, explicit indexing, scopes, snooze, structured Sieve and stored signature mode. Native qualification uses NC35.0.2 / PostgreSQL16 / LinuxServer / primary S3; final clean-package installation is recorded separately. It is not represented as deployed. It is not an upstream release or a replacement IMAP implementation. It adds closed flag, single-UID MOVE, folder and exact message-tag operations inside Mail's existing Horde client and a native service that updates the exact cache identity. MCP's normal package does not install or modify Mail.

The original archive must have SHA256 `c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`. The build tool verifies that digest before reading fixed source members. It uses bounded streaming reads and writes only an overlay under this checkout's `mcp/build`; no full archive is expanded into the host temporary filesystem.

From the repository root, with that verified archive available:

```sh
mkdir -p mcp/build/native-mail-guard
php native/mail-guard/build.php /path/to/mail-5.12.5.tar.gz mcp/build/native-mail-guard/current-v13
cmp mcp/build/native-mail-guard/current-v13/NativeGuardManifest.php mcp/lib/Modules/Mail/Integration/NativeGuardManifest.php
tar --sort=name --mtime=@0 --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' -czf mcp/build/native-mail-guard/mail-guard-v13-normalized-overlay.tar.gz -C mcp/build/native-mail-guard/current-v13 mail
```

`current-v13/mail` contains the complete reviewed overlay. The current manifest preserves v10 as `LEGACY_METADATA_DATA` and v9 as `LEGACY_POSTGRESQL_DATA`; all historical v1–v10 manifests retain their original bytes and qualified scopes. Current delivery and content never fall back to older parsers when the v11 boundary fails. The manifest also fingerprints native account/cache entities and mappers, account/ownership services, event classes/registration, the factory and bundled Horde Base/Socket implementation. The generated `NativeGuardManifest.php` must match MCP's committed boundary manifest; source changes require reviewed regeneration and renewed tests. A native version/method name or configuration flag cannot override a mismatch.

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

## v6 native draft identity and protected editor

The native `saveLocalDraft` null-transport path preserves an explicit owned,
same-account alias and a bounded single parent in In-Reply-To and References.
Missing/foreign aliases and unsafe parent values refuse before client creation.
Existing native MIME and SMTP implementation methods remain unchanged.

New qualified MCP writes persist `mail_local_messages.mcp_draft_only=true` and
`failed=true`. Historical rows default to false; only a reviewed ordinary MCP
write adopts them. The native mapper locks the persisted row before updates and
keeps the protection/hold against stale objects. Stored and requested scheduling
or cryptographic state refuse adoption and protected editing. Native controller
and outbox admission enforce the same limits independently of the UI.

Native draft/outbox services, local-message and attachment mappers, and attachment
filesystem operations share a primary-session mutex per owner. Reentrant native
calls reuse that scope. Full hydrated snapshots reject stale converters even
when body/attachment changes share a timestamp. Background conversion refuses
held/protected content. A current explicit native conversion retains its scope
through APPEND and local cleanup, with a physical-session check at command dispatch
and before destructive cleanup. Filesystem phases inherit no SQL transaction;
existing partial-write/repair semantics remain conservative after admission.

Protected associations cannot be reassigned to another message, and deletion by
an obsolete parent refuses. MCP orphan cleanup uses the qualified atomic native
operation; unqualified builds retain orphan bytes/references rather than applying
a check-then-delete sequence. Original Files are never removed by this operation.

An owner-only, bounded Page entry loads an allowlisted local draft into the real
native composer. The derived Home/composer chunks and their runtime names are
content-addressed and fingerprinted. The custom native version also changes the
asset cache version. Draft-only editing disables sending, scheduling and crypto
controls; it does not alter ordinary native Mail composition. Untouched canonical
body/mode retains the original plain/HTML value; actual edits use native serialization.
The upstream release's source maps and the readable transformations/snippets in
this directory provide the inputs needed to reproduce the modified native chunks.

Explicit native local-draft conversion to IMAP remains a native action. MCP never
calls it, releases the hold or starts a sender. No exactly-once APPEND, automatic
retry, full ancestral References chain or global SMTP switch is claimed. Native
conversion uncertainty must be inspected manually. Original Files remain unchanged.

v6 adds a native migration; install/rollback requires a matched code/database
snapshot and separate deployment authorization. Stop old workers/requests before
changing the matched native code: mixed-version workers do not share these new
coordination guarantees. The immutable v5 overlay/manifest
remain accepted for their prior scopes, without v6 draft claims. Runtime qualification
also requires the known native pair and unsharded, unreplicated primary InnoDB schema.
See the [draft lifecycle plan](../../docs/superpowers/plans/2026-11-10-mail-draft-lifecycle-plan.md)
for acceptance progress; MCP never installs this dependency itself.

## v7 native manual credential-write evidence

The native assistant retains all password entry, OAuth consent, encryption and
provider transport. MCP registers an opaque owner/source-operation/connection
handoff and stores only its handle. Opening the native Page entry activates the
context through the core HttpOnly/SameSite cookie. The loaded page also carries
an opaque initial-state marker. Only the three native API producers for account
create/update and OAuth state attach it to their payload; evidence admission
requires an exact match with the cookie. A later tab cannot acquire an old page’s
write or target. Missing/mismatched markers leave ordinary native actions
available without new evidence. No credential prefill or credential field is
added to MCP.

Native account writes advance a hidden authorization revision with credentials
or connection identity, including the display name. Ordinary signatures and
preferences do not advance it. The mapper commits those changes and the counter
together under the owner session, then clears already-persisted dirty fields so
later mailbox cache work cannot resubmit credentials.

Password evidence follows the successful native save. Google/Microsoft manual
callbacks reserve one exact signed nonce state, accept a transient permit only
after a successful new token response, persist through the native mapper and
then record evidence. Their existing error branch returns old tokens unchanged:
neither that branch nor background refresh attests a manual write. A later
authorization/identity revision invalidates older evidence.

Handoffs last at most 24 hours, with 100 unconsumed native contexts per owner.
At most 32 states may be correlated per handoff; each state lasts at most
600 seconds. Further ordinary native OAuth remains independent of evidence.
New proof tables contain only identifiers, intent, timestamps and revisions,
never passwords, provider codes, tokens or secret hashes.

The closed MCP adapter verifies the exact native source, NC35.0.2 and all primary
InnoDB participants. Completion refuses inherited transactions, rechecks current
source/read permissions under the current request-wide policy snapshot and claims the one-shot proof in the same authoritative
SQL transaction as the MCP workflow and source receipt. Physical owner-session
continuity is checked before commit. Status may recover the same pending link;
it never activates a context, exchanges credentials or registers another handoff.

A same-value save may prove a native write. It never proves a different password,
current authentication or delivery. Stock/v6 and legacy workflow rows retain
their prior setup behavior. Immutable v1–v6 manifests remain limited to their
previous scopes. Both native and MCP migrations are required; mixed workers and
code/database rollback require separate operator work. Sending stays disabled.
See the [credential evidence plan](../../docs/superpowers/plans/2026-11-10-mail-native-credential-evidence-plan.md).

## v8 exact runtime qualification

The v8 build preserves all 128 v7 implementation fingerprints; only native
appinfo version/core range changes. MCP keeps 41 tools and centralizes exact
core/build admission across capabilities, draft entry, credential evidence and
alias writes. NC33/34 require build 5.12.5.8; immutable v1–v7 retain only their
previous NC35 scopes. Source/schema/grant/provider gates remain independent.

Both new cores pass direct native candidate guarantees while their MCP pairs
remain closed, followed by complete 57-case enabled Mail acceptance and all
plain/HTML and credential browser workflows without manual context arming.
Stock NC33/34 retains unverified guarded capabilities, including alias/tag writes.
The runner rejects candidate mode on final admitted source and enabled mode on
pre-admission source before creating containers; qualification phases require
the selected checkout, not an external kit. No production environment bypass.

The final normalized overlay uses files644/directories755, uid/gid/time0 and
source manifest 957ba46907945d3cd1cee00955ae0ab9ae9c3dcf0d0fd3b71b6d4298f5e81276.
The immutable review archive is preserved separately from this normalization.
Only fresh pinned primary MariaDB/InnoDB cells are qualified; upgrades, other
patches/providers and operator deployment need separate acceptance. Sending
stays disabled. See the [runtime evidence](../../docs/superpowers/specs/2026-11-10-mail-runtime-qualification-evidence.md).

## v9 primary PostgreSQL boundary

The v9 builder emits Mail5.12.5.9 / `mcp-mail-mutations-v9` for the exact NC35.0.2 pair. It preserves v8 as a separate immutable manifest; v9 does not admit NC33/34. PostgreSQL session advisory leases cover draft owners and mailbox synchronization, with a random session marker, backend PID/start identity and an actual held exclusive-lock check. Recursive acquisition, reconnect, backend termination and stale release cannot transfer ownership. The configured single TCP endpoint must resolve uniquely and match the actual backend address/port; transaction-pooling frontends, replicas and sharding are unqualified. Schema/FK/index checks remain required.

## v10 bounded metadata candidate

The v10 builder emits Mail 5.12.5.10 / `mcp-mail-mutations-v10` for the exact NC35.0.2 runtime pair. Its new `OCA\Mail\Db\BoundedMailMetadata` source performs read-only owned projections for outbox rows and requested cache IDs. Each response is limited to 50 rows; SQL reads at most 257 subject characters before returning at most 256 UTF-8 characters. It does not load entities, message bodies, recipients, attachments, IMAP data or the outbox service, and it does not infer delivery state. The primary topology proof remains the v9 direct PostgreSQL boundary. This candidate still requires its separate runtime and fixture acceptance before deployment.

Legacy cached messages are not stamped with guessed provenance. Existing header-based tag history remains preserved, while exact tag association requires a fresh native snapshot. A reviewed bounded mailbox reset is distinct from ordinary refresh; neither status reads nor deployment source checks rebuild the cache.

Production-profile acceptance also exercises Mail attachments saved to primary S3. Native `touch` creates a one-space marker on S3. The Files writer instead creates explicit empty content under a verified exclusive parent-folder lock, then releases it before the ordinary guarded content write. This path requires a web worker and default native database file locking. APCu, Redis, CLI, unknown backends, disabled locking and excluded paths are unqualified for this creation path. The shared-lock cache in Nextcloud's DB provider is disabled for CLI workers; the web-only restriction is intentional. No original Files object is used as a scratch attachment.

The [PostgreSQL implementation and deployment plan](../../docs/superpowers/plans/2026-11-10-mail-postgresql-deployment-plan.md) records outstanding acceptance and deployment gates. Sending remains disabled.


## Bounded metadata read acceptance

The v10 native helper projects only owned scalar outbox/cache metadata, at most 50
items, with SQL subject bounds and UTF-8-safe truncation. Actual PostgreSQL tests
exercise both registered MCP tools, Alice/Bob isolation, cursor pagination,
outgoing-versus-draft exclusion and uncommitted seed rollback. The exact v9
fallback still passes; the only existing native file changed from v9 is the
installation version in appinfo/info.xml. The helper is added independently.

Final v10 manifest SHA-256:
`b7a921df7c49ab95e5f59b70d49fce7d4482e7b170428fbbf55fe40ea653a859`.
Normalized overlay SHA-256:
`ed05c27b7bc19958a5b02083633252a2bb9c025f9e8e0164895e583d8523b19c`.
See the [B0 evidence](../../docs/superpowers/specs/2026-11-10-mail-bounded-metadata-evidence.md)
for qualification, full regression and limits. Native v9 remains the recorded
production deployment; no transmission/schedule/cancel capability is added.


### Current v11 stored signature mode

`GuardedSignatureMode` adds an owner-serialized local mode transition for an
owned account or its exact alias. Its closed API accepts only native constants
PLAIN=0 and HTML=1, refuses provisioned identities, compares a keyed revision of
the current account/alias metadata, and uses native mapper dirty-field tracking.
It requires the caller's shared journal transaction and original-authorization
callback; the caller also fences after its durable checkpoint and before commit.
Signature bytes, editor mode, credential state and certificate bindings are not
rewritten. No migration or transport is added.

The pinned UI does not consume `signatureMode` to choose Composer rendering: it
uses content detection and `editorMode`. This API persists the native field and
makes no formatting or visible rendering promise. Native source verification and
shipping eligibility remain separate from the operator transmission switch. The
current v11 source-qualified signature boundary is enabled; production sending
remains disabled. Historical v1–v10 manifests remain immutable.

### Current v11 source distribution and SMTP framing

The separate native source companion preserves the exact original upstream runtime,
preferred-source snapshot, reviewed overlay and full assembled runtime inventory.
Reconstruct it from the frozen MCP source bundle and pinned original archive as
documented in `docs/en/development/source-delivery.md`. Deployment must replace
the complete app tree and compare every file, including hashed JavaScript chunks;
selected boundary fingerprints cannot prove a complete installed inventory.

The SMTP writer rejects false, zero and positive short writes against the complete
wire frame length and physically closes the channel before unwinding. It cannot
continue framing or send QUIT after partial DATA. Native dispatch retains
uncertainty, never automatically reconnects or resubmits, and redacts artifact
arguments at public exception boundaries. These rules do not turn SMTP acceptance
into a guarantee of recipient delivery.

### v12 effective MIME body selection

Mail 5.12.5.12 uses the native editor mode when freezing a draft. Plain-text drafts exclude any inactive HTML body, including stale content left by an editor-mode change. HTML drafts retain their reviewed plain fallback; an empty HTML editor cannot release stale plain content. Zero-length alternatives are omitted from MIME, preserving an explicit empty plain body when neither active part has content. The MCP delivery preview describes the same effective format. Existing frozen artifacts and final or uncertain receipts are not rewritten or retried. The v11 manifest remains preserved as `LEGACY_DELIVERY_DATA`; transmitting new mail requires the exact v12 source boundary.

### v13 embedded signature images

Mail 5.12.5.13 retains the 80,000-byte plain/text budget and accepts up to 3 MiB of raw HTML when embedded raster data images account for the excess. At most ten PNG, JPEG, GIF or WebP data images, totalling 2 MiB decoded, become inline MIME parts in multipart/related with matching CID references. It never retrieves remote images. The complete original HTML remains in the native draft and source revision; frozen SMTP/FCC hashes bind the exact converted MIME. Unsupported encodings and excess visible HTML fail before queue admission. The v12 manifest remains immutable as LEGACY_DELIVERY_V12_DATA. No schema or retry rules change; uncertain attempts remain terminal.

MCP previews compact large image payloads into MIME/byte/SHA-256 records and expose body_html_preview separately from actual body_html, together with the SHA-256 and byte size of the full original HTML. They never silently truncate visible text for approval or save this review projection as the draft body. Large HTML depends on the exact v13 boundary; previous receipts retain their stored identity and state.
