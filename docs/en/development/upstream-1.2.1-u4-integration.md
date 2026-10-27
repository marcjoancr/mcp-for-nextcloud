<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U4: optional Mail

Execution of the [U4 plan](upstream-1.2.1-u4-plan.md), from fork
`9b58004193939c9da0bf6b2d3843ff2184487991`, using upstream release
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`. Nextcloud MCP remains a **2.0.0
development candidate**, supporting native Nextcloud 33–35 / PHP 8.4 minimum.
No deployment, MinicoLab server change or PR #14 operation is included.

## Prerequisite CI and Tasks correction

All four U3 workflows passed for that exact head:
[Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40518096670),
[Quality](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40518096588),
[Package](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40518097144),
[Native integration](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40518096243).
Quality's jobs completed before its aggregate status; it was not reported green
until GitHub recorded `completed/success`.

PHP turns numeric-string array keys into integers. `ParentPlanText` now accepts
and normalizes those keys, preserving title/UID escaping and identity. A create
and parent-change regression first failed without the title, then passed for
UIDs `123` and `0`: 14 focused Tasks tests / 55 assertions. The isolated fix is
commit `ff13cfc1` (`fix(tasks): retain parent titles for numeric UIDs`).

## Delivered behavior and ownership

`Modules/Mail/Accounts`, `Mailboxes`, `Presentation` and `Tools` own the two
[Mail read capabilities](../reference/tools/mail.md). `Contracts/MailReader`
returns arrays; only `Integration` holds native Mail objects. A fixed-class lazy
resolver is injected from Application; no container or compatibility handler is
introduced inside the feature. Static catalog/guide discovery does not instantiate
native services. Existing feature owners and shared MCP execution remain intact.

The gateway calls only `AccountService::findByUserId`, `AccountService::find` and
`MailboxMapper::findAll`. It rechecks the account owner, excludes delegated
accounts and projects only public account/folder fields. No message body,
credential, server configuration, synchronization or mutation is exposed.
Foreign and nonexistent account IDs have identical failures. Empty cache is
reported honestly, unknown counters stay null, names are bounded and cleaned,
and inbox/path/ID sorting keeps pagination deterministic.

New `mail.read` starts **denied**, deliberately stronger than upstream's ordinary
read default. Explicit individual or trusted-group grants enable it; existing
permissions and resource restrictions retain their meaning. Mail must be enabled
for the actor with a known version at least 5.0.0. Unknown version is refused for
Mail while retaining existing Deck/Talk compatibility behavior. The administrator
matrix, level mapping, access inspector names and runtime de/es/pt_BR catalogs
include Mail. Human language review remains pending.

OAuth retains its existing `mcp` scope: no new granular OAuth scope is introduced.
Grant refusal/revocation is separate from OAuth scope authentication. Existing
scope-authentication tests remain mandatory; native acceptance uses real app
passwords and OAuth tokens and checks Mail grant revocation on those credentials.

## Provenance and test harness

The five API fixtures add only Mail surfaces and their source pins; unrelated
class rows remain byte-for-byte unchanged. Official archives and core Entity/
QBMapper files were checked against SHA-256 before tokenization. Historical NC31/
32 API sources do not extend supported native platforms. The full generator now
checks Mail/Circles source versions; the recipient-only generator uses the shared
fixture writer to preserve Mail provenance; its rejection test writes only to a temporary
output, protecting tracked fixtures even when the guard regresses.

The optional `--mail` cell pins Mail 5.12.5 and Dovecot 2.4.5. It publishes no
ports, checks run ownership before provisioning and uses the existing labeled
cleanup. No shared-engine control, unrelated cleanup or image pruning was copied.
The first native attempt stopped because umask 077 made the public app archive
unreadable by container www-data. It failed visibly, cleaned its resources and
retains evidence under run `mcp-it-20261027143023-576c4611cb77`. The recipe now makes
that verified public archive readable, matching the other app fixtures. A second
attempt (`mcp-it-20261027143346-dc09675abf3a`) stopped before provisioning because
the new Mail guide was not yet on the package allowlist; the explicit package
inventory now includes it. Neither failed attempt counts as native acceptance.

The initial generator-rejection regression exposed an incomplete generator port:
it wrote the synthetic NC32 tree. The original tracked fixture was restored and
only verified Mail rows reapplied. The test now uses an isolated output path; the
version guard and fixture reproduction checks pass. No snapshot was blessed to
hide the failure. Shared MCP captures append only raw Mail definitions; People/
Logs captures append only Application's new module name. Published historical
fixtures still hide Mail because they have no known installed Mail version.

## Validation

Focused capability/admission/contract tests: **208 tests / 9,829 assertions**.
Complete contract directory: **2,736 tests / 10,227 assertions**.
Mail harness planning/ownership controls: **2 tests / 31 assertions**.
Both PHPStan configurations pass without suppression changes.
The final full catalog after the review fix passed: **8,683 PHP tests / 73,849
assertions**, **717 browser cases**, 29 documentation-tool tests, 10 client-runner
controls, 11 release/source controls, **1,360 PHP syntax checks**, both PHPStan
configurations and the production package. Evidence is in
`mcp/build/upstream-1.2.1-u4/check-final.json` (project/worktree identity, code 0).
The earlier full check also passed (8,682 / 73,837), before the new sort regression.
Documentation validates **167 Markdown files / 111 package sources**. Translation
status is **96 review-required / 4 missing**, with valid provenance. REUSE covers
**1,763 / 1,763** files, changed/new source scanning finds no secrets, actionlint
and `git diff --check` pass. No analysis suppression was added.

The browser build tests intentionally log missing-import failures for their
negative cases; native policy tests intentionally exercise MariaDB deadlocks.
Their assertions pass. Dirty development packages report source offer unavailable
as designed and are not trusted release/source kits.
The NC35.0.1 / PHP 8.5.11 / MariaDB 10.11.19 `upgrade-local --mail` cell passed
**300 tests / 4,488 assertions / 9 expected skips**, including both Mail tests
(**53 assertions**). Six historical baseline probes do not apply to the frozen
fork baseline; three statement-log tests require PostgreSQL. Recovery belongs to
the separate NC35/SQLite/fresh cell and is not claimed here. Native cache/IMAP
canaries and Basic/OAuth ownership/revocation checks passed.

Upgrade evidence: `mcp/build/integration/mcp-it-20261027143620-3ad10db865bd/mcp-it/artifacts/`,
with `result.json` exitCode 0, JUnit, runtime pins, package/test hashes and redacted
logs. This package predates only the final pure folder-comparison fix. The final-code
NC35/MariaDB `fresh --mail` cell also passed **300 tests / 4,488 assertions / 9
expected skips**, including both Mail cases and their 53 assertions. Its evidence
is `mcp/build/integration/mcp-it-20261027144505-af76507b077d/mcp-it/artifacts/`,
with `result.json` exitCode 0. Both runs cleaned their labeled containers/network.
The source-commit fields name pre-commit base `9b580041`; their package/test hashes
identify the tested working-tree inputs. Credentials/raw logs are not committed;
share only redacted artifact directories. This is local HTTP fixture acceptance,
not public HTTPS or hosted-client qualification.

## Final technical review

One independent whole-branch review found one important defect and no critical
or minor findings: PHP's tuple comparison treated numeric-looking folder paths
numerically but other paths lexically, giving a non-transitive sort. The fix uses
explicit inbox priority, `strcmp` of case-folded paths and numeric ID. A regression
with shuffled `2`, `10`, `1a`, equal case-folded names and three pages first failed,
then passed. The focused Mail suite now passes **26 tests / 95 assertions**.

Review boundaries are explicit decisions: executor results, not the reviewer,
establish full-check/native success; pinned fixtures do not certify every Mail
patch; per-account selection is outside U4 and the grant covers owned accounts;
production/client/human approvals remain separate. Assuming otherwise would
claim unsupported compatibility or deployment approval. The external execution
ledger follows devbox's rule against repository harness state; its loss would
reduce execution traceability, not alter runtime behavior. No second review or
minor cosmetic pass was performed.

A final path-ledger check additionally caught a developer-tool omission: the old
recipient generator dropped `mailSources` when writing its result. It now uses
`FixtureFile`, preserving those pins and all unrelated class surfaces. An isolated
regression runs the actual copied generator against synthetic checksummed archives
and a temporary output; it first failed on missing Mail pins and then passed
**196 assertions**. This tooling-only follow-up does not change the runtime package
or native test inputs. After this tooling-only fix, PHP lint passes **1,361 files** and the entire PHP
suite passes **8,684 tests / 74,053 assertions** (`php-final.log` and `lint-final.log`
in the U4 evidence directory). Unchanged browser, PHPStan and native results above
remain applicable. Documentation and package checks were repeated at closure.

## Remaining work

U5 full 1.2.1 reconciliation and release qualification remain open. LinuxServer
L1–L5, intended-server upgrade/recovery, trusted source/artifact preparation and
distribution, and F3/F4/F5 approvals remain open. The observed installation baseline
is still **1.2.0 → 2.0.0**; this development fixture is not that server rehearsal.

Public HTTPS; Inspector's prior nonzero **14/15** and encrypted-store issue;
real ChatGPT consent/tools/revocation; and human security, operations, recovery,
license, accessibility and language reviews remain pending. The new Mail guide
still needs es/ca/pt-BR/pt-PT translations; the existing 96 translation entries
remain review-required and are not silently marked current. Automated review is
not human approval. Native Mail acceptance does not qualify every Mail patch,
mail server, hosted client or production topology.

Publication is limited to the already-authorized `fork/main`; original `main`,
PR #14 and its reference branch remain intact. New U4 remote CI is separate from
the passing U3 prerequisite and must be observed for the resulting commit.

Closure publication status: the numeric-UID fix is committed as `ff13cfc1` and
Mail as `f4e5527c`. Automatic approval review rejected the attempted push to
`fork/main`, considering authorization for that protected destination insufficiently
explicit. No push took place; renewed owner confirmation is needed before retrying.
The remote remains at U3 `9b580041`, whose four workflows were rechecked as
completed/success. U4 remote CI has not started and remains pending publication.

The owner subsequently authorized publication; `fork/main` reached `99df0211`.
[U5](upstream-1.2.1-u5-integration.md) records the resulting CI failure in legacy
upgrade comparisons and its repair. The prior publication blocker above is historical.
