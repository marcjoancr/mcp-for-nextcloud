# C6 bounded mailbox refresh — delivery evidence

Baseline `0eb55144b571d7acc39c2430407998477be901a5`, MCP2.7.0. This extends
A/D/C0–C5 with an explicit reviewed refresh. Sending remains disabled. No live
installation, publication, push or deployment is authorized or performed.

## Execution boundary

`mail_refresh_mailbox` adds the independent denied-by-default `refresh` grant.
The 36 existing raw Mail tool definitions remain unchanged; the new definition is
appended. Ownership, current connection selection, reviewed reset intent,
profile/cache revisions and source permission remain bound to the shared signed
worker and durable journal. A new read-stage fence leaves the operation prepared
until cache dispatch. Read refusal is before effect; commit or receipt uncertainty
retains the account claim and replay/status never execute again.

Native v5 requires the exact custom Mail5.12.5 source on NC35.0.2, a primary
MySQL/MariaDB connection without replicas/sharding, transactional native cache,
account and recipient tables, the C5 schema and nullable 512-byte refresh marker.
The immutable v1–v4 fingerprints remain scoped to their original actions.

A private client observes one read-only mailbox without the native cache backend.
Fixed commands, pre-tokenization wire limits and actual stable UIDVALIDITY,
modification sequence and UID set bracket a full bounded snapshot. CONDSTORE is
required; ESEARCH/SEARCHRES/CONTEXT/QRESYNC are excluded. Limits are 200 cached and
remote messages, 32KiB per protocol line, 64KiB per literal/header, 8MiB total,
a native monotonic deadline and the existing 20-second parent watchdog. Socket
timeouts alone are not the budget. No whole-account synchronization, classifier,
synchronization event, body loading, thread rebuilding, draft/outbox/MDN or SMTP
path runs in C6.

All ordinary native sync criteria and C6 share a physical MySQL session mutex.
Private session token, connection ID and exact DTO owner fence writes and release.
Timestamp columns are metadata only. Old shutdown callbacks, reconnects and killed
children cannot release a successor. Native C5 snapshot versions retain their sync
owner. Native read-cache callbacks without that owner cannot delete database rows.

The cache commit locks and compares the owned account/mailbox/current message rows.
It rejects concurrent cache/profile changes and verifies final cardinality. Valid
incarnations keep IDs and previews; new/vanished UIDs update precisely and exact
tags use C5's shared native mapper. Unknown/profile-changed/epoch-changed cache
requires reviewed `allow_cache_reset=true`, with new IDs and preserved legacy
NULL-exact history. No Message-ID/name guessing occurs. Success rotates an opaque
native token component, invalidating prior reviewed plans/cursors.

Folder freshness is unknown, stale or an observed historical snapshot. The marker
matches cache/profile/generation proof and uses the time of observation, never
commit time or a promise that the remote mailbox is still current. Reads/search/
status never refresh or enqueue work. `threads_state: native_rebuild_pending`
explicitly excludes native thread rebuilding.

## Verification record

The first 46-test native regression passed 1,146 assertions, including real HTTP
approval/replay/revocation/status, held-reply hash preservation, exact flag/tag
updates, reviewed reset/new IDs, protocol limits, cache races, connection and
receipt loss, real competing sessions, child kill and reconnect. The additional
read-cache owner regression passed in the full 47-test suite / 1,147 assertions.
The final storage guard and closed-parser correction passed the full native
47-test suite / 1,157 assertions. This includes actual UID queries for an empty
count and ordinary native synchronization after the rotated cache token. The
repository qualifier stayed false until the final whole-check gates passed. Temporary candidate activation exists only inside labelled disposable
local fixtures and is removed before the normal enabled-package run.

Storage RED accepted MyISAM accounts/recipients and an undersized marker; GREEN
rejects all three with the native helper. Budget, lease, worker and read-freshness
RED/GREEN tests cover bounded bytes/UIDs, stale releases, before-effect refusal,
uncertain cache dispatch, replay and no implicit refresh. Source/API/publication/
licensing compatibility is checked with the full catalog.

The first whole test completed 10,235 tests and exposed three contract updates
(new tool count, exact native fingerprint path exceptions, library SPDX headers),
all fixed. A subsequent whole check reached the old 10MiB snippet fixtures and
exhausted the unchanged 1GiB limit in PHPUnit's data exporter. Their provider
retained 25,174,016 bytes for the entire suite. Deferring generation to each case
retains zero provider payload bytes, preserves all five original payload sizes and
refusals, and passes all 18 snippet tests / 39 assertions. No test is skipped,
weakened or given a larger memory limit.

The post-fix whole check passed **10,242 PHP tests / 93,716 assertions**, **837
browser tests**, both static-analysis scopes, architecture/API/localization/docs
checks and packaging. The original 1GiB limit is unchanged (peak 1,023MiB).
Only then was the repository qualifier enabled and the temporary fixture hook
removed completely. The final normal packaged-source run also passed **47 native Mail tests / 1,157
assertions**, with the repository gate enabled and no fixture override. The
temporary runner changes are absent from the final patch.

## Final review and artifact

Exactly one fresh read-only review found an Important pre-parser gap: unsolicited
COPYUID and tokenizer-accepted variants could expand ranges before the bound.
A 31-byte response expanded 1,000 entries in the pinned implementation. Unit and
actual-wire regressions first failed; the closed response/type/code grammar now
rejects COPYUID, quoted variants, ESEARCH/VANISHED variants and endpoint-changing
REFERRAL before semantic parsing. Numeric FETCH responses and 2,048 protocol
lines are bounded, including repeated spaces and logical literal continuations.
An actual-wire case exposed Base::search skipping UID SEARCH for a zero count;
the closed provider uses the native protected search primitive with fixed options
so both UID observations always reach the server. No synthetic count is introduced.

The old guide's unavailable-refresh statements were also corrected and translated.
Although labelled Minor, that finding was treated as Important: the guide is the
required entry point and would misstate the delivered capability. Both fixes had
failing tests before implementation and share one implementer fix pass; there was
no second reviewer or re-review. No minor finding is deferred.

The normalized v5 overlay has **49 files**, with **80 fingerprinted paths**:

- Overlay SHA256: `1b74e8b6f0a39f2e8f28331fea1e426c3f348d72fdab8f45ffef0e8cbf8194a5`.
- Manifest SHA256: `77d02705bfd308d5b39b9b7f3bda90faef2fd23b79587ae24b943ff672558007`.
- Original native archive SHA256: `c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`.
- Local output: `mcp/build/native-mail-guard/mail-guard-v5-overlay.tar.gz`.

All four legacy normalized archive digests remain unchanged.

## Operational limits and decisions

- Closed complete bounded observation instead of the general synchronizer: large
  mailboxes refuse and thread rebuilding remains native work.
- Connection ownership instead of expiring locks: busy mailboxes refuse; other
  database providers remain closed pending qualification.
- Locked cache fingerprint instead of refactoring every native cache writer:
  concurrent changes conservatively abort the refresh.
- Read-cache callbacks without a sync owner change memory only: obsolete cached
  rows remain until owned native sync or explicit refresh can remove them safely.
- Reviewed cache reset with new IDs: callers must re-query old IDs, cursors and
  plans. Remote messages, Files and legacy tag history are retained.

Native installation is separate. Rollback requires matched code/database snapshots;
new entity columns make a source-only downgrade unsafe. No force unlock,
automatic reconciliation, folder deletion, account send or credential API is added.
D+ account/alias workflow and full Mail parity remain separate pending work.
