<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# M4: evidence reconciliation and remaining acceptance gates

Subsequent status: [L0 input renewal](fork-linuxserver-l0-current-candidate.md)
now binds LinuxServer planning to this candidate. Native LinuxServer acceptance
and the external/human gates below remain pending.

Date: 2026-10-30. **Local M4 reconciliation is complete; F3 and release acceptance
remain open.** This phase reverified retained evidence and live resource absence.
It did not repeat native suites, reconstruct the package again, qualify a new
LinuxServer fixture, publish sources, access production, push, deploy or modify
PR #14. HTTPS, Inspector end-to-end, hosted ChatGPT and human reviewers remain
pending.

## Accepted candidate and coverage

The [plan](fork-platform-source-acceptance-plan.md#m4--reconcile-evidence-and-close-only-the-accepted-scope)
requires one immutable kit. All 15 declared native cells and the separate source
offer fixture bind to the following app `mcp` version `2.0.0`:

- Source: `efb27979b6a90122ce45a6a509e52390030f7ed9`.
- Trusted manifest SHA-256: `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.
- Runtime archive SHA-256: `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297`.
- Installed inventory: **975 files in each of 16 fixtures**, matching the kit.

[M1](fork-platform-m1.md), [M2](fork-platform-m2.md) and [M3](fork-platform-m3.md)
together report **4,500 cases, 68,658 assertions, 131 expected conditional skips
and zero failures/errors**. Cases include skips: 4,369 were not skipped. M4
compared the exact declared matrix with the retained results, without missing,
extra or duplicate cells.

| Profile | Accepted cells | Scope |
| --- | ---: | --- |
| NC33 / MariaDB | 2 | Fresh and 1.0.8 upgrade |
| NC34 / MariaDB | 2 | Fresh and 1.0.8 upgrade |
| NC35 / MariaDB | 7 | Fresh and all six declared upgrade origins |
| NC35 / PostgreSQL | 2 | Fresh and 1.0.8 upgrade |
| NC35 / SQLite | 2 | Fresh and 1.0.8 upgrade; local test profile |

Measured core versions remain NC33.0.10/PHP8.4.27, NC34.0.5/PHP8.5.12 and
NC35.0.2/PHP8.5.12; database versions are MariaDB10.11.19, PostgreSQL16.15 and
SQLite3.46.1. Runner revisions differ between batches, but their recorded runner
file hashes agree with each other and the current tracked runner files. Runner
identity is separate from candidate identity; no moving checkout replaced the kit.

These results preserve the original coverage boundaries:

- Mail5.12.5/Dovecot2.4.5 ran only in NC35/MariaDB fresh and fork-1.1.2 upgrade.
- NC33/Deck1.17.7 lacks the native dependency API. Executed assertions establish
  hiding/refusal there, not dependency-feature parity. Those branches are not
  reported as JUnit skips.
- PostgreSQL query-log checks ran in its two cells. Non-SQLite concurrency checks
  ran on MariaDB/PostgreSQL; SQLite's three concurrency cases remain skipped.
- Six legacy migration cases ran only for the 1.0.8 origin; other origins have
  their separate before/after replacement-continuity probes.
- Coordinated recovery belongs to NC35/SQLite/fresh: old credentials were rejected,
  reconnection worked and service remained closed after recovery. This is not
  LinuxServer, S3 or production-backup acceptance.

## Source offer and reconstruction

The separate M1 fixture passed **124 checks**: non-admin personal settings and MCP
discovery, Basic/Bearer boundaries, disabled-MCP behavior, invalid metadata denial,
anonymous retrieval of all five kit files and tamper rejection. The source host
was HTTP loopback inside the disposable container; no host port was published.
It does not establish a public HTTPS destination or acceptance by a hosted client.

M4 rehashed the five retained downloads, both extracted runtime trees and the
73 dependency files. They still match M1's reconstruction from the downloaded
bundle. The rebuilt archive SHA-256 was
`e536ac032e7b81bb5c2726c23a155d5647b2e081267d6ab405566bd1250c0995`;
compressed bytes differ from the accepted archive because of archive metadata,
while all extracted runtime files match. This is file-content reproducibility,
not byte-identical compression or a fully offline build. Locked package registries
and caches remain build inputs. M4 performed no new reconstruction.

The canonical `verify:source` command also passes against the frozen kit and its
trusted digest using Node24.22.1/npm11.19.1. This closes local source-offer,
retrieval, integrity and reconstruction checks, not final source publication or
human license/history review.

## LinuxServer: historical acceptance remains separate

The [L0-L5 campaign](fork-linuxserver-l5.md) qualified U5
`940c3da36f0d9c5310a98d7c3f355d2d99c86d33`, with manifest SHA-256
`2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63`.
Its retained evidence is in the same project's `upstream-121-reconciliation`
worktree, not this worktree. The initial lookup here found no L5 index; resolving
that location required no recreation, relocation or modification of old evidence.

M4 checked all **13 indexed L5 records and 45 historical run-result hashes**.
Those results contain **32 failed and 13 passed attempts**, including diagnostics
and superseded attempts; they are not 13 accepted phases. The accepted L1-L4
executions remain the ones identified in L5: baseline qualification, B0 capture,
upgrade to U5/B1 and coordinated R1/R0 recovery. L5's checkpoint/private-input
verification remains historical. M4 did **not** rehash multi-gigabyte checkpoint
payloads or rehearse restoration; their full contract must be reverified before
reuse. Earlier failures and the recorded L5 retention cleanup remain visible.

Both U5 and the current kit contain 975 runtime files. M4 verified the old kit
and compared the actual archive inventories: exactly three paths differ, with
none added or removed:

| Path | Difference |
| --- | --- |
| `appinfo/source.json` | Candidate source commit |
| `composer.json` | Description of the LinuxServer test command |
| `vendor/composer/installed.php` | Root package version/reference derived from the source commit |

This narrows the qualification delta; it does not turn U5 evidence into acceptance
of the final artifact. **Current-candidate LinuxServer qualification remains
pending.** The Apache matrix does not establish Nginx/PHP-FPM, S3 or intended-host
compatibility.

## Cleanup, evidence integrity and retention

Successful fresh all-state Docker queries confirmed absence of all exact-owned
resources from the source fixture and 15 native cells: **31 containers,
16 networks and 31 volumes**. Queries also found no resources carrying the
historical LinuxServer run label. M4 created or removed no Docker resources and
used no broad pruning.

The M0, M0-review, M1, M2 and M3 indexes still match all **414 indexed records**
(not necessarily 414 distinct files). The M3 progress-reader diagnostic remains
recorded separately from native success. The historical L5 index retains SHA-256
`749b17343f990dc7dd6b87d551802385295fac0566610247bc36d7b7e6a5c7e1`.

New private evidence is under `mcp/build/m4/`. Its `evidence-index.json` binds five
records: the allowlisted reconciliation summary, cleanup observations, private
historical-location provenance, dependency check and source-integrity log. Index
SHA-256: `a1d2d233759a3c9aaeab525d95fb1c7186bdf94c62c18a2e3201d6170d4bcb82`.
Old indexes and artifacts were not rewritten. Validation/review logs are retained
separately, outside this sealed index.

The repository owner remains retention owner. Stat checks confirmed mode `0700`
on the 16 native/source run roots, both candidate kit directories, the rebuild
directory and 45 historical LinuxServer run roots. New M4 files are private
(`0600` under `0700`). Retain accepted synthetic evidence and original diagnostics
for F3, intended-host comparison and independent human review; revisit disposal
at the next release/intended-host acceptance decision. There is no automatic
30-day deletion policy or newly authorized cleanup.

The reconciliation summary exports only explicit evidence fields. It contains no
credentials, database contents, raw logs or host-private input paths. This is not
a complete redaction audit of every raw artifact. Kits, private inputs, source
history and raw evidence are **not an approved public evidence bundle**; keep
historical-location provenance private and review each publication separately.

## Closure validation

Fresh `composer --working-dir=mcp package` passes: **187 Markdown files and
115 package sources**, with all 100 translations still marked `review-required`.
The working-tree archive is a dirty development build with its source offer
disabled; it does not replace the immutable acceptance kit. The canonical source
verification also passes for that kit and the trusted digest above. No application
or runner code changed, so M4 did not repeat the native suites.

A fresh-context automated review found no actionable findings in the six scoped
documents. It independently rechecked the 414 prior indexed records, five M4
records, 13 L5 records, 45 historical result hashes, 15 JUnit totals, all 16
installed inventories and the three-path runtime delta. It also checked recorded
cleanup aggregation and run-root permissions. It did not query live Docker,
repeat suites/builds/reconstruction, rehash checkpoint payloads, audit every raw
artifact for publication or perform external/human acceptance. Those limits do
not replace the primary session's fresh cleanup checks or close the open gates.
Final package/source validation is retained outside the sealed index.

## Review before commit (2026-10-30)

The documentation review reverified the original five M4 records, all 414 prior
indexed records, 13 historical L5 records and 45 run-result hashes. All 15 native
results, 16 installed inventories, downloaded-source trees, 73 dependency files
and the exact three-path runtime delta still agree with this report. Fresh
successful Docker queries again confirmed the absence of the 31 containers,
16 networks, 31 volumes and historical LinuxServer-labelled resources.

No application or runner correction was required. Original evidence was not
rewritten, native suites and restoration were not repeated, and L0 was not
renewed. The additional proof is retained as
`mcp/build/m4-review/verification.json`, with SHA-256
`6b398c8bed88ee0d3c28b4cb4f35106da11f13219757bbe960abfbffc121cce2`.
Package/documentation and immutable-source validation logs are retained alongside
it. Current-kit LinuxServer qualification, F3, HTTPS, Inspector end-to-end,
ChatGPT, source publication/history review, intended-host acceptance and all
human reviewers remain pending. This review does not supply human acceptance.

## What closes and what remains open

M0 preparation/control checks, M1 ordinary-user source checks and reconstruction,
M1-M3's exact 15-cell matrix, and M4 reconciliation/owned-resource cleanup can
close for the frozen kit and recorded pins. No unresolved native test failure is
hidden by this closure. Image/toolchain maintenance observations remain dated;
new advisories or patches require review before another campaign.

| Open gate | Required evidence or decision |
| --- | --- |
| Current-kit LinuxServer | Renew L0 profile/kit binding, then qualify the necessary upgrade, source offer and recovery under its existing plan; preserve old checkpoints |
| Intended MinicoLab host | Separate acceptance of actual Nginx/PHP-FPM, database/S3, update control, integrity and recovery; no production operation is authorized here |
| Release history and attribution | Review distributable source history, notices and third-party attribution before publication; automated integrity is not human license approval |
| Final immutable sources | Approve destination and trusted digest channel, publish all five kit files, verify anonymous retrieval and deployed-build binding |
| HTTPS | Approved public TLS route and OAuth/reachability checks |
| Inspector | Refresh the end-to-end private/public OAuth journey; encrypted-provider component tests alone do not close this gate |
| Hosted ChatGPT | Actual hosted-client connection, discovery, authorized calls and reconnect/revocation checks |
| Human reviewers | Security, operation/recovery, license/attribution, accessibility and language acceptance; all 100 translations remain `review-required` |

The next bounded technical step is **renewing LinuxServer L0 for this frozen
candidate**: review the profile/pins and run its planning/input controls, without
bootstrapping a fixture or changing production. Only subsequent authorized
qualification can close the current-kit LinuxServer gate. Keep the retained U5
B0/B1 identity intact; do not relabel old checkpoints as the new candidate.
F3 and release readiness remain open until their applicable gates are satisfied.
