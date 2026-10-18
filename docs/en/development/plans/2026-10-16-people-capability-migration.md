<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# People capability migration plan

> **For agentic workers:** Implementation requires separate authorization. Once
> authorized, use `superpowers:executing-plans` for ordered execution. Finish and
> validate each phase before starting the next. Checkboxes record implementation checkpoints.

**Status:** Implemented locally and independently reviewed; external release gates remain.
**Review:** Checked against production, direct/indirect consumers and test discovery
at `9afd64c`; implementation was authorized and began at reviewed plan commit `43c00d0`.
**Evidence:** [Execution report](2026-10-16-people-capability-migration-report.md).
**Date:** 2026-10-16.
**Planning baseline:** `9afd64c`, after the completed Notes migration.
**Goal:** Migrate `users_search` by capability while preserving native sharing-search
restrictions, publication, result ordering and privacy; retire every old People owner.
**Architecture:** One search capability behind one MCP tool, an explicit catalog
and a lazy module. Reuse Nextcloud's `ISearch` as the native boundary; no new gateway,
repository, cross-feature contract or generic search framework.
**Tech stack:** PHP >=8.2, Composer PSR-4 `OCA\Mcp`, PHPUnit 10.5, native app `mcp`,
declared Nextcloud 32–35 support. No dependency, database or public API changes.
**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Files](2026-10-16-files-capability-migration-report.md),
[Deck](2026-10-16-deck-capability-migration-report.md),
[Talk](2026-10-16-talk-capability-migration-report.md),
[DAV](2026-10-16-calendar-dav-capability-migration-report.md),
[Notes](2026-10-16-notes-capability-migration-report.md), and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Keep identifiers, comments, PHPDoc and technical documentation in English.
  Preserve localized user-facing messages and meaningful multilingual fixtures.
- Preserve `users_search`, module `people`, operation `read`, its absence of an
  `app` filter, the `people.read` grant, title, annotations, schemas, guide and
  raw metadata key order. Keep People eighth in the nine-module list, before Logs.
- Public requests still pass through ToolRegistry, ArgumentValidator and GrantPolicy.
  People stays read-only: no WriteTool, preview, confirmation or plan renderer.
- Keep the sharing collaborator search, not IUserManager enumeration, direct SQL,
  Contacts directory search or a Files/Talk/Deck delegation. Do not reconstruct
  the core's administrator/group restrictions in MCP.
- Core search reads the authenticated host session. ToolContext does not impersonate
  a user. Keep McpController's Basic/Bearer session binding and cleanup unchanged.
  Do not cache terms, results, identity or translated errors between requests.
- Preserve coercion, trim/length/limit validation, ranking, deduplication, empty
  output shape, logging and exception boundaries. Record defects separately rather
  than silently strengthening validation or changing failure handling in this move.
- People metadata and guide discovery must not resolve its ISearch/logger dependencies,
  invoke search, or depend on optional apps. This is a feature-local constraint: the
  shared registry still has its own logger. No container or service locator in People.
- Reuse the validated worktree and branch; preserve unrelated changes. No push, PR,
  deployment, real-account enumeration or other feature migration is included.
  Keep harness scratch outside the repository and build artifacts inside it.

## Review focus

1. Bearer/session identity differs from a passed tool context or changes between
   calls: native search must use the controller-established session, without cached
   results or new impersonation (Phases 0, 1 and 3).
2. A native exception contains a private query or e-mail: preserve the fixed localized
   failure and class-only warning, without forwarding exception objects/messages or
   accidentally widening the catch boundary (Phases 0 and 1).
3. Exact/partial users and groups collide, including IDs `0` and equal user/group IDs:
   preserve typed deduplication, first occurrence and the combined final limit
   rather than sorting or applying separate per-type caps (Phases 0 and 1).
4. Whitespace, multibyte terms, malformed native rows or misleading e-mail sublines
   change acceptance/output during extraction: characterize current behavior and
   keep schema validation distinct from direct-call coercion (Phases 0 and 1).
5. A lazy module loses its grant filter, app-less availability or a reflective fixture
   still builds the retired constructor: validate real composition, global publication,
   discovery and the production-only archive together (Phases 2 and 3).

## 1. Findings and scope

The inventory below records the planning baseline; final ownership is in Section 2.

The production owner is `mcp/lib/Tools/People/PeopleModule.php` (175 lines), with two
constructor dependencies: `OCP\Collaboration\Collaborators\ISearch` and PSR logger.
It combines schemas/guide text, dispatch, native query validation/execution, safe
failure mapping and result projection. There is one tool and no write/resource
consumer. Its size does not justify splitting every helper into another class.

`mcp/tests/Unit/Tools/People/PeopleModuleTest.php` contains **17 test methods**, with
no data providers, covering users/groups, ordering, limits, schema, grants, registration
and exception privacy. It is the only dedicated People test file.

Affected consumers and evidence:

| Consumer | Required treatment |
| --- | --- |
| `lib/AppInfo/Application.php` | Replace the old MODULES entry in place; add explicit lazy module/tool factories. People currently relies on constructor autowiring. |
| `tests/Unit/Tools/WritePlanContractTest.php` | Retarget the People class in READ_ONLY; preserve read-only coverage. |
| `tests/Unit/Contract/{ToolsListContractTest,WriteGateContractTest,VisibilityContractTest}.php` | Their builders traverse every Application module. Add final People factory-map handling during cutover, before the full gate. |
| `tests/Unit/ApplicationRegistrationTest.php` | Exercise real module/tool registration closures and absence of native resolution during discovery. |
| `tests/Unit/McpControllerTest.php` | Retain Basic/Bearer identity, translation and session-cleanup tests. No auth refactor. |
| `tests/Unit/Tools/{ToolPresentationTest,ToolGuideTest}.php`, language contracts | Keep public title/guide instructions and literal translation sources intact. |
| `tests/Unit/Contract/NextcloudApi/` and its version fixtures | Scanner follows lib recursively; verify the moved ISearch/IShare dependencies remain checked. Change fixtures/scanners only for an evidenced gap. |

Files, Deck, Talk, Calendar and Contacts reference the public `users_search` name in
instructions/descriptions. They do not consume People implementation classes. Keep
those strings, fixtures and READMEs unchanged; no compatibility facade is required.
The existing OCP interface already provides the narrow native integration boundary.

## 2. Final structure and interfaces

Paths below are relative to `mcp/` and are the complete production scope:

```text
lib/Modules/People/
  PeopleModule.php
  PeopleToolCatalog.php
  Tools/SearchUsers.php
  Search/PeopleSearch.php

tests/Unit/Modules/People/
  PeopleModuleTest.php
  Search/PeopleSearchTest.php
tests/Unit/Contract/PeopleMigrationCompatibilityTest.php
tests/Unit/Contract/PeopleCatalogTest.php
tests/Architecture/PeopleBoundaryTest.php
tests/Support/People/Compatibility/{definitions.json,results.json,README.md}
```

- **PeopleSearch:** `__construct(ISearch $search, LoggerInterface $logger)`;
  `search(array $arguments): array` returns `list<array<string,string>>`. Own
  `MIN_TERM=2`, `MAX_TERM=100`, `DEFAULT_LIMIT=10`, `MAX_LIMIT=25`, plus private
  result-set/type constants, `matches(array $result, bool $includeGroups): array`
  and `account(string $uid, array $match): array`. Move validation, the native call,
  its narrow failure boundary and projection here. Keep the single literal
  `Translator::t('The search for people failed; try again.')` at its sole use;
  a separate one-method message class or mapper would add navigation without reuse.
  No userId parameter: the native service uses the host's current session.
- **SearchUsers implements Tool:** `__construct(PeopleSearch $search)`;
  `static definition(): ToolDefinition`; `execute(array $arguments, ToolContext
  $context): array` wraps `PeopleSearch::search()` with `ToolResult::json()` once.
  Preserve its JSON flags, indentation, invalid-UTF-8 substitution and text envelope;
  do not encode/scrub rows inside the capability or add structuredContent.
  Context is accepted by the shared contract, never used to override native identity.
  Static metadata owns the exact existing description and schema; limits reference
  PeopleSearch constants without requiring an instance.
- **PeopleToolCatalog:** `static classes(): array` returns the ordered single class;
  `static definitions(?array $classes = null): array` returns a map keyed by tool
  name. Reject non-Tool entries, duplicates and non-read definitions. Project the
  original raw order: `name`, `description`, `module`, `operation`, `inputSchema`.
  Do not add `app: null`, grants or confirmation fields or rely on ToolDefinition's
  differently ordered default projection.
- **PeopleModule implements ToolModule, ToolGuideNotes:**
  `__construct(array $factories)` accepts exactly `users_search => Closure(): Tool`.
  Check map keys and closures without invoking them. `definitions(): array` returns
  catalog values; `guideNotes(): array` retains all three English notes.
  `call(string $name, array $arguments, string $userId): array` rejects unknown names
  before factory resolution, validates the returned Tool/name and dispatches with
  `new ToolContext($userId)`. Do not retain search methods or limit aliases here.

Application registers the module's closure map and the SearchUsers factory;
PeopleSearch uses ordinary typed autowiring for ISearch and LoggerInterface.
No production consumer outside the People feature may import its implementation
classes, except Application for composition.
Only the registered module publishes People. Static discovery intentionally becomes
lazy; native-service construction failures are not moved into the native-call catch.
Do not scaffold
empty Contracts, Execution, Presentation or Infrastructure folders for symmetry.

## 3. Behavior to freeze before extraction

| Area | Required compatibility |
| --- | --- |
| Public definition | `users_search`; app-less `people.read`; query required, 2–100 characters; limit 1–25/default 10; include_groups boolean/default false; no additional properties. Exact raw/published definitions, schema shapes, title, annotations, guide and module order. |
| Direct validation | Unknown name fails first with `InvalidArgumentException('Unknown tool')`. Query is `(string)` then `trim`, measured with `mb_strlen`; limit is `(int)` with default 10. Invalid bounds use `InvalidArgumentException('Invalid search term or limit')`. Direct include_groups retains `(bool)` coercion; registry validation remains stricter. Inputs rejected by the applicable validation layer never invoke native search. |
| Native call | Exactly `search($term, [IShare::TYPE_USER], false, $limit, 0)`, adding TYPE_GROUP second only when requested. Use first tuple element; ignore the has-more flag. No extra paging, enumeration, secondary lookup or hidden native call. |
| Ordering and identity | Exact users, exact groups, partial users, partial groups. Skip groups entirely unless requested. Skip non-array matches and empty IDs; retain ID `0`. Deduplicate by `type . ':' . id`, retaining the first row; equal user/group IDs survive. Apply one limit after deduplication. |
| Projection | User keys: type, uid, displayName, optional email; group keys: type, id, displayName. Label fallback uses `??`, preserving empty labels. E-mail is included only for a nonempty subline containing `@` and no ASCII space; do not substitute stricter validation. Groups omit email. Empty result encodes a JSON list `[]`. |
| Serialization | Preserve ToolResult::json exactly, including Unicode/slashes, pretty printing and invalid-UTF-8 substitution in the final text block. Do not normalize or reorder decoded rows to make fixtures pass. |
| Failures | Catch Throwable only around the native search/tuple assignment. Log exactly `MCP people search failed`, with app=mcp and exception_class; throw the fixed translated ToolFailure. Do not log term, results, exception object/message/trace. Mapping or logger failures outside that boundary retain existing behavior. |
| Policy and isolation | Denied people.read hides publication and rejects calls before native access. With grants, disabling every optional app still leaves the tool available. Native identity follows the authenticated session; no cross-call result/locale cache. |

Freeze representative definitions and result/error envelopes from the unmodified
baseline, using deterministic fake data only. Reset Translator in teardown. New
malformed-row or Unicode cases characterize existing behavior; do not claim the
baseline accepts every malformed value or silently broaden the catch to achieve it.

## 4. Ordered phases and acceptance

Set `TASK_REPO` to the validated worktree. Run PHP commands from `"$TASK_REPO/mcp"`.
Use the focused selection in Section 5 for every phase, plus the full catalog task.
Wait for command completion, inspect exit status and output, then commit/advance.
Do not regenerate frozen fixtures from the replacement or remove provider cases.

### Phase 0 — Freeze publication, native behavior and privacy

**Files:** Create the compatibility test and three fixture files listed in Section 2;
extend the existing PeopleModuleTest where needed. No production changes.
**Interfaces:** Produces immutable definitions/guide/results/error fixtures and
method/expanded-case inventories, consumed unchanged by all subsequent phases.

- [x] Capture exact raw/published metadata, module order and guide output, plus
  user-only, mixed exact/partial, empty and capped results and localized failures
  in English, Portuguese and Spanish. Record the actual source commit in README.
- [x] Add named cases for gaps: `testTrimmedUnicodeBoundsAndDirectCoercion`,
  `testTypedDeduplicationPrecedesCombinedLimit`, `testNativeFailureBoundaryAndPrivacy`,
  `testDeniedGrantStopsNativeAccessAndOptionalAppsAreIrrelevant`, and
  `testRepeatedCallsFollowHostSessionAndCurrentLocale`. Assert Section 3 values;
  use a changing host/session double for the last case, not a fake userId filter.
  This checks MCP does not cache or impersonate; controller tests and disposable-host
  validation separately establish session binding and native policy behavior.
- [x] Include empty/missing labels and IDs, ID `0`, non-array matches, ignored result
  sets and e-mail sublines with spaces versus other whitespace. Capture existing
  mapping failures separately from the sanitized native-call exception path.
  Pin `"  éé  "` as native term `"éé"`, 100 versus 101 multibyte characters,
  omitted/default arguments, and direct `limit: "2"` / `include_groups: "false"`
  coercion versus registry type rejection. Include invalid UTF-8 in returned labels
  to freeze final ToolResult encoding, not a new capability normalization.
- [x] Test both RuntimeException and Error thrown by native search with private text;
  expect the fixed ToolFailure and one class-only warning per call. Separately return
  `['invalid-result', false]` from the native double: the mapping TypeError remains
  outside the sanitized catch. Make the logger throw a known exception and assert
  it escapes unchanged. Keep direct exception expectations distinct from the registry's
  existing generic error mapping; do not broaden logging or catches.
- [x] Inventory all 17 original methods and expanded discovery; run focused tests
  and `devbox run test --cwd "$TASK_REPO" --json`. Expected: all pass against the
  original implementation, with no production diff or mutation diagnostics.
- [x] Commit `test(people): freeze search publication and privacy contracts`.

**Acceptance:** Before any extraction, fixtures pin public output and all five review
focus areas have baseline cases or explicitly mapped existing coverage. New lazy
construction and catalog rejection are structural requirements tested in Phases 2–3,
not claims about the pre-migration constructor.

### Phase 1 — Extract the single search capability

**Files:** Create `lib/Modules/People/Search/PeopleSearch.php` and
`tests/Unit/Modules/People/Search/PeopleSearchTest.php`; modify the old PeopleModule
and constructors in `tests/Unit/Tools/People/PeopleModuleTest.php` and
`tests/Unit/Contract/PeopleMigrationCompatibilityTest.php` to consume the capability.
The three global builders continue recursive typed autowiring in this phase; verify
that behavior rather than introducing their final factory-map branches early.
**Interfaces:** Consumes native ISearch/logger and Phase 0 contracts; produces
`PeopleSearch::search(array): array` and the four constants defined in Section 2.

- [x] Write capability tests for exact native arguments, ordering/projection, invalid
  input avoiding search, localized safe failures and consecutive calls. Run them
  directly; expected initial failure is the missing PeopleSearch type.
- [x] Move validation, search/catch, matches/account and limits unchanged. The old
  module temporarily injects PeopleSearch, keeps name dispatch and ToolResult::json,
  and references capability limits directly. Delete its extracted methods/constants;
  do not create aliases or a replacement broad service.
- [x] Run the new capability suite, focused selection and full catalog task.
  Expected: all pass; public fixture bytes and failure precedence remain unchanged.
- [x] Commit `refactor(people): extract native people search capability`.

**Acceptance:** One implementation of native search and projection; no new native
API, identity handling, filtering, pagination or result cache. The old module is
still the sole publisher at this checkpoint and is removed in the next phase.

### Phase 2 — Cut over publication and retire old source/tests

**Files:** Create the other three final production files and PeopleCatalogTest;
modify Application, PeopleMigrationCompatibilityTest, the three global reflective
builders and WritePlanContractTest;
move `tests/Unit/Tools/People/PeopleModuleTest.php` to its final path and namespace.
**Interfaces:** Consumes PeopleSearch; produces the Tool, catalog and lazy ToolModule
interfaces in Section 2. Frozen contracts are retargeted without new golden data.

- [x] Write catalog/module tests red: exactly one read Tool; unknown name rejection;
  missing/extra/non-closure factory rejection; mismatched result rejection; duplicate
  and non-Tool/non-read catalog rejection; definitions/guide with throwing factories.
  Assert that the module implements neither PreviewsWrites nor RendersPlans and the
  published SearchUsers is not a WriteTool.
- [x] Implement SearchUsers, catalog and module; wire actual Application factories
  once and replace the MODULES entry at its existing index. Move all guide text and
  metadata without rewording or new source translations.
- [x] Retarget all three indirect builders immediately and retain the READ_ONLY exemption.
  Move all 17 original tests, adjusting fixture construction without weakening their
  assertions. Update the old test namespace to `OCA\Mcp\Tests\Unit\Modules\People`;
  no test from Phase 0 stays behind. Translate only non-English assertion diagnostics,
  not fixture content.
  Use small explicit builders; no general-purpose test container or other feature's
  test factory is needed for this four-class production design.
- [x] Delete `lib/Tools/People/` and `tests/Unit/Tools/People/` completely. Remove
  every active old import, constructor, constant reference and autoload string.
- [x] Run isolated final People suites, focused selection and full catalog task.
  Expected: all pass; unchanged raw/public output, only one publisher, no writes.
- [x] Commit `refactor(people): migrate tool publication and retire legacy owners`.

**Acceptance:** All production requests use final classes; all original tests run
from final owners; no compatibility aliases, old-test loaders or duplicate dispatch.

### Phase 3 — Final boundaries, composition, package and documentation

**Files:** Create PeopleBoundaryTest; extend ApplicationRegistrationTest and People
compatibility/catalog coverage as needed. Update architecture specification,
adding-a-tool guide, both docs indexes, plan status and
`docs/en/development/plans/2026-10-16-people-capability-migration-report.md`.
**Interfaces:** Final People implementation is private to its feature except
Application composition and shared module contracts. No new public feature port.

- [x] Add negative scanner examples for imports, fully qualified calls, escaped class
  strings and retired source/test/autoload paths. Reject containers/global locators,
  other feature internals and production test dependencies. Then assert the actual
  production/test/scripts/appinfo/composer/CI trees contain no retired People route.
  Cover old source and test namespaces, class_alias and hand-written loaders as well
  as path strings. Historical documentation and intentional synthetic negative-test
  strings are the only exceptions; do not skip entire People test files or match a
  mere People prefix. Inspect bootstrap.php and phpunit.xml for stale loaders.
- [x] Exercise actual registration closures with host doubles: metadata and guide
  must not resolve People's ISearch/logger dependencies; resolving/executing SearchUsers receives the
  intended capability. Invoke users_search through the registry, verify native
  arguments/output and deny its grant before native access. Verify app-less discovery.
- [x] Reconcile every original method and expanded provider identity after namespace
  normalization; explain every count change. Run isolated, focused, architecture,
  native API and full test/package gates from Section 5; expected all green.
- [x] Extract the actual production archive under ignored build storage. In a fresh
  process with only its production autoloader and minimal declared host fixtures,
  load all four types, compare their and Application's source bytes, raw/published
  definition and guide,
  verify module order and reject retired/test classes or external classmap paths.
  No development vendor/test bootstrap. There are no saved write plans to probe.
- [x] Self-review and perform the final independent review authorized by the execution
  workflow. Resolve verified blockers and rerun affected gates before completion.
  Record phase commits, counts, fixture provenance, deviations and external gaps.
- [x] Mark implemented only after gates pass; update migrated tool count from 71 to
  72 then, leaving Logs and other work separate. Commit
  `refactor(people): verify boundaries and document completed migration`.

**Acceptance:** No retired source/test tree, transition facade, registration, loader
or runtime string remains. Full tests/package and the production-only probe pass.
All evidence distinguishes local completion from supported-version/live-host checks.

## 5. Verification and remaining external evidence

Planning verification on `9afd64c`, PHP 8.4.26 / PHPUnit 10.5.65, passed:
**109 tests / 6,464 assertions** in 0.714 s. The execution selection below adds
VisibilityContractTest, an indirect builder identified by the phase 2 full gate:

```bash
cd "$TASK_REPO/mcp"
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result --filter 'People|ToolsListContractTest|WriteGateContractTest|VisibilityContractTest|WritePlanContractTest|ApplicationRegistrationTest|ToolPresentationTest|ToolGuideTest|L10nLanguagesTest|ToolErrorsLanguagesTest|McpControllerTest'
```

That count is historical planning evidence. The execution report records full-suite,
package and expanded focused results; live-server validation remains separate.
Keep People in new test names so the selection retains them. For final validation:

```bash
cd "$TASK_REPO/mcp"
composer dump-autoload --optimize
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Modules/People
# Repeat the focused command above.
php -d memory_limit=1G vendor/bin/phpunit tests/Architecture
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Contract/NextcloudApi
devbox run check --cwd "$TASK_REPO" --json
git -C "$TASK_REPO" diff --check
```

Expected: successful exit and passing assertions/package validation. Existing fixture
PSR-4 notices must not become packaged dependencies. Inspect native scanner discovery
and version fixtures; moved types must not reduce coverage merely by leaving the old
namespace. Preserve the exact ISearch positional call through a focused mock assertion.

Before release, run PHP 8.2–8.5 CI and disposable Nextcloud 32–35 checks for Basic and
Bearer identity, sharing enumeration disabled, exact-only behavior, group sharing
disabled, same-group restrictions and hidden groups. Local mocks prove delegation,
not native plugin policy or directory behavior. Use synthetic users on disposable
hosts, never real account enumeration as a structural-migration diagnostic.

### Documentation review outcome

The review clarified feature-local laziness, the unchanged native exception boundary,
final serialization ownership, constructor updates in both intermediate and final
phases, and exact old-test namespace retirement. It separates baseline behavior
from new architectural checks and distinguishes session-double evidence from real
host authentication/policy guarantees. The focused selection was rerun during review: **109 tests / 6,464 assertions**,
all passing in 0.644 s on the same runtime. Subsequent full-suite and package
evidence is in the execution report. Live-host verification remains a release gate.

## 6. Completion and continuation

A full migration without transition folders is feasible in this bounded scope.
There are no internal consumers requiring a temporary compatibility API. The Phase 1
old module is an intermediate checkpoint, never a completion state; Phase 2 retires
it and Phase 3 proves retirement across tests and packaging. Rollback uses reviewed
Git commits; no data migration or dual runtime is necessary.

Final retirement checklist:

- [x] Old `lib/Tools/People/` and `tests/Unit/Tools/People/` directories are absent.
- [x] No retired namespace, limit alias, forwarding class, duplicate publisher,
  bootstrap loader or classmap entry remains active.
- [x] All 17 original methods plus added Phase 0 cases are discovered from final
  owners; global builders and the read-only contract point to the final module.
- [x] Frozen outputs, real composition, full tests and production archive pass;
  the execution report records review results and remaining integration gates.

After completion, Logs is the next feature to analyze. Administration, shared MCP
registry/policy relocation, developer commands, static analysis and README/docs i18n
remain separate work. The completed People migration does not change their ownership. English docs stay canonical; add no placeholder translations.
