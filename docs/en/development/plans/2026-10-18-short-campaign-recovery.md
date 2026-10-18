<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Recovery proposal for the short validation campaign

**Status: proposed; recovery parameters not accepted or executed.** The user owns
devbox and has accepted operation/recovery responsibility and resource ceilings.
The [owner decision](2026-10-18-upstream-1.1.0-staging-acceptance-report.md#confirmed-decisions--short-validation-campaign)
replaces a 30-day allocation with a bounded validation campaign. It does not select
a backup provider, approve costs, authorize restoration or approve human reviews.

This proposal adapts cadence and retention to that campaign. It keeps the
[provisioning design's](2026-10-18-upstream-1.1.0-devbox-provisioning-proposal.md)
coordinated state boundary and [S6](2026-10-18-upstream-1.1.0-staging-acceptance.md)
credential-cut requirements. It supplies no second restore script or weakened
acceptance path. The [PR evidence checklist](2026-10-18-upstream-pr-validation-checklist.md)
uses its evidence as E-RECOVER only after actual execution.

## 1. Recommended parameters for owner approval

All values below remain **PROPOSED**. Existing accepted resource ceilings and the
user's role do not need reconfirmation.

| ID | Proposed campaign value | Acceptance / consequence |
| --- | --- | --- |
| RC01 — Destination | Dedicated encrypted restic repository on independent S3-compatible storage outside devbox; reuse an appropriate existing account if the owner selects one | Owner supplies provider/region, cost ceiling, scoped account and private destination record. No signup, paid service or credential creation is authorized. Same-disk copies are useful preservation, not independent recovery. |
| RC02 — Custody/alerts | User coordinates separate repository credentials, recovery password custody outside devbox and a private failure notification channel | Record sanitized references and who can recover when devbox is unavailable. No secret in Git/chat/logs/argv. Alert recipient/channel is still unspecified; no message is sent now. |
| RC03 — Cadence | Checkpoint at the verified native S1 baseline before S2, before each destructive/global-state test, and at session end if state changed. During active multi-session work retain 00:00/12:00 UTC checkpoints, serialized with tests/cron | Every destructive operation needs its own verified pre-change checkpoint even if a scheduled one is recent. Never back up live mutable state with an uncoordinated timer. S6 additionally needs its exact credential-bearing pre-cut checkpoint. |
| RC04 — Pauses/resumption | Close ingress and quiesce writers during pauses. Record whether the campaign is paused and the latest verified checkpoint; suspend periodic work only after a verified end-of-session checkpoint and writers remain stopped | Resume with integrity/availability checks and a fresh coordinated checkpoint before new acceptance writes if the prior verified recovery point is at least 24 h old. If integrity or continued quiescence is uncertain, block resumption for diagnosis; a new checkpoint cannot certify unexplained state. A declared, quiescent pause is distinct from a missed active-campaign job. |
| RC05 — RPO/RTO | Retain proposed RPO 24 h for routine fixture changes and zero loss of the verified baseline for a planned destructive step; RTO 90 min for the bounded primary recovery | Measure using the existing proposal's definitions. Alert on failed/missed active jobs or recovery-point age over 18 h; block new test writes at 24 h. These are unproven targets, not promises. |
| RC06 — Retention | Keep all campaign checkpoints needed for outstanding tests, plus the native baseline, each destructive-step pre-change checkpoint and the final technically verified recovery checkpoint (human acceptance remains separate). Proposed review buffer: 7 calendar days after the owner records campaign closure | Replaces the proposed 7 daily/4 weekly/2 monthly buckets for this campaign only if accepted. No wait for calendar buckets to fill. The buffer triggers a disposition review, not automatic expiry/pruning; unresolved findings can require longer retention within an approved budget. |
| RC07 — Resource/disposition | Preserve existing 70 GiB working budget, 50 GiB reserve and 12 GiB combined spool/cache ceiling; backup with peer services stopped; restore primary only within its 20 GiB allowance | Stop on capacity risk; do not prune old evidence or other projects automatically to fit. Peer restore needs separate capacity review. Exact snapshot/volume cleanup and evidence retention need later explicit approval. |

A shorter campaign reduces the number of checkpoints and running hours; it does
not remove consistency or independent-copy requirements. If no independent storage
is available, continue permitted local documentation/PR preparation, report recovery
as blocked, and do not claim S0 closure, start S1 under the full plan or perform a
destructive staging exercise on the strength of a same-disk copy.

## 2. Checkpoint sequence and evidence

The following is a planning checklist, not an execution record. Use the existing
recipe's coordinated backup procedure once a new reviewed revision and exact
operations are authorized; it remains the command authority.

- [ ] **CP-NATIVE:** In S1, after native fixtures/access/logging are verified and before
  installing MCP, capture the native baseline. Record absence of MCP credentials
  as expected for the fresh origin. Verify its independent copy before S2.
- [ ] **CP-INSTALLED:** After S2 installs the exact candidate and verifies state,
  capture its matched app/config/data/database baseline before destructive scenarios.
- [ ] **CP-PRECHANGE:** Before each policy-destructive/global test, capture the current
  known-good state and fixture expectations. Record scenario ID and reset strategy;
  a previous test's mutated state cannot silently become the next baseline.
- [ ] **CP-RECOVERY:** Follow S6's prescribed credential issuance, pre-cut checkpoint,
  revocation and restoration order. It must demonstrably predate the tested cuts;
  CP-NATIVE/CP-INSTALLED alone cannot prove restored credential revocation.
- [ ] **CP-CLOSE:** At a changed-state session end and at campaign close, checkpoint
  the documented state behind closed ingress. Do not label intentionally revoked
  or failed fixtures a clean baseline; preserve their scenario state explicitly.

Each checkpoint coordinates matching application files/config/custom apps/data,
a logical MariaDB dump, LDAP config/data, external-mount fixture content and private
deployment state as specified in the provisioning proposal. Pause cron/background
writers and test traffic, use the operator lock, and stop LDAP after quiescing its
mutations before copying its raw config/data volumes.
No live raw database-volume backup, mixed-time archive or writable backup mount of
live source data. Exclude ephemeral caches from durable-state assumptions and reset
them on restore. Independent peer state must also be coordinated when its test
coverage requires it. Quiesce both instances, produce logical dumps while their
DB containers are running within their existing limits, then stop peer services
before starting the backup worker. Record paired snapshot IDs and keep both sides
quiescent through verification; do not attempt a logical dump from a stopped DB.

Record checkpoint ID, source/package/image identities, quiescence/recovery-point
and verification times, fixture expectation inventory, sanitized remote snapshot
reference, complete scope and success/failure. A successful upload alone is not a
verified restore. Before releasing lock/maintenance or allowing a destructive
step, run the pinned restic equivalent of `check --read-data` against the independent
repository and record success. Metadata-only checks are insufficient. Include data
verification in duration/resource accounting; failure leaves the change blocked.
S1 reads back its native baseline; S6 subsequently restores the selected checkpoint
and demonstrates application recovery. A local spool is not a completed remote checkpoint.
Never store credential values, database dumps or raw logs in the evidence document.

## 3. Restore proof and failure handling

- [ ] Authorize exact source checkpoint/replacement volumes and recovery window.
  Keep originals stopped and intact; the candidate identity and matched state are
  frozen. Do not run an original and restored primary concurrently to bypass capacity.
- [ ] Close/revoke public access, drain in-flight requests and independently prove
  that private administration remains usable. Maintenance mode alone is not the
  barrier for private tests that need maintenance lifted.
- [ ] Restore coordinated state using the existing S6/recipe procedure. Restore
  LDAP/external fixture state, restart caches/workers as prescribed, and verify
  native content/ACL expectations and the installed candidate before testing MCP.
- [ ] Apply supported credential-cut/disconnect controls and native app-password
  revocation before public reopening. Test old and fresh controls on the same
  reachable private path; failure caused by maintenance/firewall/expiry is not
  proof of revocation. Follow S6's exact handling of expired 300-second codes and
  unexpired restored-lineage controls; never alter clocks, rows or lifetimes.
- [ ] Measure RPO against the checkpoint recovery point, not upload completion.
  Measure RTO from declared exercise start until integrity, old-credential refusal,
  fresh reconnection and scope checks pass privately. Public reopening is separate.
- [ ] Finish with MCP/test access closed and evidence retained. Failure keeps the
  failed state isolated; record diagnosis/retest and do not overwrite originals,
  suppress errors, delete evidence or claim the objective was met.

The evidence proves only the recorded database/topology and bounded primary dataset.
It does not certify peer disaster recovery, provider durability, human review,
production recovery or a later PR candidate with changed credential semantics.
The prior SQLite rehearsal cannot substitute for this MariaDB/operator procedure.

## 4. Decisions still open and implementation handoff

The user only needs to decide the new campaign parameters, not reaccept ownership
or resource ceilings: RC01/RC02 provider, costs and custody; RC03/RC04 cadence and
pause/resume rules; RC05 targets/window; RC06 retention/buffer; RC07 disposition
policy. Approving these design values still does not authorize executing recovery.

After acceptance, record the decision IDs in the S0 report, update the proposed
cadence/retention references and prepare a new hashed external recipe revision.
The existing r3 archive/lock remains unchanged and historical; it must not acquire
false approval through documentation edits. Coordinate scheduling, alert delivery,
locks, checkpoint markers and resume behavior before activating any timer.

Human security/operations evidence review and final verdicts remain pending. The
user's operational role permits future authorized assistance, not an automatic
H-OPS signature. No environment, backup account, timer, image pull or restoration
is created by this proposal; no push or PR publication is performed.
