<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Complete Files migration by capability

> **For agentic workers:** After explicit implementation authorization, use
> `superpowers:executing-plans` for native execution, or
> `superpowers:subagent-driven-development` if the user chooses delegated execution.
> Execute the tasks in order and check their acceptance criteria. Full Files
> implementation was subsequently authorized; other modules remain out of scope.

**Status:** Files implementation, local verification and review complete; results are recorded
in the [execution report](2026-10-16-files-capability-migration-report.md). Remote PHP
matrix and disposable-server checks remain integration gates.

**Date:** 2026-10-16.

**Goal:** Complete the migration of all 23 Files tools into cohesive capabilities,
retire the old Files implementation and its temporary adapter, and preserve public
behavior across tools, resources, checkout routes, and administrative consumers.

**Architecture:** Keep the existing registry, confirmation gate, and framework
entry points. Finish feature-owned code under `Modules/Files`, expose a narrow
resource contract, and move only genuinely shared backup primitives to
`Platform/FileAccess`. The final Files module is a small permanent registration
and dispatch adapter, with no resource orchestration or fallback implementation.

**Tech stack:** PHP >=8.2, Composer PSR-4 namespace `OCA\Mcp`, PHPUnit 10.5,
native Nextcloud app `mcp`, declared Nextcloud 32–35 support. No new production dependency.

**Spec:** [Architecture specification](../architecture-specification.md).
**Evidence:** [Completed pilot report](2026-10-16-files-pilot-report.md).
**Planning baseline:** `1f6e067`, branch `refactor/files-read-edit-pilot`.

## 1. Feasibility and scope decision

**A complete Files migration without retained transition files is feasible.**
The pilot establishes the handler boundary; the remaining work is larger than
moving the other 21 schema entries. There are 35 PHP files under `lib/Tools/Files`,
two token types under `lib/Checkout`, and callers outside both directories.
Their destinations and consumer updates are part of this plan.

This is not evidence that a one-step migration is already safe. The recorded
local check passed 5,100 tests and package validation on PHP 8.4.26. Remote CI on
PHP 8.2–8.5 and real-user Nextcloud verification remain outstanding. Phases allow
small reviewable changes; the final integration gate requires all phases, not a
promise to remove compatibility code later.

During implementation, the existing module can serve unmigrated operations.
Each extracted operation has exactly one active implementation. Do not add class
aliases, deprecated forwarding classes, alternate namespace trees, or an optional
fallback route. The final artifact must contain no `lib/Tools/Files/` or
`lib/Checkout/` directory and no temporary Files adapter.

**This does not finish the repository-wide migration.** `Tools/Common`, the
registry/write gate, `Service`, and other feature modules still contain active
shared or feature code. Their continued existence is not a Files compatibility
shim. Removing those directories requires separate migration plans. In particular,
do not migrate Deck, DAV transport, OAuth, or administrative JavaScript here.

### Dependencies that prevent deleting the old directory immediately

| Current consumer | Dependency | Resolution in this plan |
| --- | --- | --- |
| `AppInfo/Application.php` | Files module and text extractor | Replace module registration; bind resource contract and lazy tool factories. |
| `Service/ResourceRegistry.php` | Extractor, Files messages, module constant | Delegate Files resources to a public capability contract; retain resource grants and envelopes. |
| `Controller/CheckoutController.php` | Checkout, backup, creation, token types, messages | Move owned code and extract token policy/mutation orchestration while retaining routes. |
| `Controller/CheckoutLimitController.php` | Checkout limits | Update the owned service import; keep configuration keys and validation. |
| `Controller/SettingsController.php` | Batch and checkout-token stores | Update imports; preserve user reset behavior and database tables. |
| `Settings/AdminSettings.php` | OCR availability | Update the reading-capability dependency; keep admin output. |
| `Service/VisibilityGuard.php` | Backup folder and privacy failure messages | Use shared backup location/message primitives, without importing Files internals. |
| `Tools/Contacts/ContactBackup.php` | Backup folder constant only | Use the shared location primitive; do not migrate Contacts. |

Production references, tests, fixtures, reflection builders, scanner roots, and
package contents all count as consumers. Historical documents can retain old paths
when explicitly describing a baseline; live contributor instructions cannot.
Historical references are plain text or links pinned to the baseline commit, not
relative links to deleted files. Validate those links at the final removal gate.

## 2. Global constraints

- Code, identifiers, comments, PHPDoc, and technical documentation are English.
- User-facing messages retain the existing translation system and exact source
  strings; preserve meaningful multilingual and byte-encoding test fixtures.
- Keep app ID, supported versions, dependencies, routes, grant names, database
  schemas, config keys, token formats, and existing data unchanged.
- Preserve all 23 tool names, list order, schemas, defaults, app filters,
  annotations, `grantAnyOf`, result blocks, errors, plans, and guide wording.
- Keep global confirmation in `WriteGate`; retain independent shared confirmation
  and existing `plan_state` requirements for sharing only. Do not add a required
  ETag or plan-state token to editing, replacing, or checkout.
- Preserve phase-specific failure ordering, backup-before-write, hidden-tag
  propagation, access rechecks, and own-versus-foreign lock behavior. Previews have
  no write, backup, token, or lock-takeover effects.
- No file deletion, backup pruning, or new cleanup path. The sole node-deletion
  exception remains removal of an empty folder created by the undone batch.
- Preserve local fixtures and run relevant checks after every capability. Do not
  use a repository-wide reformat or weakening of assertions to make moves pass.
- Use a validated devbox worktree. Reuse a selected worktree; if a new one is
  required, use `devbox worktree create --project mcp-for-nextcloud --type refactor
  --slug files-capabilities --json`. Do not rename existing branches or place
  harness state inside the repository.
- Local implementation commits follow successful checks. Pushing, opening a PR,
  deployment, or publishing requires a separate explicit request.

## 3. Final ownership and file structure

All paths in this section are relative to `mcp/`. These are planned destinations,
not directories to scaffold before they own code.

```text
lib/
  Modules/Files/
    FilesModule.php                 # permanent ToolModule adapter, no domain work
    FilesToolCatalog.php            # explicit ordered metadata for 23 tools
    Tools/                         # one entry point per public tool
    Contracts/FileResourceReader.php
    Reading/                       # text, OCR, directory/tree and resource reads
    Search/                        # text/name search and Nextcloud query values
    Editing/                       # text replacement, backup and verified commit
    Versions/                      # version access and restore orchestration
    Checkout/                      # links, tokens, policy and transfer writes
      Http/                        # bounded request-body handling
    Creation/                      # new-file targets and writes
    Images/                        # selection, rendering, metadata and search
    Sharing/                       # existing focused sharing services
    Reorganization/                # inspection, folders, copy/move and batches
    Presentation/                  # guide and plan-rendering dispatch
  Platform/FileAccess/
    BackupLocation.php
    BackupMessages.php
  Controller/                      # existing HTTP routes and attributes stay here
  Mcp/Tools/                       # existing pilot contracts; metadata extended
tests/
  Unit/Modules/Files/               # capability tests, grouped by responsibility
  Unit/Mcp/Tools/
  Unit/Contract/                   # existing public-boundary suite stays here
  Architecture/FilesBoundaryTest.php
  Support/Files/                    # Files-only fixtures and version stubs
```

### Disposition of every old Files source

| Existing files beneath `lib/Tools/Files/` | Destination or replacement |
| --- | --- |
| `FilesModule.php` | Remove after final switch; permanent module at `Modules/Files/FilesModule.php`. |
| `FilesMessages.php` | Split into capability-owned `*Messages.php`; no forwarding facade. Shared backup failure source moves to `Platform/FileAccess/BackupMessages.php`. |
| `FilesPlanRenderer.php` | Small dispatcher at `Presentation/FilesPlanRenderer.php`; content, versions, checkout, creation, sharing and reorganization renderers live with their capability. |
| `TextExtractor.php`, `OcrSupport.php` | `Reading/`, preserving signatures and limits. |
| `FileBackup.php`, `UnifiedDiff.php` | `Editing/`; remove location ownership from `FileBackup` once all callers use `BackupLocation`. |
| `VersionTools.php` | Split into `Versions/VersionReader.php` and `Versions/VersionRestorer.php`; no all-purpose `VersionTools` facade. |
| `CheckoutService.php` | `Checkout/CheckoutService.php`, still responsible for issuing links and limits. |
| `FileCreation.php`, `FileExists.php` | `Creation/`, preserving its target/write/receipt boundary. |
| `ImageTools.php` | Split into `Images/ImageViewer.php`, `ImageSearch.php`, `ImageSelector.php`, `ImageRenderer.php`, and `ImageMetadata.php`. |
| `Reorganization.php` | Split into `Reading/TreeReader.php` and `Reorganization/{FolderCreator,CopyMove,MoveInspection,FolderPreparation,TreeMeasure,BatchMover,BatchUndo}.php`. No replacement god class. |
| `Inspection.php`, `MoveConflict.php`, `MoveReport.php`, `ReorganizationLimits.php` | `Reorganization/`, preserving values and limits. |
| `MovePlanner.php` | `Reorganization/MovePlanner.php`; extract its existing second class into `Reorganization/MovePlan.php`. |
| `Batch.php`, `BatchStore.php` | `Reorganization/`, keeping table name, ownership predicates, partial progress, and undo state. |
| `NameSearchQuery.php`, `NameLikeComparison.php`, `MimeLikeComparison.php`, `SearchBinaryOperator.php`, `SearchComparison.php`, `SearchOrder.php` | `Search/`; image search can reuse these named query primitives. |
| All nine `Sharing/*.php` files | `Sharing/` under the new module; retain focused services and security boundaries. |

Also move `lib/Checkout/{CheckoutToken,CheckoutTokenStore}.php` into the new
`Modules/Files/Checkout/` namespace, updating all consumers in the same phase.
Do not change persisted token rows to accommodate a PHP namespace change.

Shared helper names are intentional: `BackupLocation` owns `FOLDER = 'MCP backups'`
and `isBackupPath(string $path): bool`; `BackupMessages::failed(): string` returns
the exact translated source currently in `FilesMessages::backupFailed()`. Both
Files backup handling and visibility use these primitives. Contacts consumes only
the folder location. Neither shared class imports a feature module.

### Complete tool coverage

Every handler below lives in `Modules/Files/Tools/<Class>.php` and implements the
existing `Tool` or `WriteTool` signatures. The table groups work, not publication
order; the catalog must retain the current full order.

| Phase | Public tool → handler | Capability |
| --- | --- | --- |
| Existing pilot / 2 | `files_read` → `ReadFile` | `Reading/FileReader` |
| 2 | `files_list` → `ListFiles`; `files_search` → `SearchFiles`; `files_tree` → `ReadTree` | `Reading/DirectoryReader`, `Search/FileSearch`, `Reading/TreeReader` |
| Existing pilot / 3 | `files_edit` → `EditFile`; `files_replace` → `ReplaceText` | `Editing/TextFileEditor`, `Editing/TextReplacer` |
| 3 | `files_versions_list` → `ListVersions`; `files_version_read` → `ReadVersion`; `files_version_restore` → `RestoreVersion` | `Versions/VersionReader`, `Versions/VersionRestorer` |
| 4 | `files_checkout` → `CheckoutFile`; `files_upload` → `UploadFile`; `files_create` → `CreateFile` | `Checkout/FileCheckout`, `Creation/FileCreation` and transfer services |
| 5 | `files_image_view` → `ViewImage`; `files_images_view` → `ViewImages`; `files_image_search` → `SearchImages` | `Images/` capabilities |
| 6 | `files_list_shares` → `ListShares`; `files_share` → `ShareFile`; `files_unshare` → `UnshareFile` | Existing focused services in `Sharing/` |
| 7 | `files_mkdir` → `CreateFolder`; `files_copy` → `CopyFile`; `files_move` → `MoveFile`; `files_move_batch` → `MoveBatch`; `files_undo_batch` → `UndoBatch` | `Reorganization/` capabilities |

Messages and plan-rendering methods move with their owning capability, with
callers updated immediately. `Presentation/FilesGuide::notes(): array` retains the
existing guide text. `Presentation/FilesPlanRenderer::render(string $tool,
array $plan): ?string` delegates to capability renderers with the same signature
and preserves null fallback. Shared presentation formatting belongs in the narrow
`Presentation/PlanFormatting.php`, not a new general helper collection. These
presentation APIs remain static and dependency-free, as the existing renderer is;
rendering must not resolve tool factories or optional application services.

## 4. Review focus and verification workflow

1. Sharing metadata loses alternate grants or destructive annotations: Phase 1
   pins all raw/published definitions; Phase 6 tests share-only versus link-only grants.
2. A moved resource/controller bypasses identity, visibility, or body limits:
   Phases 2 and 4 pin resource grants and transfer check/spend ordering.
3. Backup, lock, ETag, or raw-byte semantics change while common code is extracted:
   Phase 3 pins combined failures, encoding fixtures, and recovery paths.
4. Partial batch failure or undo deletes an unrelated folder or repeats work:
   Phase 7 tests partial progress, stored ownership, collision, and single undo.
5. Source moves bypass scanners, optional-app guards, autoloading, or packaging:
   every phase checks its new sources; Phase 8 verifies the final artifact and
   resolves unavailable optional services only when the selected call needs them.

For each extraction, first run existing characterization tests on the unchanged
path, then add the missing boundary test and observe its expected failure. Move or
implement the smallest boundary, run its focused suite and source contracts, and
review the diff before a local commit. A failure caused by a broken fixture is
not the intended red test. Do not duplicate existing extractor or guard tests.

In each capability phase, add its handlers to the catalog in baseline relative
order, replace their raw definition entries in the old module at the same list
positions, and delegate their call/preview routes through required injected
handlers. Update construction sites and reflection fixtures so existing public
contract tests exercise the new implementation in that phase. Remove the replaced
methods when their final caller moves. The temporary old constructor may grow
until Phase 8 replaces it; no nullable handler fallback or parallel public route
is allowed. Do not defer all dispatch changes to Phase 8 and test only dead code.

Apply the same rule to presentation: introduce `PlanFormatting` with the first
renderer extraction in Phase 3, update the old renderer to delegate only the
extracted cases, and delete their old bodies immediately. Move checkout/creation
renderers in Phase 4, sharing in Phase 6, and reorganization in Phase 7. At Phase 8,
replace the remaining dispatch table with the permanent presentation dispatcher
and remove the old renderer. Preserve its null-on-incomplete-plan/error behavior.
Each phase checks public rendered output as well as the domain plan.

Phases run sequentially: 0 pins the baseline; 1 provides shared foundations;
2–7 migrate active capabilities and their consumers; 8 removes the remaining
composition and test scaffolding. Reviewable intermediate commits are not separate
completed migrations and do not authorize integration before the final gate.

PHP/Composer commands below run from `mcp/`, after catalog setup. Devbox commands
use the repository root in `FILES_ROOT`. Every phase also runs:

```sh
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'FilesPilotCompatibilityTest|FilesMigrationCompatibilityTest|FilesBoundaryTest|FilesPilotBoundaryTest|NoFileDeletionTest|L10nSourceTextTest|NextcloudApiContractTest|VisibilityContractTest'
```

`FilesMigrationCompatibilityTest` is introduced in Phase 0. Replace the pilot
architecture test with `FilesBoundaryTest` as the scope expands; the command
selects either name during the series. Check that the expected tests actually ran.
Full catalog checks run at baseline, before switching the registered module, and
at final acceptance. A failed phase blocks dependent work until diagnosed.

## Phase 0 — Establish the complete compatibility baseline

**Files:** Create `tests/Unit/Contract/FilesMigrationCompatibilityTest.php`,
`tests/fixtures/files-definitions.json` and its SPDX sidecar. Create the execution
report at `docs/en/development/plans/2026-10-16-files-capability-migration-report.md`
only when execution begins. Keep the existing pilot snapshot as baseline evidence.

**Consumes:** Current definitions, public dispatch, pilot evidence and catalog tasks.
**Produces:** Full 23-tool snapshot and an ownership/reference inventory.

- [x] Revalidate project/worktree identity and status; inspect changes since
  `1f6e067`. Preserve unrelated work and record the actual execution base. Set
  `FILES_ROOT` to the validated absolute `repo_root` returned by devbox.
- [x] Run setup if needed and `devbox run check --cwd "$FILES_ROOT" --json`.
  Expected: full PHPUnit and production package validation succeed. Carry forward
  any missing remote/live verification as a release gate, never a claimed pass.
- [x] Capture all raw definitions from the unchanged module, including optional
  metadata and order; pin published output under representative grants and app
  availability. Expected values must not be derived from migrated handlers.
- [x] Add `testAllFilesDefinitionsMatchBaseline`, `testToolsArePublishedOnce`,
  `testResourcesRetainIndependentGrantChecks`, and sharing grant combinations.
  Existing baseline paths must pass before extraction.
- [x] Inventory all 35 old source files, token sources, concrete construction
  sites, reflection builders, service registrations, constants, and scanners.
  Assign every message/rendering method to its capability using the map above.
  Record PHPUnit-discovered test classes/methods and fixture/bootstrap ownership
  so Phase 8 can reconcile moves without silently dropping coverage.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'FilesMigrationCompatibilityTest|FilesPilotCompatibilityTest|ToolsListContractTest|ResourceRegistryTest'`.
  Accept only with all 23 names covered and known external consumers recorded;
  commit the characterization baseline locally.

## Phase 1 — Make the shared contract sufficient and relocate foundations

**Files:** Modify `lib/Mcp/Tools/ToolDefinition.php` and its contract test; move
reading/backup/diff/query foundations per the source map; create the two shared
backup primitives. Update `Service/VisibilityGuard.php`,
`Tools/Contacts/ContactBackup.php`, affected module imports, and scanner tests.

**Interfaces:** Append `?array $grantAnyOf = null` and
`?bool $destructiveHint = null` after `?string $app = null` in `ToolDefinition`.
`toArray()` omits absent values and preserves existing raw-definition key order,
including optional metadata before description/schema where present in the
baseline. Do not introduce an arbitrary metadata bag. Existing definitions
without optional metadata remain identical.

- [x] Add round-trip tests for absent/present app, alternate grants and true/false
  destructive hints; pin unchanged read/edit output. Observe the missing support.
- [x] Extend metadata and move `TextExtractor`, `OcrSupport`, `FileBackup`,
  `UnifiedDiff` and six query types to their final owners, updating every caller.
  Constructors and operation signatures stay intact unless the location primitive
  explicitly replaces their constant/path ownership.
- [x] Extract `BackupLocation` and `BackupMessages`; update visibility and Contacts
  without touching their unrelated behavior. Remove superseded constant/message
  forwarding methods after the last caller moves.
- [x] Start `tests/Architecture/FilesBoundaryTest.php`: allow only named surviving
  old helpers while phases remain, and fail if the allowlist grows. Recursively
  scan new Files and shared backup sources immediately. Keep the no-deletion
  exception at its exact existing path until Phase 7 moves that one operation.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'ToolContractTest|ReadFileTest|TextFileEditorTest|FileBackupPrivacyTest|VisibilityGuardTest|ContactsModuleTest|ResourceRegistryTest|SearchOperatorsStringableTest'` plus the common selection.
  Acceptance: public metadata and message text are unchanged, shared primitives
  have no feature imports, and moved sources have one location. Commit locally.

## Phase 2 — Complete directory, search, tree, and resource reads

**Files:** Add the Phase 2 handlers, `Reading/{DirectoryReader,TreeReader,FileEntry,NextcloudFileResourceReader}.php`,
`Search/FileSearch.php`, and `Contracts/FileResourceReader.php`. Modify
`Service/ResourceRegistry.php`, `AppInfo/Application.php`, the legacy Files adapter,
and existing read/search/resource tests.

**Interfaces:** `DirectoryReader::list(Folder $root, string $userId, string $path): array`;
`TreeReader::tree(Folder $root, string $userId, string $path, int $depth, int $limit): array`;
`FileEntry::describe(Folder $root, string $userId, Node $node): array`;
`FileSearch::search(Folder $root, string $userId, string $query, int $limit, string $mode = 'auto'): array`.
The public `FileResourceReader::read(string $uri, string $userId): array` returns
the existing resource envelope. Its public constants `MAX_TEXT_CHARS = 100000` and
`MAX_BLOB_BYTES = 524288` preserve current text/binary limits.
`NextcloudFileResourceReader` implements it;
the registry keeps grant checks and exposes its existing constants through this
public contract rather than depending on a tool module.

- [x] Pin normalized paths, hidden ancestors/shares, denied reads, escaped search
  metacharacters, duplicate results, overfetch, and full-text fallback. Reuse
  existing format/OCR/limit tests through migrated public dispatch.
- [x] Add resource cases for encoded traversal, hidden binary nodes, false size
  metadata, and permission denial before capability execution. Do not replace
  resources with `files_read`: resource MIME/blob handling and envelopes differ.
- [x] Extract the listed methods from `FilesModule` and `Reorganization::tree`;
  retain recursive limits (depth 5, 2,000 entries), search bounds and ordering.
  Move their messages and the module's entry formatting to the named owners.
- [x] Bind the resource contract explicitly in `Application`; retain Notes resource
  behavior. Remove Files-specific extraction and old module-constant imports from
  `ResourceRegistry`. Remove dead read/search/tree implementations immediately.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'ReadFileTest|FilesModuleTest|FilesSearchContentTest|FilesTreeTest|FilesOcrTest|ResourceRegistryTest|ApplicationRegistrationTest'` plus common checks.
  Acceptance: read fixtures need no write/batch/image services, unavailable
  full-text apps retain fallback behavior, and resources depend on the public
  contract. Commit each cohesive extraction after it passes.

## Phase 3 — Complete text changes and versions

**Files:** Add `Tools/{ReplaceText,ListVersions,ReadVersion,RestoreVersion}.php`,
`Editing/{TextReplacer,ContentWriter,ContentPlan,EditingPlanRenderer}.php`,
`Versions/{VersionReader,VersionRestorer,VersionPlanRenderer}.php`; update the
pilot editor, legacy adapter and version/edit tests. Move capability messages.

**Interfaces:** `ContentWriter::commit(Folder $root, string $userId, File $file,
string $path, string $content, ?string $etag, string $before): array` returns the
existing edit receipt. `ContentPlan::describe(File $file, string $userId,
string $path, array $plan): array` owns the shared metadata/notices currently in
`planContent`, preserving ordering. `TextReplacer::preview(array $arguments, string $userId): array`
returns a domain plan; `execute(array $arguments, string $userId): array` returns
the MCP envelope. `VersionReader` retains the existing `VersionTools::list`,
`read`, and `describe` signatures; `VersionRestorer` retains `restore` and exposes
`preview(Folder $root, string $userId, string $path, string $version): array` for
the current restore plan. Preserve the `files_versions` filter and default list
limit 50.

- [x] Pin raw-byte replacement for BOM/Latin-1 content, absent/ambiguous snippets,
  optional ETags, combined lock/shared/ETag failures, disabled versioning,
  hidden-tag propagation, and recovery information after failed mutation.
- [x] Share only the proven text commit between edit and replace. Keep their
  preparation and error ordering distinct. Do not send binary checkout transfers
  through a text-specific commit or add execution-only checks to previews.
- [x] Split version reads/restores and preserve lazy resolution of the optional
  provider. Reuse backup/visibility/locking policy; preserve restore confirmation
  and receipts. Delete dead legacy `commit`, `planContent`, and edit helpers once
  no remaining operation uses them; ledger any still-live helper with its consumer.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'EditFileTest|TextFileEditorTest|FilesEditToolsTest|FilesModuleTest|FilesPlanTest|FilesLockTest|VersionToolsTest|FileBackupPrivacyTest|FilesPlanRendererTest'` plus common checks.
  Acceptance: one text commit path, no general prepared-write framework, no
  `VersionTools` facade, and no loss of byte/backup safety. Commit by capability.

## Phase 4 — Complete creation and checkout, including HTTP consumers

**Files:** Add the three Phase 4 handlers. Move `FileCreation`, `FileExists`,
`CheckoutService`, `CheckoutToken`, `CheckoutTokenStore` to their mapped owners.
Add `Checkout/{FileCheckout,CheckoutPolicy,CheckoutWriter,CheckoutFailure,ResolvedCheckout}.php`
and `Checkout/Http/BodyReader.php`. Modify checkout/limit/settings controllers,
their tests, and token-store fixtures. Keep controller route methods/attributes.

**Interfaces:** Retain moved service APIs. `FileCheckout::preview(array $arguments,
string $userId): array` and `execute(array $arguments, string $userId): array`
extract the legacy tool plan/call orchestration and reuse `CheckoutService` for
link issuance. Before reading a body, call
`CheckoutPolicy::assertLink(?CheckoutToken $row, string $kind): void`; extract
subsequent resource resolution and policy rechecks to
`CheckoutPolicy::resolve(?CheckoutToken $row, string $kind): ResolvedCheckout`
and conditional consumption to `spend(CheckoutToken $row, ?string $spent = null): void`.
`ResolvedCheckout::__construct(CheckoutToken $token, ?File $file)` holds immutable
references; the file is absent for create tokens. Do not persist this object
between requests. `CheckoutWriter::replace(CheckoutToken $row, File $file, string $body): array`
and `create(CheckoutToken $row, string $body): array` return the existing receipts;
`$body` remains a temporary-file path, not eagerly loaded bytes.
`CheckoutFailure::__construct(int $status, string $message, array $payload = [])`
extends `RuntimeException` with an HTTP status and safe existing response payload;
the controller retains endpoint-specific response serialization.
`BodyReader::withStoredBody(int $limit, callable $consume): ?Response` owns bounded
streaming and the existing temporary-file disposal. Its callback receives the
temporary path and returns the controller response; null means body storage
failed and the controller retains the existing 413 response. A private `store`
retains the current `storeBody` behavior. The HTTP-specific reader invokes the
callback only after storage succeeds and removes the file in `finally` on callback
success or failure. Keep allocation and `unlink` together so the no-deletion
scanner still proves the temporary-path origin; do not add arbitrary-path disposal
or exemptions for the controller. Move the overridable input-stream test seam to
the reader and update controller fixtures in the same commit. These are focused
boundaries, not another full controller copied into one service.

- [x] Pin download/upload/create status codes and bodies; invalid, expired, spent,
  wrong-user and revoked-grant cases; content type/length mismatches; current
  upload limits and single-use atomic consumption. Compare observable call order
  where token/body/backup side effects make it part of the contract.
- [x] Extract in three commits: owned types/imports; token policy; mutation/body
  boundaries. Keep HTTP identity/translation configuration and response mapping
  in the controller, with no alternate route or token format.
- [x] Preserve header/token rejection before body storage, policy recheck after
  reading, spend timing, ETag check, backup, then write. A lock before spending
  leaves the link usable; a later refusal retains recovery guidance. Preserve
  checkout's `asUser = false` lock semantics and creation's no-overwrite rule.
- [x] Pin temporary-file disposal on success and mutation exceptions, plus
  over-limit/read failures and no callback or token spending when storage fails.
  Preserve the existing early allocation-failure behavior; any additional resource
  leak fix requires a separate behavioral change. Run the no-deletion scanner
  against both the HTTP reader and controller immediately after extraction.
- [x] Test current binary transfers and declared-size create markers, including
  existing token rows created before the PHP move. Keep tables and config names.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'CheckoutControllerTest|CheckoutCreateTest|CheckoutTokenStoreTest|CheckoutTokensSchemaTest|CheckoutLimitControllerTest|FilesCreateTest|FilesPlanTest|SettingsControllerTest|ApplicationRegistrationTest'` plus common checks.
  Acceptance: top-level `lib/Checkout` is gone, the controller is an HTTP adapter,
  and issued links/old rows still work. Commit only after each selection passes.

## Phase 5 — Complete images without retaining ImageTools

**Files:** Add the three image handlers and five image capabilities from the map;
move image messages and update `FilesImageToolsTest` and its fixtures.

**Interfaces:** `ImageViewer` retains `view(Folder $root, string $userId, string $path,
int $maxSize): array` and `viewMany(Folder $root, string $userId, ?array $paths,
?string $folder, int $limit, int $maxSize): array`; `ImageSearch` retains
`search(Folder $root, string $userId, array $args): array`. Selection, rendering and
metadata helpers keep their existing parameters/results as they move to the
corresponding named capability. Viewer owns response assembly and byte budgeting;
search owns query construction, not image rendering.

- [x] Pin image/text block order, MIME/base64 content, byte and dimension budgets,
  batch selection, hidden/unreadable nodes, EXIF metadata, tags/date boundaries,
  unsupported preview providers, and per-user visibility.
- [x] Split existing methods according to the ownership above; keep configured
  limits, batch cap 6, default max dimension 1,568 and range 256–2,048. Avoid
  constructing image/preview collaborators for direct read or text-edit tests.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'FilesImageToolsTest|AdminOcrStatusTest|AdminUnsupportedAppsTest|ReadFileTest|TextFileEditorTest'` plus common checks.
  Acceptance: no active `ImageTools` facade or mega-service; all existing image
  behavior passes through the new handlers. Commit the verified extraction.

## Phase 6 — Complete sharing with its distinct approval protocol

**Files:** Move the nine sharing services as a unit; add the Phase 6 handlers and
`Sharing/{SharingMessages,SharingPlanRenderer}.php`; update sharing tests and
`ApplicationRegistrationTest`. Keep `PlanState` in its current shared location.

**Interfaces:** Preserve moved service signatures. Handler `preview` delegates to
the existing plan API and returns the domain plan; `execute` preserves each
existing envelope. In particular, `files_share` keeps both text and structured
content, including one-time password presentation; do not serialize it as a
generic JSON-only result.

- [x] Pin share-only/link-only/no-grant access, the selected recipient's required
  grant, own-share filtering, changed or missing `plan_state`, recipient changes,
  expiry/password policies, link-password leakage, and unavailable optional apps.
- [x] Move services, definitions, messages and renderers with import updates;
  retain the existing alternate-grant gate and the writer's recipient-specific
  check. Do not replace sharing approval with `confirm_shared`.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'FilesListSharesTest|FilesShare|FilesUnshare|ShareAccessTest|ShareFormatterTest|SharePermissionTest|ShareRecipientTest|LinkPasswordTest|ApplicationRegistrationTest'` plus common checks.
  Acceptance: metadata includes exact `grantAnyOf`/`destructiveHint`, stale plans
  never execute, and credentials stay out of logs and unrelated responses.
  Commit the migrated sharing capability.

## Phase 7 — Complete reorganization, persistence, and undo

**Files:** Add the five Phase 7 handlers; split `Reorganization` according to the
map; move its associated values/store/planner/report; add
`Reorganization/ReorganizationPlanRenderer.php` and messages. Update settings
reset consumers, tests, and the no-deletion exception at the time of the move.

**Interfaces:** Preserve the current public method signatures when relocating:
`FolderCreator` owns `mkdir`/`planMkdir`; `CopyMove` owns `copy`/`move` and their
plans; `MoveInspection` owns `inspect`/`guardFolder`/`assertUnlocked`;
`FolderPreparation` owns `checkFolder`/`createFolders`; `TreeMeasure` owns `measure`;
`BatchMover` owns `batch`/`planBatch`; `BatchUndo` owns `undoBatch`/`planUndoBatch`.
Keep `MovePlanner::plan(Folder $root, string $userId, array $moves, array $mkdirs): MovePlan`
and store signatures. Inject inspection/preparation/measurement directly; no
remaining service may call back through a `Reorganization` facade.

- [x] Pin same/different storage copies and moves, destination collisions,
  hidden descendants, permission changes, shared destinations and locked children.
  Keep bounds: 2,000 nodes, 1 GiB copy bytes, 200 batch items.
- [x] Split single operations first, then batch execution, then undo. Preserve
  plan-wide validation and per-mutation rechecks, partial-progress records and
  user ownership of stored batches. Keep the existing table and retention values
  for database records; this must never become deletion of backup files.
- [x] Test partial failure/resume, expired/foreign/already-undone batches, changed
  file IDs/ETags, and nonempty or independently created folders during undo.
  Only `BatchUndo::undoBatch` may remove its own empty created folder.
- [x] Update the no-deletion scanner's sole exception to the exact new file and
  method; assert a deletion in any other method/file is rejected. Never allow
  both old and new removal implementations to remain active.
- [x] Run `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'FilesMkdirTest|FilesCopyMoveTest|FilesBatchTest|FilesUndoBatchTest|FilesTreeTest|BatchStoreTest|StoreDoublesContractTest|FilesLockTest|SettingsControllerTest|NoFileDeletionTest'` plus common checks.
  Acceptance: no `Reorganization` god class, persisted batches remain compatible,
  and no expanded deletion allowance. Commit the passing steps separately.

## Phase 8 — Switch composition and remove every Files transition artifact

**Files:** Complete `Modules/Files/{FilesModule,FilesToolCatalog}.php`,
`Presentation/{FilesGuide,FilesPlanRenderer,PlanFormatting}.php`, and
`AppInfo/Application.php`. Remove the old Files module/messages/renderer and any
empty old source directories. Move Files-only tests/helpers to their final owners;
update the authoring guide and the execution report. No source aliases remain.

**Interfaces:** The final module implements the current `ToolModule`,
`PreviewsWrites`, `RendersPlans`, and `ToolGuideNotes`. These are active shared
contracts, not deprecated wrappers. Its constructor accepts an explicit
`array<string, Closure(): Tool> $factories` built in `Application`, plus no feature
services. `definitions()` uses the static catalog without calling factories;
`call`/`preview` select exactly one factory and pass authenticated `ToolContext`.
Validate factory keys against the catalog without calling factories; validate
the resolved tool name/write capability only when that tool is selected. Unknown
names are rejected. Do not cache localized definitions.
The service container is captured only at the framework composition boundary,
never passed into handlers. Metadata enumeration must not resolve optional apps.

- [x] Add `testDefinitionsDoNotResolveTools`, `testOnlySelectedToolIsResolved`,
  `testFactoryAndCatalogNamesAgree`, and constructor-free metadata tests. Require
  exactly 23 definitions in baseline order, no duplicates, and unchanged public
  grant/confirmation filtering. Observe failure before adding the final module.
- [x] Run the full catalog check before switching registration. Replace the single
  Files entry in `Application::MODULES`, register its factories, and remove the
  old adapter in the same commit. Keep registry/write-gate behavior untouched.
- [x] Remove dead implementations, temporary imports/allowlist entries and unused
  compatibility constants. Move Files-only tests from `tests/Unit/Tools/Files`
  and `FilesModuleTest.php` to `tests/Unit/Modules/Files`; move Files fixtures/stubs
  to `tests/Support/Files` and update their cross-suite consumers. Move the
  Files-owned `tests/Unit/Checkout` suite into `tests/Unit/Modules/Files/Checkout`,
  including its HTTP adapter tests; remove the emptied old test directories.
  Update namespaces, bootstrap includes, PHPUnit discovery and reflection paths
  together; retain test coverage rather than retaining alias fixtures. Keep shared fake
  trees/users/locks used by other modules in their existing locations for now.
- [x] Make `FilesBoundaryTest` reject all old Files/Checkout namespaces in
  production imports, fully qualified references, service IDs, and autoload maps;
  test representative failures. Check handlers cannot import controllers or
  containers and shared backup primitives cannot import feature internals.
  Extend the old-reference audit to tests, support code, bootstrap, scripts and
  configuration, not just production imports. Only explicit negative test strings
  and historical documentation may mention retired paths/namespaces; neither can
  load or instantiate a retired type. No test-only compatibility shim is allowed.
- [x] Update scanners to use only final existing source roots, removing the old
  glob assertion that would fail when `Tools/Files` disappears. Confirm recursive
  coverage of handlers, capabilities, checkout HTTP code and shared primitives.
- [x] Run `devbox run check --cwd "$FILES_ROOT" --json`; inspect the archive for
  new classes, missing old paths, and absence of tests/dev dependencies. Run
  `composer dump-autoload --optimize` from `mcp/`, then
  `php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --filter 'ApplicationRegistrationTest|FilesBoundaryTest|ToolsListContractTest'`.
  Expected: rebuilt class maps and passing registration/boundary checks; stale
  development autoload entries must not mask a missing moved type. Compare final
  PHPUnit discovery with the Phase 0 inventory: account for every moved, renamed
  or deliberately consolidated test so a passing run cannot hide a dropped suite.
- [ ] Obtain PHP 8.2–8.5 CI results when publishing is explicitly authorized, and
  verify on an authorized disposable Nextcloud instance: read/resource, preview,
  edit/replace/restore, create/upload/checkout, shared and lock refusals,
  sharing/unsharing, partial batch failure and undo. Without that environment,
  record the precise outstanding gate and stop short of production readiness.
- [x] Update docs with actual commands, registration, dependency/fixture footprint,
  removed paths, review results and integration evidence; validate local links and
  SPDX attribution. Check links across all tracked Markdown, including the
  specification and historical pilot documents. Retarget current-source links to
  final owners; retain baseline references as plain text or immutable commit links.
  Do not mark future implementation checkboxes done prematurely.

## 5. Final acceptance and integration policy

- [x] All 23 tools use the new handlers with unchanged public contracts and order.
- [x] Every old source and external consumer in the inventory has a final owner.
- [x] No `mcp/lib/Tools/Files`, `mcp/lib/Checkout`, Files compatibility aliases,
  fallbacks, old production namespaces, or duplicated active implementations remain.
  Files-only test directories and fixtures also have final owners, with no
  transition imports, loader aliases, or unaccounted loss of test discovery.
- [x] The final module only registers/dispatches; heavy work lives in cohesive
  capabilities. Large services were decomposed, not merely renamed or relocated.
- [x] Direct capability tests need only their collaborators. Architecture and
  source scans enforce the final boundary without a Files legacy allowlist.
- [x] Stored tokens/batches, routes, configuration, localization, grants, resource
  output, backup/lock rules, and package self-containment remain compatible.
- [x] Full local checks and fresh package/autoload verification pass. Independent
  review has no unresolved correctness, privacy, or compatibility blocker.
- [ ] PHP matrix and disposable-server evidence are complete before production
  integration; absent evidence is recorded as a gate, not waived by unit tests.
- [x] The execution report and contributor guide describe the final structure;
  deferred repository-wide work is clearly distinguished from Files completion.

Use one coordinated migration series with reviewable capability commits. It can
be reviewed phase by phase, but the completion decision includes Phase 8 removal;
do not ship a supposedly finished Files migration that depends on a future cleanup.
Existing code may coexist within the implementation branch until its phase is
complete. This is compatible with a final tree containing no transition artifacts.

Rollback uses coherent commit groups: consumer import updates and namespace moves
revert together, and a module switch reverts with its old-source removal. Avoid
reverting only half of a dependency move. No user-data conversion is planned.
Do not add shims as a rollback strategy or discard unrelated changes.

**Continuation:** Files has been implemented through the final removal phase.
Resolve the recorded integration gates before production integration; evaluate the
next repository migration as a separate bounded plan.
Do not combine it with all remaining repository migrations. No implementation,
branch rename, publication, or application change is authorized by this document.
