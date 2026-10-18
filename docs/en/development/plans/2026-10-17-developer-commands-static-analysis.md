<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Developer commands and static analysis implementation plan

> **For agentic workers:** After implementation is authorized, use
> `superpowers:executing-plans` to execute the phases in order. Check each phase's
> evidence before advancing; use the existing isolated worktree and branch.

**Status:** Implemented and locally validated. See the [execution report](2026-10-17-developer-commands-static-analysis-report.md)
for evidence, review and external gates. The evidence/decision tables below preserve
the pre-implementation baseline; Section 5 records the historical planning review.
**Date:** 2026-10-17.
**Baseline:** `c45f907d8ea0593991835869eeac5b377e2a91d4`.
**Goal:** Give contributors one predictable command surface, reproduce CI locally,
and introduce useful static checks without hiding errors or changing application behavior.
**Architecture:** Composer owns the contributor commands in `mcp/`. Small scripts
handle only operations Composer cannot express directly. PHPStan checks all production
classes at a basic level and a named core at a stronger level, independently of PHPUnit doubles.
**Tech stack:** PHP >=8.2, Composer >=2.8 for contributor commands, PHPUnit 10.5; development-only PHPStan ^2.1 (locked to 2.3.1).
**Spec:** [Architecture specification](../architecture-specification.md), especially
requirements G3–G7 and sections 8 and 10; this plan defines the bounded tooling milestone.
**Lessons:** [Shared MCP execution report](2026-10-17-shared-mcp-infrastructure-migration-report.md),
[Files report](2026-10-16-files-capability-migration-report.md), and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Keep PHP >=8.2, app ID `mcp`, PSR-4 `OCA\Mcp`, declared Nextcloud 32–35 support,
  all 76 published tools and runtime dependencies unchanged. Keep PHPUnit 10.5.
- Preserve grants, visibility, confirmation, audit, locking, failure precedence,
  public schemas/messages and frozen compatibility fixtures. Tooling must not
  initialize Nextcloud or resolve optional applications during analysis.
- Code, comments, PHPDoc and canonical documentation are English. Preserve existing
  localized content and link only to translations that exist.
- No application refactoring, runtime type tightening, broad reformat, dependency
  upgrades unrelated to PHPStan, database changes, admin JavaScript migration or
  documentation website. Formatting/style automation needs a separate reviewed scope.
- No suppression baseline (generated or handwritten), `ignoreErrors`, inline ignores,
  excluded production files/directories, permissive fake host APIs or application
  shims to make analysis pass. Do not lower levels, disable individual rules or
  hide failures behind successful shell exits or CI `continue-on-error`.
- Preserve the selected branch/worktree. Keep harness state external; ignored build
  output stays inside `mcp/build/`. Do not relocate package-manager caches to `/tmp`.
- No push, PR, release, deployment or persistent external harness modification is
  implied. Future phase commits are local and follow successful checks.

## Review focus

1. A contributor runs from the root, a path containing spaces, or passes a quoted
   filter: command arguments and exit status must survive unchanged (Phase 1).
2. A filter matches nothing, dependencies are missing, or a child command fails:
   verification must fail visibly instead of producing a misleading green result (1, 4).
3. OCP, core/DAV and optional-app symbols differ from test doubles: analysis must use
   evidence-backed declarations without executing test fixtures or a live host (2).
4. A production file is added outside the stronger core, or a private local config
   relaxes rules: the committed whole-library gate still covers it (2–4).
5. Packaging follows development setup: neither PHPStan nor its configuration,
   symbols, tests or caches may enter the distributable or affect runtime loading (2, 4).

## 1. Evidence and decisions

| Current evidence | Consequence | Proposed decision |
| --- | --- | --- |
| `mcp/composer.json` has no scripts; no root Composer manifest or Makefile. | Contributors copy commands from multiple places. | Keep one manifest; use `composer --working-dir=mcp …` from the root. No second command framework. |
| CI and the external devbox catalog pass `memory_limit=1G`, `post_max_size=1G`, `upload_max_filesize=1G`; some README/authoring examples invoke PHPUnit directly. | Environment-dependent failures; the previous migration observed the default upload limit fail an existing expectation. | Put these values in the single test command and route focused aliases through it. |
| `mcp/phpunit.xml` has one `unit` suite covering all of `tests/`, including architecture and contract checks. | Renaming/splitting suites can silently lose cases. | Keep discovery unchanged; offer directory-based subsets. |
| 559 PHP files in `lib/`; 336 under `tests/`, one script, one appinfo PHP file and three templates. | A small static pilot alone would leave most production unchecked. | Basic whole-library analysis plus a stronger explicit core; syntax-check all five first-party trees. Counts are observations, not limits. |
| No installed/locked PHPStan or Psalm package and no analyzer configuration. Mentions inside dependencies' `require-dev` metadata are not installations. | There is no measured analyzer error inventory yet. | Add one analyzer; record its initial diagnostics before deciding corrections. No claim that the proposed levels already pass. |
| `nextcloud/ocp` is locked to a stable33 revision and supplies no Composer autoload mapping. `tests/bootstrap.php` registers OCP/OC loaders and loads a test stub. | Reusing PHPUnit bootstrap would give analysis a different host model. | Use static symbol discovery, separate from executable test doubles. |
| `Platform/Dav` names `OCA\DAV` classes; Deck/Talk have dynamic native boundaries. | OCP alone cannot describe every integration. | Model only consumed missing signatures with provenance; retain native API contract tests and real-host gates. |
| `scripts/package.sh` creates a staged `--no-dev` install; `check_package.php` has a production vendor allowlist. | Tooling must remain development-only, including Composer autoload metadata. | Preserve the packaging implementation and add targeted leakage checks if existing assertions do not cover them. |
| Root and packaged READMEs duplicate test commands; both packaged READMEs still cite `tests/Unit/Tools/FakeTree.php`. | New contributors can follow retired paths. | Correct command/path references in Phase 4 without moving operational manuals. |
| Root `.gitignore` still contains old Tools/Logs exceptions. | Case-insensitive ignore matching can hide future files under final Logs owners. | Replace them with exact final source/test/support exceptions and verify matching. |

The previous execution report records 5,253 tests / 44,744 assertions and 2,129
native API tests / 4,126 assertions. Those are historical results, not tests rerun
for this planning task. No analyzer was installed or executed during planning.

### Command interface

All examples below run from the repository root. From `mcp/`, omit `--working-dir=mcp`.
Only `test` and its focused aliases accept additional PHPUnit arguments.

| Command | Contract |
| --- | --- |
| `composer --working-dir=mcp install` | Install the committed lock; never update dependencies implicitly. |
| `composer --working-dir=mcp validate --no-check-publish` | Validate manifest/lock consistency; preserve intentional pinned dependencies and review warnings. No `--no-check-lock`. |
| `composer --working-dir=mcp test` | Full existing PHPUnit discovery, all three PHP limits, `--fail-on-empty-test-suite`. |
| `composer --working-dir=mcp test -- tests/Unit/Modules/Files/Reading/ReadFileTest.php` | One file through the same test entry point. |
| `composer --working-dir=mcp test -- --filter 'EditFileTest\|TextFileEditorTest'` | Quoted filter forwarded to PHPUnit; zero selected tests fails. |
| `composer --working-dir=mcp test:architecture` | `test` with `tests/Architecture`. |
| `composer --working-dir=mcp test:api` | `test` with `tests/Unit/Contract/NextcloudApi`. This includes scanner tests as well as API cases. |
| `composer --working-dir=mcp lint` | Syntax-check PHP in `lib`, `appinfo`, `templates`, `scripts`, `tests`; no code execution or formatting. |
| `composer --working-dir=mcp analyse:lib` | Whole `lib/`, PHPStan level 0; zero diagnostics. |
| `composer --working-dir=mcp analyse:core` | Stronger selected core, PHPStan level 5; zero diagnostics. |
| `composer --working-dir=mcp analyse` | Run both analysis gates; stop on the first failure. |
| `composer --working-dir=mcp package` | Existing `sh scripts/package.sh`, including archive validation; may download locked production packages. |
| `composer --working-dir=mcp check` | Validate manifest/lock, lint, both analysis gates, full tests, then package; stop at failure. REUSE remains a separate documented CI job. |

Use Composer script references, descriptions and `@php` rather than a custom task
runner. The `test` script is exactly
`@php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --fail-on-empty-test-suite`.
Use `@test tests/Architecture` and `@test tests/Unit/Contract/NextcloudApi` for subsets.
Use Composer >=2.8's `@no_additional_args` on every command/reference inside
`check`, `analyse`, `analyse:lib`, `analyse:core` and `package`. These fixed gates
intentionally discard extra script arguments and always run their full configured
scope; document that behavior in their descriptions. In particular, `check --
--filter X` must not narrow tests and `analyse:core -- --level 0` must not lower the
level. Only `test`/its subsets forward PHPUnit options; `lint` may forward focused
paths when invoked directly. Use `@composer validate --no-check-publish
@no_additional_args` in `check`, then `@lint`, `@analyse`, `@test`, `@package`, each
with `@no_additional_args`. CI calls fixed gates without arguments. Set Composer
>=2.8 explicitly as a development prerequisite and in workflow tool setup; do not
add a runtime dependency on Composer to the packaged app.

Disable Composer's process timeout only for verification scripts, through `Composer\Config::disableProcessTimeout`, rather
than globally changing installation behavior. Test forwarding with this callback
present. Do not add install/update/autoload hooks that run these gates: packaging
installs without development dependencies or source scripts.
Composer documents [script references, PHP execution and argument forwarding](https://getcomposer.org/doc/articles/scripts.md).

### Analysis scope and honesty

Choose **PHPStan `^2.1`**, pinned by `composer.lock`, solely in `require-dev`.
Do not add a second analyzer, extension installer, strict-rules bundle or formatter.
Resolve a PHP 8.2-compatible release without changing existing runtime packages or
locked OCP/Sabre/DBAL/Symfony/PHPUnit versions. An unavoidable conflict requires
revisiting this plan, not silently updating the host API reference.

Two permanent gates have different responsibilities:

- `phpstan.neon`: level **0**, `paths: [lib]`, so new production PHP files join the
  basic gate automatically. Level 0 is an initial error floor, not full type safety.
- `phpstan/core.neon`: level **5**, exact initial app-relative paths below (prefix
  them with `../` inside this nested configuration). It shares environment
  settings with the library gate, but does not include that gate's level/path list.

```text
lib/Mcp/Tools/Tool.php
lib/Mcp/Tools/WriteTool.php
lib/Mcp/Tools/ToolModule.php
lib/Mcp/Tools/RestrictedModule.php
lib/Mcp/Tools/ToolContext.php
lib/Mcp/Tools/ToolDefinition.php
lib/Mcp/Tools/DavCollectionSchema.php
lib/Mcp/Tools/Validation/
lib/Mcp/Tools/Results/
lib/Platform/Logging/
lib/Platform/Time/
```

This core exercises schemas, arguments, results, exception privacy and an OCP
consumer without demanding a whole-application typing rewrite. Level 5 checks
argument types in addition to earlier checks; see the official [rule levels](https://phpstan.org/user-guide/rule-levels).
Expanding level 5 to more capabilities and eventually all production is follow-up
work, not a condition hidden inside this milestone. Tests, templates, scripts and
appinfo are syntax-checked, not claimed as PHPStan-covered production classes.

Shared settings: `phpVersion: 80200`, `reportUnmatchedIgnoredErrors: true`, Composer
autoload for installed dependencies, and bounded parallelism (at most two workers).
Use explicit `--configuration` paths in Composer so local config discovery cannot
silently weaken CI parity. Put each gate's cache under ignored
`mcp/.cache/phpstan/lib` or `mcp/.cache/phpstan/core`; paths are relative to the config
file containing them: `phpstan.neon` includes `phpstan/common.neon`, uses `lib` and
`.cache/phpstan/lib`; `phpstan/core.neon` includes `common.neon`, uses `../lib/…`
and `../.cache/phpstan/core`. Common config contains no `paths`, `level` or `tmpDir`
to avoid additive path inheritance and cache collisions. Both commands use
`@php vendor/bin/phpstan analyse --memory-limit=1G --no-progress` plus their explicit
`--configuration` and `@no_additional_args`. No cache or baseline is committed.
These options and relative-path rules are defined in the [configuration reference](https://phpstan.org/config-reference).

No suppression baseline or inline ignores are part of this milestone. Correct
inaccurate PHPDoc using observed callers/behavior; never invent a type assertion
that hides real uncertainty. Do not remove defensive checks to satisfy dead-code
warnings. Genuine behavior defects require their own reviewed fix; a blocked
analysis gate remains visibly incomplete until resolved. If zero-diagnostic gates
require a broader behavior change, pause that phase with the exact findings and a
scope proposal instead of reducing levels or omitting files.

### Host symbols and boundaries

1. Discover installed OCP symbols with `scanDirectories: [vendor/nextcloud/ocp]`
   as an app-relative description: the actual value in `phpstan/common.neon` is
   `../vendor/nextcloud/ocp`. Composer resolves other installed libraries. A missing
   required scan directory or declaration must fail, not silently remove coverage.
2. Inventory missing non-Composer symbols by FQCN, consumed members, source
   release/commit and signature before creating declarations. Use existing
   `tests/fixtures/nextcloud-api/*.php`, `NextcloudApiUsage::SOURCES` and pinned
   upstream source as evidence; fixtures are metadata, not PHP class declarations.
3. Place the necessary static declarations under `mcp/phpstan/symbols/`, organized
   by external namespace (`OC/`, `OCA/DAV/`, `OCA/Deck/`, `OCA/Talk/` only when
   actually consumed). Mirror the actual FQCN, including its spelling; do not
   assume the Talk app ID `spreed` is its PHP namespace.
4. Register those files for **symbol scanning**, never `require`, test bootstrap,
   Composer autoload or app registration. Preserve inheritance, arity, visibility
   and evidenced signatures. No fabricated `__call`, blanket `mixed` methods or
   universal-object configuration. Dynamic boundaries not inferred by PHPStan
   remain explicitly covered by existing API contracts, not falsely typed away.
5. Record a manifest in `phpstan/README.md` mapping every declaration to its exact
   source revision, consumed members and signature evidence. Initial host modeling
   uses the existing stable33/OCP reference and matching API snapshot versions; keep
   cross-version guards and tests intact. Reconcile each declared member with that
   evidence, including return types/inheritance not fully captured by API fixtures;
   consult pinned upstream sources where needed. No duplicate FQCN or contradictory
   declaration may be added. Repeat this reconciliation when source versions change.
6. Document provenance beside each declaration. If a PHPDoc correction to an
   already-discovered vendor symbol is necessary, use a separate `.stub` under
   `phpstan/stubs/`, registered through `stubFiles`. Do not create an empty folder
   or a stub when discovery alone suffices.

PHPStan distinguishes [symbol discovery](https://phpstan.org/user-guide/discovering-symbols)
from [PHPDoc stub overrides](https://phpstan.org/user-guide/stub-files).
Host declarations are a maintained development integration boundary, not temporary
runtime replacements. They must never override authoritative OCP declarations.
Do not load broad Deck/Talk/DAV PHPUnit doubles into production analysis. Existing
API snapshots cover multiple versions (including retained 31 fixtures), whereas
OCP stable33 is one reference; neither this analysis nor those snapshots replace
real Nextcloud 32–35 testing.

## 2. Final file ownership

Paths are repository-relative. Create only the files needed by the corresponding phase.

| File / area | Responsibility |
| --- | --- |
| `mcp/composer.json`, `mcp/composer.lock` | Command definitions, descriptions and locked dev-only analyzer. |
| `mcp/scripts/lint.php` | Small deterministic PHP syntax walker using `PHP_BINARY -l` in subprocesses; paths anchored to the app directory. |
| `mcp/phpstan.neon` | Whole-library level and scope. |
| `mcp/phpstan/common.neon`, `mcp/phpstan/core.neon` | Shared host/environment settings; stronger core scope. |
| `mcp/phpstan/README.md`, `symbols/`, optional `stubs/` | Host signature provenance and narrowly scoped analysis declarations; no runtime code. |
| `mcp/tests/Unit/Scripts/LintTest.php` | Syntax-runner process and path behavior. |
| `mcp/tests/Unit/Scripts/DeveloperCommandsTest.php` | Composer command subprocess smoke tests; never recursively invoke the full test/check aggregate. |
| `mcp/scripts/check_package.php`, `mcp/tests/Unit/Scripts/CheckPackageTest.php` | Enforce and test rejection of development files outside `vendor/`, preserving existing archive behavior and the vendor allowlist. |
| `.github/workflows/tests.yml` | Existing PHP 8.2–8.5 test matrix invokes the canonical test command. |
| `.github/workflows/quality.yml` | Manifest validation, lint and both analysis gates on PHP 8.2; explicit commands, no second rule definition. |
| `.github/workflows/package.yml` | Canonical package command; retain existing REUSE job and read-only permissions. |
| `.gitignore` | Final Logs owner exceptions; existing `.cache/` rule already covers analyzer caches. |
| `REUSE.toml`, `mcp/THIRD-PARTY-NOTICES.md` | Required attribution for new tooling files and development dependencies. |
| `CONTRIBUTING.md`, `docs/en/development/setup.md`, `testing.md`, `static-analysis.md` | Contribution entry, installation, command/coverage contracts, analyzer maintenance. |
| Both root READMEs, both packaged READMEs, docs indexes, architecture spec and authoring guide | Correct stale commands/paths and link current guidance; preserve operational content. |

`lint.php` accepts optional file/directory arguments for focused checks; with no
arguments it walks the five fixed trees. Sort files, handle spaces without shell
interpolation, do not follow directory symlinks, exclude dependency/build/cache
subtrees, and fail on missing paths, zero PHP files or any parse/process error.
It must use no dependency autoloader and never execute inspected source. Do not
turn it into a generic process framework or merge it with the package checker.

## 3. Ordered implementation phases

No phase advances on an uninspected or failing result. Each phase ends with a
focused local commit after its gate passes. Preserve original tests/data providers
and all captured public output; add tests only for newly introduced tooling risks.

### Phase 0 — Reproduce the baseline and inventory coverage

**Files:** Read existing Composer/CI/scripts/tests and the prior report. Create
`docs/en/development/plans/2026-10-17-developer-commands-static-analysis-report.md`
as an in-progress evidence record, updated by every phase and finalized in Phase 4.
**Interface:** Baseline test discovery, frozen hashes, dependency versions and a
missing-host-symbol inventory feed Phases 1–3.

- [x] Record HEAD, clean/dirty status, PHP/Composer/extensions, tracked first-party
  PHP paths, existing command destinations, fixture hashes and PHPUnit XML discovery.
- [x] Run `devbox run check --cwd WORKTREE --json` in this environment, or the exact
  current PHPUnit command and `sh scripts/package.sh` elsewhere. Inspect completion.
- [x] Record full test counts, focused Files read/edit time and whole-suite elapsed
  time with environment/cache state; no numerical speed target is promised.
- [x] Inventory non-Composer host references and existing snapshot provenance.
  Record the distinction between PHPStan checks, API contracts and real-host tests.

**Acceptance:** Existing full tests/package pass, or pre-existing failures are
explicitly resolved before proceeding. No baseline fixture recapture. The command
inventory accounts for CI, docs and the external devbox catalog.

### Phase 1 — Canonical test, syntax and package commands

**Files:** Composer scripts; new lint script and its two named test files; existing
package script/checker remain the sole packaging implementation.
**Interface:** Produce `test`, `test:architecture`, `test:api`, `lint`, `package`.
Do not advertise `analyse`/`check` before their implementations exist.

- [x] Add failing subprocess cases in `DeveloperCommandsTest` for a quoted filter
  selecting a tiny temporary fixture, a no-match filter returning nonzero, and a
  deliberately failing fixture returning nonzero. Capture effective PHP limits
  in that fixture, not just Composer JSON strings. Pass `--no-configuration` and
  the explicit temporary fixture path so the subprocess cannot discover its parent
  suite. Fixtures live in ignored build output and are removed by test teardown;
  they are not permanent PHPUnit cases.
- [x] Add `LintTest` cases for valid PHP that would throw if executed, invalid PHP,
  a filename containing spaces, a missing path, and a directory with zero PHP files.
  Verify diagnostic filenames and process exit codes.
- [x] Run those tests with the original raw PHPUnit command and record the expected
  missing-command/script failures. Implement the command contract and syntax walker.
- [x] Verify the focused cases pass; run a focused Files test, architecture and API
  subsets and canonical `lint`, then the complete canonical test command and
  package command. Compare expanded original case identities, allowing only intentional tooling-test additions.
- [x] Check both root `--working-dir=mcp` and app-directory invocation. Exercise a
  path-with-spaces fixture and a disposable app root containing spaces, without
  renaming the actual worktree. Verify a syntax/process failure in a default lint
  tree fails the full lint command; exclude only named dependency/build/cache trees,
  not arbitrary untracked files. Clean probes before other gates run.

**Acceptance:** One source for test limits; correct forwarding and failure status;
zero-test runs fail; unchanged full discovery and packaging. No live host is needed.

### Phase 2 — Establish the whole-library static gate

**Files:** Dev dependency/lock; common and library PHPStan config; evidenced symbol
files/README; minimal truthful PHPDoc corrections in diagnosed owners; attribution.
**Interface:** Produce `analyse:lib`, level 0 over every file under `lib/`.

- [x] Add only PHPStan to `require-dev` and resolve its lock on the PHP 8.2 platform.
  Diff all locked package versions, runtime requirements and autoload mappings.
- [x] Run the proposed library configuration before corrections; record diagnostics
  by unresolved host symbols, inaccurate annotations, genuine defects and unsupported
  dynamic boundaries. Do not generate an ignore baseline.
- [x] Add only the verified host declarations needed for static scanning. Check
  provenance and every supplied signature against API snapshot/source versions;
  never copy executable doubles. Remove one required declaration in a disposable
  config and verify failure; a return to a clean run requires restoring the genuine
  declaration, not an ignore or invented signature.
- [x] Correct inaccurate documentation types without altering runtime signatures
  or behavior. Separately report genuine defects and stop this gate if their fix
  requires broader authorization. Repeat analysis until zero diagnostics.
- [x] In an ignored disposable configuration/fixture, seed an undefined function
  and unresolved class, then a misspelled method on a modeled host class (using a
  disposable level-2 configuration sharing the same host settings). Confirm each
  is reported by its intended rule level, remove the defect and confirm success. Confirm symbol scanning executes no fixture/host code.
- [x] Run canonical lint, architecture/API contracts, full tests and package. Inspect
  archive members and Composer autoload maps for analyzer/config/symbol/cache leakage.

**Acceptance:** Every production class is in the committed level-0 scope; no
suppression baseline, missing-symbol ignores or runtime stand-ins. Analyzer and
host setup work offline after locked dependencies are installed. Existing API and
compatibility assertions remain unchanged. Record the limits of level 0 honestly.

### Phase 3 — Enforce stronger checks on the defined core

**Files:** Core configuration, named core owners' PHPDoc only when needed, analysis
command composition and the static-analysis guide.
**Interface:** Produce `analyse:core` at level 5 and `analyse` running both gates.

- [x] Analyze every listed core path at level 5 and classify findings before edits.
  Share environment config without inheriting the level-0 path array into this scope.
- [x] Resolve evidenced PHPDoc errors. Do not add casts, runtime type changes,
  assertions or removed branches merely to satisfy the analyzer.
- [x] Use an ignored temporary fixture invoking real `ToolContext`/`ToolDefinition`
  with an array in a required string argument to demonstrate a level-5 type error,
  then replace it with a string and demonstrate success. Keep fixtures out of
  permanent production scopes and package output.
- [x] Verify a new temporary production file outside the core with a level-0 error
  is caught by the whole-library gate; remove it in a guaranteed cleanup step.
  Check `analyse:lib -- <existing-clean-file>` still detects that out-of-file error,
  and `analyse:core -- --level 0` still detects the seeded core type error. Place the
  core probe temporarily inside a configured directory and clean it in `finally`.
  No CLI argument may substitute the fixed gate's configured paths or rule level.
- [x] Run lint, both gates, relevant core unit/contract tests and full tests/package
  before committing. Record the precise scopes, zero diagnostics and immutable fixture hashes.

**Acceptance:** Both gates are mandatory, independently reproducible and clean.
The stronger core has no baseline/exclusion escape; no claim of level-5 coverage for
all 559 production files. Future expansion cannot silently lower existing coverage.

### Phase 4 — CI parity, contributor guides and final cleanup

**Files:** Aggregate `check` command; workflows; docs/README/CONTRIBUTING references;
ignore/attribution updates; targeted existing package tests; final execution report.
**Interface:** Produce the complete command table and contribution workflow.

- [x] Add `check` in the specified order. Use controlled subprocess smoke checks to
  prove it stops on an early failure and never reaches packaging. Also prove
  `check -- --filter NoSuchTest` forwards no filter to its children. Use a disposable
  manifest copying the real script definitions with leaf commands replaced by
  recording/failing probes for these orchestration checks, then run the actual
  no-argument gate separately; never recursively run the full aggregate from PHPUnit.
- [x] Switch the PHP 8.2–8.5 test job to `composer test`; add the PHP 8.2 quality job
  for validate/lint/analyse; switch the package job to `composer package`. Preserve
  event triggers, failure visibility, REUSE and read-only repository permissions.
  Tests/quality jobs install locked dev dependencies before commands and use the
  same PHP extensions. The package job stays independent of the source vendor tree;
  its staging script alone installs production dependencies. Set the PHP 8.2 quality
  runtime explicitly: `php -l` uses the running PHP and is not an 8.2 parser merely
  because PHPStan's target is 80200.
  Do not run `check` in every matrix cell and rebuild the package four times.
- [x] In a disposable source checkout without `vendor/`, verify commands needing
  dependencies return nonzero. Follow the documented installation steps and
  `composer --working-dir=mcp check-platform-reqs`, then run the canonical gates.
  Do not remove the active worktree's vendor directory or bypass platform checks.
- [x] Document extensions used by CI, setup from a clean clone, all command working
  directories, focused examples, failure diagnosis, analysis scope and host provenance.
  Update root README entry points and the authoring guide. Correct commands and the
  fake-tree path in both packaged READMEs without relocating operational documentation.
- [x] Keep packaged README instructions usable when `docs/` is absent from the
  archive: explain development commands require a source checkout and `composer install`
  with dev dependencies. Do not add broken `../docs/` archive links. New English
  guides can have explicitly labeled English fallbacks from Portuguese entry points.
- [x] Replace obsolete Logs ignore exceptions with existing final paths under
  `mcp/lib/Modules/Logs`, `mcp/lib/Access/Logs`, `mcp/tests/Unit/Modules/Logs`,
  `mcp/tests/Unit/Access/Logs` and `mcp/tests/Support/Logs`. Verify representative
  new filenames using `git -c core.ignoreCase=true check-ignore --no-index -v` and
  actual untracked-path discovery; do not merely inspect already tracked files.
  Preserve unrelated ignore rules and remove synthetic paths after the check.
- [x] Verify the production archive excludes `phpstan/`, configs, tests, scripts,
  caches and dev vendor packages. Extend `check_package.php` and `CheckPackageTest`
  with parameterized negative archives for `mcp/phpstan/`, `mcp/phpstan.neon`,
  `mcp/tests/`, `mcp/scripts/`, `mcp/.cache/` and `mcp/.phpunit.cache/`, plus a PHPStan
  vendor member; preserve the clean-archive positive case and current rejections.
  This closes a real gap: the current vendor allowlist does not reject those
  top-level development paths. Composer metadata can still mention dev dependencies
  and script names; it must not autoload or execute them in a production install.
  A fresh PHP process must load production classes using only the archive autoloader
  and explicit minimal host declarations, never the development bootstrap.
- [x] Run canonical `check`, reconcile all original expanded test cases and capture
  hashes, validate local Markdown links and attribution, and inspect `git diff --check`.
  Record actual PHP matrix/REUSE job results when available; mark unrun remote checks
  pending rather than claiming them passed locally.
- [x] Record initial/final commands, levels, scopes, diagnostics, timings, changes,
  tests and unresolved external checks in an execution report. Delete task-created
  probes and empty temporary directories; preserve unrelated build artifacts.

**Acceptance:** A clean source checkout plus documented prerequisites can run all
local gates without devbox. CI uses the same command definitions. No duplicate
runner, transitional config, unused helper, stale live development path, ignored
new source file or package leak remains. Existing package scripts and the two
analysis scopes are final owners, not wrappers scheduled for deletion.

## 4. Closure and remaining work

The milestone finishes with usable commands and zero-diagnostic configured gates,
not a claim that all possible static errors or real-host regressions are eliminated.
Full PHPStan analysis of tests/scripts/templates, level-5 expansion to other
capabilities, stricter levels, automated style/formatting and frontend checks require
separate evaluated scopes. Do not add a placeholder `style` command that does nothing.

The external devbox catalog currently duplicates PHPUnit flags and packages after
its test command. Document the final mapping for its owner: `setup` stays Composer
install, `test` becomes `composer --working-dir=mcp test`, `check` becomes
`composer --working-dir=mcp check`. Apply and verify that external profile change
only under the environment's authorization; it is not a repository commit. Until
updated, label devbox's old `check` as tests/package only, not the new complete gate.
Never commit harness metadata or introduce a repository dependency on devbox.

Real Nextcloud 32–35 checks and the PHP 8.2–8.5 matrix remain the existing release
obligations; a green PHPStan run against stable33 does not discharge them. Admin
JavaScript separation and broader README/docs navigation/i18n remain independent
plans. Rollback uses focused Git commits; no data migration or runtime fallback is
needed for this tooling change.

## 5. Planning review

Reviewed against `c45f907` on 2026-10-17. No implementation or analyzer installation
was performed during this review. The reviewed documentation is committed separately.

The proposal was checked against the current Composer manifest/lock, both workflows,
packager/checker, PHPUnit discovery/bootstrap, native API contracts, final source
owners, existing guides, ignore rules and the external catalog. Official Composer
and PHPStan references above were consulted on 2026-10-17.

The phases cover G3 behavior preservation through frozen tests/package checks,
G4 focused commands, G5 English tooling, G6 unchanged localized runtime output and
G7 canonical documentation. Each review-focus item maps to explicit negative and
positive validation. Corrections from review:

- Make Composer >=2.8 and fixed-gate argument handling explicit; preserve focused
  test forwarding while preventing silent narrowing of complete checks.
- Correct the nested PHPStan paths and separate shared settings from each scope/cache.
- Require signature provenance/reconciliation and missing-symbol failure checks;
  all diagnostic suppressions remain prohibited.
- Close the package checker's non-vendor leakage gap with a named implementation
  owner and negative/positive archive tests, without altering runtime dependencies.
- Require lint in intermediate phase gates, independent CI dependency setup and
  preservation of all original cases/fixtures; distinguish PHP parser and analyzer targets.
- Correct the filter example's Markdown table escaping and the documentation-only status.

At planning review time, no analyzer result or implementation acceptance was asserted.
That review changed only this plan and its index links. Subsequent implementation
and validation are recorded in the execution report linked above.
