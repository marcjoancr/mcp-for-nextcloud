<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# M2: remaining core and database acceptance

Date: 2026-10-30. **M2 complete: eight additional native cells, 10/15 total with
[M1](fork-platform-m1.md).** M3 was not started. Public HTTPS, Inspector end-to-end,
hosted ChatGPT and all human reviewers remain pending. No production-server
operation, push, deployment or PR #14 change occurred.

Subsequent [M3 acceptance](fork-platform-m3.md) brings the same candidate to
15/15 native cells. The M2 results below remain the historical batch record.

## Identity and execution order

The [approved plan](fork-platform-source-acceptance-plan.md#m2--remaining-core-and-database-profiles)
was executed sequentially. Each unfiltered suite, installed-file comparison,
expected-skip check and independent cleanup passed before the next cell began.
No application or runner code changed for these runs.

- Candidate: `efb27979b6a90122ce45a6a509e52390030f7ed9`, app `mcp` version `2.0.0`.
- Trusted manifest SHA-256: `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.
- Runtime archive SHA-256: `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297`.
- Runner: `88d8696947d5c3101bf1e062227c7c367c2b000a`; clean when all eight runs staged their inputs.
- Host verification runtime: Node 24.22.1 / npm 11.19.1, PHP 8.4.27 / Composer 2.8.8.

Every command used `composer --working-dir=mcp test:integration` with the existing
`--candidate-kit` directory and trusted manifest digest. The four `upgrade`
commands additionally used the reviewed `--baseline-package` archive and digest.
Each installed candidate matched all **975 runtime files** in the same immutable
kit; none fell back to rebuilding the moving checkout. The exact commands, plans,
image pins and runner-file hashes are retained in the evidence.

## Native results

All eight commands and harness results exited **0**, with no JUnit failures or
errors: **2,400 cases, 37,303 assertions and 64 expected conditional skips**.
The case count includes the skipped cases; they are not claimed as executed checks.

| Order | Profile / mode | Cases | Assertions | Skips | Run ID |
| ---: | --- | ---: | ---: | ---: | --- |
| 1 | NC35 / PostgreSQL / fresh | 300 | 5,052 | 8 | `mcp-it-20261030010311-4b9450f9ddf0` |
| 2 | NC35 / PostgreSQL / upgrade | 300 | 5,256 | 2 | `mcp-it-20261030011348-fdc84d8cbfa7` |
| 3 | NC35 / SQLite / fresh | 300 | 4,396 | 14 | `mcp-it-20261030012351-5bb04d8e44d4` |
| 4 | NC35 / SQLite / upgrade | 300 | 4,599 | 8 | `mcp-it-20261030012958-e1369d400173` |
| 5 | NC34 / MariaDB / fresh | 300 | 4,435 | 11 | `mcp-it-20261030013637-2f86fdcaa016` |
| 6 | NC34 / MariaDB / upgrade | 300 | 4,638 | 5 | `mcp-it-20261030014433-9197ca4772c4` |
| 7 | NC33 / MariaDB / fresh | 300 | 4,362 | 11 | `mcp-it-20261030015214-315df3f9d200` |
| 8 | NC33 / MariaDB / upgrade | 300 | 4,565 | 5 | `mcp-it-20261030015834-bf134055c2bf` |

Both modes in each row below reported these actual installed versions. Contacts
and Deck matched the expected version and archive hash; **Mail was not selected
in any M2 cell**. Mail acceptance remains the separate M1/M3 coverage in the plan.

| Profile | Nextcloud | PHP | Database | Contacts | Deck |
| --- | --- | --- | --- | --- | --- |
| NC35 / PostgreSQL | 35.0.2 | 8.5.12 | PostgreSQL 16.15 | 8.9.2 | 1.19.0 |
| NC35 / SQLite | 35.0.2 | 8.5.12 | SQLite 3.46.1 | 8.9.2 | 1.19.0 |
| NC34 / MariaDB | 34.0.5 | 8.5.12 | MariaDB 10.11.19 | 8.9.2 | 1.18.5 |
| NC33 / MariaDB | 33.0.10 | 8.4.27 | MariaDB 10.11.19 | 8.9.2 | 1.17.7 |

## Conditional coverage and compatibility limits

The exact skipped test identities are retained in each cell's `summary.json` and
JUnit report. The independently checked skip groups are:

- Two Mail cases in every M2 cell, because Mail was deliberately not selected.
- Six legacy cases only in fresh installs: one native-client migration, four
  upgrade snapshots and one legacy-credential case. **All six actually ran in
  every upgrade cell.**
- Three PostgreSQL query-log cases on non-PostgreSQL cells. **All three actually
  ran on both PostgreSQL cells.**
- Three concurrency cases only on SQLite: two real-deadlock datasets and the
  group-grant pre-event race. Those cases ran on PostgreSQL and MariaDB.

SQLite acceptance does not establish production database/concurrency support.
The non-SQLite runs supply that separate concurrency coverage.

**Deck dependencies are unavailable in the NC33/Deck 1.17.7 profile.** Its tests
assert the absent native API, hidden dependency tools and refusal of direct calls;
they do not exercise native dependency reads/writes. These are conditional test
branches, not JUnit skips. The six affected cases account for the 73 fewer
assertions per NC33 mode compared with NC34. NC34/35 execute the native dependency
behavior, including visibility and write safeguards. Passing NC33 therefore does
not claim this optional feature exists there.

## Upgrade provenance and coordinated recovery

Every M2 `upgrade` starts from **1.0.8**, commit
`a1a7911cc72dcf2487d17e06e23edfa89b0ecf92`; it is distinct from M1's upstream 1.2.0
origin. The approved baseline archive SHA-256 is
`7371124da35accd35061e18496169b5c6cb1c686d2395d05f297e3ef55a5115b`.

The harness preserves the installed baseline at `/tmp/mcp-previous` before
replacing it. An external observer copied that preserved tree from each owned
upgrade container before cleanup. In all four runs, all **352 regular files**
matched the reviewed 1.0.8 archive exactly, including version metadata. Each
`baseline-inventory.json` retains the measured file hashes and sizes.

The NC35/SQLite/fresh cell additionally passed the runner's coordinated recovery
rehearsal: app/database backup and restore, asset hash verification, preservation
of restrictions, rejection of old access tokens, refresh tokens and authorization
codes, and successful explicit reconnection. The MCP service was left closed.
The other seven cells explicitly defer this rehearsal to that selected cell;
they are not counted as seven more recovery tests. This does not qualify
LinuxServer, the intended host, an S3 backend or production backups.

## Cleanup and retained evidence

No host ports were published. An external observer recorded full container and
network IDs plus their mounted Docker volume names, without saving container
environments. Every cell passed independent all-state resource checks before
advancing. A final live query confirmed all **14 containers, eight networks and
14 volumes** absent, by exact IDs/names and run labels. No broad pruning was used.

The observer now records full IDs. M2 preflight also rechecked the retained M1
abbreviated IDs by prefix against full daemon IDs, plus its volume names; none
remained. That additional proof is `preflight-m1-cleanup.json`. Historical M1
observations and sealed evidence were preserved unchanged.

The ignored local evidence root is `mcp/build/m2/`; native run roots remain under
`mcp/build/integration/`. The repository-relative `evidence-index.json` binds
**154 records** and has SHA-256
`c9b22550b42a4145741a336aa3aeb07eee4191e1e0e0151b30270a4687bd4f6f`.
It includes plans, installed-candidate inventories, JUnit/runtime reports,
selected stage logs, baseline inventories, per-cell gates and cleanup observations.
`closure.json` records the final live cleanup, optional-feature coverage and
successful revalidation of all 68 M0, 38 M0-review and 46 M1 indexed files.
The trusted manifest and runtime archive hashes also remain unchanged.

Private credentials, raw logs, database state, generated clones and source kits
are not an approved publication bundle. Documentation/package checks are retained
separately from the sealed acceptance index and do not replace the accepted kit.

## Closure validation

Documentation and package validation pass: **185 Markdown files and 115 package
sources**, with all 100 translations still marked `review-required`. The
working-tree package is a dirty development build with its source offer disabled;
it does not replace the frozen acceptance archive. The canonical `verify:source`
command also passes against the existing kit and its independently trusted digest.
Logs are retained as `package-validation.log` and `source-integrity-validation.log`
outside the sealed acceptance index.

A read-only automated review found no actionable inconsistency in the five
changed documents or the 154 indexed records, inventories, JUnit totals, skip
sets and recovery evidence. It assessed retained cleanup proof without rerunning
live Docker queries; the successful live checks above were performed by the
primary session. This review is not human acceptance. Final packaging/document
validation is retained separately as `package-final.log`.

## Review before commit (2026-10-30)

A fresh review rechecked all 154 sealed evidence records, the eight JUnit reports,
expected skip sets, installed candidate inventories and four preserved baseline
trees. Their counts and hashes agree with this report; no application or runner
correction was required. Successful live Docker queries again confirmed the
absence of the exact 14 containers, eight networks, 14 volume names and run labels.
The original evidence was not rewritten and the eight native suites were not
repeated for this documentation-only review.

The additional proof is retained as `mcp/build/m2-review/verification.json`, with
SHA-256 `1949494bc54ee7c1b5c712a79b20b700fd3013d461c8ba229596fab43d2bbcc3`. Fresh package/documentation and immutable-source verification
logs are retained alongside it as `package.log` and `source-integrity.log`.
M3 and all external/human acceptance gates below remain pending.

## Remaining work

There is no unresolved M2 technical failure. **M3 is the next bounded batch**:
the five remaining NC35/MariaDB upgrade origins, with Mail selected for
`upgrade-local`. It was not started here. M4 still reconciles the complete matrix,
LinuxServer's previously tested U5 kit versus this successor, source publication
and release-history/attribution review. The current acceptance is **10/15**, not
full platform or intended-host acceptance.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT, human security,
operations/recovery, license/attribution, accessibility and language reviews all
remain pending; the 100 translations still require human review. MinicoLab
host/S3/recovery qualification and final immutable source publication are separate
gates. No production server was accessed or changed.
