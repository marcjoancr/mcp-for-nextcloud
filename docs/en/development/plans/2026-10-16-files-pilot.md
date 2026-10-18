<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Files read/edit pilot implementation plan

> **For agentic workers:** After explicit implementation authorization, use
> `superpowers:executing-plans` for native execution, or
> `superpowers:subagent-driven-development` if the user chooses delegated execution.
> Follow the selected environment's guidance. Do not execute this plan merely
> because it has been written. The user subsequently authorized this bounded pilot;
> see the [implementation report](2026-10-16-files-pilot-report.md) for the outcome.

**Status:** Local pilot complete and verified. Remote CI and live Nextcloud
verification remain outstanding; broader migration requires a separate decision.

**Goal:** Extract `files_read` and `files_edit` into focused tool handlers and
capabilities while preserving their public behavior and the existing policy path.

**Architecture:** Keep the current Files module as a temporary adapter behind
`ToolRegistry` and `WriteGate`. Introduce a small typed tool contract, explicit
registration, and feature-local reading/editing code. Other tools remain on their
existing implementations.

**Tech stack:** PHP >=8.2, Composer PSR-4, existing Nextcloud services, PHPUnit 10.5,
and the repository's package checks. No new production dependencies.

**Spec:** [Developer experience and modular architecture specification](../architecture-specification.md).

**Baseline examined:** `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92`.

## Global constraints

- Keep code, identifiers, comments, PHPDoc, and technical source documentation in English.
- Keep user-facing messages localized through the existing translation system.
- Keep project documentation under `docs/`, with English as its reference language and explicit translations.
- Keep PHP >=8.2, app ID `mcp`, PSR-4 namespace `OCA\Mcp`, Nextcloud 32–35 declarations, and existing production dependencies.
- Preserve names, ordering, schemas, defaults, descriptions, grants, app filters, responses, errors, and translation behavior.
- Retain the existing registry, write gate, plan renderer, audit, and error-privacy boundary.
- Do not add a required ETag or `plan_state` to `files_edit`.
- Preview must not mutate data, create backups, or issue checkout tokens.
- Preserve lock warning/refusal distinctions and backup-before-write ordering.
- Do not modify production Nextcloud, publish a preview, push, or open a PR without an explicit request.
- Keep harness state outside the repository and preserve pre-existing changes.

## Review focus

Each risk has an owning task and test assertions below:

1. A caller bypasses confirmation or receives duplicate tool definitions: Task 4.
2. A hidden ancestor/share or changed access exposes content: Tasks 2 and 3.
3. A lock appears during backup, or a backup fails: Task 3.
4. Extraction, OCR, multibyte limits, or response blocks drift: Task 2.
5. Moving source bypasses source-scanning checks or breaks the production package: Task 5.

## Requirement coverage

| Specification requirement | Pilot coverage | Deferred work |
| --- | --- | --- |
| G1: Cohesive modules | Tasks 2–4 extract two Files operations. | Remaining Files capabilities, Deck, DAV, and admin scripts. |
| G2: Shared mandatory policy | Task 4 preserves registry/write-gate dispatch and refusal results. | Wider policy-directory migration. |
| G3: Observable compatibility | Tasks 1–5 characterize, compare, and verify behavior and packaging. | No functional behavior changes are authorized. |
| G4: Explicit dependencies and focused tests | Tasks 2–6 isolate construction and measure the change surface. | General Composer aliases and gradual analysis/style tooling. |
| G5: English code and technical prose | Applies to every task; Task 6 documents authoring conventions. | Unrelated legacy comment cleanup. |
| G6: Localized user messages | Tasks 2–5 reuse messages, check translation behavior, and retain source coverage. | No translation-system redesign. |
| G7: English-first documentation in `docs/` | Task 6 adds the actual authoring guide and links existing translations. | Operational-doc relocation, translation freshness checks, and optional website. |
| G8: Incremental migration and review | Tasks 0 and 4 establish baseline/coexistence; Task 6 stops for evaluation. | Broader migration requires a separate decision. |

The broader architecture is a destination. Deferred items are not prerequisites
for accepting the bounded pilot and must not be silently added to its scope.

## Scope map

Paths are repository-relative. Listed new types do not exist at the examined
revision. Existing source references are linked separately in the tasks.

| Files | Responsibility |
| --- | --- |
| `mcp/lib/Mcp/Tools/{Tool,WriteTool,ToolContext,ToolDefinition}.php` | Minimal authoring contracts and immutable metadata/context. |
| `mcp/lib/Modules/Files/FilesToolCatalog.php` | Explicit pilot class list, validated definitions keyed by name. |
| `mcp/lib/Modules/Files/Tools/ReadFile.php` | Read schema and tool adapter. |
| `mcp/lib/Modules/Files/Reading/{FileReader,FileRead}.php` | Reading/extraction orchestration and existing two-block result representation. |
| `mcp/lib/Modules/Files/Tools/EditFile.php` | Edit schema, preview, and execution adapter. |
| `mcp/lib/Modules/Files/Editing/{TextFileEditor,EditRequest}.php` | Shared edit preparation and mutation orchestration. |
| `mcp/lib/Tools/Files/FilesModule.php` | Temporary delegation for exactly the two pilot names. |
| `mcp/lib/AppInfo/Application.php` | Required dependency bindings only where autowiring needs them. |
| `mcp/tests/Unit/Modules/Files/` | Focused tests and a minimal `FilesPilotFixture.php`. |
| `mcp/tests/Unit/Mcp/Tools/ToolContractTest.php` | New internal contract behavior. |
| `mcp/tests/Unit/Contract/FilesPilotCompatibilityTest.php` | Public schema, result, and policy compatibility. |
| `mcp/tests/Architecture/FilesPilotBoundaryTest.php` | Meaningful dependency rules for the new subtree. |
| `docs/en/development/adding-a-tool.md` | Guide based on the implemented pilot. |
| `docs/en/development/plans/2026-10-16-files-pilot-report.md` | Baseline, measured outcomes, temporary debt, and verification evidence. |

Do not rename all current test folders. Update existing construction sites when
required dependencies are added, without translating meaningful fixtures or
rewriting unrelated assertions. Preserve public constants referenced by other
consumers, even if the legacy module temporarily forwards their values.

## Task 0: Establish the implementation baseline

**Files:** Create the pilot report named above only when implementation begins.

**Consumes:** Current checkout, project catalog tasks, and both design documents.
**Produces:** Recorded revision, clean/dirty state, test baseline, and fixture/dependency measurements.

- [x] Read current repository guidance and revalidate the selected project with
  `devbox context --cwd PATH --json`; inspect `git status --short --branch`.
- [x] Use the selected isolated worktree or create one through the required
  `devbox worktree create --project mcp-for-nextcloud --slug files-tool-pilot --json`
  workflow. Validate the returned path before editing. Never edit the base checkout
  as a fallback if isolation fails.
- [x] Set `PILOT_ROOT` to that validated absolute path. Record the actual base SHA;
  review relevant changes if it differs from the examined revision.
- [x] Run `devbox run setup --cwd "$PILOT_ROOT" --json`, then
  `devbox run check --cwd "$PILOT_ROOT" --json`. Expected: successful PHPUnit and
  production package validation. Record any existing failures and diagnose before
  using this baseline; do not describe an unexecuted check as passing.
- [x] Record the existing 24-parameter Files constructor, the dependencies needed
  by read/edit fixtures, and one focused-test runtime. From `mcp/`, use
  `php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Tools/FilesModuleTest.php --filter testReadsTextPdfDocxAndOdt`
  as the before/after comparison: it exists before extraction and exercises the
  same public module after Task 4. Keep the runtime configuration and fixtures
  comparable. Measure new direct-handler tests separately; do not compare two
  different test selections as a performance improvement.

Portable focused commands below run from the validated worktree's `mcp/` directory
after setup. The existing executable is `vendor/bin/phpunit`; proposed Composer
aliases from the specification are not available yet. Use the same PHP runtime
and adequate memory as CI when exercising large extraction fixtures. Commands
below name the selected tests; a zero exit with no tests selected is not success.

## Task 1: Pin compatibility and add the minimal tool contract

**Create:** Four `Mcp/Tools` files, `ToolContractTest.php`, and
`FilesPilotCompatibilityTest.php` from the scope map.

**Reference:** `mcp/lib/Tools/ToolModule.php` (historical path, now retired),
[FilesModule](../../../../mcp/lib/Modules/Files/FilesModule.php), and
[FilesModuleTest](../../../../mcp/tests/Unit/Modules/Files/FilesModuleTest.php).

**Interfaces produced:**

- `Tool::definition(): ToolDefinition` (static) and
  `Tool::execute(array $arguments, ToolContext $context): array`.
- `WriteTool extends Tool` with `preview(array $arguments, ToolContext $context): array`.
- Immutable `ToolContext::__construct(public readonly string $userId)`.
- Immutable `ToolDefinition::__construct(string $name, string $module, string
  $operation, string $description, array $inputSchema, ?string $app = null)`;
  `toArray(): array` emits the existing definition keys and omits absent `app`.

- [x] Add characterization cases against the unchanged module and registry. Pin
  the complete definitions of the two tools, their positions in the full list,
  validation of unknown properties, and current result/envelope shapes. Use fixed
  synthetic fixtures and literal expectations; never compute expected results by
  calling the new implementation. These characterization tests should pass now.
- [x] Add `ToolContractTest` cases for definition round-trip, absent/present app,
  preserved schema/defaults, and authenticated UID representation. New-class tests
  should fail because the contracts do not yet exist.
- [x] Implement only the interfaces and immutable values above. Do not add an enum
  migration, generic schema builder, reflection discovery, or a service container.
- [x] Run `vendor/bin/phpunit --filter 'ToolContractTest|FilesPilotCompatibilityTest'`.
  Expected: the characterization and new contract tests pass.
- [x] Review and create a focused local commit after the checks pass. No push.

## Task 2: Extract reading without changing its MCP representation

**Create:** `ReadFile.php`, `FileReader.php`, `FileRead.php`, and
`mcp/tests/Unit/Modules/Files/{ReadFileTest,FilesPilotFixture}.php`.
**Modify:** `mcp/tests/Unit/Tools/NoFileDeletionTest.php` to include the new Files
subtree recursively in its source enumeration without expanding its batch-undo
exception. A flat `Modules/Files/*.php` glob would miss the nested handlers and
capabilities. Inspect the other source-discovery checks listed in Task 5 at this
step too; extend them here if their current discovery misses the new paths.

**Reference:** Current `FilesModule::read`, `file`, and `text`; existing
[TextExtractor](../../../../mcp/lib/Modules/Files/Reading/TextExtractor.php),
[OcrSupport](../../../../mcp/lib/Modules/Files/Reading/OcrSupport.php), and
[FilesOcrTest](../../../../mcp/tests/Unit/Modules/Files/Reading/FilesOcrTest.php).

**Consumes:** Task 1 contracts, existing root access, visibility, extraction, access
metadata, OCR, and localization helpers.

**Produces:** `FileReader::readText(string $userId, string $path): FileRead`;
immutable `FileRead::__construct(string $text, array $metadata)` and
`FileRead::toToolResult(): array`; `ReadFile implements Tool`.

- [x] Build a minimal fixture using existing fake trees and users where useful.
  Do not inherit the broad `FilesToolsTestCase` merely to obtain a file. Do not
  construct database, batch, checkout, sharing, or image-preview collaborators.
- [x] Add `ReadFileTest` assertions: the correct user's normalized path is used;
  extracted text is content block 0; JSON metadata is block 1; path, ETag, size,
  MIME, and access metadata match the legacy output.
- [x] Add cases for hidden file/ancestor and denied access, a textless scanned
  document/image, OCR enabled/disabled, and extraction-limit behavior. Reuse
  existing PDF/DOCX/ODT fixtures and expected notices. Verify the 20 MiB input and
  100,000-character limits, including a multibyte boundary, without duplicating
  extractor implementation tests already covering the same condition.
- [x] Run `vendor/bin/phpunit --filter ReadFileTest` and confirm failures concern
  the missing extraction boundary, not a broken test fixture.
- [x] Implement reading with the same `NodeAccess` error mapping, visibility
  checks, extractor, OCR notices, and JSON flags as the old path. Keep descriptions
  in `FilesMessages`; do not introduce untranslated user-facing strings.
- [x] Retain the legacy source set and recursively enumerate PHP files beneath
  `Modules/Files` in the no-deletion scan. Assert enumeration includes `ReadFile.php`
  and `FileReader.php`; Task 3 must also include `EditFile.php` and `TextFileEditor.php`.
  Retain the existing checks and their sole empty-folder undo exception.
- [x] Inspect the remaining source-discovery checks listed in Task 5 now. Existing
  recursive scanners may need no edit; adjust any narrower discovery when its
  first affected source is introduced, and run that contract before committing.
  Task 5 is the final coverage verification, not permission to defer broken coverage.
- [x] Run `vendor/bin/phpunit --filter 'ReadFileTest|FilesModuleTest|FilesOcrTest|NoFileDeletionTest|L10nSourceTextTest|NextcloudApiContractTest|VisibilityContractTest'`.
  Expected: both old and new behavior pass. Public dispatch remains legacy until Task 4.
- [x] Review and create a focused local commit.

## Task 3: Extract edit preparation and mutation with preserved ordering

**Create:** `EditFile.php`, `TextFileEditor.php`, `EditRequest.php`, and
`mcp/tests/Unit/Modules/Files/{EditFileTest,TextFileEditorTest}.php`.

**Reference:** Current `FilesModule::planWrite`, `planContent`, `write`, `commit`,
`assertEditable`, and `lockNotice`; existing
[FileBackup](../../../../mcp/lib/Modules/Files/Editing/FileBackup.php),
[FilesPlanTest](../../../../mcp/tests/Unit/Modules/Files/Presentation/FilesPlanTest.php), and
[FilesLockTest](../../../../mcp/tests/Unit/Modules/Files/FilesLockTest.php).

**Consumes:** Task 1 contracts, Task 2 fixture, and existing access, visibility,
backup, diff, locking, shared-access, and translation collaborators.

**Produces:** Immutable `EditRequest` with path/content/nullable ETag/shared
confirmation and `fromArray(array $arguments): self`;
`TextFileEditor::preview(EditRequest $request, string $userId): array` (domain plan),
`TextFileEditor::execute(EditRequest $request, string $userId): array` (MCP envelope),
and `EditFile implements WriteTool`.

- [x] Test request mapping: absent ETag remains null, absent `confirm_shared`
  remains false, and global `confirm` is not interpreted as shared confirmation.
- [x] Add preview tests asserting the unchanged diff, sizes, ETag, access, backup
  folder, shared notice, recoverability, and lock warning. Count effects: zero
  content writes, zero backups, zero token issuance, and no lock takeover.
- [x] Add execution tests: one verified backup precedes a successful content write;
  stale supplied ETag, non-text files, backup paths, content over 10 MiB, disabled
  versioning, denied updates, and missing shared confirmation refuse as before.
  Assert the exact shared-refusal MCP envelope and payload against a synthetic
  baseline fixture, not only that execution was blocked. Preserve acceptance of
  omitted ETag and absence of a plan-state requirement.
- [x] Pin first-failure precedence: with a stale ETag and a foreign lock, preview
  reports the stale ETag and execution reports the lock. With a stale ETag and
  missing shared confirmation on an unlocked shared file, execution returns the
  shared-refusal payload before backup/ETag validation. Preserve the current
  preview behavior when versioning is disabled; do not add execution-only checks
  to preview through a shared preparation method.
- [x] Test backup failure prevents mutation; a pre-existing foreign lock prevents
  backup; an own manual lock retains current behavior; a lock appearing after
  backup prevents mutation and the result retains recovery information.
- [x] Test visibility or access changes between preview and confirmed execution
  are checked again. Test hidden-tag propagation using existing backup behavior.
  Do not assert full atomicity where the underlying APIs do not provide it.
- [x] Run `vendor/bin/phpunit --filter 'EditFileTest|TextFileEditorTest'` and confirm
  the missing boundary is the cause of failure.
- [x] Implement shared internal preparation, keeping preview's warning semantics
  distinct from mutation refusal. Reuse existing guards and backup implementation;
  do not create backups in shared preparation. Preserve mutation-time rechecks,
  exception mapping, translated messages, and error privacy.
- [x] Repeat the Task 2 source-discovery audit for the newly added edit sources
  before this commit; assert their inclusion and update affected checks now.
- [x] Run `vendor/bin/phpunit --filter 'EditFileTest|TextFileEditorTest|FilesPlanTest|FilesLockTest|FilesModuleTest|FileBackupPrivacyTest|NoFileDeletionTest|L10nSourceTextTest|NextcloudApiContractTest|VisibilityContractTest'`.
  Expected: existing and new assertions pass without weakened expectations.
- [x] Record helpers still needed by unmigrated replace/checkout/restore paths;
  temporary overlap is migration debt, not a shared public API. Commit locally.

## Task 4: Connect both handlers through the existing policy boundary

**Create:** `FilesToolCatalog.php` and
`mcp/tests/Unit/Modules/Files/FilesToolCatalogTest.php`.

**Modify:** Existing `FilesModule.php`, required construction sites, and
`Application.php` only if explicit bindings are needed. Extend the Task 1
compatibility test and retain existing registry/application registration tests.

**Interfaces:** `FilesToolCatalog::classes(): array` (static) returns the ordered
list `[ReadFile::class, EditFile::class]`;
`FilesToolCatalog::definitions(?array $classes = null): array` (static) returns
validated definitions keyed by tool name. Null uses `classes()`; an explicit
class list allows validation of synthetic definitions without instantiation.
Require `ReadFile` and `EditFile` through the legacy module's constructor; do not add nullable policy dependencies or fallback constructors.

- [x] Test explicit catalog membership, no duplicate names, and rejection of a
  non-read tool lacking `WriteTool`. Pass small test-only tool classes through the
  explicit metadata-validation input for invalid and duplicate definitions; do not resolve services or
  instantiate those classes to inspect their definitions.
- [x] Extend public-boundary cases: missing/false `confirm` produces only the
  plan; true confirmation executes; an ungranted read/edit never reaches the
  capability; shared confirmation remains independent; unexpected exceptions are
  sanitized. Assert the full tool list retains order and contains each name once.
- [x] Run `vendor/bin/phpunit --filter 'FilesToolCatalogTest|FilesPilotCompatibilityTest'`.
  New catalog/delegation cases should fail before integration.
- [x] Replace the two legacy schema entries with catalog definitions in the same
  positions. Replace only their `call`/`preview` routes with injected handler calls,
  constructing `ToolContext` from the registry-supplied user ID. Keep `NodeAccess`
  behavior equivalent and keep `FilesPlanRenderer` on the existing renderer path.
- [x] Remove legacy read/edit implementations that now have no callers. Retain
  shared helpers still used by other tools and compatibility constants used by
  resources. Do not register a second Files module in `Application::MODULES`.
- [x] Update all direct constructor calls, found with
  `rg -n 'FilesModule' mcp`, checking aliases, fully qualified calls, and reflective
  test builders as well as direct construction. Place required dependencies
  before optional constructor parameters. Preserve constructor-free metadata
  enumeration used by `WritePlanContractTest`; definitions must not access injected
  handler instances. Existing tests should exercise migrated handlers through the
  real legacy adapter.
- [x] Run `vendor/bin/phpunit --filter 'FilesToolCatalogTest|FilesPilotCompatibilityTest|ToolRegistryTest|ApplicationRegistrationTest|FilesModuleTest|FilesPlanTest|FilesLockTest|FilesEditToolsTest|ResourceRegistryTest|WritePlanContractTest|ToolsListContractTest'`.
  Expected: public policy, unchanged tools, and resource consumers remain valid.
- [x] Review and create a focused local commit.

## Task 5: Enforce the new boundary and verify compatibility/package coverage

**Create:** `mcp/tests/Architecture/FilesPilotBoundaryTest.php`.

**Inspect and modify only if necessary:** Existing source-discovery assumptions
in `mcp/tests/Unit/Contract/NextcloudApi/LibScanner.php`,
`mcp/tests/Unit/Contract/VisibilityContractTest.php`,
`mcp/tests/Unit/Tools/NoFileDeletionTest.php`,
`mcp/tests/Unit/L10nSourceTextTest.php`, and
`mcp/tests/Unit/Tools/FilesModuleTest.php`. Do not relax their behavioral guarantees.

**Consumes:** Task 4 wired implementation.
**Produces:** Boundary checks and verification evidence covering the new paths.

- [x] Add import/dependency checks for the pilot: handlers cannot import the legacy
  `FilesModule`, controllers, a general container, or other modules' internals.
  Permit the named existing Files helpers and guards used by the extraction.
  Verify a representative forbidden import is detected before relying on the check.
- [x] Verify source-scanning contracts include the new `Modules/Files` and
  `Mcp/Tools` trees. Recheck the no-deletion coverage added in Task 2. Update other
  narrow discovery lists if needed; do not exclude moved code to make tests pass.
  Preserve no-deletion and localization checks.
- [x] Run `vendor/bin/phpunit tests/Architecture/FilesPilotBoundaryTest.php`, then
  `devbox run check --cwd "$PILOT_ROOT" --json`. Expected: architecture tests,
  full PHPUnit suite, production archive build, and archive validation pass.
- [ ] Confirm the existing CI matrix exercises the new code on PHP 8.2–8.5 before
  integration when CI results are available. Record local-only execution honestly.
- [ ] When an authorized disposable Nextcloud instance is available, exercise a
  read, preview, confirmed edit, shared refusal, and lock refusal under a real user.
  Otherwise record this as outstanding integration verification, with the exact
  limitation; do not claim the unit/API contracts ran a real Nextcloud server.
- [x] Review licensing of new files, production archive contents, and optional-app
  loading. No tests/dev dependencies or external `docs/` assumptions in the package.
- [x] Review and create a focused local commit.

## Task 6: Document the actual authoring workflow and evaluate the pilot

**Create:** `docs/en/development/adding-a-tool.md`.
**Update:** `docs/en/README.md` and the pilot report from Task 0.

**Consumes:** Verified implementation and Task 5 evidence.
**Produces:** A contributor-facing guide and an explicit expand/revise decision.

- [x] Explain how to define a read tool, define a write tool, inject dependencies,
  register it once, and run its focused tests. Use the actual implemented classes
  and commands; explain the temporary legacy adapter rather than concealing it.
- [x] Document English code/comments, localized messages, preserved fixtures,
  Markdown links, and the English-first translation workflow from the spec.
  Link the existing Portuguese docs; do not create empty translated pages.
- [x] Record before/after handler dependencies, fixture requirements, changed-file
  footprint, and the same Task 0 comparison-command runtime, plus separate direct
  handler-test timings. A read test must not need unrelated Files
  services; an edit test must not need batch/image/checkout services.
- [x] Record residual legacy overlap by method and consumer, its removal condition,
  test/package results, and any unavailable real-server checks.
- [x] Check local Markdown links and whitespace, then review the example against
  real signatures. Commit documentation only after those checks pass.
- [x] Stop at the pilot review. Recommend expanding only if the change surface is
  clearer and all compatibility requirements remain satisfied. Do not migrate
  other tools or implement the broader roadmap automatically.

The two unchecked verification items above are environment limits, recorded in
the report; they are not claims of a remote CI or live-server pass. Extraction
format/limit cases were retained in existing tests and exercised through the
migrated public adapter. Baseline evidence was recorded before implementation
and consolidated into the report at completion.

## Acceptance checklist

- [x] Public definitions, ordering, grants, messages, and result blocks are unchanged.
- [x] Both tools use the existing registry/write gate and are registered exactly once.
- [x] Read and edit tests construct only the dependencies relevant to their capability.
- [x] Previews have no mutation/backup/token effects; execution preserves refusal and recovery behavior.
- [x] ETag, shared-refusal payload, combined-failure precedence, visibility, locking, and backup tests pass.
- [x] Source-scanning contracts cover the moved code; full checks and package validation pass.
- [x] Real Nextcloud verification is completed or explicitly recorded as outstanding.
- [x] The authoring guide uses actual APIs; translation and package documentation remain intact.
- [x] Residual adapter/helper debt and an expand/revise recommendation are recorded.

## Rollback and excluded follow-up work

Use focused local commits so the integration change can be reverted first if a
regression appears, followed by extraction commits as needed. Tests and baseline
evidence remain useful. Never remove someone else's edits or rewrite shared history.
There is no database migration, grant migration, or user-data conversion.

The broader specification deliberately exceeds this pilot. Separate plans cover
remaining Files tools, Deck interfaces, DAV extraction, admin JavaScript,
documentation relocation, translation checks, Composer aliases, gradual static
analysis/style tooling, and optional GitHub Pages publication. The existing
`mcp/README*`, package script, CI workflows, and runtime dependencies are not
rewritten merely to complete this pilot.
