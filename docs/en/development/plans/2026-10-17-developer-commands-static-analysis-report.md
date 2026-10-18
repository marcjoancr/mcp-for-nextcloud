<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Developer commands and static analysis execution report

Status: complete; phases 0–4 and final review corrections locally validated. Baseline: `aa03b2d` (reviewed plan).
Branch/worktree: `docs/talk-capability-plan`; no rename, push, PR or deployment.

## Phase 0 — baseline

- PHP 8.4.26; Composer 2.8.8; PHPUnit 10.5.65. The worktree was clean.
- `devbox run check`: **5,253 tests / 44,744 assertions**, 34.671 seconds,
  516.92 MB; production `mcp-1.0.8.tar.gz` built and validated.
- Focused read/edit tests: **16 / 72**, 0.290 seconds, 66 MB; existing installed
  dependencies, warm filesystem, no test result cache for the focused run.
- Saved expanded test discovery, all tracked JSON fixture hashes, library hashes
  and locked dependency versions before changes. No fixtures were regenerated.
- Source inventory: 559 library PHP files; 336 test/support/fixture PHP files;
  one script, one appinfo PHP file and three templates.
- CI and external catalog use the three 1G PHP limits; Composer has no scripts.
  README/authoring instructions differ. Packaging is already isolated with `--no-dev`.
- OCP is stable33, without its own autoload mapping. Core/DAV, Deck and Talk have
  additional symbols; the initial source inventory has 56 candidate references
  including comments. API snapshots retain 31–35 provenance. Actual missing symbols
  will be measured in Phase 2; test doubles will not define the analysis host.
- Available extensions include curl, dom, fileinfo, gd, intl, mbstring, PDO/sqlite,
  Phar, SimpleXML, tokenizer, xml, xmlreader, xmlwriter, zip and zlib.

## Process decisions

- External scratch replaces the skill's `.superpowers` workspace because server
  instructions prohibit repository harness files. Cost: manual bookkeeping; no
  runtime effect. Phase briefs and evidence remain external until summarized here.

## Follow-up status

The [catalog and available validation report](2026-10-17-devbox-catalog-validation-report.md)
records the subsequently authorized profile update, additional PHP runtimes and
REUSE execution, including the initial failures and their verified corrections.
The remaining-gate statements below are historical to the original implementation run.

## Remaining gates at implementation closure

Local PHP 8.4 evidence does not claim the PHP 8.2–8.5 CI
matrix, REUSE action or real Nextcloud 32–35 checks have run in this task.

## Phase 1 — canonical commands

Implementation: `2fb23b3`.

- Added Composer `test`, `test:architecture`, `test:api`, `lint` and `package`.
  Test limits are defined once; focused arguments retain their boundaries.
- Seven subprocess tests first failed because commands/linter were absent, then
  passed (7 tests / 20 assertions). They exercise parse-only behavior, empty/missing
  paths, default discovery, excluded generated trees, symlinks, spaces, actual PHP
  limits, quoted filters, no-match selections and propagated test failures.
- `lint`: 903 PHP files; architecture 40 tests; API 2,129 / 4,126; Files read 5 / 21.
- Full suite: **5,260 / 44,788**, 36.743 seconds; production package passes.
  All 5,253 original expanded cases remain; seven tooling cases added.
- Composer invocation from root, app directory and a disposable app root containing
  spaces passed. Manifest/lock validation passes; runtime code and lock unchanged.
- The first discovery comparison incorrectly collapsed provider cases by method name;
  corrected to the XML case ID and verified all original expanded cases before Phase 2.
  The implementation commit followed successful full tests/package; this evidence
  correction is recorded separately.
- Composer emitted a low-free-space advisory for shared `/tmp` during expected
  failing probes. No operation failed for space; no unrelated data was removed.

## Phase 2 — whole-library analysis

- PHPStan **2.3.1** satisfies the reviewed `^2.1` constraint. It is the only added
  locked package; all original lock entries and runtime dependencies are unchanged.
- Initial whole-library run: **139 diagnostics** (134 missing host references and
  five redundant self imports). Final `analyse:lib`: **zero errors**, level 0 over
  all `lib/`, PHP target 8.2, no baseline, ignore or excluded production path.
- 39 analysis-only host declarations (35 missing classes plus four Deck ancestors/
  interfaces) preserve **500 member declarations** and class headers from official
  Nextcloud 33.0.0 / Deck 1.17.0 sources. Provenance and SHA-256 are recorded in
  [the manifest](../../../../mcp/phpstan/README.md). No test bootstrap is loaded.
- Negative probes detect unknown functions/classes, a missing host declaration and
  a misspelled host method at the appropriate level; corrected input passes. A
  side-effect marker proves scanned declarations are not executed.
- Signature reconciliation and all original JSON fixture hashes pass. Runtime
  changes are exactly five removed self-import lines; no signature/body changed.
- Full tests: **5,260 / 44,788**, 27.871 seconds. Lint: 903 files; architecture and
  API gates pass (API **2,129 / 4,126**). Production archive and autoload-map inspection
  confirm no analyzer, host model, config, tests, source scripts or cache leakage.
- Composer reported existing PSR-4 warnings for test doubles in foreign namespaces
  during its optimized autoload rebuild; they are not PHPStan suppressions. The
  existing explicit test loaders and all tests remain intact.

### Phase 2 process decisions

- `/tmp` scratch disappeared during a tool refresh. Committed phase reports preserve
  earlier evidence; original hashes were reconstructed from Git and current analysis
  was rerun. Scratch moved to persistent per-user state outside the repository.
  Cost: the earlier raw uncommitted logs are unavailable; recorded results are retained.
- Permit five self-import removals alongside PHPDoc corrections: they resolve real
  duplicate-name diagnostics without changing resolution or behavior. Cost if wrong:
  restore the imports; full compatibility tests protect behavior.
- Preserve public/protected host surfaces, private constructors, properties/constants
  and required ancestors rather than incomplete call-only sketches. This preserves
  interface/inheritance contracts. Cost: 39 maintained declaration files; source
  signatures remain auditable and never enter runtime.

## Phase 3 — stronger core

- The exact reviewed core runs at level 5, independently of the level-0 library
  scope. Shared settings contain no inherited paths, level or cache directory.
- Initial result: one `function.impossibleType` diagnostic. Two validator input
  PHPDocs incorrectly promised string-only keys before validation. Existing tests
  exercise root/nested lists and the implementation explicitly rejects them. The
  annotations now describe unvalidated array keys; native signatures, defensive
  checks and output types are unchanged. Both analysis scopes pass with zero errors.
- A wrong array argument to the real ToolContext fails; a string passes. A temporary
  error outside the core still fails `analyse:lib` when a clean file is supplied;
  passing `--level 0` to `analyse:core` cannot hide the core argument error. All probes
  were removed in guaranteed cleanup. The probe was corrected to return its object
  rather than trigger an additional unused-expression diagnostic.
- Focused core/contracts: **57 / 766**; lint: 903 files. Full suite: **5,260 / 44,788**,
  27.168 seconds; package passes; original captured JSON hashes remain unchanged.
- [Static analysis guide](../static-analysis.md) documents scope, provenance,
  maintenance and the distinction between parser, analyzer and native host coverage.

## Phase 4 — CI, contribution workflow and package boundary

- `check` composes validate, lint, both analysis scopes, tests and package. A disposable
  manifest copies the real orchestration and substitutes recording leaves: a lint
  failure stops all later steps; extra filter arguments reach none of the leaves.
- Seven parameterized archive cases reject analyzer symbols/config, tests, scripts,
  both cache roots and the PHPStan vendor member. The six new top-level checks and
  the absent `check` command first failed; existing vendor rejection already passed.
  All script tests then passed: **45 / 140**, 3.282 seconds.
- Tests CI retains PHP 8.2–8.5; quality explicitly uses PHP 8.2 for validate/lint/analyse;
  package uses Composer once; the existing REUSE job and read-only permissions remain.
  Workflow YAML parses locally. Remote actions were not run.
- Clean source export without vendor fails visibly for test and analysis commands.
  Locked installation and platform requirements pass; canonical `check` succeeds
  independently of devbox. Existing Composer test-double PSR-4 warnings stay visible.
- Active-worktree `check`: **5,268 tests / 44,813 assertions**, 28.443 seconds,
  516.92 MB. Clean-export `check`: same totals, 27.649 seconds, 516.92 MB.
  Both lint **903 PHP files**, report **zero errors** for each analyzer scope and
  successfully build/validate the production archive.
- Final discovery contains **5,268 unique expanded case IDs**: the original 5,253
  plus 15 tooling/archive cases. Phase 1 reconciled the original IDs; final Git
  comparison confirms every original test file is byte-identical except the additive
  package test/provider. All original JSON fixture bytes and original locked package
  records are unchanged. PHPStan remains the only added dependency, development-only.
- Fresh PHP loads eight production classes (core tools/results/validation/protocol,
  Files reader/editor) using only the extracted archive autoloader, without host
  stand-ins or development bootstrap. Classmap paths remain inside the archive;
  neither PHPUnit nor PHPStan resolves. Archive development-path checks pass.
- All five final Logs paths expose representative new source files to Git with
  case-insensitive matching. Synthetic paths were removed. Obsolete exceptions are gone.
- Contributor/setup/testing/analysis guides, authoring instructions, both root and
  packaged README languages and index/spec status agree with the implemented commands.
  Packaged READMEs require a source checkout and contain no absent docs-tree links.
- Changed Markdown file targets resolve; changed source attribution is covered by
  inline SPDX headers or existing REUSE.toml annotations. `git diff --check` passes.
  This attribution inspection is not a claim that the REUSE action ran locally.
- Verification helpers initially used the wrong XML element name and treated newly
  added tests as changed originals; corrected inspection uses `testCaseMethod` IDs
  and the original Git tree. The archive smoke probe initially named tool operations
  instead of actual reader/editor classes; correcting those probe names passed.
  No production change or suppressed error was used to repair these probes.

## Pending work recorded at implementation closure

- Run remote PHP 8.2–8.5 and REUSE jobs; run real Nextcloud 32–35 release checks.
  Local PHP 8.4 and stable33 static declarations do not establish those results.
- The external devbox profile still uses the old tests/package gate. Its owner should
  map `test` to `composer --working-dir=mcp test` and `check` to
  `composer --working-dir=mcp check`; `setup` remains locked Composer installation.
  No profile or harness metadata was changed by this repository task.
- Evaluate stronger analysis by capability, test/script analysis, automated formatting
  and frontend checks in separately reviewed scopes. No placeholder commands exist.
- Administrative JavaScript separation and broader README/docs navigation/i18n remain
  independent migration work. No runtime adapter or transitional tooling is retained.

## Final independent review and closure

A fresh reviewer examined `aa03b2d..ead0e93` read-only and independently reconciled
all 39 host class headers and 500 members. No critical findings or deferred minors.
Two important findings were corrected in one pass:

1. Eleven DAV declaration headers incorrectly added AGPL-3.0-or-later alongside
   the original AGPL-3.0-only identifier. Preserve the exact original identifiers,
   add the matching license text to root `LICENSES/`, and correct the notice and
   symbol guide. A source-to-declaration identifier/text check failed for all eleven
   before the fix and passes for all 39 afterward. No declaration signature changed.
2. The authoring guide still called the old external devbox task the full check.
   A targeted documentation check reproduced this; the guide now gives Composer
   `check` and explicitly states the external catalog's limited current scope.
   The same check passes after the correction.

The final complete `composer --working-dir=mcp check` passes: **5,268 tests /
44,813 assertions**, 27.756 seconds, 516.92 MB; **903** syntax-checked files; **zero**
errors in both analysis scopes; production package built and validated. Changed
local Markdown targets and whitespace checks pass. The final corrections affect
attribution and documentation only. No second review or unrelated implementation
was added. The task-created clean export, extracted package, downloaded reference
sources and external scratch are removed at closure; unrelated build artifacts and
the final app archive remain. The existing branch/worktree stays in place.

### Rulings on review boundaries

- Remote PHP/REUSE and real-host results remain explicitly pending because these
  environments were not exercised. Do not infer results from local gates. Cost if
  wrong: CI or integration failures remain undiscovered until those gates run.
- Existing package portability/archive-normalization behavior remains unchanged;
  this task adds development-path rejection and verifies the generated archive.
  Cost if wrong: pre-existing portability or normalization defects need separate fixes.
- Stronger checks outside the named core and dynamic Talk behavior remain bounded
  follow-up work. Existing contracts and real-host obligations are retained. Cost:
  level-0/dynamic-boundary defects can remain outside current static detection.

The earlier process decisions and these three boundaries are the complete ruling
record. No minor review finding is deferred. No push, PR, merge or deployment ran.
