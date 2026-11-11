# Complete Mail B and extras implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete B and optional Mail parity, qualify it, publish PR/release and deploy with production sending disabled.

**Architecture:** Dedicated native immutable delivery queue and closed guarded services; MCP handles exact previews, journal admission, current permissions/connection/resource selection and bounded worker dispatch. Reuse physical-session owner/mailbox leases and capped native transport reads.

**Tech Stack:** PHP, Nextcloud35.0.2, Mail5.12.5 guarded dependency, PostgreSQL16, PHPUnit, browser/native fault fixtures.

**Spec:** [Complete Mail design](../specs/2026-11-11-mail-complete-design.md).

## Global Constraints

- Production sending stays disabled; tests submit only to synthetic fixtures.
- Native v1–v10 artifacts remain immutable; new features require exact new source/runtime/schema qualification.
- No credentials/private MIME/body in receipts/logs; no implicit crypto downgrade or destructive source import.
- Every mutation uses exact preview/confirmation, durable operation ID and read-only status/replay; uncertainty never permits automatic repeat.
- Existing grants/selections/scopes remain intact; new permissions denied by default.
- Harness ledger/briefs/reports remain outside repository at /tmp/mail-complete-20261111; project build outputs stay in mcp/build. This overrides skill scratch-directory defaults per AGENTS instructions.

## Review Focus

- Crash after remote acceptance must not produce another SMTP/move/APPEND command.
- Concurrent native UI/account/alias/draft changes must not change approved identity/content.
- Scope or connection revocation must affect delayed work and receipt access.
- Malicious provider literals must be bounded before allocation, including unsolicited responses.
- Unsupported crypto, MIME, provider extensions or cache generations must refuse without data loss.

### Task 1: Native immutable delivery queue and dispatch

**Files:** Create native/mail-guard/src/Transmission/*, native/mail-guard/src/Service/{GuardedDelivery,FrozenMessageRenderer,SentCopyRepair}.php, migration; modify native draft coordinator/storage and patch generators only as necessary. Add focused mcp/tests/Unit/Modules/Mail/MailDelivery*Test.php and mcp/tests/Integration/Mail/McpMailDeliveryTest.php. Builder/source manifest integration belongs to Task2.

**Interfaces:** Produce native GuardedDelivery::preview(string uid,int draftId):array (safe exact content/identity/revisions); queue(string uid,int draftId,string approvedRevision,string operationKey,int connectionId,string sourceTool,int sendAt,string timezone):array; status(string uid,string id):array; due(int limit):array; cancel(string uid,string id,string revision):array; dispatch(string uid,string id,object fence):array. Fence authorizes current uid/connection/sourceTool/account immediately before effect. Produce SentCopyRepair::preview/repair closed IMAP-only methods. IDs are opaque, receipts sanitized. Settle exact signatures in /tmp/mail-complete-20261111/native-delivery-interface.md before MCP implementation.

- [ ] Write failing behavioral tests for changed/missing sender/attachment, native protected source edit fence, idempotent queue admission, queued/attempting/accepted/uncertain/cancelled transitions, two-worker/cancel CAS, source privacy and no auto replay. Observe RED.
- [ ] Implement dedicated native queue/migration, frozen canonical content revision, exact MIME/envelope/FCC snapshot, source association and current identity proof under existing owner lease. No normal outbox conversion.
- [ ] Implement durable pre-wire claim, current authorization fence, bounded due selection, conservative SMTP uncertainty and independent Sent-copy-only repair. Gate execution by disabled-by-default native setting. Preserve existing ordinary native behavior outside guarded queue.
- [ ] Run focused tests/lint, add deterministic native protocol fault scenarios and commit. Expected: no repeated SMTP submission after ambiguous outcome; repair never resolves SMTP client.

### Task 2: MCP B tools, worker and native artifact admission

**Files:** Modules/Mail/Transmission/*, Tools/{SendDraft,ScheduleDraft,CancelDelivery,ReadDelivery,RepairSentCopy}.php; AppInfo/Application.php, MailToolCatalog, capabilities, WriteGate, journal/store receipts, permissions/UI/translations/public tools; native/mail-guard/build.php and native manifests/runtime boundary; background job/command as needed.

**Interfaces:** Consume Task1 interface report. Produce separately granted send/schedule/cancel/read delivery/Sent repair tools, current connection checks and background authorization; journal admission succeeds with delivery ID, status reports native delivery evidence separately. Preserve first43 tools' order.

- [ ] RED tests for exact preview/plan changes, denied/revoked source selection, timezone/DST validation, read-only status, durable replay, operation receipt privacy and separately denied new grants.
- [ ] Wire bounded worker with originating connection/user/app/grant/scope revalidation before native fence; avoid extending existing25s mutation tickets to represent schedules.
- [ ] Generate native v11 artifact (Mail5.12.5.11, mcp-mail-mutations-v11), preserve immutable v10 manifest, extend API/runtime/source qualification without blessing stock/new untested pairs.
- [ ] GREEN focused PHP/API/UI tests, update public tool contract and commit.

### Task 3: Hard-capped content and Files export

**Files:** native Content/IMAP/Service capped reader and transport budget integration; MCP Integration/MailContentReader.php, Files export and larger attachment saver, Tools export; tests native/MCP.

**Interfaces:** Native readRaw/readPart closed owned message APIs return bytes+generation+hash, bounded before allocation; MCP export uses IncomingFileWriter, operation checkpoint and exact destination/content plan.

- [ ] RED malicious oversized literal/line/count/deadline and UIDVALIDITY/reselection tests; no mark-read or crypto fallback.
- [ ] Implement25MiB raw/decoded part and40MiB wire cap before reads; fresh client and exact selection proof. Keep existing small base64 response bound; larger parts save directly to Files.
- [ ] Implement confirmed EML/part export with no overwrite, policy recheck/status/replay and changed content refusal. GREEN focused tests and commit.

### Task 4: IMAP draft search/import and indexed body search

**Files:** native draft import/content parsing and generation-bound PostgreSQL body index/migration; MCP Drafts importer/search/index tools and tests.

**Interfaces:** Consume capped reader; produce explicit protected copy import, cached Drafts search, index selected IDs max20, indexed search max50 with coverage and keyset cursor.

- [ ] RED tests prove original IMAP draft remains, exact supported content copied, crypto/unsupported MIME refuses, duplicate import replays, index cross-owner/stale generation exclusion and bounded results.
- [ ] Implement safe copy without handleDraft/source-deletion events; index bounded extracted text explicitly, no unbounded crawl or body-search claims beyond coverage.
- [ ] GREEN native/MCP tests, API/docs registration and commit.

### Task 5: Complete account/folder resource scopes

**Files:** Access/ResourceScope, Db/ResourceScopeMapper, Mail gateways/module/worker/status/readers, native resource enumerator and admin selection UI/tests.

**Interfaces:** Typed Mail resource IDs account:<positive decimal> and mailbox:<positive decimal> within existing string storage, module mail; account permits owned folder descendants, mailbox permits message actions only. All sources/destinations/status/current workers enforce scope; no selected resource grants tool permission.

- [ ] RED denial/filter tests across all43 baseline tools and additions, hidden reply/forward/attachment/destination IDs, account-only mutations, failed policy reads, saved restriction and worker/replay revocation.
- [ ] Implement shared scope guard/enumerator/canonical validation, integrate complete consumer graph and UI, then open activation barrier only after checks pass.
- [ ] GREEN scope/policy/UI regression and commit.

### Task 6: Guarded snooze and native structured filters/absence/identity modes

**Files:** native guarded snooze intent/wake worker, capped ManageSieve settings and native UI/background fencing; MCP independent tools/grants and identity signature mode; tests.

**Interfaces:** Consume guarded moves/current scope/worker authorization; no silent wake destination fallback or replay after unknown MOVE. Structured bounded Sieve state with revision and exact supported actions only; transmission gate required for vacation enable/redirect.

- [ ] RED worker/wake/cancel races, lost COPYUID, missing original folder, scope revocation; Sieve CAS/oversize/unknown-section preservation/lost install response/no secret logs; identity crypto/provisioning field preservation.
- [ ] Implement one-message snooze/wake intent using existing qualified move proof and shared leases; configure/read/disable absence and safe filters with exact script revision/readback and dedicated status. Keep production gate false.
- [ ] GREEN native/MCP tests and commit.

### Task 7: Acceptance, PR and release

**Files:** Complete native fixtures, public tool docs/translations, changelog/version/release source kit and evidence.

- [ ] Run real PostgreSQL/LinuxServer/S3 qualification with synthetic SMTP/IMAP/ManageSieve fault matrix for Tasks1–6, including process death and independent sessions; no skips for claimed features.
- [ ] Run full catalog checks (PHP/static/UI/docs/API/package), source inventory/REUSE and fresh whole-branch review; fix material findings with RED→GREEN evidence.
- [ ] Bump MCP to2.8.0 for the added tool surface; freeze reviewed commit/source/native hashes. Push feature branch, open reviewable PR against main and attach it; publish versioned release with runtime/native/source artifacts after required checks. No automatic merge if review/CI rejects.

### Task 8: Production deployment and final state

**Files:** External operator plan/backups and deployment evidence docs.

- [ ] Inspect live versions/policy/cache provenance and available resources; create exact staged artifacts and consistent recovery backup, preview exact changes.
- [ ] Freeze admission/drain workers, install coherent MCP/native source, migrate via fresh same-image isolated worker with private writable config, restart/verify before reopening.
- [ ] Verify HTTPS/native/runtime/schema, unchanged policy/connections/scopes, bounded capability checks, cron and disabled send gate. Preserve originals and recovery backups. Native rebuild/provenance work only if it preserves original messages and tag history without unapproved bulk reset.
- [ ] Update master plan/evidence and give PR/release/deployment links and honest feature/provider limits.
