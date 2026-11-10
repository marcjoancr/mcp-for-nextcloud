# Native Mail draft lifecycle delivery evidence

Status: final regression passed. This record is not a release or installation
approval. Sending through MCP remains disabled. Baseline:
`9c2e1ffcc16c67e107c26a46bd2dbbc2e712efd2` on `feat/mail-release-a`.

## Implemented boundary

The v6 custom dependency derives from the SHA-pinned official Mail5.12.5 archive
(`c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0`).
Its custom version is 5.12.5.6. It preserves an explicit owned same-account alias,
the immediate reply parent in In-Reply-To/References, and native MIME attachment
serialization during explicit local-draft conversion. SMTP implementation methods
outside `saveLocalDraft` remain byte-identical to the pinned source. No new MCP
tool, grant, transport or sender is added.

Qualified new/reviewed ordinary drafts persist `mcp_draft_only=true` and remain
held. The bounded owner-only native Page entry uses the existing composer with
Send, scheduling and unsupported crypto actions disabled. Native controller,
mapper and outbox checks enforce protection independently. Canonical untouched
plain/HTML bodies preserve their original bytes; explicit edits use native
serialization. Historical, scheduled or crypto rows are not implicitly adopted.

The final-review finding was reproduced in two real native RED cases: a cached
background converter and an explicit stale converter could APPEND an old DTO,
then delete the newer protected local row/attachments. A row lock or outer SQL
transaction across filesystem work was insufficient: pinned NC35 filesystem cache
code may commit/restart its own transaction. The consolidated correction adds a
physical primary-session owner mutex shared by native lifecycle, mapper and
attachment entry points. Complete hydrated snapshots detect same-second changes.
Short SQL phases and filesystem/IMAP work share ownership without an ambient
transaction. Losing that physical session invalidates command/cleanup admission.

MCP acquires the qualified native scope before Files/forward staging. Failure
before callback admission can be reported as before-effect; later failure remains
conservative. Native atomic orphan cleanup rechecks association under the same
scope. Unqualified builds retain staged bytes/references. Protected associations
cannot be taken by another message or deleted by a stale parent. Existing partial
write/repair semantics, 24-hour cleanup eligibility and original Files remain.

## Observed acceptance

Tests run only in labelled disposable NC35.0.2/MariaDB/Dovecot cells, with no live
accounts, production install or public listener. PHP inside the pinned NC35 image
is 8.5.12; local PHPUnit uses PHP8.4.27.

- Native original serializer RED: missing explicit alias From, before product changes.
- Native enabled-source acceptance before concurrency correction: 51 tests /
  1,412 assertions plus actual Chromium editor/MCPHTTP/Files/IMAP proof. This is
  historical evidence, superseded by the final regression below.
- Native stale-conversion RED: two tests failed exactly because old converters
  were not refused. After correction: two tests / 12 assertions passed.
- Separate-process coordination: seven tests / 46 assertions passed. They cover
  the stale converter cases, contention during body/recipient/attachment update,
  contention after APPEND, KILL of the actual MariaDB session before and after
  APPEND followed by a successor native update, protected attachment reassignment,
  wrong-parent deletion and orphan eligibility changing before atomic cleanup.
  Before-wire loss yields zero APPEND; after-wire loss yields one APPEND and
  retains the successor's local body/bytes. Every case checks no submission.
- Both static-analysis scopes passed after the coordination implementation.
- API/architecture: 3,236 tests / 29,284 assertions passed. The custom native
  exception marker has an exact Integration-file declaration, separate from the
  original Mail/Nextcloud API fixtures; native runtime acceptance proves it.

- Final guarded native regression: **58 tests / 1,458 assertions**, no skips,
  plus both actual Chromium plain and HTML workflows. The actual HTTP response
  supplies the native entry URL without manually arming provenance or substituting
  its route. Subject-only plain/HTML edits preserve exact approved body bytes;
  explicit body edits use native submitted content. Alias/thread/attachment bytes
  are independently read back from IMAP. Whole-session no-send checks pass.
- The rebuilt final overlay matches the tested MCP manifest byte-for-byte. All
  generated PHP passes syntax checks: 72 overlay files / 113 fingerprints.
- Immutable v5 actual-source gate proof: **1 test / 16 assertions**, old seven
  scopes accepted, v6 drafts refused, tamper closes and restoration reopens. This
  is an offline source-gate proof, not a new physical v5 cell.

- Stock Mail negative control: **19 executed / 39 expected skips / 530
  assertions** across the same 58-case suite. The seven new coordination cases
  require v6 and skip on stock. Stock alias/header loss remains characterized;
  automatic cleanup retains orphan/already-missing references in `cleaning` while
  preserving associated/uncertain bytes and original Files.

The complete registered catalog passed on the final source: **10,341 PHP tests /
95,113 assertions**, **837 browser tests**, both static-analysis scopes, PHP syntax,
documentation/client/release checks and production packaging. The build-failure
fixture intentionally reports its missing import while all browser cases pass.
Translation review/missing states remain advisory, as at the baseline.

One fresh final review identified the stale-conversion race above; it was resolved
in one consolidated fix pass and verified by the native stale/current/contention/
physical-session tests and final regressions. No second whole-branch review was
performed. No actionable review item remains outstanding for this delivery.

## Native artifact identity

| Artifact | SHA256 |
|---|---|
| Normalized v6 overlay | `7f3053b1dd5146b372b69e291c631bdb607b4d7c8fbf6574ab4d4103491be23c` |
| Final v6 manifest | `68623d3852aaa6644b77276ad0d14ccfd9d25cbd24a5ea71b32db54bfcc2fa0f` |
| Preserved v5 overlay | `1b74e8b6f0a39f2e8f28331fea1e426c3f348d72fdab8f45ffef0e8cbf8194a5` |
| Preserved v5 manifest | `77d02705bfd308d5b39b9b7f3bda90faef2fd23b79587ae24b943ff672558007` |

The v6 overlay is a dependency overlay requiring the pinned original app; it is
not the MCP package or a standalone Mail installation. Reproduction uses
`native/mail-guard/build.php` against the pinned original archive. The normalized
archive sorts names, fixes timestamps/owners and suppresses gzip timestamps.

## Limits and remaining module work

No exactly-once APPEND, complete ancestral References reconstruction, automatic
native conversion retry or global SMTP switch is claimed. Native conversion is
outside the MCP journal; an accepted APPEND followed by failure remains uncertain.
MCP status/replay never converts or sends a draft. Cached native lifecycle objects must reload the current version after refusal.
The native editor does not add a cross-request content-merge protocol.

Qualification is the exact source/schema/pair and transactional primary provider;
NC33/34 and other native/provider combinations remain unverified. Immutable v5
keeps its previous qualified scopes without gaining v6 draft guarantees. Native
credential-write evidence with an unchanged profile is subsequent work; current
authentication observations are not proof of changed credentials.

Installation is separate and requires a matched code/database snapshot and old
workers/requests stopped before switching versions. No deployment, push, PR or
release publication has been performed.
