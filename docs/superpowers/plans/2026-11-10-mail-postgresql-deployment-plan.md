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
- [ ] Run complete Mail native PostgreSQL acceptance, browser credential/draft checks and real-session failure cases.
- [ ] Rehearse stock-to-custom PostgreSQL upgrade and consistent recovery on the LinuxServer profile.
- [ ] Run catalog check, review and build matching immutable runtime/native/source kit.

- [ ] Verify S3 incoming-file creation with web/database parent exclusion and independent CLI contenders; refuse unsupported backends before preview.
- [ ] Recheck bounded refresh after removing unrelated capability/schema work from its callbacks.

**Expected:** Admission remains closed until the qualified artifact passes; all required checks pass and immutable digests are recorded.

## Task 3: Production deployment

**Files:** operator scripts and private backups outside repository; evidence in project docs.

- [ ] Recheck live identity, current versions, database and operator controls.
- [ ] Stage exact complete applications, take consistent private backups and establish recovery.
- [ ] Freeze admission, drain workers, replace APCu file locking with default database locking, install, migrate and restart with exact source metadata.
- [ ] Verify HTTPS, source/schema, grants/connections and no-send capabilities; document result.

**Expected:** Healthy migrated instance with qualified Mail and sending still disabled. Stop without live changes if prerequisites fail.

## Task 4: B foundation and optional export

**Files:** Mail outbox read and raw-export service/tools, permissions, presentation, unit/integration tests and public tool reference.

- [ ] Implement and verify bounded owned outbox inspection with separate permission and no dispatch.
- [ ] Implement generation-safe bounded raw export to Files with confirmation/journal/status/replay, or record an exact native boundary blocker before exposing it.
- [ ] Run appropriate tests, review, update master plan and list remaining B/extras work.

**Expected:** Tested initial B/extras slice, with actual transmission disabled and incomplete work explicitly recorded.
