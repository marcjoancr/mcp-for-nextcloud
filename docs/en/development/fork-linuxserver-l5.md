<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L5 local evidence and regression closure

Subsequent status: [L5 for the renewed profile](fork-linuxserver-l5-current-candidate.md)
now closes scoped local evidence, regression and retention acceptance. External
and human gates remain open; the phase-end statements below retain their scope.
This historical report continues to qualify U5 only. Its retained evidence remains
in the same project's `upstream-121-reconciliation` worktree; see the
[M4 evidence reconciliation](fork-platform-m4.md#linuxserver-historical-acceptance-remains-separate).

This record follows the [L5 plan](fork-linuxserver-upgrade-recovery-plan.md#l5--review-evidence-validate-and-clean-up)
on 2026-10-29, starting at harness revision `b664495f`. It covers evidence review,
private-artifact retention, cleanup and the relevant existing Apache regressions.
It does not deploy to the intended server, publish a branch or alter PR #14.
Public HTTPS, Inspector, hosted ChatGPT and human review remain pending.

## Candidate and evidence boundaries

The LinuxServer candidate remains U5 commit
`940c3da36f0d9c5310a98d7c3f355d2d99c86d33`, version 2.0.0. Its source-kit manifest
SHA-256 remains `2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63`;
the profile SHA-256 remains
`743f2704669f259cc97810771a5bea58722871f9a13962bb5beae33b54d4cc63`.
A fresh `--plan` verifies the complete retained kit and profile without creating
resources. L5 is an audit phase, not another fixture bootstrap: `--phase L5`
continues to be rejected by the runtime CLI.

The accepted LinuxServer executions are not rerun or relabelled in this phase.
Their recorded exit states, trusted manifests, every checkpoint payload and bound
private input are verified again before and after retention work. L2's older
capture schema is verified with its own checkpoint/private-input contract; it is
not silently promoted to an L3/L4 restore input. An initial audit attempt using
the later restore validator rejected the absent L2 version/path fields. The
one-off audit was corrected; no historical manifest or runtime validator changed.

| Phase | Accepted execution and evidence | Qualified outcome |
| --- | --- | --- |
| L0 | [U5 renewal](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27), fresh kit/profile verification | Immutable baseline/candidate inputs and planning controls |
| L1 | [Baseline qualification](fork-linuxserver-l1.md), `mcp-l1-a82b94f4-b6ae-490d-938f-3bfa69c7f62c` | 26 checks, 1.2.0 baseline, closed admission and owned cleanup |
| L2 | [Accepted closure capture](fork-linuxserver-l2.md#accepted-closure-capture), `mcp-l2-387f06b3-f62d-4f31-93fb-86908e790bf6` | Baseline fixtures and coherent B0; no upgrade/recovery claim |
| L3 | [Isolated upgrade](fork-linuxserver-l3.md), `mcp-l3-140d56d2-a971-4e2b-a587-d5523a106f77` | 1.2.0 → 2.0.0, 55 native tests / 1,144 assertions, recreation and B1 |
| L4 | [Recovery](fork-linuxserver-l4.md), `mcp-l4-ee13f47c-50d2-4648-922d-0a64c865bb34` | 72 stages, 273 HTTP checks, R1 → 2.0.0 and both R0 stops → 1.2.0 |
| L5 | This record | Validation and final cleanup results are recorded below |

The before/after verification records are byte-identical, SHA-256
`d32de90992e87df4a9d93b54091f333b23c83eb8309eff52dd950dca805b6129`.
They cover eight checkpoint copies: L2 B0, L3 B0/B1, and L4 original B0/B1 plus
its three independently captured recovery cases. Their trusted generation/digest
pairs remain exactly those in the linked phase reports. Credential expiry today
does not invalidate the recorded live-window checks, but prevents treating these
old credentials as a fresh revocation experiment.

## Permission correction and retention

Docker copies can preserve source permissions despite a restrictive host umask.
L3/L4 recorded archive files with mode `0644` inside private `0700` directories.
The checkpoint sealer now normalizes regular payloads to `0600` and directories
to `0700` before publishing a manifest. It still refuses special files and links;
verification alone does not change permissions or bytes. A filesystem regression
test failed against the old implementation and passes with the correction. All
42 LinuxServer Node controls pass, including ownership mismatch, inspection and
removal errors, interrupted status, diagnostic-failure cleanup and archive guards.
No application code or frozen source-kit identity changed.

A separate retained-evidence pass inspected all 45 completed LinuxServer runs.
The exact cleanup preview was recorded before mutation. It removed 64 obsolete
paths containing **39,362,274,284 logical bytes**: failed/superseded checkpoint
payloads, redundant private oracles and the remaining working files from an early
L1 attempt. This is a byte inventory, not a claim about physical filesystem space
reclaimed. Accepted checkpoints, their private inputs and all historical result
files were preserved. The 1,581 retained files and 72 directories are restricted
to `0600` and `0700`; 1,330 entries needed a mode change. All result-file hashes
and accepted payload/private-input hashes remained unchanged.

Earlier phase statements about retained diagnostic checkpoint payloads describe
the state at their publication. This L5 retention record supersedes those
statements: failed/superseded payloads have now been removed; their results,
resource journals, available diagnostics and trust references remain. A retained
trust reference for a removed payload is historical evidence, not a usable backup.
The accepted L2/L3/L4 payloads remain available for local review.

The early run `mcp-l1-9cef5677-69b2-4a27-900a-51c8b2a03012` still records an
incomplete network cleanup. L5 does not rewrite that failure. Fresh successful
Docker queries found no LinuxServer-labelled containers, networks or volumes,
including that run; only its obsolete local working copies required removal.
No broad prune, unrelated fixture removal or worktree cleanup was performed.

Retention is owned by the repository owner, who already accepted operation and
recovery responsibility. Keep the accepted synthetic backups and bound private
inputs for the outstanding F3/intended-host comparison and independent human
review. The closure review retains them for that purpose; revisit
disposal at the next F3 release or intended-host acceptance decision. No
30-day campaign or unattended deletion schedule is introduced. Keep sanitized
reports and digests in Git. The raw evidence remains in ignored run-specific output beneath `mcp/build/`, and the L5 audit uses the same output convention. Nothing under `build/` is staged or published.

## Regression validation

The relevant existing Apache cells are run without filters or version overrides:

```sh
composer --working-dir=mcp test:integration -- 35 mariadb upgrade-upstream-120
composer --working-dir=mcp test:integration -- 35 sqlite fresh
devbox run check --cwd ABSOLUTE_WORKTREE --json
```

These cells use the reference matrix's Nextcloud 35.0.1 Apache image; the upgrade
cell uses MariaDB 10.11.19. They are separate from LinuxServer 35.0.2/PostgreSQL/
SeaweedFS and cannot replace its native assertions. The SQLite cell owns the
existing coordinated recovery scenario. The runner creates unique owned
resources with no published ports. Exact container and anonymous-volume IDs were
captured before cleanup so final absence can be checked independently.

The 11 conditional skips in the upgrade cell are six tests belonging to the older
`upgrade` baseline mode, two opt-in Mail tests and three PostgreSQL log tests.
SQLite additionally skips three cross-session/deadlock cases whose locking
requirements do not apply to SQLite. Their results remain skips, not passes;
Mail and the complete database/platform matrix are not requalified by these cells.

The Apache candidate is a development package from the current branch. Its
application source is unchanged from U5; subsequent differences are test/harness
code and Composer script descriptions. It is not the exact frozen U5 release
archive, a new source-offer qualification or a full supported-platform matrix.

The first two native attempts failed honestly: Apache's recursive PHPUnit suite
also discovered the three LinuxServer-only tests under `ci/linuxserver/`. They
require the LinuxServer private bootstrap, seeded users and DAV oracle. The
MariaDB attempt `mcp-it-20261029181955-c22606557316` reported 303 tests / 4,436
assertions, two errors, one failure and 11 skips. SQLite attempt
`mcp-it-20261029182250-531125f05ddc` reported 303 tests / 4,398 assertions, the same
two errors and one failure, and 14 skips; its recovery step was not reached.
Both exit codes are 2, not accepted passes.

The Apache configuration now excludes only `ci/linuxserver/`; LinuxServer's
explicit `l3-tests.xml` still selects all three tests. This removes an invalid
cross-profile selection, not an assertion or capability. Acceptance required both complete Apache cells to pass again without filters,
as recorded below. Failed-attempt JUnit/results remain
private; their temporary credentials and working copies were removed after exact
container/network/anonymous-volume absence checks.

Both complete repeats exited **0**, with no errors or failures:

| Cell | Accepted run | Tests / assertions / skips | JUnit suite seconds |
| --- | --- | --- | --- |
| NC35 / MariaDB / `upgrade-upstream-120` | `mcp-it-20261029183246-e6454709fe43` | 300 / 4,435 / 11 | 421.262 |
| NC35 / SQLite / `fresh` | `mcp-it-20261029183246-74a4bcceae38` | 300 / 4,396 / 14 | 350.922 |

The 300 selected cases include the listed skips. JUnit suite duration excludes
provisioning, the separate recovery step and cleanup. MariaDB tests the actual
1.2.0 → 2.0.0 replacement and native capabilities; its coordinated-recovery step
is explicitly not selected. SQLite additionally passed its real app/database
backup/restore, pre-revocation state check, old access/refresh/code rejection,
controlled reconnection and final service closure. It does not establish
LinuxServer/S3 recovery; L4 supplies that separate evidence.

All four Apache attempts completed owned cleanup. Independent successful queries
confirm zero exact-owned containers/networks and absence of all six anonymous
volume IDs captured while those attempts were alive. After previewing exact
paths, raw working logs, credentials, cloned baselines, test copies and packages
were removed. Redacted artifact bytes and JUnit/result files are unchanged,
retained in run-specific ignored integration output, with `0700`
directories and `0600` files. No unrelated fixture was removed.

The full registered check passed **8,707 PHP tests / 74,469 assertions**,
**717 browser tests**, **1,390 PHP syntax checks**, both fixed PHPStan scopes,
documentation and packaging after the permission correction. The separate native
repeats validate the subsequent Apache selection correction. Documentation grew
from 177 to 178 Markdown files while this record was added; package documentation
remains 115 sources. Final documentation/package checks are recorded separately in
`final-docs.log` and `final-package.log` under the L5 audit directory. Translation
provenance remains valid, with **100 translations review-required**; this is not language approval.

Packaging retains the explicit expected warning
`Dirty development build: source offer unavailable.` The development package is
not the qualified frozen source offer or a release publication. L0 kit verification
and L4's restored-source delivery remain the distinct evidence for frozen U5.

## Evidence index and local conclusion

The private audit index has SHA-256
`749b17343f990dc7dd6b87d551802385295fac0566610247bc36d7b7e6a5c7e1`.
It binds the before/after evidence review,
kit/profile/image checks, retention previews/results, Apache result summary,
full local check, Node controls and audit script inventory. The Apache summary
also binds each result, JUnit, plan, report, package inventory and available
recovery log. Source commands and exact pins above allow fresh isolated repeats;
retained raw private inputs must never be pasted into a public report.

The three changed runner/test/config inputs are recorded in
`runner-files.json`, SHA-256
`de1b1f3edf395e549b74730a9f118cd456072818cd3b7051d59cfef90f75c895`, on top of `b664495f`.
Documentation is excluded from this code inventory. No application code changed.
The audit scripts are retained privately with their hashes; destructive cleanup
requires its exact saved preview and cannot be replayed against a different run.

**L5's scoped local acceptance passes:** the reviewed phase evidence is intact,
required regressions pass, obsolete private working copies are removed and all
owned resources are absent. Earlier failures remain recorded. The local L5 commit closes this batch; final release and intended-host qualification
remain separate gates requiring a concrete approved scope.

## Independent automated review

One fresh-context read-only review found no critical, important or minor issues.
It independently checked the eight accepted checkpoint copies/private inputs,
45 historical result hashes, retained modes, exact previewed removals, all four
Apache artifact/JUnit records and the evidence/code/script inventories. Read-only
Docker queries confirmed all six captured Apache containers and anonymous volumes,
the associated networks and LinuxServer-labelled resources absent. It also checked
the final validation logs. This is automated technical review, not human approval.

The review intentionally did not certify a new runtime L5 command, the full support
matrix, the intended host or external clients, production recovery guarantees, or
future backup disposal. Those exclusions match the approved scope and the gates
below; treating them as passed would incorrectly close F3. No review findings
were deferred. The subsequent closure review authorizes a local commit only;
merging, publication and deployment remain outside this batch.

## Closure review before commit (2026-10-29)

The final review found no further code defect. It clarified the operational L5
boundary, replaced pending-commit wording and retained accepted checkpoints for
the still-open F3 and human-review gates. No application, runner, test or suite
selection changed after the accepted execution inventory above.

Fresh verification matched all eight checkpoint copies and private inputs, all
45 historical LinuxServer results and 90 retained Apache artifact hashes. All
1,581 retained files and 72 directories keep their required modes; the 64 removed
paths and all exact-owned resources remain absent. `review-checkpoints.json`
matches the earlier before/after record byte for byte. `review-audit.json` has
SHA-256 `0d7d7017c6269162008c0169af1b734be35d9e821de8821a9f7b22f902ea7667`.
These read-only checks repeat neither restoration nor destructive cleanup.

The 42 LinuxServer Node controls and 57 focused PHP harness/response/effect tests
(398 assertions) pass again. Logs are `review-node.log` and `review-php.log`.
The full 8,707-test/717-browser check and both accepted native Apache executions
remain evidence for the unchanged code bytes; they are not claimed as new runs.
Documentation and packaging are revalidated for this commit, with logs
`review-docs.log` and `review-package.log`. The expected development-package
source-offer warning remains explicit. No human acceptance is inferred.

## Remaining gates

- Intended-host qualification: installed bytes/migrations, effective DB target,
  FPM and startup hooks, update writers, real S3 settings and backup/restore controls.
- Final release artifact, trusted source delivery and the complete support matrix.
- Public HTTPS: DNS, trusted certificate chain, ingress and hosted-client access.
- Inspector: prior **14/15**, nonzero outcome and encrypted-storage finding remain
  unresolved; no Inspector acceptance was rerun or inferred here.
- ChatGPT: real hosted consent, tool use and revocation remain unqualified.
- Human security, operations/recovery, license/attribution, accessibility and
  language review remain pending. Automated review is not human acceptance.

L5 closes the scoped local LinuxServer campaign. It does not close F3, authorize
production recovery, promise an RPO/RTO, validate real desktop/mobile
synchronization or certify additional production apps.
