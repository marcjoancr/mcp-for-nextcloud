# Mail runtime qualification across the supported Nextcloud majors

## Intent and accepted boundary

Continue the accepted sequence after native drafts (v6) and native credential
evidence (v7): qualify the same 41 Mail tools on the repository's pinned NC33/34
cells. MCP remains 2.7.0. Native Mail owns credentials, account forms, provider
transport, MIME and cache. Sending, scheduling, MDN and outbox dispatch remain
disabled. No live installation, push, PR, release or worktree cleanup.

Start from local source commit 425d7fb58ef48e77237693559d296e82db79f7ae.
Preserve immutable v1–v7 manifests and the v7 overlay/package digests recorded in
the credential evidence. This work extends proven runtime pairs; it does not add
a tool, permission, transport or new native mutation behavior.

Target exactly NC33.0.10, NC34.0.5 and the existing NC35.0.2, on the matrix's
pinned images and MariaDB10.11.19, primary unsharded/unreplicated InnoDB.
Do not infer support for another patch, Mail build, provider or database.

## Approaches and implementation calls

Changing the v7 artifact in place would invalidate its recorded immutable
acceptance. Merely relaxing version comparisons would expose paths before native
proof. The builder's choice is a separate v8 / Mail5.12.5.8 source artifact using
the same reviewed v7 implementation, with an installable NC33–35 app manifest and
a preserved v7 legacy manifest for its previously qualified NC35 scope.

Centralize runtime matching in a small MailRuntimePairs policy. Initially the
extended pair set is empty. Existing NC35 behavior and legacy native scopes stay
unchanged. Add each exact NC33/34 core only after its native candidate guarantees
pass; then require full enabled-source acceptance for that pair. Failed
acceptance closes the new pair again. This is qualification within the existing
native-assistant architecture, not a replacement implementation.

## Architecture

MailRuntimePairs compares a strictly normalized core version and an exact
native app build. NC35 retains prior admitted builds. Extended pairs require
Mail5.12.5.8; stock or legacy v1–v7 on NC33/34 stays unverified for guarded paths.

MailNativeBoundary continues checking source identity. Add v8 to current
contract scopes and preserve LEGACY_CREDENTIALS_DATA for v7. Credential evidence
accepts current v8 or immutable v7, with the existing schema and runtime gates.
Expose runtimeMatches(coreVersion) on the boundary to share its app version with
MailCapabilities, NativeDraftLinks and NativeSetupEvidence. MailAliasBoundary
uses the same policy before its unchanged two-file/schema/journal checks.

No grant changes. Original/source/read permissions, current request-wide policy
snapshot, owner restrictions, SQL-session continuity, generation/provenance,
budgets, confirmations and uncertain-operation semantics remain required.
Request-wide snapshot limits remain as documented for v7.

The native overlay changes only appinfo version/core range and manifest
identity. Its credential, draft, protocol, listener, mapper, owner-session and
page-bound evidence implementation must remain byte-identical to v7. Compare
all overlapping native files except appinfo/info.xml before qualification.

## Native qualification before enabling a new pair

Extend the existing disposable --mail matrix to the exact pinned NC33/34
MariaDB/fresh cells, retaining NC35/upgrade-local and refusing unsupported cells.
Keep original app pins unless their actual installed manifests reject a target;
inspect pinned implementation before choosing an additional exact release.

Candidate runs retain closed MCP runtime admission for the new core. Execute
the existing direct native protocol/session/cache/draft concurrency tests and
actual credential form/provider/tab browser guarantees. Candidate context arming
is allowed only when the actual NativeSetupEvidence adapter is unavailable for
that runtime; never infer this from the global credential switch alone.
Assert the new MCP runtime gate stays closed in these runs.

Critical candidate coverage: same physical SQL session/owner mutex and killed
session; shared proof/workflow/source rollback; credential/name contention;
flags and moves with generation/UID/COPYUID constraints; exact tag cache;
bounded refresh/cache invalidation; stale draft conversion/attachment ownership;
native cookie and per-page request binding; manual password and Google/Microsoft
success/error/old-token/refresh/state/replay/cross-owner cases, and three late-tab
interleavings across real distinct MCP connection scopes. No-send checks apply.

After each candidate passes, admit only that exact new core/build in the source
policy and run the entire native Mail group plus plain/HTML draft browser and
credential browser workflows without manual context arming. Current enabled
acceptance must use actual HTTP MCP-issued context/link, complete once, recover
status and replay without native credential resubmission.

Stock runs verify reads/settings/legacy composition and unsupported guarded
paths. They must assert unverified capability metadata on NC33/34 rather than
pretending the old NC35 characterization applies. Prior NC35 stock and exact
v6/v7 source/tamper scope compatibility remain covered.

## Validation and delivery

Use TDD for the runtime/matrix policy and all meaningful discovered defects.
Run native cells serially, freezing functional sources during each snapshot/run.
After both extended cores pass enabled acceptance, run the NC35 regression,
stock coverage, immutable compatibility, focused API/architecture checks and the
complete devbox check with Node24 and project TMPDIR. One fresh whole-slice final
review, one consolidated fix pass, documentation and clean local artifacts follow.

Record exact images, native hashes, counts, skipped unsupported cases, proof
limits and any blocked pair. Do not promote a failing or untested pair. If a
pinned upstream app genuinely cannot run on a target, retain that pair closed and
record implementation evidence; do not fake support or force an incompatible app.

## Builder check resolutions

The read-only builder check found no new functional-scope decision for the user.
All v7 source scopes remain admitted on NC35. The builder's concrete choices are:
default guarded builds now produce v8; old v7 is reconstructed only from its
immutable artifact/source revision, never silently rewritten. A pure shared
policy normalizes three/four core segments, rejects leading zeros, suffixes and
malformed input, and pairs new admitted cores only with exact build .8.

The runner requires an explicit candidate/enabled phase for a guarded NC33/34
cell. Candidate asserts all guarded capability metadata is unverified and native
draft/credential adapters and alias writes remain unavailable. The selected
native primitive tests bypass no production policy: they invoke native services
with fixture fences, as existing tests already do. Credential context arming
requires actual adapter unavailability, not a disabled global credential switch.
Enabled runs require runtime admission and never manually arm a native context.

Existing harness pins Deck and other apps per core already; retain those pins.
Actual Forms5.4.1 metadata allows NC33–35, and Mail5.12.5 allows NC32–35.

## Final review source-phase correction

The runner validates its selected checkout policy against the pinned image core
and generated native build before creating a run. Candidate requires a
pre-admission revision where that exact pair is closed; it is deliberately
rejected by final admitted source. Enabled requires admitted source. Qualification
phases reject external candidate kits because checkout policy cannot establish
the policy inside a different package. Ordinary kit behavior is unchanged.
Historical candidate snapshots remain the evidence for closed-pair tests.
