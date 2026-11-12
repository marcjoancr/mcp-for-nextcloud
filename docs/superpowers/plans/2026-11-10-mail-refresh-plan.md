# Mail C6 Guarded Mailbox Refresh Implementation Plan

> **For agentic workers:** Execute inline with superpowers:executing-plans and TDD, one fresh final review. Keep the ledger outside the repository.

**Goal:** Add explicit owned-mailbox cache refresh, durable status and truthful snapshot freshness without enabling sending.
**Architecture:** Native v5 adds a closed read-only snapshot collector and atomic targeted cache commit. It does not invoke whole-account sync, classifiers, synchronization events, draft/outbox workers or arbitrary callbacks. Existing journal, signed child process and grants remain authoritative. Ordinary native synchronization and C6 share connection-owned MySQL advisory locks with token-checked ownership. Cache fingerprints and C5 revision chains reject overlapping cache changes.
**Tech Stack:** MCP2.7.0, existing PHP/Nextcloud DB/native Horde APIs, pinned NC35.0.2/Mail5.12.5/MySQL primary; native/unit/browser tests.
**Spec:** C6 in [approved completion design](../specs/2026-11-07-mail-completion-design.md) and [master plan](2026-11-07-mail-completion-plan.md). User explicitly authorized C6 plus necessary native dependency and conditional unlock on verified guarantees. Baseline0eb55144.

## Global constraints and decisions

- `mail_refresh_mailbox` / independent denied-by-default `refresh` grant; account_id, mailbox_id and explicit optional allow_cache_reset=false, standard approval fields. No refresh from read/search/status.
- Native v5 only; preserve immutable v1–v4 fingerprints for original scopes. Sending always disabled. No deployment/push/PR or worktree removal.
- Reuse 5/user/account/min mutation admission and existing 20-second parent watchdog. Native monotonic deadline and per-command/commit checks; bounded reads before parser allocation: max200 remote/cached messages, max32KiB protocol line, max64KiB literal/header, max8MiB total. Reject excess, unsupported CONDSTORE or unstable generation/MODSEQ/UID set before cache dispatch. Never report a partial capture as a complete refresh.
- Closed native command set permits authentication/read-only EXAMINE, STATUS, UID SEARCH and peek header/envelope/flag fetch. Disable ESEARCH/SEARCHRES range expansion. No SELECT/STORE/APPEND/MOVE/COPY/CLOSE/EXPUNGE or send path.
- MySQL session-owned advisory mutex plus native lease token: stale owner cannot release a successor; connection loss invalidates proof. All ordinary sync entry locks use it too. No age-only force unlock. A killed CLI releases its physical connection; unresolved cache commits retain journal uncertainty.
- Read stage performs current authorization without claiming a write. At cache dispatch, atomically admit/record observed snapshot through the existing native journal phases. A read failure is safely before cache effect; cache uncertainty never replays.
- Cache commit locks exact mailbox and all bounded current rows, compares request/cache fingerprint and profile, and checks native lease. Existing valid UID incarnations preserve IDs/preview/AI data and receive current flags/tags; vanished rows are removed. Unknown/profile-changed/epoch-changed data needs approved allow_cache_reset and fresh headers/new IDs. Legacy header tag history remains preserved.
- Persist observation time/generation/profile/cache fingerprint; freshness means an observed snapshot, never live/current forever. Reads report unknown/stale truthfully and do not update state. Native thread rebuilding remains explicitly pending where safe targeted parity is unproven.

## Review focus

1. Stale native sync owners, reconnects and shutdown callbacks cannot unlock or write through a successor lease.
2. Bogus counts, UID ranges, oversized lines/literals and slow servers cannot allocate unbounded data or extend the parent budget.
3. Remote changes during observation and cache/permission/profile changes before commit refuse; historical observations never promise remote state at commit; uncertain commits/status/replay never repeat cache work.
4. Reset preserves exact owned scope and history, invalidates old IDs/cursors/plans and never guesses Message-ID identity.
5. Held replies, unrelated mailboxes/users, seen flags, draft/outbox/SMTP/MDN state survive every success/refusal/fault; no hidden worker/event dispatch.

## Task 1 — Native lease and read budgets

Files: native/mail-guard/src/Sync/{MailboxLease,RefreshBudget}.php, native mailbox lock adapter and bounded native socket reader; new native migration. Tests: Unit/Modules/Mail/MailRefreshLeaseTest.php and MailRefreshBudgetTest.php; real native competing connections/kill cases.

- [x] Observe RED for stale-token release, connection replacement, reentry and bounded read exhaustion, then implement GREEN on the pinned native implementation.
- [x] Replace native timestamp-only sync authority with owner checks, preserving UI timestamps as metadata only; prove final/shutdown releases are idempotent and cannot affect a newer owner.

## Task 2 — Native closed snapshot/cache provider

Files: native src/Service/GuardedMailboxRefresh.php, Sync snapshot/cache/freshness helpers, IMAP guard and deterministic patch-refresh.php; preserve v4 manifest.

- [x] RED/GREEN actual native read-only snapshot, stable UIDVALIDITY/MODSEQ and cardinality checks, cache fingerprint CAS, exact reset/vanished/flag/tag paths and no-send/held state.
- [x] Read-only failures remain before cache effect; commit acknowledgment loss remains uncertain. Persist only bounded sanitized evidence. Fingerprint critical native sources and retain old immutable artifacts.

## Task 3 — MCP tool, journal and presentation

Files: Mail/Sync/MailRefresh.php, Tools/RefreshMailbox.php, Integration/MailRefreshTarget.php, Mutations/RefreshRequest.php and refresh fence, existing worker/session/target/services/capabilities/catalog/grants/DI/presentation/l10n, API and publication fixtures.

- [x] RED/GREEN independent permission/selection, reviewed reset, stale target, exact proofs, replay/status/source revocation and 20-second process bound.
- [x] Add read-only freshness metadata with no implicit native call that refreshes. Preserve existing36 raw tool definitions and append one per locale.

## Task 4 — Qualification and delivery

- [x] Focused/native faults plus A/D/C0–C5 regression, full catalog check with Node24 and project-local test TMPDIR.
- [x] One fresh final reviewer; one Important/Critical fix pass with meaningful RED/GREEN and full verification, no re-review.
- [x] Unlock only qualified source/schema/provider after all guarantees; update evidence/master/reference and locally commit, package from clean identity. No send/deploy/push.
