# C3 guarded Mail moves — delivery evidence

## Scope and dependency

C3 extends MCP2.7.0 plus A/D/C0/C1 at baseline `3c785d14cecdbcdad6337a13d7576edc23133c46`.
It adds `mail_move_message`, `mail_archive_message`, `mail_trash_message` and
`mail_restore_message`, each with its own denied-by-default permission and
connection selection. The catalog now has 28 tools. Sending remains disabled.

The dependency is reviewable source in `native/mail-guard`, built from the unchanged
Mail5.12.5 archive SHA256
`c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`.
Its contract is `mcp-mail-mutations-v2`; the reviewed native file hashes are in
`mcp/lib/Modules/Mail/Integration/NativeGuardManifest.php`. Only the exact qualified
Nextcloud35.0.2/Mail5.12.5 pair and file fingerprint admit C3. Stock Mail, altered
or unknown builds, and other native pairs refuse. The immutable original C1
fingerprint remains supported for flags only.

This is a custom native source overlay, not an upstream Mail release. It modifies
the existing client and cache listener and supplies native service/guard classes;
no vendor transport, credential copy, raw-client callback or reflection is added.
It is installed only in labelled disposable qualification fixtures by the test
runner. Neither MCP startup nor its package installs it into a shared instance.
Deployment, publishing and upstream contribution are separate work.

## Behavior and architectural decisions

The preview uses owned cache metadata only. Its private immutable request binds
source/destination IDs, names, UID generations and cache tokens, connection profile,
Archive/Trash roles and action. Confirmation compares that request again before
signed worker admission and at each wire/cache stage. Role or identity changes
require another preview. Restore requires an explicit existing destination and a
source in configured Trash. General move is the broader permission; the three
specialized actions provide narrower alternatives. No missing destination is
created and no already-trashed message is trashed again.

Native Mail uses its existing factory, authenticated client, formatter and command
pipeline. MOVE+UIDPLUS and sticky UIDs are required. After verifying destination
generation, source read-write selection is armed to the actual connection,
mailbox/generation/mode/UID. Fresh destination STATUS cannot reselect the source.
Only one UID MOVE is permitted. Its exact active pipeline captures one raw COPYUID
before Horde expands UID sets or updates its caches. Only the expected single
source UID, expected destination generation and one canonical destination UID are
accepted. Successful tagged completion and a fresh destination epoch check precede
durable remote proof. There is no COPY/delete/expunge fallback, force_map,
Message-ID search, implicit reconnect or automatic repair.

Standard IMAP cannot atomically condition MOVE on a destination epoch. A destination
race or missing/contradictory acknowledgement after wire admission therefore remains
uncertain, keeps the account claim and never retries. This is an explicit limit,
not a claim that a post-wire check prevents every external destination race.

Trash pre-delete hooks are fenced before invocation by the new one-shot
`preconditions_may_start` phase, then native targets/roles are checked again before
final client selection. Trusted native listeners remain native extensions; no new
first-party send/outbox listener is called. Hook interruption is uncertain even
if MOVE did not start. Ordinary move has no pre-delete hook unless its actual
destination is configured Trash.

After durable remote proof and current cache admission, native Mail locks both
mailbox rows in ID order and the exact source message in a short DB transaction.
It rechecks ownership, names, IDs, generations and synchronization token revisions,
then removes the source through native `MessageMapper::deleteByUid`, including
recipient cleanup. No network I/O occurs under row locks. The cache commit precedes
one native webhook-compatible guarded deletion event; the ordinary cache listener
skips its already-applied source removal. Other native listeners still receive it.
There is no retry or event redispatch after failure.

Native ordinary move removes source cache only. Destination incorporation stays
with normal native Mail sync and the receipt reports
`destination_state: native_sync_pending`. No draft flush or explicit synchronization
is added. A succeeded receipt proves the MOVE and source cache/event completion,
not destination cache freshness. Cache/permission/event failures preserve earlier
proof in `remote_verified` or `cache_pending` and leave the root uncertain.

The existing journal, account claim, CAS fencing, signed stdin worker and bounded
process are reused. Worker budget stays 20 seconds and rate stays five admissions
per owner/account/minute. Public status exposes only allowlisted phases/identities;
current original-action permission is required. Status and repeated confirmation
spawn no worker and repeat no native effect. Uncertain operations have no automated
unlock or reconciliation path.

## Verification record

Tests are based on the pinned implementation, not native documentation.

- Raw COPYUID parser: intended missing-boundary RED, then GREEN, 1 test/22 assertions.
  Rejects ranges, multiple UIDs, missing fields, wrong epochs/UIDs and noncanonical
  or out-of-range numbers without expanding untrusted sets.
- Durable C3 fence: intended schema/admission RED, then GREEN with C1 journal/fence
  regressions, 8 tests/58 assertions. Hook and wire admissions are one-shot, proof
  and cache receipts bind exact destinations, revocation preserves remote evidence.
- Immutable confirmation: intended missing-handler RED, then GREEN. Destination
  generation changes invalidate confirmation; approved requests are not retargeted
  through another snapshot at dispatch.
- Native Dovecot/protocol qualification: 3 tests/192 assertions. Includes duplicate
  Message-ID in both folders, one exact move, source-only cache removal, 21 protocol
  success/fault modes, missing MOVE/UIDPLUS, unstable/read-only source, disappearance,
  wrong/missing/malformed/duplicate COPYUID, unsolicited COPYUID, destination epoch
  changes before/after wire and lost acknowledgement. Forbidden COPY/STORE/EXPUNGE/
  SEARCH/create counts remain zero. Durable replay never repeats MOVE.
- Native cache/hook/event faults preserve the appropriate phase and claim. Destination
  cache generation reset refuses source deletion; cache revocation retains proof;
  event failure after source cache commit never redispatches; hook failure occurs
  before final selection and never repeats its hook. Outgoing and SMTP captures
  remain unchanged.
- HTTP normal qualification: 1 test/81 assertions before the additional changed-role
  scenario. Four actions plus generic move-to-Trash, explicit restore destination,
  ownership/same-folder refusal, exact receipts, replay/status, original-permission
  revocation and no-send capture.
- Catalog/translation/confirmation inventory: 20 tests/3,713 assertions GREEN.
  Both fixed production static-analysis scopes GREEN.

Final native matrix (all `--filter McpMail`, NC35/MariaDB/fresh):

| Native build | Executed tests | Assertions | Expected skips |
| --- | --- | --- | --- |
| Qualified v2 | 21 | 704 | 0 |
| Original immutable C1 flags-only | 16 | 404 | 5 v2-only |
| Stock Mail5.12.5 | 13 | 333 | 8 custom-guard-only |

The final v2 run includes source/destination cache epoch changes, changed-role
confirmation with an unchanged source, cross-account destination refusal,
already-Trash refusal, exact-source restore and fingerprint tamper/recovery.
Legacy flags continue to work and legacy/stock moves remain blocked. D's existing
authentication-only diagnostics also pass; no envelope or message is submitted.

The normalized v2 overlay is `mcp/build/native-mail-guard/mail-guard-v2-overlay.tar.gz`,
SHA256 `3c42624bf41deafce9429f7e02d2fdb4442e71285264309091e8dac6c41175fc`.
Its 24-file manifest has SHA256
`f787241ffc993375a0abe96f89ee062913dd2d011a5833f7b35b6520f1b7e5b9`.
An independent rebuild produced identical manifest/PHP bytes and normalized tar
SHA256. Native source and builder syntax checks pass.

One fresh-context read-only final review found zero Critical, Important or Minor
issues across all five plan review-focus areas. A preliminary literal-continuation
candidate was withdrawn after verifying that the pinned native mailbox formatter
forces quoted output; no speculative runtime change was made. Its only deferred
judgment was full-check success while that check was still running. The actual
full-check result is recorded below; none of these checks authorize deployment.

## Complete catalog check

`devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/mail-release-a --json`
completed with exit0: **10,012 PHP tests / 90,859 assertions**, **837 browser tests**
(no skipped scenarios), PHP syntax, both fixed static-analysis scopes, documentation
validation and production packaging. The final added move-ticket signing test also
passes its focused run (4 tests/50 assertions including related move proofs). The
last full PHP run on the final tree passed **10,013 tests / 90,872 assertions**
with no failures. Documentation and packaging were checked again after the
delivery evidence update.
The final review's deferred check judgment is therefore resolved by completed output.

## Remaining scope

C2 folder/role administration, C4 identities/forwarding, C5 tags, C6 explicit safe
refresh, C7 whole-module acceptance and D+ parity/reconciliation remain pending.
Native UI/IMAP reply-parent and alias preservation limitations from C0 remain.
Permanent deletion, batches, cross-account moves, SMTP submission and sending are
excluded. The historical [C1 evidence](2026-11-08-mail-c1-guard-evidence.md) remains
valid for its original artifact.
