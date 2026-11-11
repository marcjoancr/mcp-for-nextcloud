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

- [x] Write failing behavioral tests for changed/missing sender/attachment, native protected source edit fence, idempotent queue admission, queued/attempting/accepted/uncertain/cancelled transitions, two-worker/cancel CAS, source privacy and no auto replay. Observe RED.
- [x] Implement dedicated native queue/migration, frozen canonical content revision, exact MIME/envelope/FCC snapshot, source association and current identity proof under existing owner lease. No normal outbox conversion.
- [x] Implement durable pre-wire claim, current authorization fence, bounded due selection, conservative SMTP uncertainty and independent Sent-copy-only repair. Gate execution by disabled-by-default native setting. Preserve existing ordinary native behavior outside guarded queue.
- [x] Run focused tests/lint, add deterministic native protocol fault scenarios and commit. Expected: no repeated SMTP submission after ambiguous outcome; repair never resolves SMTP client.

### Task 2: MCP B tools, worker and native artifact admission

**Files:** Modules/Mail/Transmission/*, Tools/{SendDraft,ScheduleDraft,CancelDelivery,ReadDelivery,RepairSentCopy}.php; AppInfo/Application.php, MailToolCatalog, capabilities, WriteGate, journal/store receipts, permissions/UI/translations/public tools; native/mail-guard/build.php and native manifests/runtime boundary; background job/command as needed.

**Interfaces:** Consume Task1 interface report. Produce mail_send/send, mail_schedule/schedule, mail_cancel_delivery/cancel_delivery, mail_read_delivery/read_delivery and mail_sent_repair/repair_sent tools with independent grants, current connection checks and background authorization; journal admission succeeds with delivery ID, status reports native delivery evidence separately. Preserve first43 tools' order.

- [x] RED tests for exact preview/plan changes, denied/revoked source selection, timezone/DST validation, read-only status, durable replay, operation receipt privacy and separately denied new grants.
- [x] Wire a bounded job launching one signed fresh CLI child per item via existing NativeMutationProcess20-second process limit plus CLI alarm and a fresh25-second delivery ticket. Sent-copy repair must use the same absolute child bound, including its APPEND write; include action and approved repairRevision in the signed ticket. Revalidate originating persistent OAuth connection/user/app/grant/scope before native fence; queue lifetime is independent of ticket lifetime. Basic/session connection zero refuses. Bound each job sweep to50 entries and an absolute budget, restore parent context. Review up to25 future queued entries using a persistent metadata audit cursor as well as up to25 due entries; audit runs with transmission off, never opens a transport for valid future/off-gate entries, and blocks only authoritative revocation. Terminal before-wire blocked entries release native source/identity holds; uncertain attempts remain held.
- [x] Recover lost queue acknowledgements through native read-only findByOperation(uid,key), derived from journal scope/original operation ID. Add sanitized delivery_id to receipts/compaction and source_tool checks to cancel/repair/replay. Complete automatic normal Sent copy only with target bound in initial approval; pending copy remains explicit and never resends.
- [x] Generate native v11 artifact (Mail5.12.5.11, mcp-mail-mutations-v11), preserve immutable v10 manifest, extend API/runtime/source qualification without blessing stock/new untested pairs.
- [x] GREEN focused PHP/API/UI tests, update public tool contract and commit.

### Task 9: Native private-payload account and user lifecycle

Execute after the Task2 implementation checkpoint and before its final review and Task3. Review Tasks2 and9 together as one B safety deliverable; Task2 remains pending approval until this prerequisite passes. This dependency was added after source inspection proved a native lifecycle gap; keep delivery qualification closed until its native acceptance and review pass.

**Files:** native/mail-guard/src/Transmission/AttemptStore.php and HeldAccountDeletion.php; new Lifecycle/MailErasureStore.php, MailErasureService.php, background cleanup job and migration; pinned UserDeletedListener/AccountService integration through build patch; native/MCP tests and current v11 manifest only.

**Interfaces:** Consume existing DraftCoordinator physical owner lease and immutable queue. Produce narrow native account-payload cleanup plus durable user-erasure capture/retry for exact original account identities. Ordinary account deletion retains unresolved-delivery denial and erases derived private payload only with admitted account removal. User deletion captures old account IDs and queue references before the nonblocking lease attempt; pending erasure fences those identities against fresh admission/dispatch. Retry shares the lease and cannot target a later recreated UID's new accounts. No new MCP administrative erasure tool.

- [x] RED ordinary account deletion for cancelled/blocked/accepted+verified deliveries proves no orphan frozen payload; unresolved ordinary deletion refuses and preserves source/payload.
- [x] RED actual native UserDeletedEvent path across all queue states proves native accounts/credentials and frozen payload are removed without SMTP/IMAP/APPEND; active-worker independent-session lease permits no partial erasure and persists a retry.
- [x] Implement SQL-only exact-account payload purge in the successful account-removal transaction; add idempotent durable native erasure marker/retry, shared owner exclusion and pre-effect erased-consent fence. Do not load/log body to erase it. Retry also handles already-missing accounts/orphan rows. Keep all unrelated owners intact.
- [x] RED→GREEN repeated event/job, after-busy retry, original user recreation with new account IDs, and worker pre-wire denial. Preserve uncertainty/no-repeat semantics; erasure cannot recall already accepted remote mail and must not claim it can.
- [x] Regenerate current native v11 source hashes, run focused native PostgreSQL/LinuxServer/S3 lifecycle plus delivery regression, focused API/runtime tests and commit. Qualification remains closed until fresh task review accepts the guarantees.

**B safety acceptance:** Reviewed implementation through `4a9cdc4a78560b3c4b4c6ec70035957b6f5fc5e8`; all four review findings closed. Native delivery/lifecycle regression:36 tests/320 assertions. Clean committed MCP package SHA256 `395619d570377668e3002d700473f56986450b4b1cdb1af5ba9f74d0fdf294ce` passed9 actual tool/child tests/60 assertions. Native manifest `ee3a6de7a415c4f2538e62b9e0ae808ab1bf1ad2db13f7cec91b76cdec55024e` matches the installed source. Qualification remains false until Task7 freezes and accepts the complete v11 feature set; production transmission remains false. This checkpoint is not the final2.8.0 release.

### Task 3: Hard-capped content and Files export

**Files:** native Content/IMAP/Service capped reader and transport budget integration; MCP Integration/MailContentReader.php, Files export and larger attachment saver, Tools export; tests native/MCP.

**Interfaces:** Native GuardedContentReader readRaw/listParts/readPart/readDraft/readIndexText closed owned APIs (see external content-reader-design report) bind actual selected connection/mailbox/UIDVALIDITY/UID and narrow authorization fence; raw/part return private bytes+generation+hash, listParts bounded scalar metadata only. MCP export uses IncomingFileWriter, operation checkpoint and exact destination/content plan.

- [x] RED malicious oversized literal/line/count/deadline and UIDVALIDITY/reselection tests; no mark-read or implicit decryption. Opaque encrypted raw export preserves bytes; parsed crypto refuses. Include UID before/after literal, missing tagged completion and public fetch retry refusal.
- [x] Implement bounded BODYSTRUCTURE part discovery with pre-token grammar limits and conditional node/parameter hooks BEFORE Horde object construction; do not materialize every attachment or require whole-message25MiB for listing a25MiB decoded base64 part. Share this closed manifest with draft/import/index readers; avoid unbounded Horde parseMessage recursion.
- [x] Implement25MiB raw/decoded part and40MiB wire cap before reads; fresh client and exact selection proof. Keep existing small base64 response bound; larger parts save directly to Files.
- [x] Route current-v11 message/thread/composition body reads through fixed native `readBody(uid,messageId,fence)` using the same closed session/manifest, actual decoded body<=1MiB and metadata-only attachment listing. Preserve text/max_chars output and independent read-thread grant; lying RFC822.SIZE, reconnects or unavailable new-source qualification never fall back to the legacy parser. Genuine older source paths retain their prior behavior. Test large-attachment/small-body reads, no Seen and thread-only OAuth.
- [x] Implement confirmed EML/part export with no overwrite, policy recheck/status/replay and changed content refusal. GREEN focused tests and commit.

**Content acceptance:** Reviewed through commit4b1f8821430642aa3f96838be70d942f74a30cd4. Both MIME/permission findings and single-part root coverage are closed. C8 native manifest214d817e619dda6b10b7ba98f982e640db3cc8782908f0e9d4d49f70b5986f63 passed22 native/FPM/OAuth/TLS tests/355 assertions and7 root/B/Sent/refresh regressions/102 assertions. These are explicitly mutable diagnostic tests with content eligibility overridden only in the fixture; Task7 still owns clean shipping qualification. Production transmission remains disabled.

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
