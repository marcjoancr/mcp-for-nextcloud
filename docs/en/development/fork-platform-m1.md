<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# M1: source offer and primary native acceptance

Date: 2026-10-30. **M1 complete: 2/15 native matrix cells**, plus the distinct
source-offer fixture and reconstruction from its downloaded sources. M2-M4,
public HTTPS, Inspector end-to-end, hosted ChatGPT and all human reviewers remain
pending. No production deployment, push or PR #14 change is included.

Subsequent [M2 acceptance](fork-platform-m2.md) brings the same candidate to
10/15 native cells. The M1 results below remain the historical batch record.

## Identity and execution order

The [approved plan](fork-platform-source-acceptance-plan.md) was executed in order:
source offer, downloaded-kit reconstruction, NC35/MariaDB fresh with Mail, then
NC35/MariaDB upgrade from upstream 1.2.0. Each gate and exact-owned cleanup passed
before advancing. No application or runner code was changed for these runs.

- Candidate: `efb27979b6a90122ce45a6a509e52390030f7ed9`, app `mcp` version `2.0.0`.
- Trusted manifest SHA-256: `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.
- Runtime archive SHA-256: `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297`.
- Runner: `6c6c2ccec458837ef5b19e980241438d504ee80b`; clean when each run staged its inputs.
- Verification/build runtime: Node 24.22.1 / npm 11.19.1, PHP 8.4.27 / Composer 2.8.8.

All three fixtures consumed the existing immutable kit. Each installed-tree gate
measured and matched all **975 files** before acceptance checks. None rebuilt a
checkout package or changed the candidate's identity. The source and runner
revisions remain separate in plans/artifacts. Later report edits are documentation
only and do not redefine the accepted kit.

## Source offer and downloaded-source reconstruction

Run `mcp-source-c01bed828ff17340` used NC35/SQLite and passed **124 checks**:

- A real personal-settings HTTP page exposes source identity, digest, links and
  attribution for a non-admin user.
- Basic and Bearer authentication work through `initialize` and `server/discover`.
  Anonymous MCP access is refused; source files themselves download anonymously.
- A disabled MCP connection still permits the personal source offer, while MCP
  access is denied. Missing or wrong-revision offer metadata is not advertised.
- All five kit files download and match the separately trusted manifest. A
  modified downloaded bundle is refused; restoring only the probe append is
  followed by full verification before reconstruction.

The source HTTP server was loopback-only **inside the disposable container**;
there was no host port, public HTTPS endpoint or hosted-client journey. This is
technical HTTP acceptance, not a human visual/accessibility or license review.

A fresh clone from the **downloaded** `source.bundle`, detached at the candidate
SHA, ran locked dependency installation and canonical packaging. Every extracted
runtime path, size and hash matches the original kit: **975 runtime files**,
including **73 vendor files** matching the downloaded dependency archive.
The rebuilt archive SHA-256 is `e536ac032e7b81bb5c2726c23a155d5647b2e081267d6ab405566bd1250c0995`.
Archive metadata/timestamps are not normalized; compressed archive equality is
not claimed. No runtime difference was excluded. This rebuild uses locked
registries/trusted caches and is not fully offline toolchain reconstruction.

The source fixture is separate from the full NC35/SQLite matrix cell and does
not contribute an extra cell or certify that profile's recovery rehearsal.

## Native results and conditional skips

Both full, unfiltered suites ran Nextcloud **35.0.2**, PHP **8.5.12** and MariaDB
**10.11.19** with the M0 linux/amd64 image pins. MCP reports **2.0.0**, enabled,
with maintenance off and no pending DB upgrade. Contacts 8.9.2 and Deck 1.19.0
match their pinned releases; required optional-app provisioning completed.

| Cell | Tests discovered | Assertions | Conditional skips | Failures / errors | Exit |
| --- | ---: | ---: | ---: | --- | ---: |
| Fresh with Mail | 300 | 4488 | 9 | 0 / 0 | 0 |
| Upgrade from upstream 1.2.0 | 300 | 4435 | 11 | 0 / 0 | 0 |

Totals include skipped cases. The fresh cell enabled Mail 5.12.5 with the pinned
Dovecot 2.4.5 fixture, and both Mail test methods ran. Mail was deliberately not
selected in the 1.2.0 upgrade cell, as specified by the matrix.

| Skip group | Fresh + Mail | Upgrade 1.2.0 | Reason |
| --- | ---: | ---: | --- |
| NativeClientMigrationTest / UpgradeSnapshotTest / LegacyCredentialsTest | 6 | 6 | These tests require mode `upgrade`, the distinct 1.0.8-origin migration; the 1.2.0 replacement has its own probe below |
| PostgresQueryLogTest | 3 | 3 | PostgreSQL statement-log assertions do not run on MariaDB |
| McpMailHttpTest / McpMailReadTest | 0 | 2 | Mail is opt-in and not selected for this upgrade origin |

JUnit does not include skip-reason text for these cases. The named tests and
counts were reconciled against their explicit source guards; `summary.json`
retains each skipped class/method. No missing required app or filtered selection
was counted as a successful capability check. PostgreSQL and other upgrade-origin
coverage remains mandatory in M2/M3.

## Upgrade continuity and preserved baseline

Run `mcp-it-20261030003941-a8cfd3b3ba03` consumed the reviewed upstream 1.2.0 archive
with SHA-256 `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801`,
bound to commit `02526119b845ccc1d8399be002da3e30b5527df6`. It first enabled
1.2.0 and seeded policy restrictions, resource scope, an OAuth client and a
credential. Then native `occ upgrade` completed, followed by an Apache restart
and a successful state/HTTP continuity probe before the full suite.

The probe compares migration, policy, client, generation, scope and credential
state before/after the transition, checks the retained restrictions, and exercises
`tools/list` with the pre-upgrade Bearer credential in a fresh Apache worker.
Both compiled entry assets remain present. The probe removes only its seeded
fixture state before the shared suite proceeds.

The harness preserves the installed baseline by renaming it to
`/tmp/mcp-previous` before replacing the app. That preserved tree was copied out
while the owned container was still running: all **532 regular files** match the
reviewed 1.2.0 archive exactly. `baseline-inventory.json` retains their measured
hashes/sizes. This is an isolated app-upgrade acceptance, not a production DB/S3
backup or coordinated recovery qualification.

## Independent cleanup and evidence

No host ports were published. An external observer recorded only the owned runs'
container/image IDs, network IDs and mounted Docker volume names, without
container environments or credentials. After each command, independent queries
covered **all container states**, networks and the recorded volumes; each exact
resource was absent. No broad Docker cleanup was used.

| Run | Removed containers | Removed networks | Removed volumes |
| --- | ---: | ---: | ---: |
| `mcp-source-c01bed828ff17340` | 1 | 1 | 1 |
| `mcp-it-20261030003028-6234fbe16eb9` | 3 | 1 | 3 |
| `mcp-it-20261030003941-a8cfd3b3ba03` | 2 | 1 | 2 |

The local report/evidence root is ignored `mcp/build/m1/`. Its selected
`evidence-index.json` contains **46 files**, with SHA-256
`9d2a2fa158d150fc1773d245552bb1e3fbcb4496f78fe390a8eec1c102bcf7c8`.
It binds selected results, candidate/installed inventories,
plans, runtime reports, JUnit, upgrade/install logs, cleanup observations and the
downloaded-source reconstruction. Native run roots remain in
`mcp/build/integration/`; the source run is in `mcp/build/source-acceptance/`.
Private credentials, raw logs, generated clones and source kits are not an
approved publication bundle. Existing M0 kits/indexes and LinuxServer checkpoints
remain unchanged. Documentation/package validation logs are retained separately
from the sealed acceptance index.

Closure documentation and package validation pass: 184 Markdown files and 115
package documentation sources. The working-tree package is explicitly a dirty
development build with its source offer disabled; it does not replace the frozen
acceptance archive. Both original kits, the downloaded kit and all M0, M0-review
and M1 indexed files were reverified unchanged. Logs are retained as
`package-validation.log`, `docs-validation.log` and `closure-integrity.log` outside
the sealed acceptance index.

An additional automated review found no actionable inconsistency in the report,
46 indexed records, runtime/vendor inventories, baseline, totals or skip guards.
Its direct Docker socket access was denied, so it assessed recorded cleanup
evidence without treating the failed query as success. The primary session then
repeated live all-state queries against the exact captured container IDs, network
IDs, volume names and run labels; all were absent. The successful result is in
`cleanup-final.json` (SHA-256 `44fa121e04e9fac4dbae7561ce139efad6227c0bbffecdd6ce4dbd9769223ec2`),
outside the already sealed acceptance index. This review is not human acceptance.

## Remaining work

M1 has no unresolved technical failure. Next is **M2**, eight sequential cells:
NC35/PostgreSQL, NC35/SQLite, NC34/MariaDB and NC33/MariaDB, each fresh and from
its approved legacy baseline. M3 covers the five remaining origins; M4 reconciles
all evidence and the LinuxServer/intended-host scope. None was started here.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT, human security,
operations/recovery, license/attribution, accessibility and language reviews
remain pending; all 100 translations still require human review. Final immutable
source publication/history review and MinicoLab host/S3/recovery qualification
are separate gates. No push, deployment or PR #14 operation occurred.


## Pre-commit review (2026-10-30)

The documentation diff and its 46 sealed evidence records were reviewed again.
No executable correction or candidate regeneration was needed. The plan now
explicitly records M0/M1 completion and the installed-tree gate's real execution.
The frozen/downloaded kits, retained rebuilt tree, all 975 runtime files and 73
vendor files, and the 532-file preserved baseline match their recorded inputs.
The baseline archive also retains its approved SHA-256.

JUnit still records 300 cases per cell, zero failures/errors, and the documented
9/11 conditional skips. The source fixture still records 124 checks. These are
reverified retained executions; no native fixture or reconstruction was rerun in
this review. Fresh Docker queries covered all container states, exact captured
IDs, network IDs, volume names and run labels; all owned resources remain absent.
Review results are retained separately under ignored `mcp/build/m1-review/` so
that the accepted M1 index and original evidence remain unchanged. HTTPS,
Inspector end-to-end, ChatGPT, all human reviews and the M2-M4 gates remain open.
No M2, push, deployment, production-server operation or PR #14 change occurred.

Documentation and packaging pass again (184 Markdown files, 115 package sources).
The review's `verification.json` has SHA-256
`896757c679d39a8840244e8783e0703b6d48edc0f53d198a5b0e4185efce45b2`;
`kit-verification.log`, `package-validation.log` and `docs-validation.log` retain
the additional checks. The documentation working-tree package remains separate
from the frozen acceptance archive.
