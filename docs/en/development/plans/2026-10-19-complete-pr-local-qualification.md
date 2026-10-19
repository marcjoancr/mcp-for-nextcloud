<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Complete PR committed-candidate local qualification

Status: the available automated local qualification completed for the committed
candidate. **The PR remains unopened: real-client/UI validation and human verdicts
remain pending.** No push, standing staging deployment or public exposure occurred.
This follows the user's requirement to complete agreed locally executable validation
before opening the complete contribution. Disposable native test containers are
separate from the unapproved standing S1 target and its S0 prerequisites.

Follow-up: the [closed devbox UI exercise](2026-10-19-closed-devbox-ui-acceptance.md)
subsequently tested actual Nextcloud pages on the same frozen artifact. Its bounded
results do not close external-client, HTTPS/proxy, recovery or human acceptance.

## Frozen candidate and artifact

- Branch: `refactor/complete-upstream-pr`.
- Source/code candidate: `b96884631bf9bda245ce9895facfa8380ab08998`.
- Upstream main/HEAD: `0911f9123a661968e707fc4c391adcf9d37c3e84`, confirmed again by
  read-only HTTPS `git ls-remote --symref` on 2026-10-19 UTC. It is the exact merge
  base; no upstream delta was silently incorporated.
- Independent full-history clone: ignored
  `mcp/build/verification/pr-candidate-b968846/clean-clone`; its checkout names the
  exact source above. Both lockfiles installed before the full portable gate.
- Production archive: ignored
  `mcp/build/verification/pr-candidate-b968846/mcp-1.1.0.tar.gz`.
- Archive SHA-256: `de71b3cbf223764033e97fc59c3122e4c11944730dabe8b79a7f44fe9eb5d751`.
- Regular-file manifest SHA-256: `8c8bf2d1c43367d1b36bd2d049eaf1a42679b8d417ce82d7b02c9abcac23a1f4`; **863** regular files.

The [machine-readable evidence](../contracts/complete-pr-candidate-b968846.json)
records hashes, runtime image identities, test results, exact outgoing inventory
updates and per-cell archive identities. Compared with the review archive
`e37fbeb8903c693f0097173b213c1922515e3e29224c6c84d15e46392744e23c`, only
`vendor/composer/installed.php` differs: exactly two root references advance from
`c6f92e9` to the committed `b968846`. Every other regular-file byte, member path,
type, mode and link target is unchanged. Both production bundles remain identical.
The original S0 and earlier review archives remain preserved and unchanged.

At the frozen candidate, the outgoing contribution has **1,929 paths** without rename pairing:
**1,270 additions, 501 deletions and 158 modifications**. Direct upstream-to-head
and three-dot sets are identical. To avoid duplicating the original 1,918-row
inventory, the new evidence pins that unchanged inventory's SHA-256 and records
all **17** exact path/blob/mode changes from its assembly candidate to `b968846`.
Together they reconstruct the complete updated inventory. The full regenerated
inventory and its SHA-256 are retained in local evidence. Subsequent evidence-only
documentation is outside this frozen code candidate; regenerate the final displayed
PR inventory against the actual publication head. No runtime source, translation
provenance, release version or dependency was changed during qualification.

## Fresh local checks

Commands ran on 2026-10-19 UTC. Node 24.21.0 and locked dependencies were used;
host PHP is 8.4.26 with Composer 2.8.8. Container PHP uses the previously prepared
images pinned by immutable image ID in the evidence and Composer 2.8.12. The
standard per-user package caches were retained. Containers publish no ports;
strict-PHP containers additionally have networking disabled and 2 CPU/2 GiB limits.

| Check | Result |
| --- | --- |
| `composer --working-dir=mcp check` in the independent clone | PASS, exit 0: Composer validation, **188 Markdown files / 110 package sources**, **29** docs tests, **1,144** PHP syntax checks, both PHPStan scopes, **7,660 PHP tests / 65,227 assertions**, **609** browser cases with zero retries/skips, and production package verification. |
| Strict PHP 8.2.34 | PASS: **7,660 / 65,227**, no errors/failures/skips. |
| Strict PHP 8.3.35 | PASS: **7,660 / 65,227**, no errors/failures/skips. |
| Strict PHP 8.4.26 | PASS: **7,660 / 65,227**, no errors/failures/skips. |
| Strict PHP 8.5.11 | PASS: **7,660 / 65,227**, no errors/failures/skips. |
| `uv tool run --from reuse==5.1.1 reuse lint` | PASS: **1,522/1,522** candidate files; no copyright/license/read errors. |
| Archive comparison and inventory reconciliation | PASS; exact hashes and path/blob evidence linked above. |

Each strict run executed the complete ordinary suite, with
`--fail-on-empty-test-suite --fail-on-deprecation --display-deprecations
--fail-on-warning --fail-on-risky`, memory limit 1 GiB and the canonical upload/body
limits. Container exports first install locked dependencies and run
`composer assets:build`; they mount only their own export plus read-only Node and
Composer tools. The host strict run used the already built independent clone.
These are fresh results, not the earlier PHP-matrix evidence carried forward.

The first PHP 8.2 export omitted generated JavaScript before direct PHPUnit
execution. The suite correctly reported **one error and one failure** in
`L10nTest::testCodeAndJsonKeysMatch` and
`AdminAssetsTest::testReferencedScriptsAndStylesExist` (7,660 cases / 64,528
assertions). The temporary preparation was corrected to build assets, and all four
complete strict suites passed. The original `php-8.2.log` is retained; no test,
application code, filter or suppression was changed. A preliminary image-metadata
probe also handled an absent optional `WorkingDir` key after its initial lookup
failed; that was a read-only setup error. Known Composer PSR-4 diagnostics concern
explicitly loaded third-party fixtures; they are not new runtime autoload bypasses.
The missing-module browser diagnostics are passing negative build fixtures.

## Fresh native matrix and recovery

All **14/14** pinned cells passed: **2,800 cases / 34,686 assertions**,
zero failures/errors, and **84 explicitly inapplicable skips**. The matrix
covers Nextcloud 32.0.15, 33.0.9, 34.0.4 and 35.0.1; MariaDB 10.11.19, PostgreSQL
16.15 and SQLite; fresh installs, original/local 1.0.8 upgrades and same-version
upstream 1.1.0 replacement. The user-facing version remains the real declared 1.1.0.
No qualification-version override, test filter or missing-application skip was used.

Each cell ran `composer --working-dir=mcp test:integration -- NC DB MODE` using
[`ci/matrix.json`](../../../../mcp/tests/Integration/ci/matrix.json). The evidence
pins that source file and commit for its image digests and baseline revisions. Two cells ran concurrently; the existing lock serialized package
builds. Each received its own labeled network/containers and immutable test-source
snapshot. The native source files match the frozen candidate in every run. Each
archive's complete regular-file contents match the frozen artifact; compressed
hashes can differ because packaging timestamps are not normalized. Archive hashes,
JUnit, runtime metadata and source pins are recorded per cell rather than treating
the version string as evidence of equality.

| Nextcloud/database/origin | Cases / assertions / inapplicable skips | Run ID |
| --- | --- | --- |
| 32-mariadb-fresh | 200 / 2295 / 9 | `mcp-it-20261019075002-490e7e45eb74` |
| 32-mariadb-upgrade | 200 / 2499 / 3 | `mcp-it-20261019075002-ce93ded62dae` |
| 33-mariadb-fresh | 200 / 2295 / 9 | `mcp-it-20261019075303-a25bacd9e1cb` |
| 33-mariadb-upgrade | 200 / 2499 / 3 | `mcp-it-20261019075312-9ec983954d35` |
| 34-mariadb-fresh | 200 / 2295 / 9 | `mcp-it-20261019075552-f76476ce263b` |
| 34-mariadb-upgrade | 200 / 2499 / 3 | `mcp-it-20261019075624-7c5cabba7c61` |
| 35-mariadb-fresh | 200 / 2295 / 9 | `mcp-it-20261019075848-fb1835248e7c` |
| 35-mariadb-upgrade | 200 / 2499 / 3 | `mcp-it-20261019075932-7c3832b571ae` |
| 35-mariadb-upgrade-local | 200 / 2499 / 3 | `mcp-it-20261019080144-cce7440158c7` |
| 35-mariadb-replace-upstream | 200 / 2295 / 9 | `mcp-it-20261019080257-bc14a267dedb` |
| 35-pgsql-fresh | 200 / 2898 / 6 | `mcp-it-20261019080821-5275437a6b50` |
| 35-pgsql-upgrade | 200 / 3102 / 0 | `mcp-it-20261019080821-ae77e55bfcae` |
| 35-sqlite-fresh | 200 / 2256 / 12 | `mcp-it-20261019081147-44f8ead6e3e3` |
| 35-sqlite-upgrade | 200 / 2460 / 6 | `mcp-it-20261019081221-1a6943a6ad90` |

The first matrix coordinator exited with code 143 after ten cells had completed;
the external cause is not established. Eight results had been saved by the
coordinator. The other two were recovered only after verifying their durable runner
success result, complete JUnit output and candidate archive identity. No native
containers or networks remained at that checkpoint. The four outstanding cells
then ran in a separate invocation. The interruption and original process results
are preserved; no failed or incomplete cell was counted as a pass.

The recorded skips are the existing database- or baseline-specific cases, including
upgrade-only state/credential baselines, PostgreSQL statement logging and SQLite's
serialized-write alternatives. JUnit identifies every skipped case but emits empty
`<skipped />` elements.
The JSON records each reason from the committed test guard, its source line/hash,
and verification that the cell database/mode satisfies that guard; these reasons
are source-derived rather than text emitted by JUnit. Required apps, empty
collections and failed tests are not skipped.
This matrix exercises actual core behavior, events, database sessions and Basic/Bearer
requests; it does not certify named external MCP applications or a real proxy/UI.

The NC35/SQLite/fresh cell also passed the existing coordinated recovery rehearsal:
back up matching app/database state, revoke credentials, restore both while closed,
restart caches, invalidate restored credential generations, and prove old
access/refresh/code refusal plus explicit fresh reconnection. It finished with MCP
closed. The recovery log and hash are recorded in the evidence. This proves that
bounded synthetic same-version SQLite scenario; it does not approve the proposed
MariaDB/LDAP/external-storage restic procedure, independent backup provider, native
app-password recovery or operator RPO/RTO targets.

Scoped post-run inspection found no containers or networks belonging to these
14 runs. The runner's cleanup operates only on matching labels and disposable
volumes; no shared-daemon change, image prune or other-project cleanup was performed.
Private fixture credentials, raw logs and backups were not committed. Sanitized
artifacts remain below each run's `mcp-it/artifacts`; the parent directory is not an
upload bundle.

## What remains before opening the PR

The user's publication condition remains in force. The passing gates above close
fresh candidate/clone/package/PHP/native qualification; they do not turn missing
client access or human approval into a pass.

| Pending area | Concrete next requirement |
| --- | --- |
| Real clients C1a–C1e / C2–C4 | Confirm available versions/accounts for Claude web/Desktop/Cowork/Code, ChatGPT, Gemini CLI, a registered public client and an HTTP Basic MCP client; then perform the actual positive/negative journeys. No account availability is inferred from these server-side tests. The inspected PATH did not provide Claude, Gemini or MCP Inspector executables. |
| Real Nextcloud UI/proxy | Execute the remaining actual theme/CSP, authentication/proxy-origin, keyboard/screen-reader and display checks on an authorized target; synthetic browser fixtures do not close them. |
| Standing staging / recovery | Resolve the existing S0 topology/private-access and recovery destination/custody/objective decisions before S1. The recovery and access proposals still require confirmation; no persistent target or independent backup service was created. |
| Human verdicts | Security/technical, accessibility, operational and language reviewers remain pending. All 96 documentation translations and German/rendered interface review remain pending; the same 60 source-changed translation references are preserved. |
| Publication/version/history | Review document curation, minor/major version and integration strategy. Preserve the four translation-source checkpoints or reconstruct exact-source provenance if rewriting history. Opening/pushing still needs explicit authorization after local technical gates are satisfied. GitHub CI has not run on this unpublished branch. |

A real-client availability question was sent while tests ran; no client credentials
were requested or inspected. The existing owner/resource decisions do not need
reconfirmation. Missing reviewers remain pending rather than assigned to the agent.

Documentation follow-up: `composer --working-dir=mcp docs:check` passed for
**189 Markdown files / 110 package sources**; `docs:translations` retained all
**96 review-required** states with valid provenance; `test:docs` passed **29/29**.
REUSE 5.1.1 passed for **1,524/1,524** files after adding this evidence. The
upstream-to-candidate and working-tree whitespace checks passed. These checks
cover the evidence-only additions; the frozen runtime artifact remains unchanged.

## Documentation review and candidate boundaries

The documentation review reconciled the preserved artifacts against their recorded
hashes, all 14 native JUnit results and package contents, the four strict PHP results,
the 84 source-derived skip reasons and the complete outgoing inventory with its
17-path overlay. The failed preparation and coordinator interruption remain visible.
The matrix source reference makes its image and upgrade-baseline pins independently
retrievable from the frozen candidate, without relying on private runtime logs.

This review changes only development documentation. Its commit is a later evidence
revision, not a newly executed PHP/native candidate; `b968846` remains the tested
source and the preserved archive above remains the qualified artifact. Documentation,
provenance, tooling tests, licensing and inspection of that archive are the relevant
checks for this commit. On 2026-10-19, those documentation checks were rerun with
the counts above, and `php scripts/check_package.php
build/verification/pr-candidate-b968846/mcp-1.1.0.tar.gz templates lib` from `mcp/`
passed with exit 0. The preserved archive SHA-256 is unchanged. PHP/native suites
were not rerun during this documentation review. No containers or application
services were started. A future runtime change or rebuilt publication package must be evaluated
under the existing candidate-change rules before reusing these results.

Local verdict: **AUTOMATED CANDIDATE QUALIFICATION COMPLETE; REMAINING LOCAL
CLIENT/UI ACCEPTANCE BLOCKS PR OPENING**. Human and S7 approval are not claimed.
