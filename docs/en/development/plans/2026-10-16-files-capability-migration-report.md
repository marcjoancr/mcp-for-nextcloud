<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Files capability migration execution report

Status: Files implementation, local validation and independent review complete.
Remote PHP-matrix and disposable-server verification remain integration gates.
Execution base: `5580e8a`. Scope: Files and its direct consumers only.

## Validation evidence

| Phase | Evidence | Status |
| --- | --- | --- |
| 0 | Full devbox check: 5,100 tests, 30,406 assertions; production package valid. Full definitions/publication snapshot: six grant/app contexts. Focused compatibility/resource suite: 56 tests, 2,523 assertions. | Complete |
| 1 | Metadata red: unsupported grantAnyOf. After foundation moves: full suite 5,108 tests, 30,403 assertions. | Complete |
| 2 | Independent directory-reader test observed red; public read/search/tree and resource suites green. Full suite: 5,109 tests, 30,514 assertions. | Complete |
| 3 | Shared text commit boundary observed red; edit/replace, versions and rendered plans pass. Full suite: 5,110 tests, 30,634 assertions. | Complete |
| 4 | Checkout policy, binary writes and body ownership separated; lifetime test red→green. Full suite: 5,111 tests, 30,765 assertions. | Complete |
| 5 | Image selection, rendering, metadata and search separated; independent renderer test red→green. Full suite: 5,112 tests, 30,839 assertions. | Complete |
| 6 | Sharing services and entry points migrated; grant, stale-plan, recipient and credential tests preserved. Full suite: 5,112 tests, 30,956 assertions. | Complete |
| 7 | Single operations, inspection, preparation, bounded measurement, batch execution and undo separated. Exact deletion-method check strengthened. Full suite: 5,113 tests, 31,099 assertions. | Complete |
| 8 | All 23 lazy factories, final owners and scans; full devbox check: 5,125 tests, 31,747 assertions and valid production package. Rebuilt autoload: 30 tests, 3,171 assertions. | Complete locally; integration gates outstanding |

The existing ResourceRegistry tests already exercise independent Files grant,
traversal, hidden-node and protocol checks; they are retained rather than copied.
The discovery inventory and command logs were kept outside the repository during execution;
the reconciliation and validation results are recorded below.
Remote PHP 8.2–8.5 CI and a disposable Nextcloud instance remain production gates.
No publishing or deployment is included in this execution.

## Final ownership and developer experience

`Modules/Files/FilesModule` receives one factory map and contains no file workflows.
Its catalog has all 23 handlers in baseline order. `Application` owns all container
lookups; enumeration never resolves a handler. Tests exercise every actual handler
factory and enforce selected-only dispatch, name agreement and write capability.

Capabilities are Reading, Search, Editing, Versions, Checkout, Creation, Images,
Sharing and Reorganization. Presentation owns metadata-only guide/renderer dispatch.
The resource contract is `Contracts/FileResourceReader`. Shared `BackupLocation`
and `BackupMessages` live under `Platform/FileAccess`, with no feature imports.
The checkout controller is an HTTP adapter; `CheckoutPolicy`, `CheckoutWriter` and
`Checkout/Http/BodyReader` own authorization, binary writes and temporary lifetime.

The former `Reorganization` class is gone. Its operations have distinct owners;
`MovePaths`, `MoveExecution` and `BatchVisibility` share path calculations, mutation
protection and privacy checks without duplicating their implementation. Image
selection, rendering, metadata and search are also separate. Existing sharing
services retain their cohesive public signatures and approval protocol.

Removed production directories: `mcp/lib/Tools/Files`, `mcp/lib/Checkout`.
Removed test directories: `mcp/tests/Unit/Tools/Files`, `mcp/tests/Unit/Checkout`.
Files tests now follow capabilities under `tests/Unit/Modules/Files`, and Files
support/stubs live under `tests/Support/Files`. There are no aliases or old loaders.
Shared fake trees/users/locks remain in place for other modules.

A direct read still needs one handler dependency (`FileReader`); a direct edit
needs `TextFileEditor`. Their focused fixtures build no unrelated services.
`FilesToolsTestCase` is retained for public cross-capability scenarios; focused
module fixtures supply only selected handler factories and fail on other calls.
An ordinary tool addition changes its handler, needed capability, catalog/factory
entries and tests, without a central workflow switch or protocol rewrite.

The package contains all 112 Files PHP source files, with no retired Files/Checkout
paths, tests or PHPUnit. Production dependencies, routes and stored token/batch
formats are unchanged. Three unused legacy message methods and their translation
keys were removed; all active localized output remains covered by existing tests.

## Evidence and test discovery

The complete registered check ran on PHP 8.4.26:

```sh
devbox run check --cwd "$FILES_ROOT" --json
```

After `composer dump-autoload --optimize`, registration, architecture and tool-list
checks passed again. Existing non-PSR-4 third-party test stubs still emit Composer
warnings; they are explicitly loaded test fixtures and do not enter the package.
No strict-PSR gate or blanket suppression was introduced.

The phase-0 discovery inventory contains 5,107 cases: the original 5,100 plus seven
all-definition/publication snapshots. Final discovery contains 5,125 cases. No
baseline case was dropped. Namespace moves preserve methods/providers; the catalog
case `testOnlyPilotToolsAreExplicitlyListed` became
`testExplicitSubsetCanBeEnumeratedWithoutConstruction`, with complete catalog/order
coverage supplied by the full-definition snapshot. Two scanner method names lost
the pilot qualifier. Eighteen further cases cover metadata, independent capability
construction, temporary disposal, shared commit ownership, exact undo deletion,
retired-reference auditing and lazy composition.

## Execution decisions

1. Keep orchestration state outside the repository, as the workspace policy requires.
   Cost if wrong: only the location of execution records; no product behavior changes.
2. Use the same upload/post PHP limits as the catalog task in focused commands.
   The plain CLI default changes checkout test inputs. Cost if wrong: repeat checks
   with the intended environment; no production configuration was changed.
3. Keep sorted traversal entries in `TreeEntries`, shared by tree reading and copy
   measurement. Cost if wrong: revisit that small internal boundary, not public APIs.
4. Let checkout resolution invoke an optional owner-language callback after token
   authorization. This preserves the previous locale timing while HTTP configuration
   stays in the controller. Cost if wrong: rework that internal callback; token/status
   and localized failure cases are covered.

## Outstanding gates and recommended continuation

No Files transition implementation remains. Production integration still needs
PHP 8.2–8.5 CI and an explicitly authorized disposable Nextcloud instance covering
resources, protected writes, binary checkout, shares, locks, partial batches and
undo. Local tests do not replace those checks. No push, PR, merge or deployment was
performed as part of this execution.

The next implementation candidate is Deck: `DeckToolModule` (958 lines) and
`DeckServiceGateway` (1,016 lines) still combine many operations. First inventory
its schemas, plans, storage/API boundaries and optional-app behavior, then approve
a bounded plan using Files' static catalog and lazy composition. Avoid expanding
a Deck migration into shared DAV or every remaining feature.

Subsequent independent scopes remain Talk, Notes, Calendar/Contacts/Tasks and their
DAV/reference contracts; shared registry/policy organization; administrative JS;
Composer developer commands, gradual static analysis/style; and documentation
relocation/translations with English fallbacks. These are repository migration
work, not deferred Files compatibility removal.

## Independent final review

A fresh reviewer examined `5580e8a..203a407`, including the closing documentation
and scanner changes. Verdict: no actionable findings and no declined judgments;
approved for local completion subject to the recorded integration gates. The
review independently ran compatibility, architecture, application registration and
dispatch checks without PHPUnit result caching: 35 tests, 846 assertions, all
passing. No minor findings were deferred.

The final scanner additionally covers package scripts, CI configuration and
Composer/PHPUnit configuration. Its focused check passed 11 tests, 737 assertions.
All 93 local Markdown file/heading links validated. Source inspection confirms
the final module is 72 lines and the checkout HTTP adapter 293 lines; these are
observations, not size limits.

The implementation remains on `refactor/files-read-edit-pilot` in its existing
worktree. No branch rename or remote integration was performed. Capability commits
are separate; the final module switch and retirement of source/test paths are
coherent within `203a407`. Roll back namespace moves together with their consumers.
