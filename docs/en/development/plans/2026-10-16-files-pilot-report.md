<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Files read/edit pilot report

Date: 2026-10-16.

The authorized pilot extracts `files_read` and `files_edit` while retaining their
public policy path. Local implementation, compatibility tests, and production
package validation are complete. Wider migration is paused for evaluation; live
Nextcloud verification and remote CI remain outstanding.

## Baseline and scope

- Examined application revision: `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92`.
- Implementation base: `5a5f28f` (reviewed documentation only), clean before work.
- Branch renamed from `docs/devex-architecture-docs` to `refactor/files-read-edit-pilot`.
- Reused the validated isolated devbox worktree; no base-checkout changes.
- Environment: Debian PHP 8.4.26, Composer 2.8.8, PHPUnit 10.5.65.
- PHP/Composer prerequisites were initially absent. They were installed, then
  catalog setup and the baseline check passed. No dependency manifest or lockfile
  change was needed. Setup's existing test-stub PSR-4 warnings did not prevent it.

Only the two named operations moved. `ToolRegistry`, `WriteGate`, renderers,
`Application::MODULES`, messages, app metadata, runtime dependencies, CI, and
packaged READMEs remain unchanged. No push, PR, deployment, or wider tool migration
was performed.

## Architecture delivered

`Mcp/Tools` contains four small contracts/values: `Tool`, `WriteTool`,
`ToolDefinition`, and `ToolContext`. The context carries authenticated identity,
never services or caller-supplied identity. Schemas remain with the handlers.

`Modules/Files/Tools/ReadFile` delegates to `Reading/FileReader`; `FileRead` keeps
the text and metadata blocks separate. `Tools/EditFile` maps validated arguments
to `Editing/EditRequest` and delegates to `TextFileEditor`. Both capabilities use
the existing guards and helpers. Their visibility and lock dependencies are
required, with no production fallback constructors.

`FilesToolCatalog` explicitly lists the two handlers and validates metadata without
service construction. The legacy `FilesModule` consumes these definitions in their
original positions and delegates only their call/preview paths. Its dead `read`,
`text`, `write`, and `planWrite` methods were removed. Concrete dependencies autowire;
no new application binding or discovery path is needed.

## Developer-experience observations

| Observation | Before | After |
| --- | --- | --- |
| Public-module constructor parameters | 24 | 26 during coexistence |
| Direct read handler constructor | Full module required | 1 capability; reader has 5 dependencies |
| Direct edit handler constructor | Full module required | 1 capability; editor has 7 dependencies |
| Read fixture | Legacy module construction includes database, checkout, batches, versions, images, and optional services | File tree/root, users/access metadata, config/tags/visibility, extractor/temp manager, OCR/app status |
| Edit fixture | Same broad module fixture | Read fixture plus clock, locks, backup, shared-write guard |
| Legacy module length | 1,082 lines | 1,002 lines |
| New production handler/capability length | Not applicable | Read handler 31, reader 44; edit handler 42, editor 142 lines |

The adapter constructor grows during coexistence; this pilot does not claim to
have eliminated the monolith. Direct tests no longer pay for unrelated Files
services. `NodeAccessInfo` still uses the existing share-manager double to describe
ownership; no share writer/lister/remover is needed by those fixtures.

The production footprint is eleven new files plus the existing adapter: four
contracts/values, six read/edit types, and one catalog. Existing test construction
changes are confined to `FilesToolsTestCase`, `FilesModuleTest`,
`FilesSearchContentTest`, and `FilesPlanTest`; the latter continues to inject a
backup with a never-call assertion for previews. `NoFileDeletionTest` now covers
the nested pilot sources and identifies its sole existing exception by exact path.
New focused, contract, and architecture tests complement existing behavior tests.
Documentation changes stay under `docs/`.

Adding another ordinary tool still requires an explicit catalog entry and adapter
dependency/dispatch entry, plus presentation/plan metadata if its public name is
new. The [authoring guide](../adding-a-tool.md) documents that current cost instead
of implying automatic registration already exists.

## Verification evidence

The catalog command for both baselines and final verification was
`devbox run check --cwd "$PILOT_ROOT" --json`, where `PILOT_ROOT` is the validated
isolated worktree. It runs the full test suite, builds the production archive, and
validates that archive.

| Check | Result |
| --- | --- |
| Before implementation | 5,064 tests, 30,205 assertions; 33.078 s; 410.51 MB; package valid |
| Contract characterization | 6 tests, 30 assertions |
| Read extraction with source/API/visibility checks | 2,179 tests, 5,037 assertions |
| Edit extraction with safety regressions | 2,222 tests, 5,342 assertions |
| Adapter/registry/resource integration selection | 189 tests, 3,619 assertions |
| Final full catalog check | 5,100 tests, 30,406 assertions; 36.020 s; 410.51 MB; package valid |
| Separate direct read/edit selection | 16 tests, 72 assertions; 0.312 s; 64 MB |

The same public read comparison was run from `mcp/` before and after:

```sh
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Tools/FilesModuleTest.php --filter testReadsTextPdfDocxAndOdt
```

Both runs executed one test/four assertions at 36 MB. Observed PHPUnit times were
0.066 s before and 0.187 s after. These single short runs establish no speed
improvement and are not a stable performance benchmark. The demonstrated benefit
is reduced construction scope and clearer responsibility, not faster execution.

Compatibility coverage includes full pilot definitions and list order, separate
read result blocks, unknown-argument rejection, grant refusal, global versus shared
confirmation, omitted ETag, no plan-state requirement, and unexpected-error privacy.
Existing tests now exercise the migrated paths for text/PDF/DOCX/ODT, scanned PDFs,
OCR notices, 20 MiB extraction limits, 100,000-character multibyte truncation,
10 MiB edit limits, non-text/backup paths, versioning, locks, backup failures, and
localized messages. The focused read tests also cover hidden nodes and ancestors.

New edit cases pin preview/execution first-failure ordering, own/foreign locks,
lock appearance after backup, permission/visibility changes after preview, and tag
propagation when a source becomes hidden during preparation. The existing backup
privacy tests verify tags are assigned while the backup is empty. These checks
preserve current safeguards; they do not claim atomicity across Nextcloud APIs.

The architecture rule tests representative forbidden dependencies and permits only
named legacy helpers. `LibScanner` and localization scans already traverse `lib/`
recursively; no scope relaxation was needed. The API scan explicitly asserts that
the extracted capabilities are included. No-deletion coverage includes nested
read/edit handlers and capabilities, with no new removal exception.

The validated `mcp-1.0.8.tar.gz` includes all eleven new production PHP files.
Archive inspection found no tests or root documentation directory. Existing
package READMEs and runtime dependencies are retained. Licensing/source checks
pass for the new PHP files; documents and fixture metadata carry SPDX attribution.

## Clarification made during implementation

The specification originally grouped update permission with execution-only checks.
The existing preview already checks permission bits through `SharedWriteGuard`.
That wording was corrected: versioning and `isUpdateable()` in `FileBackup` are
execution checks; update permission bits are checked in both phases. No runtime
behavior was changed to fit the earlier wording.

The planned format/limit scenarios reuse existing tests through the migrated
public adapter rather than duplicating extractor tests. A separate
`FilesEditFixture` adds write collaborators only to tests that need them. Baseline
evidence was captured before implementation and consolidated into this report
when documenting the final result.

## Temporary overlap and removal conditions

| Legacy method | Remaining consumer | Removal condition |
| --- | --- | --- |
| `commit` | `replace` | Migrate snippet replacement and its recovery behavior |
| `planContent` | `planReplace` | Migrate replacement plans without changing their envelope |
| `assertEditable` | `planReplace`, `replace` | Migrate their text/path checks |
| `assertNotBackup` | `assertEditable`, `planCheckout`, `checkoutOut` | Migrate remaining backup-path restrictions |
| `file` | Remaining read/write tools | Each remaining Files route has its own capability boundary |
| `lockNotice` | Replacement, checkout, and restore plans | Migrate those plan paths |

The editor temporarily duplicates the first four helpers and file resolution;
its lock notices call the shared lock service directly. `FileBackup`, extraction,
diff, messages, and resource guards remain shared. This overlap must not become a
second public API or a reason to migrate unrelated tools inside this pilot.

## Independent review

A fresh reviewer inspected `5a5f28f..72cde92` against the specification and plan.
No actionable critical, important, or minor findings were identified. The reviewer
independently ran the contract/catalog/architecture selection (12 tests,
31 assertions), inspected the recorded full check and package result, and verified
clean whitespace. The assessment accepts the bounded local pilot with the remote
CI and real-server limitations below. This final documentation addition records
that assessment; it changes no implementation or tests.

## Remaining verification and decision

- The existing CI workflow covers PHP 8.2, 8.3, 8.4, and 8.5. Only PHP 8.4.26 was
  executed locally; no remote CI result is claimed because this branch was not pushed.
- No authorized disposable Nextcloud instance was available. Before production
  integration, exercise read, preview, confirmed edit, shared refusal, and lock
  refusal with a real user. Unit/API contracts are not that live-server check.
- Keep the branch/worktree for review. No user-data or database migration is needed;
  focused commits allow the adapter integration to be reverted first if necessary.

Recommendation: accept this as a bounded local pilot and use its guide for review.
The smaller construction surface supports the proposed direction, while the
adapter and helper overlap remain visible costs. Complete live/CI verification
before production integration and make a separate scope decision before migrating
any other tools. The wider roadmap remains unimplemented.
