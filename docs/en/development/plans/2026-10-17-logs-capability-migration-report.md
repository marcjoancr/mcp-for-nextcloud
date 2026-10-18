<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Logs capability migration execution report

Execution: 2026-10-17. Implementation baseline: reviewed plan commit `57bec7b`;
production matches planning baseline `0487560`. Scope: `logs_list`, `logs_analyze`
and their direct composition/test consumers. No dependency, database, settings or
public API change. The validated `docs/talk-capability-plan` worktree/branch was
reused. No push, PR, deployment or actual server log read was performed.

## Ordered phase evidence

Every phase passed its full gate before its commit and the next phase. Phase 0
characterized unchanged production; immutable fixtures were captured before any
production extraction. Phases 1–3 first ran tests targeting missing final owners
and observed failures, then migrated the owners and passed the relevant gates.
Phase 4 adds composition and boundary coverage for the completed implementation.

| Phase | Focused tests / assertions | Full tests / assertions | Full runtime | Commit |
| --- | ---: | ---: | ---: | --- |
| 0: frozen contracts | 244 / 7,767 | 5,221 / 41,117 | 33.438 s | `ee5ff69` |
| 1: helper ownership | 244 / 7,767 | 5,221 / 41,117 | 30.876 s | `3cd80a8` |
| 2: bounded capabilities | 248 / 7,807 | 5,225 / 41,217 | 31.957 s | `0544268` |
| 3: final publication and retirement | 254 / 7,840 | 5,231 / 41,292 | 32.160 s | `f5932dd` |
| 4: composition, boundaries and package | 257 / 9,340 | **5,234 / 42,798** | 34.037 s | Closing migration commit |

Runtime: PHP 8.4.26 / PHPUnit 10.5.65. Phases 0–3 used the catalog full-test task;
phase 4 used `devbox run check --cwd "$TASK_REPO" --json`, including production
package validation. Optimized Composer autoload was rebuilt after moves. Existing
PSR-4 notices concern explicitly loaded external test stubs; those are absent from
the production archive. The focused selection follows the plan unchanged.

| Final additional check | Tests / assertions | Runtime |
| --- | ---: | ---: |
| Isolated final Logs feature | 99 / 737 | 0.288 s |
| Architecture | 30 / 10,406 | 0.536 s |
| Nextcloud API contracts | 2,129 / 4,126 | 15.790 s |
| New registration and Logs boundary tests | 3 / 1,500 | 0.239 s |

The native API suite retains its original count without changing API fixtures or
the scanner. Frozen compatibility files remain byte-identical to phase 0.

## Final ownership

```text
mcp/lib/Modules/Logs/
  LogsModule.php
  LogsToolCatalog.php
  Tools/
    ListLogs.php
    AnalyzeLogs.php
    LogInputSchema.php
  Listing/ListEntries.php
  Analysis/
    AnalyzeEntries.php
    LogAnalyzer.php
  Reading/
    LogQuery.php
    LogWindow.php
    LogReader.php
    LogScanner.php
    LogFilter.php
    LogTime.php
  Privacy/LogRedactor.php
  Audit/LogAudit.php
  Presentation/LogEnvelope.php
```

These are the final 17 production types. `lib/Tools/Logs/` and
`tests/Unit/Tools/Logs/` are absent, with no alias, forwarder, duplicate publisher,
compatibility constant or loader. Tests mirror capability ownership under
`tests/Unit/Modules/Logs`; FakeFileLog lives in `tests/Support/Logs`.

Application is the only outside production importer of Logs implementation classes.
Shared `Service/LogsAccess`, `LogFileNames`, `VisibilityGuard`, `GrantPolicy` and
existing DB/controller/settings owners remain intentional shared dependencies.
The shared `Tools` framework remains in place; it is not a Logs transition folder.

## Preserved contracts and privacy

Both tools remain app-less, read-only and ninth/last in module order. Names, order,
descriptions, schemas, raw metadata key order, presentation, six guide notes and
translated results are frozen. No write/preview/confirmation contract was added.

The module publishes statically and resolves tool, role and audit factories
independently. Discovery can check roles without constructing the query or auditor.
Registry calls still check role before the read grant, denied by default even for
administrators. Denied/schema-invalid calls audit without constructing tools or
reading logs. Audit argument sanitization moved to LogAudit, with identical limits
and event/logger order. Failed success auditing withholds data; original refusal
and safe read-error behavior remains, including contingency logger failures.

LogQuery validates in the original order and makes one bounded scanner call. The
scanner uses only native `ILogFactory::get('file')` / `IFileBased::getEntries`;
exhausted budgets perform no read. LogWindow keeps raw entries internal, separate
from public filter/scan metadata. Listing redacts before the envelope; analysis
uses the same window and a second fresh time-settings lookup after scanning.
Source log-level metadata is still read after projection. No settings, result,
role or grant cache was added.

Hidden-message policy remains whole-message hiding, including exception messages,
user agents, URL routes and summaries; contains is refused. Tests cover state
changes on the same wired instance, malformed/out-of-order input, exact budgets,
native RuntimeException/Error failures and safe error translations. Invalid UTF-8
in a direct filter is substituted in encoded text without normalizing the original
structured filter. Neither result representation was silently changed.

## Fixture provenance and discovery reconciliation

`tests/Support/Logs/Compatibility` was captured at `57bec7b` with the existing
synthetic log fixture and deterministic doubles. It stores raw/published definitions,
full guide output, module order by short name, visible/hidden list/analysis output,
filtered/empty/paged/error results in English, Brazilian Portuguese and Spanish,
and ordered native audit events/logger records. No field normalization or fixture
regeneration was used after capture. Multilingual fixtures reset Translator state.

All **77 original test methods** remain in their original order across the six moved
Logs test files, including all providers. Expanded XML discovery normalized only
the exact retired test namespaces: **all 5,215 baseline cases remain**, zero were
removed and **19 were added**, yielding **5,234 cases**. Additions are six frozen/
compatibility contracts, four capability tests, six catalog tests, two architecture
tests and one real Application registration test.

## Production-only package proof

The actual built `build/mcp-1.0.8.tar.gz` was extracted under ignored
`build/logs-production-probe`. A fresh PHP process used only its production
autoloader and minimal host App/IBootstrap declarations. All 17 Logs types loaded;
their bytes and Application matched the worktree. Raw/published definitions, full
guide output and module order matched the pre-extraction fixture. All tool/role/
audit factories throw if resolved during static publication, and none resolved.
The archive has no retired Logs tree or tests; retired/test/PHPUnit classes do not
resolve and every classmap path points inside the extracted archive. No development
vendor or test bootstrap was used.

Actual Application closures were independently exercised through the real registry
and GrantPolicy with host doubles: default grant/role refusals, invalid arguments,
both authorized reads, same-instance privacy/backend changes and audit failure.
These checks prove local composition; the package probe does not emulate a host.

## Decisions and final review

- Ruling: execution scratch stayed outside the repository under server guidance.
  This report and committed fixtures retain the evidence; raw diagnostic logs are
  temporary and removed after completion. Cost: the raw command logs are not retained.
- No production behavior deviation or extra migration scope was introduced.
- Self-review passed: final ownership, lazy hooks, unchanged algorithms, native API
  use, immutable captures, original discovery and production-only packaging agree.
- The single independent review found no actionable Critical, Important or Minor
  findings. Verdict: ready to merge this migration. It separately passed 15 tests /
  1,606 assertions covering compatibility, catalog, boundaries and real registration,
  checked the full-range diff, and reviewed the successful package probe. No fix
  pass was needed and no minor issue was deferred.
- Final ruling on the reviewer's first declined item: PHP 8.2–8.5 and real Nextcloud
  32–35 behavior remain external release gates, because this environment proves only
  PHP 8.4.26 and synthetic native contracts. Local migration completion stands;
  release validation must cover the supported hosts. Cost if omitted: runtime or
  native integration differences can escape the local suite.
- Final ruling on the second declined item: physical I/O cost, rotation/concurrency
  and actual audit delivery remain host checks. The implementation preserves native
  delegation and requested-entry budgets; it does not claim byte/time bounds or
  delivery guarantees. Cost if omitted: slow reads or host-specific audit behavior
  could remain undetected. No other behavior was declined.
- Documentation validation resolved all 84 local links across the six changed
  documents; `git diff --check` passed. All nine retired feature source/test trees
  are absent and final catalogs publish 74 feature tools.

## Remaining work

All **74 feature tools across nine modules** now use capabilities, excluding the
shared guide tool. No feature source/test transition folder remains. Shared MCP
registry/policy placement and administration should be scoped separately next,
followed by developer commands/static analysis and README/docs navigation/i18n.
English remains canonical; translated links should point only to existing pages.
This execution does not migrate shared services or administrative scripts.

The current tree still splits MCP contracts between `lib/Mcp/Tools` and the shared
`lib/Tools` registry, validation, guide, results and confirmation infrastructure.
`lib/Tools/Common` contains shared file access/lock policy, while `lib/Service`
contains grants, visibility and resource/protocol services. Analyze dependency and
policy boundaries before choosing their final owners; do not simply rename these
folders into a new miscellaneous bucket. The administration entry points remain
`js/admin-grants.js`, `admin-picker.js`, `admin-tabs.js` and `connections.js`.
Composer currently exposes no developer script aliases or static analyzer. These
are concrete continuation targets, each requiring its own scope and acceptance
criteria rather than automatic implementation in this migration.

Before release, run PHP 8.2–8.5 CI and disposable Nextcloud 32–35 tests using synthetic
entries: Basic/Bearer identity, admin/allowed/non-member roles, default grants and
revocation, privacy toggles, file/non-file backends, custom date format/timezone,
admin_audit enabled/disabled, audit failure and core getEntries budget boundaries.
FakeFileLog models stable33; unit checks bound requested entries, not physical bytes,
latency, rotation, concurrency or audit delivery. Local migration completion does
not replace those external gates. No live-host evidence is claimed here.
