# C2 folders and subsequent no-send slices — delivery evidence

## Scope

Baseline: MCP2.7.0 with A/D/C0/C1/C3 at
`859c19cab43df711fd350314e7dc2420dff9099c`.
The catalog now contains 34 Mail tools: four C2 writes, C4 identity inventory,
and C5 read-only tag inventory are new. C4 forwarding and C6 draft account/subject
filters extend existing tools. Independent permissions/connection selections
start denied. Sending remains disabled.

## Native dependency and preserved compatibility

C2 remote actions require the exact `mcp-mail-mutations-v3` source overlay in
`native/mail-guard`, qualified on Nextcloud35.0.2/Mail5.12.5. It is built from the
pinned official archive SHA256
`c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`.
The generated boundary manifest fingerprints new native folder service/client/event
sources, modified client/cache/role listeners and the native account/cache/factory/
Horde dependency paths. The original immutable v1 and v2 manifests remain accepted
only for their original flag/movement scopes. Version strings, method presence or
configuration cannot override a fingerprint mismatch. Stock and unknown builds
refuse C2 remote actions; special-role configuration is local and independent.

The overlay is a custom native build, not an upstream release. MCP packaging and
startup never install it. Only labelled disposable integration fixtures install
it during qualification. No shared instance was modified, and no deployment,
push, PR or publication is part of this delivery. See
[the native build instructions](../../../native/mail-guard/README.md).

## C2 guarantees and decisions

The immutable private request carries the owned account, exact native personal
namespace, source tree, leaf destination, desired subscription, nonsecret profile,
roles, structural inventory and per-folder cache identity/revision. Inventory is
bounded to 1,000 folders; rename accepts at most 100 descendants. Wildcards,
controls, reserved names, shared/unknown ownership and protected special-role
subtrees are refused. Rename stays in the same parent. Missing create parents or
destination collisions are refused. All targets and current original-action
permission/selection are checked at admission, wire and cache boundaries.

C2 reuses the account journal/CAS, signed 20-second child, five admissions per
owner/account/minute and strict remote/cache proof allowlists. The bounded signed
stdin budget is now 64 KiB for folder trees; output remains bounded to 4,096 bytes.
No credential crosses that pipe. Timeouts include input/output waiting.

The native authenticated connection verifies NAMESPACE, literal LIST/LSUB,
STATUS generations and READONLY source selection, then admits closed native
CREATE, RENAME or subscription commands. Rename explicitly preserves approved
subscriptions and checks the complete resulting subtree/epochs plus disappearance
of the old namespace. Native formatting/pipeline handles encoding; no MCP transport
or protected-method reflection is used. Remote state can still race after checks;
post-dispatch contradictions remain uncertain, never retried or rolled back.

**Decision:** native `_renameMailbox` closes a selected mailbox, whose fallback
can use CLOSE. The v3 guard instead emits fixed RENAME through the existing native
formatter/command pipeline, retaining READONLY selection and prohibiting CLOSE/
expunge. Servers rejecting selected rename remain uncertain/inspection-only;
there is no fallback or second command attempt.

**Decision:** whole-account MailboxSync rewrites unrelated metadata and guesses
special roles. The v3 service instead locks the account and affected cache rows,
revalidates identity, updates only affected metadata through native mappers and
commits before one guarded native folder event. The ordinary role-guessing listener
skips that event; other native listeners still receive it. Folder/message IDs,
sync tokens and omitted roles are preserved. New cache rows have NULL sync tokens:
initial zero counters are not a freshness guarantee. Normal native sync remains
the owner of message caching. No whole-account sync, held-draft flush or SMTP path
is invoked. Cache/event failure retains earlier remote proof and uncertainty.

The four role IDs Drafts/Sent/Trash/Archive use the existing native dirty-field
account update and readback. Only owned selectable personal folders are accepted.
Omitted fields, authentication, signatures and security settings are preserved;
role clearing and generic account patching are unavailable.

Status/replay never repeats CREATE/RENAME, subscriptions, events or synchronization.
An interrupted creation is never deduplicated by name. An uncertain account claim
is retained; native/admin inspection is required. No force-unlock/reconciliation,
folder deletion, expunge, cross-account or cross-parent movement is added.

## C4 and safe C5/C6 continuation

Identity inventory exposes only owned primary/alias IDs, names and addresses.
Forwarding creates a new local draft with a complete attributed plain quote or an
explicit final body. Original recipients, Bcc, MIME parts and reply parent are not
inherited. Selected source parts and Files are re-read/hashed into native local
attachment snapshots, verified by readback and handled by the existing journal,
partial-repair and authorized cleanup paths. Source generation/UID and current
read/write permissions are rechecked before local save. Existing 1-MiB incoming
limits, ten attachments/25-MiB combined composition bound and complete-body preview
remain in force. Native Mail's local-vs-IMAP alias/reply limitations remain explicit.

Tag inventory is read-only and user-owned. **C5 creation/assignment remains
blocked:** native associations use header Message-ID rather than the exact cached
message/account/generation; duplicate Message-IDs share tag cache state. Native
creation may return an existing name-derived label without creation correlation.
A separate native cache-key and creation-correlation contract is required.

C6 local draft filters apply owned account and case-insensitive subject matching
before bounded pagination. **Remote refresh remains blocked:** native timestamp
sync locks have unconditional unlocks without a unique owner token. Stale-owner,
work-budget and held-draft guarantees are unproven. Ordinary reads/status never
refresh. D+ credential-change evidence, reconciliation and alias administration
remain separate pending work. This delivery does not claim all C complete.

## Verification record

Focused RED/GREEN: folder policy/roles, strict journal proofs, worker input/deadline,
identity inventory, forward composition, tag inventory and local draft filters.
Native Dovecot and scripted protocol tests cover folder create/rename/subscription,
no-op state, messages/roles preservation, tagged rejection/lost acknowledgement,
pre-existing destination and replay without a second CREATE. Real HTTP tests cover
child creation, parent/descendant rename, subscription and role patch, current
source permission on receipt lookup, foreign account refusal, alias discovery,
explicit MIME+Files snapshots, local forward metadata, replay and draft filters.
Safety captures retain seen/flags, held drafts, outbox/MDN and no SMTP submission.

Final check/matrix results and reproducible artifact hashes are appended after the
final verification; the earlier historical C1/C3 evidence remains unchanged.

## Final review and narrowed unknown-generation behavior

The independent whole-change review found one Important issue: a null cached
generation could adopt a same-name replacement folder at execution. The regression
was observed failing, then corrected in preview tree policy, request/proof schema
and the native client itself. Existing targets and every renamed descendant now
require a known, consistent approved generation. Root creation remains available;
new folders keep NULL sync tokens and require normal native synchronization before
renaming, subscription changes or child creation. Product code never starts that
synchronization implicitly. Qualification fixtures explicitly synchronize their
new empty folders as setup, and HTTP coverage asserts the pre-sync refusal.

No Critical or Minor findings were reported. Live deployment, unsupported native
versions/server variants and the then-pending final verification were declined by
the reviewer; they remain unqualified and no deployment is performed. Root owns the
final matrix/check evidence below. There was no second reviewer/fix-review loop.

## Qualified artifacts and native matrix

The final source overlay was rebuilt independently twice, with byte-identical
normalized tar archives and matching generated boundary manifests:

- v3 overlay SHA256: `f22bb2c34f2d22afbdb9095fe41950a06e08c44abb076414e410574cebdffd43`.
- v3 29-path manifest SHA256: `e090436dd19702efbd378c635d4bb9208603997a86c267b3a3964257ac6fd86a`.
- Original v1 archive retained unchanged: `01e2475f324e67440cc75e59c33e714b14eda22a1e9a612a2b771e180b003f6a`.
- Original v2 archive retained unchanged: `3c42624bf41deafce9429f7e02d2fdb4442e71285264309091e8dac6c41175fc`.

All native runs use labelled disposable NC35.0.2/MariaDB/Mail5.12.5 fixtures,
the pinned Dovecot2.4.5 server and loopback scripted fault servers. No shared
instance or public preview is used.

| Native artifact | Executed / discovered tests | Assertions | Result |
| --- | --- | --- | --- |
| Final reviewed v3 | 26 / 26 | 814 | Passed; includes cache revocation/race/event failures and pre-sync refusal |
| Original v2 | 22 / 25 | 732 | Passed; three C2-only cases skipped |
| Original v1 | 17 / 25 | 432 | Passed; eight unsupported cases skipped |
| Official stock Mail | 14 / 25 | 361 | Passed; eleven unsupported cases skipped |

The legacy/stock runs used the 25-case suite before adding the C2-only cache/event
fault case; unsupported skips are intentional, not capability proof. Flags remain
supported on v1, flags/MOVE on v2, and remote folders only on final v3. Fingerprint
tampering refuses the affected capabilities. Other native version pairs and IMAP
server variants remain unqualified. Sending is disabled in every row.

Focused final verification: folder identity/proof regression five tests / 26
assertions; Mail feature filter 811 tests / 3,241 assertions; affected architecture,
API fallback/schema and mutation-fence contracts 18 tests / 5,426 assertions.
The first full check exposed four contract mismatches; fixing them preserved
literal native setters, the shared-layer dependency boundary, MCP schema validity
and source provenance. The expanded suite also exhausted its 1-GiB budget while PHPUnit formatted a
20-MiB successful equality assertion in the existing Files ceiling test. That
assertion now uses the same strict byte equality with a bounded diagnostic, matching
the neighboring ceiling-write test; no comparison or suite coverage was weakened,
and its two 20-MiB boundary cases run in isolated PHP processes rather than
sharing the accumulated API/catalog matrix footprint. Both cases still execute
the original read/write paths and exact byte checks; the memory limit was not
increased.

## Final project verification

`devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/mail-release-a --json`
completed successfully with exit 0 on the reviewed implementation and final test
resource fix:

- PHP: **10,112 tests / 92,312 assertions**, all passed; fixed 1-GiB limit retained
  (reported suite peak 1,022.52 MiB).
- Browser JavaScript: **837 passed**, across the configured browser matrix.
- Both fixed static-analysis scopes, PHP syntax, documentation/provenance checks,
  client/release tooling tests, asset build and production packaging passed.
- Documentation: 211 Markdown files / 116 package sources validated. Existing
  translation-review/missing notices remain advisory; UI strings for the new
  permissions/titles were added to the maintained language dictionaries.

The build-test messages about an intentionally missing JavaScript module are
negative-fixture output; the enclosing browser cases and complete task passed.
The functional native qualification remains the final 26-test / 814-assertion v3
run above; subsequent changes only isolate existing Files ceiling tests and record
evidence. No native deployment or sending was enabled.
