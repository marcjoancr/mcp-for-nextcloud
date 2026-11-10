# PostgreSQL Mail and Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans or superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Support production PostgreSQL, deploy the accepted no-send Mail delivery, then begin B/outbox and optional export.

**Architecture:** Native session ownership and exact schema gates support qualified primary PostgreSQL alongside existing MySQL. Existing MCP grants, confirmation and journal remain authoritative. Deployment preserves production state and uses immutable artifacts.

**Tech Stack:** PHP, PostgreSQL 16.15, NC35.0.2 LinuxServer, pinned Mail5.12.5 custom overlay, PHPUnit/Playwright and Docker fixtures.

**Spec:** [Design](../specs/2026-11-10-mail-postgresql-design.md).

## Global Constraints

- Keep actual MCP sending disabled.
- Retain production PostgreSQL and native credential forms.
- Preserve historical manifests and artifacts; new source identity is required.
- Admit only verified primary storage, with no replicas or sharding.
- No credentials or production message bodies in logs or fixtures.
- Deploy only after relevant native, upgrade, regression and recovery checks pass.

## Review Focus

- PostgreSQL recursive advisory locking and session reconnect must not transfer ownership.
- Transaction rollback must not erase ownership markers or release a successor.
- Native UI/background paths must work on the same PostgreSQL schema.
- Existing stock-Mail data and same-version MCP migrations must survive upgrade.
- Outbox inspection and export must never dispatch, broaden grants or replay uncertain writes.

## Task 1: PostgreSQL native storage and session ownership

**Files:** native/mail-guard/src/Db/PgSessionLease.php, Drafts/DraftOwnerLease.php, Sync/MailboxLease.php, Drafts/DraftCoordinator.php, Authorization/SetupEvidence.php, Db/ExactTagAssociations.php, Sync/CacheSnapshot.php; MCP Integration/MailDraftStorage.php and MailCredentialStorage.php; targeted unit and PostgreSQL integration tests.

- [x] Add and run failing PostgreSQL admission and physical-session ownership tests.
- [x] Add PostgreSQL leases and transactional storage admission without weakening MySQL.
- [x] Verify recursive refusal, concurrent owner refusal, reconnect/termination, rollback, stale release and namespace isolation.
- [x] Run targeted tests and commit only task-owned files.

**Expected:** PostgreSQL contract tests pass; MySQL lease and unsafe-storage tests remain green.

## Task 2: Immutable native identity and acceptance

**Files:** native/mail-guard/build.php and README.md; MCP Integration/MailNativeBoundary.php, NativeGuardManifest.php, MailRuntimePairs.php; native harness gates/tests, qualification evidence.

- [x] Preserve v8 manifest; add distinct v9 native version/contract and source admission.
- [x] Run complete Mail native PostgreSQL acceptance, browser credential/draft checks and real-session failure cases.
- [x] Rehearse stock-to-custom PostgreSQL upgrade and consistent recovery on the LinuxServer profile.
- [x] Run catalog check, review and build matching immutable runtime/native/source kit.

- [x] Verify S3 incoming-file creation with web/database parent exclusion and independent CLI contenders; refuse unsupported backends before preview.
- [x] Recheck bounded refresh after removing unrelated capability/schema work from its callbacks.

**Expected:** Admission remains closed until the qualified artifact passes; all required checks pass and immutable digests are recorded.

## Task 3: Production deployment

**Files:** operator scripts and private backups outside repository; evidence in project docs.

- [x] Recheck live identity, current versions, database and operator controls.
- [x] Stage exact complete applications, take consistent private backups and establish recovery.
- [x] Freeze admission, drain workers, replace APCu file locking with default database locking, install, migrate and restart with exact source metadata.
- [x] Verify HTTPS, source/schema, grants/connections and no-send capabilities; document result.

**Expected:** Healthy migrated instance with qualified Mail and sending still disabled. Stop without live changes if prerequisites fail.

## Task 4: B foundation and optional export

**Files:** Mail outbox read and raw-export service/tools, permissions, presentation, unit/integration tests and public tool reference.

- [x] Implement and verify bounded owned outbox inspection with separate permission and no dispatch. Cached metadata batches also pass the real PostgreSQL tool path.
- [x] Implement generation-safe bounded raw export to Files with confirmation/journal/status/replay, or record an exact native boundary blocker before exposing it. **Blocker recorded below; no export tool exposed.**
- [ ] Run appropriate tests, review, update master plan and list remaining B/extras work.

**Expected:** Tested initial B/extras slice, with actual transmission disabled and incomplete work explicitly recorded.

## Accepted deployment record

Production now runs MCP2.7.0 from commit `49b00cc1f9d4285e8ee14530247a8fefcd46ad37`
and native Mail5.12.5.9 on NC35.0.2.2 / PostgreSQL16.15. HTTPS, required migrations,
exact native fingerprints, database file locking, unchanged connection/grant/scope
hashes and disabled MCP sending passed before admission reopened. See the
[PostgreSQL deployment evidence](../specs/2026-11-10-mail-postgresql-deployment-evidence.md).

Task 4 continues locally after that deployment. Its initial implementation scope
is owned bounded outbox metadata (`mail_read_outbox`, separate `read_outbox` grant)
and a cached metadata batch (`mail_read_message_metadata`, existing `read_message`
grant, at most 50 IDs). Both require a new exact native read boundary; the deployed
v9 artifact stays immutable. Neither read path dispatches, synchronizes or marks
messages read. Pagination is a live view, never evidence of delivery.

Raw EML export stays blocked: pinned native `MailManager::getSource()` delegates
to `IMAP\MessageMapper::getFullText()`, which fetches the complete RFC822 string
before a caller can enforce actual byte limits. The advertised `RFC822.SIZE`
preflight is insufficient. Require a native hard-capped stream bound to UID and
UIDVALIDITY in the same selected client, then an effectively bounded, confirmed,
non-overwriting Files write with operation status/replay. No export tool is
published while either guarantee is missing.

## Remaining work after this first slice

- [ ] Install the separately reviewed B0 MCP/native v10 snapshot in production;
  the deployed identity above remains v9/41 tools until that deployment is verified.
- [ ] B transmission: immutable approval of exact sender, recipients, body,
  attachments and revisions; explicit release of protected drafts; independent
  send/schedule/cancel permissions and current connection selection.
- [ ] Native send correctness: refuse missing attachments and unsafe alias fallback;
  retain durable attempt/queue evidence instead of treating row deletion as success.
- [ ] Dispatch/scheduling/cancellation: shared ownership and atomic worker/cancel
  exclusion; explicit uncertain SMTP outcomes with no automatic replay; repair a
  missing Sent copy without resending a possibly accepted message.
- [ ] B acceptance: synthetic SMTP partial-recipient, timeout, disconnect,
  acknowledgement loss, worker death and status/retry/cancel races; actual MCP
  sending stays disabled until its execution guarantees and authorization exist.
- [ ] Raw EML and larger incoming attachments: native hard byte limits during
  retrieval and same-generation identity proof, then bounded Files write/status.
- [ ] Review legacy production cache provenance per mailbox; use native rebuild or
  an explicitly approved eligible bounded reset, preserving original messages and
  historical tag associations. Deployment does not authorize an automatic reset.
- [ ] Other optional parity: IMAP draft import/search, account/folder resource
  scopes, indexed body search, snooze/filter/out-of-office support and advanced
  identity management. Each needs its own native implementation assessment;
  delegated/global administration, destructive deletion and key management stay
  outside the current no-send scope.

The local B0 [metadata evidence](../specs/2026-11-10-mail-bounded-metadata-evidence.md)
records its source/overlay hashes, 49 focused MCP tests, actual v9 compatibility
and v10 PostgreSQL acceptance (3 tests / 86 assertions). Complete regression and
final review still gate the end of Task4. Production remains on the deployed v9
snapshot; send/schedule/cancel are absent.
