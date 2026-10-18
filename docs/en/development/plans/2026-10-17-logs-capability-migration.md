<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Logs capability migration plan

> **Execution record:** The user authorized implementation with
> `superpowers:executing-plans`. Phases were executed and validated in order.
> See the [execution report](2026-10-17-logs-capability-migration-report.md).

**Status:** Implemented and independently reviewed locally. External release gates remain.
**Review:** Checked against production, direct/indirect consumers and test discovery
at `0487560`; execution started from reviewed plan commit `57bec7b`.
**Date:** 2026-10-17.
**Baseline:** `0487560`, after the completed People migration.
**Goal:** Migrate `logs_list` and `logs_analyze` by capability, preserving restricted
access, mandatory audit, redaction and bounded native reads, and retire all old Logs
source/test owners without compatibility code.
**Architecture:** Thin tools and an explicit catalog behind a lazy RestrictedModule;
listing and analysis share a bounded query pipeline. Retain the focused native
reader, scanner, filter, time parser, analyzer and redactor instead of replacing
working algorithms or adding a generic logging framework.
**Tech stack:** PHP >=8.2, Composer PSR-4 `OCA\Mcp`, PHPUnit 10.5, native app `mcp`,
declared Nextcloud 32–35 support. No new dependency, database or public API change.
**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Files](2026-10-16-files-capability-migration-report.md),
[Deck](2026-10-16-deck-capability-migration-report.md),
[Talk](2026-10-16-talk-capability-migration-report.md),
[DAV](2026-10-16-calendar-dav-capability-migration-report.md),
[Notes](2026-10-16-notes-capability-migration-report.md),
[People](2026-10-16-people-capability-migration-report.md), and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Code, comments, PHPDoc and technical documentation stay in English. Preserve
  translated user-facing messages and meaningful multilingual test inputs.
- Preserve both tool names, order (list then analyze), descriptions, schemas, titles,
  annotations, guide notes, result envelopes and raw metadata key order. Logs stays
  ninth/last in Application's module list, app-less, module `logs`, operation `read`.
- `logs.read` stays denied by default, including for administrators. RestrictedModule
  role eligibility precedes the grant; ToolRegistry retains validation and audit
  outcome ownership. No write/preview/confirmation interface, resource, impersonation
  or new public feature contract is introduced.
- Read only through `ILogFactory::get('file')` and `IFileBased::getEntries(limit, offset)`.
  Never open paths directly, invoke shell/occ, inspect rotated files, or read actual
  server logs for migration diagnostics. Use existing synthetic fixtures and doubles.
- Preserve filtering, redaction, grouping, time parsing, paging, error boundaries and
  audit ordering. No semantic fixes or additional caching of settings, grants, roles
  or results.
  Record independently discovered defects separately rather than fixing them silently.
- No container or global locator in Logs. Static definitions/guide do not resolve
  native reading, role or audit dependencies. Registry discovery may resolve the
  role service because it must check visibility; it must not read or audit the log.
- Reuse the validated worktree/branch. Keep harness scratch external, generated build
  artifacts under ignored `mcp/build`, and unrelated changes intact. No branch rename,
  push, PR, deployment, administration refactor or other migration is included.

## Review focus

1. Role/grant/privacy changes between calls on one module instance: no stale access
   or hidden-message state beyond the existing service lifecycle, and refusal before
   native reading (Phases 0, 2, 3).
2. Audit dispatcher or logger fails after a read, denial or invalid request: successful
   data must be withheld; original refusals and safe read failures remain (0, 2, 3).
3. Hidden text leaks through contains, URLs, exception chains, user agents or summaries:
   preserve the existing whole-message policy and both result representations (0–3).
4. Growing/out-of-order logs, malformed rows, exact budget boundaries and changing
   time settings alter scan/projection: retain one native read and existing ordering,
   including zero reads at the exhausted budget (0–2).
5. Lazy publication loses RestrictedModule, a reflective builder or packaging retains
   an old owner: prove real registration, all global contracts, full test discovery
   and a production-only archive without old routes (3, 4).

## 1. Findings and scope

`mcp/lib/Tools/Logs` contains nine production files (1,132 lines at the baseline).
`LogsModule.php` is 226 lines and combines metadata, guide, dispatch, role delegation,
audit argument sanitization, validation, query coordination and response assembly.
The other eight files already have focused responsibilities. LogRedactor's 310 lines
implement a single security policy; splitting its regexes into miscellaneous helpers
would obscure that policy. Keep it intact in this migration.

The six dedicated test files contain **77 original methods**: LogsModuleTest 20,
LogScannerTest 13, LogFilterTest 8, LogAnalyzerTest 9, LogRedactorTest 20 and
LogFileNamesTest 7. Providers expand some of these into multiple cases. FakeFileLog
is the seventh file, a shared double rather than a test. Preserve every method and
provider identity; a lower count must never be explained merely as a namespace move.

All paths below are relative to `mcp/` unless stated otherwise.

| Consumer | Treatment |
| --- | --- |
| `lib/AppInfo/Application.php` | Replace the module entry in place; register explicit module/tool factories with independent role/audit closures. |
| `tests/Unit/Contract/{ToolsListContractTest,WriteGateContractTest,VisibilityContractTest}.php` | All three reflectively build Application modules; add explicit final Logs construction, preserving each fixture's role behavior. |
| `tests/Unit/Tools/WritePlanContractTest.php` | Retarget Logs in READ_ONLY; do not remove its exemption or assertions. |
| `tests/Unit/ApplicationRegistrationTest.php` | Exercise actual factories, discovery, grant refusal, authorized execution and failing audit. |
| `tests/Unit/GrantPolicyTest.php`, `Service/LogsAccessTest.php`, `Db/LogsGroupsMapperTest.php`, `Admin/AdminLogsBlockTest.php`, `Controller/LogsPrivacyControllerTest.php` | Preserve adjacent grant, role, database concurrency, admin visibility and privacy behavior; no relocation required. |
| `tests/Unit/Contract/NextcloudApi/` | Recursive scanner must still discover ILogFactory, IFileBased, IEventDispatcher and CriticalActionPerformedEvent usage; preserve native contract cases. |
| `tests/Unit/McpControllerTest.php`, presentation/guide/language contracts | Preserve authenticated identity and translation/error publication; run alongside Logs. |
| `tests/Support/People/Compatibility/definitions.json` | Contains the short name `LogsModule` in module order, not a retired namespace. Keep this immutable fixture unchanged. |

Keep `Service/LogsAccess`, `Service/LogFileNames`, `Service/VisibilityGuard`,
`Service/GrantPolicy`, `Db/LogsGroupsMapper`, `Service/LogsGroupsConflict`, controllers,
settings, routes and migrations in their current ownership. They serve shared policy
or administration, and are not temporary facades. Their database CAS, legacy setting
import, lock and configuration semantics remain untouched. VisibilityGuard already
caches hidden tags: preserve its setters/invalidation lifecycle rather than requiring
every query to bypass that cache or silently changing shared behavior. Logs may
consume these existing services but must not import other feature internals.

No production consumer outside Application imports the current Logs implementation.
There is no reason to preserve its PHP namespaces as a public compatibility API.
The shared `lib/Tools/` framework remains legitimate after the old Logs subdirectory
is removed; completing Logs does not authorize relocating that entire framework.

## 2. Final structure and interfaces

```text
lib/Modules/Logs/
  LogsModule.php
  LogsToolCatalog.php
  Tools/{ListLogs,AnalyzeLogs,LogInputSchema}.php
  Listing/ListEntries.php
  Analysis/{AnalyzeEntries,LogAnalyzer}.php
  Reading/{LogQuery,LogWindow,LogReader,LogScanner,LogFilter,LogTime}.php
  Privacy/LogRedactor.php
  Audit/LogAudit.php
  Presentation/LogEnvelope.php

tests/Unit/Modules/Logs/
  LogsModuleTest.php
  Reading/{LogScannerTest,LogFilterTest,LogQueryTest}.php
  Listing/ListEntriesTest.php
  Analysis/{LogAnalyzerTest,AnalyzeEntriesTest}.php
  Privacy/{LogRedactorTest,LogFileNamesTest}.php
  Audit/LogAuditTest.php
tests/Support/Logs/
  FakeFileLog.php
  Compatibility/{definitions.json,results.json,audit.json,README.md}
tests/Unit/Contract/{LogsMigrationCompatibilityTest,LogsCatalogTest}.php
tests/Architecture/LogsBoundaryTest.php
```

This is 17 production classes, eight more than today: two tool adapters, a catalog,
a shared input schema, two operations, a query coordinator and a small window value.
No generic Contracts, Infrastructure, Utils or Execution directory is needed.
Existing `tests/fixtures/nextcloud-log/sample.jsonl` remains in place.

### Existing owners moved intact

| Old file in `lib/Tools/Logs/` | Final relative path in `Modules/Logs/` | Preserved interface |
| --- | --- | --- |
| LogReader | Reading/LogReader | `__construct(ILogFactory)`; `entries(int $limit, int $offset): array` |
| LogScanner | Reading/LogScanner | `__construct(LogReader)`; `scan(LogFilter, int $offset, ?int $limit): array` |
| LogFilter | Reading/LogFilter | `fromArguments(array, LogTime): self`, `isEmpty(): bool`, `describe(): array`, `hasDates(): bool`, `test(stdClass): string` |
| LogTime | Reading/LogTime | `__construct(string $format, string $zone)`; `parse(mixed): ?DateTimeImmutable` |
| LogAnalyzer | Analysis/LogAnalyzer | `__construct(LogTime, bool $hideMessages = false)`; `analyze(array): array`, static `signature(string): string` |
| LogRedactor | Privacy/LogRedactor | Existing static entry/text/ip/url/exception/shownUrl APIs and all constants |
| LogAudit | Audit/LogAudit | Native event/log recording remains; add call sanitization in Phase 2 as specified below. |
| LogEnvelope | Presentation/LogEnvelope | Move unchanged in Phase 1; replace the old static result API in Phase 2 as specified below. |

Do not leave aliases for constants or classes in their old locations. Scanner,
filter, analyzer and redactor constants retain their names/values under final owners.

### New orchestration and publication

- **LogQuery:** `__construct(LogScanner $scanner, IConfig $config, LogFileNames
  $fileNames)`; `read(array $arguments, bool $paged): LogWindow`; `time(): LogTime`.
  Own `DEFAULT_LIMIT=50`, `MAX_LIMIT=100`. Preserve the original order: read current
  hiding state, refuse `isset(contains)` when hidden, construct filter with time(),
  cast offset and list limit, validate limit then offset, scan once. Analysis ignores
  a direct-call limit exactly as today; its public schema still rejects that key.
  time() preserves empty-format fallback to ATOM, timezone default UTC and LogTime's
  invalid-zone fallback. Never retain a settings snapshot between calls.
- **LogWindow:** `__construct(LogFilter $filter, array $scan, bool $messagesHidden)`;
  private readonly filter/scan fields, with `entries(): array`, `payload(): array`
  and public readonly bool `messagesHidden`. The first returns scan entries internally; payload returns only
  `filters` followed by the exact public `scan` projection. It cannot expose raw
  entries as public MCP data. Retain the existing scanner array shape; do not invent
  another scanner/result hierarchy or an extra read to fill this value.
- **ListEntries:** `__construct(LogQuery $query, LogEnvelope $envelope)`;
  `list(array $arguments): array`. Read with paged=true, redact entries with the
  window's hiding flag, append `count` then `entries` and call the envelope once.
- **AnalyzeEntries:** same constructor; `analyze(array $arguments): array`. Read
  with paged=false, create `LogAnalyzer($query->time(), $window->messagesHidden)`,
  append `summary` and call the envelope once. Keep this second time() call after
  scanning, as in the original; do not hoist analysis settings into a cached service.
  Both operations return the complete MCP result, not raw native entries, and do
  not enforce grants or audit independently of the registry.
- **LogEnvelope:** `__construct(IConfig $config)`;
  `result(string $tool, LogWindow $window, array $data): array`. Own NOTICE,
  SOURCE_FILE, the moved HIDDEN_NOTE and DEFAULT_LOG_LEVEL=2. Build source after
  projection, reading current loglevel; combine window.payload() with operation data
  and preserve the warning, exact JSON flags and ToolResult::structured call.
  Remove the former static result signature once callers use the instance method.
- **LogAudit:** keep `__construct(IEventDispatcher, LoggerInterface)` and READ/REFUSED;
  add `recordCall(string $tool, string $userId, string $outcome, array $arguments): void`.
  Move AUDITED_ARGUMENTS and the module's sanitization loop here; delegate to the
  existing recording body, which becomes private `record(string $userId, string
  $tool, string $outcome, array $filters): void`. Keep its user-first argument order,
  distinct from recordCall. Do not catch native
  audit failures here: the registry owns the fail-closed response policy.
- **LogInputSchema:** `static forList(): array`, `static forAnalysis(): array`, sharing
  a private schema(bool $paged) implementation moved from LogsModule. Reference final
  constant owners. Metadata must not construct any service or query.
- **ListLogs / AnalyzeLogs implement Tool:** constructor injects the corresponding
  operation; `static definition(): ToolDefinition`; `execute(array $arguments,
  ToolContext $context): array` delegates and returns its result unchanged. No second
  JSON envelope, fake write plan or user impersonation. Identity is used by the
  registry's role/grant/audit path, not to filter the server-wide log by user.
- **LogsToolCatalog:** `static classes(): array` returns ListLogs then AnalyzeLogs;
  `static definitions(?array $classes = null): array` returns an ordered name-keyed
  map. Reject non-Tool entries, duplicates and non-read operations. Project raw keys
  in original order: name, description, module, operation, inputSchema; omit app.
- **LogsModule implements ToolModule, ToolGuideNotes, RestrictedModule:**
  `__construct(array $factories, Closure $accessFactory, Closure $auditFactory)`.
  Validate exact tool-map keys and closures without invoking them. `definitions()`
  and all six `guideNotes()` remain static publication; `permits(string): bool`
  resolves a LogsAccess and delegates, `audit(string,string,string,array): void`
  resolves a LogAudit and calls recordCall. Reject incorrect factory result types.
  `call(string,array,string): array` rejects unknown names before resolving a tool,
  verifies its Tool type/name and executes with ToolContext. No query, redaction,
  source assembly, schema or audit-filter helpers/constants remain in the module.

Application owns closure composition for the module and both tools; query, operations,
audit and envelope use typed autowiring. Role/audit factories are independent of tool
factories: denied/schema-invalid requests can be audited without creating a reader.
Do not cache a role decision, catch permits failures differently, or move auditing
into tool execution. Direct module calls retain their existing lower-level behavior;
public calls always enter through ToolRegistry.

## 3. Compatibility invariants

- **Access/audit:** preserve admin-or-allowed-group eligibility, non-file backend
  exclusion (`errorlog`, `syslog`, `systemd`), unknown backend fallback and grant
  denial by default. Tool listing checks role/grant but does not audit. Recognized
  calls retain denied, invalid, read_error and success outcomes, including empty
  success. A normal false role result audits denial; an exception from permits still
  escapes before the registry audit branches, and an unknown tool has no owning
  module to audit. Pin these existing boundaries rather than broadening catches.
- **Audit payload:** retain known-key order min_level, app, user, since, until,
  contains, req_id, offset, limit; keep integers, bound strings with text(...,200),
  replace string contains with `[hidden]`, omit other types/unknown keys and bound
  tool text to 64. Do not silently change raw invalid-input coercion or which defaults
  are present before/after schema validation. Event dispatch precedes app logging;
  denial logs warning, other outcomes info. Audit exceptions after success suppress
  all data; failed audits on refusals retain their original response.
- **Read budget:** SCAN_LIMIT=5000, READ_BUDGET=MAX_OFFSET=20000. No-filter list reads
  at most its page; filtered/analysis calls read at most the remaining window. Exactly
  one getEntries call when the window is positive, none at offset=20000. Preserve
  nextOffset, scanned, truncated, budgetReached, oldestScanned, malformed and unparsed,
  including a full page ending inside a fetched window and short/exhausted files.
  The budget counts returned core entries, not bytes, lines or a latency guarantee.
- **Filters/time:** min_level 0..4, contains 1..200 multibyte characters and literal
  case-insensitive matching. Keep exact app/user/reqId comparisons, ISO date parsing,
  since<=until, inclusive bounds and unparsed-time accounting. Out-of-order old
  entries never end a scan. Preserve direct-call coercion and schema differences.
- **Privacy:** hiding is checked afresh per query using LogFileNames. Preserve whole
  hidden messages, exception messages/user agents, fixed route prefixes and known
  methods; hidden summaries group by level/app/exception class and omit topUserAgents.
  Refuse contains before a read rather than returning a count oracle. Keep IP masking,
  ANSI/invisible stripping, UTF-8 substitution, PROCESSING_LIMIT=16384,
  MESSAGE_LIMIT=2000, TRACE_FRAMES=10 and PREVIOUS_LEVELS=2. Never include stack args.
- **Analysis/output:** retain signature limit 200, top signatures 20, values 10,
  totals 50, tie ordering and existing first/last/file-order semantics. Preserve
  empty arrays/maps and warning placement before text JSON. structuredContent keeps
  the original data array; JSON_INVALID_UTF8_SUBSTITUTE affects text encoding only.
  For a direct call with an invalid-UTF-8 filter, do not normalize the structured
  filter to match the encoded text. Do not replace the result with ToolResult::json.
- **Errors:** keep both literal translated LogReader failures, their narrow factory/
  read catches and all validation messages/precedence. No native exception details,
  paths, search text or log content in new diagnostics. Reset Translator in tests.

## 4. Ordered implementation phases

Each phase ends with its focused checks plus the full catalog test task before its
commit and the next phase. Intermediate reuse of the old module is permitted only
at explicit checkpoints; it is not an acceptable completed migration.

### Phase 0 — Freeze behavior before extraction

**Files:** Create `tests/Unit/Contract/LogsMigrationCompatibilityTest.php` and
`tests/Support/Logs/Compatibility/{definitions.json,results.json,audit.json,README.md}`.
Extend existing Logs tests only for uncovered behavior. No production changes.
**Interfaces:** Immutable baseline publication, results, audits and test inventories
feed every following phase; capture the exact implementation commit in README.

- [x] Capture raw/published definitions, full module guide and nine-module order
  using short class names. Capture list/analyze, visible/hidden, empty/filtered/paged
  results and safe failures in en, pt_BR and es with deterministic synthetic data.
  Capture ordered audit events and app records for success, denied, invalid and read
  failure. Freeze full result shapes, key order and warning/JSON text; no normalizing
  away defaults, privacy markers or error text. Never regenerate to fit a refactor.
- [x] Add `testLogsRoleGrantAndPrivacyChangesAreObservedBetweenCalls`: retain one
  module/registry and mutate role membership, grant and privacy settings independently.
  Use GrantPolicy::setGrant, LogFileNames::setEnabled and
  VisibilityGuard::setHiddenTagIds (or clearCache after synthetic corrupt storage),
  with a mutable group-manager double. Assert visibility/outcomes and no reader calls
  on denial or hidden contains; do not demand raw-store changes bypass existing caches.
- [x] Add `testLogsAuditFailurePreservesEachOutcome`: exercise dispatcher and app
  logger failures independently, plus contingency logger failure; assert successful
  reads lose structuredContent/data, denied/invalid retain their exceptions and safe
  read failures remain. Check audit ordering without requiring rollback of an event
  already dispatched when the subsequent app logger fails.
- [x] Add `testLogsDirectValidationOrderAndFreshTimeSettings`: combine hidden contains
  with invalid dates/limit/offset to freeze precedence, change format/timezone/loglevel
  between calls, and preserve the second analysis time lookup. Pin direct coercion
  versus registry validation, analysis limit rejection and boundary offsets 0/20000/20001.
  Include hidden direct contains=null (unset by isset), contains='' (hidden-text
  refusal before filter validation) and list limit='2' coercion versus schema rejection.
- [x] Add `testLogsNativeFailuresAndRoleExceptionsKeepTheirBoundary`: factory.get
  and writer.getEntries each throw RuntimeException and Error containing synthetic
  paths; preserve the same fixed reader failure and read_error audit. A non-file
  writer keeps the other fixed failure. Separately make permits throw: the original
  exception escapes and neither reading nor audit occurs. Unknown tool calls also
  read/audit nothing. These characterize existing boundaries, not new policy.
- [x] Add `testLogsTextEncodingDoesNotNormalizeStructuredFilters`: a direct call with
  app containing the byte 0xFF retains that byte in structuredContent.filters.app,
  while the text JSON decodes it as U+FFFD. Assert these bytes directly in PHP; JSON
  capture files cannot preserve this invalid-UTF-8 input unchanged.
- [x] Map existing redactor/analyzer/scanner cases to all remaining invariants, including
  malformed rows, growing/out-of-order logs, hostile labels, IPv6 and hidden URL routes.
  Add only uncovered assertions. No live logs or nondeterministic growth IDs in captures.
- [x] Record all 77 original methods and expanded full-suite identities; run Section 5
  focused selection and `devbox run test --cwd "$TASK_REPO" --json`.
- [x] Commit `test(logs): freeze publication privacy and audit contracts`.

**Acceptance:** Frozen fixtures and new behavior tests pass against the unchanged
original production implementation. New laziness is tested later, not misrepresented
as original behavior. Every review-focus item has a concrete test owner.

### Phase 1 — Relocate existing focused components

**Files:** Move the eight helpers using Section 2's mapping. Move five helper test
files to their final Reading/Analysis/Privacy locations and FakeFileLog to
`tests/Support/Logs/FakeFileLog.php`. Retarget the old LogsModule, its remaining test
and Phase 0 tests to these final helpers. Keep the existing envelope/audit APIs here.
**Interfaces:** Same helper APIs and constants, final namespaces; the old module
remains the only publisher and still has its existing constructor.

- [x] Move each file once, update namespace/imports and the fake's explicit consumers;
  preserve every test/provider identity and assertion. Translate non-English assertion
  diagnostics/comments only, never fixture payloads. Resolve fixture paths from their
  final depth; do not add old-path require files or copy fixtures. Update cross-folder
  imports too: Scanner uses Privacy\LogRedactor, Analyzer uses Reading\LogTime and
  Privacy\LogRedactor, and Redactor uses Analysis\LogAnalyzer for level names.
- [x] Verify the five moved suites plus old LogsModuleTest in isolation, then frozen
  contracts, Section 5 focused selection and the full test task. Composer's optimized
  loader must resolve final helpers without old files or class_alias.
- [x] Commit `refactor(logs): relocate reading analysis and privacy components`.

**Acceptance:** One copy of every helper; all original helper tests pass from final
owners. Only the old module/test remain temporarily, with no old helper imports.

### Phase 2 — Extract listing, analysis, query and response ownership

**Files:** Create LogQuery, LogWindow, ListEntries, AnalyzeEntries and their three
operation/query test files in Section 2. Modify final LogEnvelope/LogAudit; add
Audit/LogAuditTest. Modify old LogsModule and constructors in its test, Phase 0
contracts and any reflective builder requiring adaptation at this phase.
**Interfaces:** Final query/window/operation, envelope and audit APIs from Section 2;
the intermediate module delegates to these owners while retaining publication.

- [x] Write `LogQueryTest::testValidationPrecedesOneBoundedRead`,
  `ListEntriesTest::testListUsesTheWindowPrivacyState`,
  `AnalyzeEntriesTest::testAnalysisUsesFreshTimeAndTheSameWindow`, and
  `LogAuditTest::testRawArgumentsAreBoundedBeforeNativeRecording`. Assert the specified
  ordering, budget, flags, envelope and audit shape; first run must fail for missing
  capabilities or the new recordCall method, then implement the smallest extraction
  that makes them pass.
- [x] Move validation/time and list limits to LogQuery, common filter/scan projection
  to LogWindow, listing and analysis orchestration to their operations, source/hidden
  note assembly to LogEnvelope, and audit sanitization to LogAudit. Change the old
  constructor to `(LogsAccess, ListEntries, AnalyzeEntries, LogAudit)` and delegate.
  Delete extracted implementations/constants immediately; metadata refers to final
  constants, and no old-module limit aliases or duplicate result builders remain.
- [x] Retarget test constructors; global recursive typed builders must still work.
  Run new operation/query/audit tests, isolated current Logs tests, frozen contracts,
  Section 5 focused selection and full test task. Keep capture files byte-identical.
- [x] Commit `refactor(logs): extract bounded listing and analysis capabilities`.

**Acceptance:** The original module has no reading/projection/audit sanitization
logic. Exactly one implementation exists for each behavior, and all baseline results,
privacy/refusal paths and scanner call counts remain unchanged.

### Phase 3 — Publish final tools and retire the old module

**Files:** Create the final module, catalog, two tools and LogInputSchema; add
`tests/Unit/Contract/LogsCatalogTest.php`. Modify Application, all three global
builders, WritePlanContractTest and frozen-contract constructors. Move the final
LogsModuleTest to `tests/Unit/Modules/Logs/LogsModuleTest.php` and retarget its namespace.
**Interfaces:** Static Tool definitions, exact factory maps and independent role/audit
closures; public requests still use the unchanged ToolRegistry/RestrictedModule APIs.

- [x] Add catalog/module tests for exact names/order/read-only metadata, duplicate,
  non-Tool/non-read entries, missing/extra/non-closure factories, wrong Tool/name and
  wrong role/audit factory types. Definitions/guide use throwing factories without
  resolving them; unknown direct calls reject before any factory. Run red first.
- [x] Implement final publication and move schemas once. Preserve RestrictedModule
  and all six guide notes; no WriteTool, PreviewsWrites or RendersPlans implementation.
  Add Application factories and retarget its ninth module entry without reordering.
- [x] Update all three reflective builders explicitly, including closure-based role
  and audit construction. Preserve ToolsListContractTest's permissive role fixture:
  its current LogsAccess override applies only when resolving a constructor parameter,
  so calling build(LogsAccess::class) directly from a closure would bypass it. Create
  a dedicated permits=true double for that closure; keep the publication assertions.
  Retarget READ_ONLY, test imports and constructors without weakening coverage.
- [x] Delete `lib/Tools/Logs/` and `tests/Unit/Tools/Logs/` entirely, including the old
  fake's namespace. Search active consumers/loaders/string references and remove
  every old route; retain historical docs and immutable short-name fixtures.
- [x] Run isolated final Logs tests, frozen/catalog contracts, focused selection and
  full test task. Verify 77 original methods and providers still run.
- [x] Commit `refactor(logs): publish capability tools and retire legacy owners`.

**Acceptance:** Only the final module publishes both tools. Denied/schema-invalid
calls can audit without building the read pipeline; a permitted call reaches exactly
one query pipeline. No transitional module, constant alias or test loader remains.

### Phase 4 — Prove composition, boundaries and production packaging

**Files:** Extend `tests/Unit/ApplicationRegistrationTest.php`; create
`tests/Architecture/LogsBoundaryTest.php`. Update this plan, both docs indexes,
architecture specification, adding-a-tool guide and a Logs execution report.
**Interfaces:** Final implementation is private to Logs except Application composition
and existing shared contracts. Packaging must work without development autoloaders.

- [x] Exercise real Application closures with explicit host doubles: discovery checks
  role without reader/audit resolution; role and default grant refusal produce exactly
  one denied audit and zero reads; schema invalidity audits without resolving tools;
  both authorized operations read correctly; audit failure withholds successful data.
  Prove settings changes through the Phase 0 setters/invalidation are observed on
  the same wired instance and no optional-app check is introduced. Use real
  GrantPolicy/registry paths, not only direct calls.
- [x] Add negative boundary examples before enforcing the tree: imports, fully qualified
  calls, escaped strings, bare `OC`, qualified/unqualified class_alias and old require/
  autoload paths. Reject containers/global locators, other feature internals and
  production test dependencies; allow existing shared services/contracts explicitly.
  Scan lib/tests/scripts/appinfo/composer/phpunit/CI for retired source/test routes.
  Permit historical documentation and deliberate synthetic negative-test fragments
  only; do not skip entire Logs files or flag public tool names/the short LogsModule
  name. Enforce that Application is the sole outside production importer of Logs
  implementation classes, while existing shared services remain allowed dependencies.
- [x] Reconcile all original methods and expanded full-suite cases after exact namespace
  mapping. Record additions and explain every count change; do not delete providers.
  Run isolated, focused, architecture, Nextcloud API and full package gates below.
- [x] Extract the actual built archive under ignored build storage. In a fresh process
  using only its production autoloader and minimal host declarations, load all 17
  final classes, compare their bytes and Application with the worktree, compare frozen
  raw/published definitions/full guide/module order, and use throwing factories to
  prove static publication resolves nothing. Reject old trees/classes, test classes
  and external classmap paths; do not use development vendor or test bootstrap.
- [x] Self-review, perform the final independent review required by the execution
  workflow, resolve verified blockers and rerun affected gates. Record phase commits,
  fixture provenance, tests, deviations and outstanding external checks in the report.
- [x] Only after verification, mark implemented and update migrated count 72→74 across
  all nine feature modules. Keep shared MCP/admin/devex work separate. Commit
  `refactor(logs): verify boundaries and document completed migration`.

**Acceptance:** No old Logs source/test directory, loader, runtime class string,
forwarder, alias or duplicate publisher exists. Full tests/package and the independent
production-only probe pass; no completion claim substitutes for live-host evidence.

## 5. Verification and external gates

Planning validation on `0487560`, PHP 8.4.26 / PHPUnit 10.5.65:
**238 tests / 7,691 assertions**, passing in 1.291 s. Reproduce with:

```bash
cd "$TASK_REPO/mcp"
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result --filter 'Logs|LogAnalyzerTest|LogFilterTest|LogRedactorTest|LogScannerTest|LogFileNamesTest|GrantPolicyTest|ToolsListContractTest|WriteGateContractTest|VisibilityContractTest|WritePlanContractTest|ApplicationRegistrationTest|ToolPresentationTest|ToolGuideTest|L10nLanguagesTest|ToolErrorsLanguagesTest|McpControllerTest'
```

The selection intentionally includes all three global constructors and uses Logs in
new feature namespaces/test names. The count is planning evidence only; capture an
execution baseline again before production changes. After every namespace/file move,
run `composer dump-autoload --optimize` in `mcp/` before tests so stale optimized
classmap entries cannot mask a missing import or refer to deleted files. In Phases
1–2, isolated tests cover both `tests/Unit/Modules/Logs` and the remaining
`tests/Unit/Tools/Logs/LogsModuleTest.php`; from Phase 3, only the final feature path
remains. At every phase run the focused selection and
`devbox run test --cwd "$TASK_REPO" --json`; inspect exit status and reported test
success before committing or moving on. Final additional gates:

```bash
cd "$TASK_REPO/mcp"
composer dump-autoload --optimize
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Modules/Logs
php -d memory_limit=1G vendor/bin/phpunit tests/Architecture
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Contract/NextcloudApi
devbox run check --cwd "$TASK_REPO" --json
git -C "$TASK_REPO" diff --check
```

Expected: all checks pass, production package validates, original tests and native
API coverage remain. Existing PSR-4 warnings from explicit external test stubs must
not appear as production dependencies. Inspect scanner discovery before changing
Nextcloud API fixtures; the move itself adds no native API use.

Before release, run PHP 8.2–8.5 CI and disposable Nextcloud 32–35 tests with synthetic
log entries: Basic/Bearer users, admin/allowed/non-member roles, grant default and
revocation, privacy toggles, file/non-file backends, custom date format/timezone,
admin_audit on/off, audit failure and actual core getEntries behavior at window/budget
boundaries. FakeFileLog models stable33 and cannot prove every supported host's I/O,
rotation, concurrency or audit delivery behavior. Unit tests prove bounded requested
entries, not bounded physical bytes or wall time. Never use production logs as fixtures.

### Documentation review outcome

The review verified the five-phase dependency chain, all three reflective builders,
complete source/test ownership and preserved role/audit boundaries. It clarified
cross-folder imports, the permissive publication-test role factory, the private audit
argument order, autoload rebuilding and invalid-UTF-8 text/structured behavior.
The focused selection was rerun: **238 tests / 7,691 assertions**, passing in
1.115 s on the same runtime. A read-only baseline probe confirmed that encoded text
substitutes invalid UTF-8 without mutating structured filters. Full-suite and package gates subsequently passed during execution;
live-host gates remain release work. See the execution report.

## 6. Completion and continuation

A complete migration without transition folders is feasible: there are no external
implementation consumers requiring an adapter, database migration or dual runtime.
The intermediate module in Phases 1–2 must disappear in Phase 3. Rollback is through
Git commits; retain no compatibility scaffolding for rollback.

With Logs completed, all 74 feature tools (excluding the shared guide tool) use capability
owners, but the repository-wide migration is not complete. Next scope shared MCP registry/policy placement and
administration separately, then developer commands/static analysis and README/docs
navigation/i18n. The retained shared services are explicit dependencies, not hidden
migration debt. Keep English canonical and add links only to translations that exist.
