<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# M3: remaining upgrade-origin acceptance

Subsequent status: [M4 reconciliation](fork-platform-m4.md) is complete.
The phase-end statements below describe M3; external and human gates stay open.

Date: 2026-10-30. **M3 complete: five additional native cells, 15/15 declared
cells with [M1](fork-platform-m1.md) and [M2](fork-platform-m2.md).** M4 was not
started. Public HTTPS, Inspector end-to-end, hosted ChatGPT and all human reviewers
remain pending. No production-server operation, push, deployment or PR #14 change
occurred.

## Identity and execution order

The [approved plan](fork-platform-source-acceptance-plan.md#m3--remaining-upgrade-origins)
was executed in order on NC35/MariaDB. Each unfiltered suite, installed-file
comparison, expected-skip check, origin-specific continuity check and independent
cleanup passed before the next origin began. No application or runner code changed.

- Candidate: `efb27979b6a90122ce45a6a509e52390030f7ed9`, app `mcp` version `2.0.0`.
- Trusted manifest SHA-256: `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.
- Runtime archive SHA-256: `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297`.
- Runner: `b8bb3fd693323ff2d35df58a7178fc56b723bef6`; clean when all five runs staged their inputs.
- Host verification runtime: Node 24.22.1 / npm 11.19.1, PHP 8.4.27 / Composer 2.8.8.

Five preflight `--plan` commands passed against the retained kit and reviewed
baseline packages before any M3 container started. The native commands used
`composer --working-dir=mcp test:integration` with `--candidate-kit` and
`--baseline-package`, including their trusted digests. Only `upgrade-local` added
`--mail`. Exact commands and plans are retained with the evidence.

Every installed candidate matched all **975 runtime files** in the same immutable
kit. No checkout rebuild, app-version override, diagnostic filter or replacement
baseline was used. Native `occ upgrade` performed each transition to app 2.0.0;
the Nextcloud core version was unchanged.

## Native results

All five commands and harness results exited **0**, with no JUnit failures or
errors: **1,500 cases, 22,432 assertions and 47 expected conditional skips**.
The case count includes skipped cases; skips are not claimed as executed checks.

| Order | Mode | Cases | Assertions | Skips | Run ID |
| ---: | --- | ---: | ---: | ---: | --- |
| 1 | `upgrade` | 300 | 4,638 | 5 | `mcp-it-20261030101608-2b014543adc1` |
| 2 | `upgrade-local` with Mail | 300 | 4,488 | 9 | `mcp-it-20261030102444-e20768d021a3` |
| 3 | `upgrade-upstream` | 300 | 4,435 | 11 | `mcp-it-20261030103246-4040b7d6c787` |
| 4 | `upgrade-upstream-111` | 300 | 4,436 | 11 | `mcp-it-20261030104025-f5454a066c58` |
| 5 | `upgrade-upstream-112` | 300 | 4,435 | 11 | `mcp-it-20261030104759-58ffe39e06cb` |

All five runtime reports measured **Nextcloud 35.0.2, PHP 8.5.12 and MariaDB
10.11.19**, with Contacts 8.9.2 and Deck 1.19.0 matching their expected archive
hashes. The Mail cell additionally measured installed Mail **5.12.5** in
`mail.log` and used the pinned Dovecot **2.4.5** image. Its image digest and Mail
archive hash match `mail-pins.json` in the recorded runner.

Both Mail cases actually ran in `upgrade-local`: owned cached-mail access over
the authenticated MCP endpoint, and read-only tool behavior. The other four cells
deliberately omitted Mail. This supplements M1's fresh Mail cell; it does not
claim Mail coverage on every matrix profile or a real external mailbox/provider.

## Origin provenance and continuity

The five historical packages remain distinct, including the fork and upstream
packages that both identify as version 1.1.2. Their source/build provenance was
reviewed in [M0](fork-platform-m0.md); this batch reverified their catalog identity,
archive hash and installed baseline inventory.

| Mode | Version | Baseline commit | Archive SHA-256 | Installed files |
| --- | --- | --- | --- | ---: |
| `upgrade` | 1.0.8 | `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` | `7371124da35accd35061e18496169b5c6cb1c686d2395d05f297e3ef55a5115b` | 352 |
| `upgrade-local` | 1.1.2 | `14a76df26817fb8a4540eb9c9d1d0043e3e87506` | `a243104c16b3efaa0e0457a0802eeb2aba402501b774844aba33b4dd8ba0b3a3` | 864 |
| `upgrade-upstream` | 1.1.0 | `0911f9123a661968e707fc4c391adcf9d37c3e84` | `0e86c0947e9b4af7833711074489f6762216d2c2ee8f6fbb69bf6143cea6ae0e` | 440 |
| `upgrade-upstream-111` | 1.1.1 | `65e4e08b68c3f8d1ba78398a082ec567b0b0f4e4` | `6e5550fc06ec9fcc4acb6d05b775bb5af509a62b4ddadd30e3f992ff73966e0c` | 440 |
| `upgrade-upstream-112` | 1.1.2 | `27da5e107ca06d92b7af9b8a80ad9c77c12ba91e` | `846e63066d3eadd084815efe63786d23fedd99e7e019f8c8864e3e7e6dbb005c` | 441 |

Before replacement, the harness preserves the actual installed baseline at
`/tmp/mcp-previous`. The external observer copied each tree while its owned
container was still running. Every regular file matched its reviewed archive
exactly, including version metadata. The five `baseline-inventory.json` files
retain measured hashes and sizes.

For `upgrade` from 1.0.8, all six legacy JUnit cases actually ran: native-client
migration, four upgrade snapshots and preservation of legacy credentials.
For the fork 1.1.2 and the three upstream 1.1.x origins, `replacement.php` instead
seeded baseline state and verified it after the real native upgrade and Apache
restart. All four probes confirmed preservation of migration state, policies,
clients, generations, scopes and bearer access, with both compiled entry assets
present. A retained pre-upgrade credential successfully called `tools/list` over
HTTP in a fresh Apache worker. The probe removed only its own fixture state
before the shared suite ran.

## Conditional coverage

Exact skipped case identities are retained in each summary and JUnit report:

- Three PostgreSQL query-log cases in every M3 cell, because these cells use
  MariaDB. PostgreSQL acceptance remains the separate M2 evidence.
- Two Mail cases in the four cells without `--mail`; neither was skipped in
  `upgrade-local`.
- Six legacy 1.0.8 cases in the other four origins, whose transition is covered by
  the explicit continuity probe above. None was skipped in mode `upgrade`.

The non-SQLite deadlock/concurrency cases ran in every M3 cell. The seven Deck
dependency cases also ran against the available NC35 native API. M2's NC33/Deck
API limitation and SQLite concurrency exclusions still apply to those profiles;
15/15 cells is not a claim of identical optional features across platforms.

These five cells explicitly defer coordinated recovery to NC35/SQLite/fresh,
which passed in M2. They are not additional recovery rehearsals and do not
qualify LinuxServer, the intended host, S3 or production backups.

## Cleanup, diagnostic and evidence

No host ports were published. The external observer recorded full container and
network IDs and mounted volume names, without container environments. Independent
all-state queries passed after every origin; a final live query again confirmed
all **11 containers, five networks and 11 volumes** absent by exact IDs/names and
run labels. The Mail cell accounts for the extra IMAP container and volume.
No broad Docker pruning was used.

One read-only progress poll failed during the final cell's startup because its
observation legitimately had no run directory yet. A small fixture reproduced
`Path(None)` failing; the external reader was corrected to report `starting`,
and that fixture passed. The coordinator, native suites and acceptance gates
were unaffected: no native run was repeated and no evidence was rewritten.
`monitor-diagnostic.json` retains this operational failure and its verification.

Private credentials, raw logs, database state, generated clones and source kits
are not an approved publication bundle. Documentation/package validation is
retained separately and must not redefine the accepted candidate.

## Closure validation

Documentation and package validation pass: **186 Markdown files and 115 package
sources**, with all 100 translations still marked `review-required`. The
working-tree archive is a dirty development build with its source offer disabled;
it does not replace the frozen acceptance archive. The canonical `verify:source`
command also passes against the existing kit and its trusted digest. Logs are
retained as `package-validation.log` and `source-integrity-validation.log`, outside
the sealed acceptance index.

A read-only automated review found no actionable findings in the five changed
documents and 108 indexed records. It independently checked inventories, baseline
archives, JUnit totals and conditional coverage against retained evidence. It did
not rerun containers or query live Docker; the primary session performed the live
cleanup checks above. New advisories, unchanged historical implementation and the
external acceptance gates below were outside this review. This is not human
acceptance. Final documentation/package validation is retained separately as
`package-final.log`.

## Review before commit (2026-10-30)

A fresh review rechecked all 108 sealed evidence records, five JUnit reports,
expected skip sets, installed candidate inventories and preserved baseline trees.
Mail, legacy migration, continuity and Deck/concurrency coverage agree with this
report; no application or runner correction was required. Successful live Docker
queries again confirmed the absence of the exact 11 containers, five networks,
11 volume names and run labels. The disclosed progress-reader diagnostic also
matches its retained failing/passing fixture results.

## Remaining work

There is no unresolved M3 technical failure. **M4 is next, but was not started.**
It must reconcile the completed native matrix with the source-offer evidence,
LinuxServer's previously tested U5 kit versus this successor, final source
publication and release-history/attribution review. The 15/15 native result does
not by itself close F3 or authorize a release or production deployment.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT, human security,
operations/recovery, license/attribution, accessibility and language reviews remain
pending; all 100 translations still require human review. Deployment and recovery
qualification and final immutable source publication remain separate gates.
