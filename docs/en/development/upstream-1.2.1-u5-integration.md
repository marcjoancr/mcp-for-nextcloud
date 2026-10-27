<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U5 reconciliation

This record follows the [U5 plan](upstream-1.2.1-u5-plan.md). The comparison is
upstream 1.2.0 `02526119b845ccc1d8399be002da3e30b5527df6` → 1.2.1
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`; fork input is published U4
`99df0211b9dc9444534b69118cd22dbf6d439ffc`. Nextcloud MCP / 2.0.0 remains an
independent development candidate, supporting native Nextcloud 33–35 / PHP 8.4+.

## Reconciliation result

The [path ledger](upstream-1.2.1-source-ledger.md) accounts for the exact Git delta:
**165 files, 9,418 insertions, 639 deletions**. Each path now has a concrete fork
owner and disposition: **138 adapted, 26 equivalent, one deliberately excluded**.
The exclusion is the duplicate German README tree; German UI and store metadata
are retained. Existing es/ca/pt-BR/pt-PT documentation retains English provenance.
Path coverage is not a claim that every upstream line was copied or independently
security-audited. Existing U1–U4 records remain the detailed behavioral evidence.

| Capability / contract | Final disposition and evidence |
| --- | --- |
| Backup visibility | U1 canonical-origin and root recognition, including shared aliases and mirrored originals; ordinary nested names remain ordinary. |
| Configured backups and copy policy | U2 owns NFC names, historical reservations, revisioned settings, effective Versions fallback, write boundaries and mandatory Contacts copies. |
| Tasks hierarchy | U3 owns parent relations, direct-child filtering, readable titles, same-calendar checks, depth/cycle refusal and locking. U4 fixes numeric UID titles. No cascading completion/deletion or cross-client transaction is implied. |
| Mail | U4 supplies owned accounts and cached folders/counters, bounded output, explicit read grants and no IMAP refresh/messages/mutations. |
| Tool surface | All **84 module tools** remain, plus `mcp_guide` and `mcp_selftest`. Names, module, operation, app and schema constraints match pinned 1.2.1. Three Deck dependency tools remain conditional; maximum total is 86, otherwise 83. |
| Routes and administration | Backup GET/PUT routes and admin/CSRF contracts are present. Authored backup modules and generated bundles replace upstream standalone scripts; existing browser/asset tests own behavior and lifecycle. |
| Metadata and guides | Manifest now covers hierarchy, configurable copies and cache-only Mail in en/pt-br/es/de. Removed unconditional Files-backup promises; canonical guides and changelog reflect the implementation. Identity/version/platform decisions stay fork-specific. |
| Attribution and sources | Original Sintax and subtask contributor attribution retained; README credits @mturgeonferland. Mail/core API pins, generators and third-party notices remain traceable. No new dependency or runtime migration. |
| Native upgrades | Explicit legacy Mail comparison plus a pinned upstream 1.2.0 cell use the existing isolated harness. No second checkpoint format or shared-engine controller is imported. |

The metadata audit loads upstream declarations from the exact Git object in an
isolated process and compares them with static fork catalogs. Calendar handler
metadata is composed explicitly; Deck's native capability probe is stubbed on only
to enumerate its maximum schema surface, not to certify a native API. All **84/84**
input schemas have identical constraints. Ten schema descriptions differ (eight
Deck tools, Notes edit, Tasks list); these retain explanatory fork wording and do
not change accepted inputs. Native availability is validated separately.

For each UI locale de/es/pt_BR, 34 of the 40 upstream changed keys are exact. Six
standalone-script messages are replaced by the authored controller/view's seven
messages, including preservation of unsaved input and uncertain-save handling.
No unused legacy strings are copied just to satisfy textual equality. Four Mail
guide drafts have been added with the English source pinned to `99df0211`, package
allowlisting and explicit pending human review. Existing drafts are not silently
marked refreshed; U1–U4 changes still require language review and reconciliation.

## U4 prerequisite CI and repair

U4 [Quality](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40552400380)
and [Package](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40552400273)
passed, but [Native integration](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40552400385)
failed. In [Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40552400352),
PHP 8.2, 8.3, 8.4 and 8.5 passed; the JavaScript job was still in its browser
installation step when checked. Its browser suite had not run; the cause of the
stall was not established. No cancellation, rerun or success was invented. The native
workflow’s five historical `upgrade` cells still asserted that the catalog was identical to 1.0.8; the new
Mail permission made two `UpgradeSnapshotTest` comparisons fail. This is a real
CI failure, not a passing U4 matrix or a removed test. The 1.0.8 raw-state, migration
and credential checks remain in place.

`LegacyCatalogContract` accepts exactly the old catalog plus `mail.read`, checks
that every new matrix cell is strictly `['read' => false]`, then removes only that
checked cell from the legacy comparison. Removed/changed old operations, unknown
additions, enabled Mail, missing cells and wrong value types are rejected. The old
users/permissions, configuration bytes, generations and tokens are still compared.
This deliberately differs from upstream's new default of Allow.

The missing helper was observed by a focused regression; the original native
failure supplies the end-to-end RED. Focused guard tests pass **2 / 16 assertions**.
Manifest tests first failed for missing German metadata and selected capability
limits, then passed with the existing 1.1.2 description contract: **11 / 186**.
The new 1.2.0 mode first failed planning; the harness suite then passed **16 / 184**.

## Validation

The full registered `devbox run check` completed with **exit 0**, against the
correct isolated project/worktree. It passed **8,687 PHP tests / 74,152 assertions**,
**717 browser cases** across Chromium/Firefox/WebKit, **1,363 PHP syntax checks**,
both PHPStan scopes, 29 documentation-tool tests, 10 client-runner controls,
11 release/source controls and production packaging. After the review added the
explicit 2.0.0 assertion, the complete unified PHP command passed again:
**8,687 tests / 74,153 assertions**. No suppression or relaxed assertion was added.
The unchanged browser and analysis results remain applicable.

Final documentation validates **173 Markdown files / 115 package sources**;
translations report **100 review-required / zero missing**, with valid provenance.
The four new Mail drafts reference the English body at `99df0211`; the later
English navigation addition is not silently marked human-reviewed. Final
`composer package` passes and builds `mcp-2.0.0.tar.gz` with all 115 allowed sources.
REUSE covers **1,771 / 1,771** files; changed/new source scanning reports zero
secrets, actionlint and `git diff --check` pass. The build intentionally reports
source offer unavailable while dirty; it is not a trusted release/source kit.
Browser missing-import diagnostics and native deadlocks are intentional negative
cases whose assertions pass, not suppressed failures.

All five previously failing legacy-upgrade cells and the added upstream 1.2.0
cell passed locally, each with **zero errors/failures**:

| Cell | Tests / assertions / skips | Local run ID |
| --- | --- | --- |
| NC33 / mariadb / `upgrade` | 300 / 4565 / 5 | `mcp-it-20261027174908-247894081cd6` |
| NC34 / mariadb / `upgrade` | 300 / 4639 / 5 | `mcp-it-20261027180059-d80929bea3d5` |
| NC35 / mariadb / `upgrade` | 300 / 4638 / 5 | `mcp-it-20261027181003-818b6b5c62ff` |
| NC35 / pgsql / `upgrade` | 300 / 5256 / 2 | `mcp-it-20261027174945-7a97819b2fb8` |
| NC35 / sqlite / `upgrade` | 300 / 4599 / 8 | `mcp-it-20261027180058-d7d6facb2432` |
| NC35 / mariadb / `upgrade-upstream-120` | 300 / 4435 / 11 | `mcp-it-20261027180841-39d92b38bab3` |

Two skipped Mail tests in these cells are covered by U4's separate opt-in Mail
acceptance; three SQL statement-window tests require PostgreSQL; SQLite also skips
three row-lock/deadlock cases; the 1.2.0 mode skips six pre-1.1 migration cases,
which remain covered by the legacy `upgrade` cells. The matrix now has 15 cells;
this U5 local run is **six cells**, not a claim to have rerun all 15 locally.
The U4 [NC35/MariaDB fresh Mail cell](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40552400385/job/122359458990)
and [NC35/SQLite fresh recovery cell](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40552400385/job/122359458926)
passed on the published U4 input; those are earlier evidence, not final U5 CI.

The new 1.2.0 test installed actual upstream 1.2.0, ran `occ upgrade` to 2.0.0,
and verified unchanged migration/policy/client/generation/scope state and working
old bearer access before running the suite. Its immutable runtime is NC35.0.1 /
PHP8.5.11 / MariaDB10.11.19, defined in `matrix.json`. Its input SHA-256 values are:

| Input | SHA-256 |
| --- | --- |
| Upstream 1.2.0 baseline package | `1f5ee55f97568b143bd3a24992222922c029ab3c6957cd6c5062b3a5e24cedb5` |
| U5 development candidate package | `55df8dff549b46812c033c15c22ce5400209717f64f0e83c7a363a21a290560e` |
| Native test source | `db930d841de1b1bee85c7cdff3183b02618d1895dd69fd85d4b03a9c6a3e33ee` |

Local command evidence lives in `mcp/build/upstream-1.2.1-u5/`: `check.json`,
`php-final.log`, `package-final.log`, `docs-final.log`, `translations.log`,
`reconciliation.json`, `reuse.log`, `gitleaks.log` and the native command logs.
Each run stores `result.json`, `junit.xml`, `plan.json`, `runtime.md`,
`packages.sha256`, `test-source.sha256` and redacted logs under
`mcp/build/integration/<run-id>/mcp-it/artifacts/`. All six result files have exit
code 0 and their run-owned containers/networks were verified absent. No ports were
published. Source-commit fields identify base `99df0211`; package/test hashes
identify the tested working-tree inputs. Later report/version-assertion changes
and the final Spanish metadata qualifier do not change production PHP behavior.
These ignored paths are local evidence, not public artifacts. Raw credentials/logs
are not committed; only sanitized artifacts are suitable for later sharing.

### Retained failed diagnostics

Two early native starts (`mcp-it-20261027175739-4e2a1b15c6d6` and
`mcp-it-20261027175741-046a4812fc4d`) failed during packaging because ledger links
were written before this report existed. No acceptance or container run is claimed
for them. The complete document graph was validated before restarting the cells.
The new Portuguese review notices initially did not match the established validator,
and equivalent-page navigation was missing on the English Mail page; both were
corrected without weakening validation.

An extra direct PHPUnit invocation after review omitted the unified command's
upload limits and pinned Node PATH. It failed five tests: two Files upload-plan
expectations saw the CLI's 8 MB limit instead of the configured 50 MB, and three
`DeveloperCommandsTest` cases encountered Node 20 instead of the required 24.21.
The failed diagnostic is retained in `php-direct-failed.log`. No application or
test assertion was changed to accommodate it; final verification uses
`composer --working-dir=mcp test` with the pinned Node toolchain. This does not
invalidate the earlier complete catalog check, which used those settings.

## Final independent review and decisions

One independent whole-branch review found no runtime correctness or permission
issue. It identified two inaccurate ledger owner links: Files replacement belongs
to `TextReplacer`, and the upstream `DeckCutPresentationTest` delta changes a
manifest version assertion, not Deck schema behavior. Both links were corrected;
the canonical `InfoXmlTest` now explicitly preserves version 2.0.0. Although graded
minor by the reviewer, these factual errors were material to this inventory and
were fixed in one pass. Manifest and prior-description checks pass **11 / 187**.
No review finding remains deferred and no second review was claimed.

Decisions made during execution:

- Repair U4's known catalog failure in U5 before claiming acceptance. The exact
  addition/default guard prevents projection from hiding changed legacy grants.
- Reuse the existing state/credential probe for 1.2.0 rather than import a second
  snapshot format. Its Apache fixture cannot establish LinuxServer recovery.
- Preserve the earlier 1.1.2 description fixture: its existing paragraphs remain
  applicable. Add current manifest checks instead of replacing old guarantees.
- Keep the 84-tool comparison as a pinned audit, not another permanent full
  snapshot duplicating the existing publication contracts. Future upstream intake
  must repeat it; this one-time comparison cannot detect future drift by itself.
- Keep execution bookkeeping external, following devbox instructions. Loss of
  that scratch ledger would affect execution traceability, not runtime behavior.

The reviewer did not certify unfinished tests/remote CI, re-audit every earlier
U1–U4 behavior or independently reproduce the ignored 84-tool artifact. This report
uses observed executor results and cited batch evidence for those claims. Neither
review nor those fixtures substitute for intended-server recovery, hosted-client
qualification or human language/security/license approval. Treating them as such
would falsely qualify an unsupported deployment.

## Deliberate differences and remaining gates

- Mail starts denied and refuses unknown installed versions. A grant covers all
  owned Mail accounts; per-account restrictions are not implemented. Existing
  OAuth `mcp` scope does not bypass operation policy.
- Capability owners, mandatory dependencies, authored browser modules and the
  run-owned disposable harness remain; no legacy `Service`/`Tools` tree, duplicate
  implementation, Colima queue or global cleanup is introduced.
- Fork identity, version 2.0.0 and NC33–35/PHP8.4 native floor remain. Historical
  API fixtures/PHP8.2–8.3 tests do not broaden support. German README expansion is
  excluded; German store text is a draft requiring human review.
- LinuxServer L1–L5 and the intended-server **1.2.0 → 2.0.0** upgrade/recovery remain
  pending. Apache fixture results cannot qualify that topology. Trusted source
  kits, distribution/update control, integrity and F3/F4/F5 release approvals
  remain separate gates; a dirty package is not a trusted release artifact.
- Public HTTPS, Inspector's previous **14/15** result/encrypted-store issue and
  real ChatGPT consent/tools/revocation remain pending.
- Human security, operations/recovery, license, accessibility and language reviews
  remain pending. Automated checks and agent review are not human approval.
- Remote CI of the eventual U5 commit must be observed separately. A corrected
  local run does not retroactively turn U4's failed workflow green.

U5 remains on the local `chore/upstream-121-reconciliation` branch; publication
and remote checks of these new commits remain pending. The earlier authorization
was used to publish the three U4 commits; no further push is claimed here.

No production server was modified, no deployment or release was performed, and
PR #14 and its reference branch remain unchanged.
