<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Administration JavaScript responsibility separation plan

> **For agentic workers:** Implementation requires separate authorization. Once
> authorized, execute the phases in order and pass each gate before advancing.
> Use the selected isolated worktree and branch; do not rename the branch.

**Status:** Reviewed; ready for implementation authorization. Documentation only;
no frontend migration or new tooling has been implemented.
**Date:** 2026-10-17.
**Baseline:** `ce857e504ecb9a895374e1b29858fefc247d1e97`.
**Goal:** Give each administration interaction a clear owner, preserve the personal
connections consumer, and remove all four previous scripts without compatibility
wrappers, duplicate implementations or a new framework.
**Architecture:** Plain JavaScript ES modules, explicit page composition, local
feature state and a narrow Nextcloud/HTTP boundary. Build two self-contained classic
scripts for the existing Nextcloud asset loader.
**Tech stack:** Existing PHP >=8.2 / Nextcloud 32–35 declarations, Composer commands,
plain JavaScript; proposed development-only esbuild and Playwright Test.
**Spec:** [Architecture specification](../architecture-specification.md), G1–G7.
**Lessons:** [Shared MCP report](2026-10-17-shared-mcp-infrastructure-migration-report.md),
[DevEx plan](2026-10-17-developer-commands-static-analysis.md) and
[compatibility validation](2026-10-17-devbox-catalog-validation-report.md): inventory
consumers before moving owners, preserve observable contracts, expose failed gates,
and distinguish local evidence from real-host validation.

## Global constraints

- Preserve endpoints, request shapes, authorization, CSRF handling, translations,
  DOM IDs/classes, tab order, keyboard behavior, confirmations and error feedback.
  PHP remains authoritative for permissions, revocation and privacy. Frontend
  extraction must not recreate server policy or change controller behavior.
- Keep the five tabs and eight section blocks, responsive CSS, server-rendered OCR
  status and personal Connect/Disconnect form. CSS edits are limited to correcting
  comments that name retired scripts. No redesign, CSS reorganization,
  TypeScript conversion, runtime framework, general state store or event bus.
- Code, comments and canonical documentation stay English. Keep literal translation
  keys and interpolation/escaping conventions, including existing Spanish and
  Brazilian Portuguese catalogs. No translated documentation placeholders.
- Do not silence failures, lower existing checks, substitute source-string checks
  for interaction tests, or invent host APIs to obtain green tests. Record any
  pre-existing behavioral defect separately; do not silently change it in this move.
- No `legacy/`, `compat/`, forwarding exports or retained global picker facade at
  completion. Temporary coexistence is bounded by the atomic switch in Phase 4.
- Keep generated output inside the app, caches in standard user locations and test
  servers on `127.0.0.1`. No external harness files in the repository, push,
  publication, deployment or unrelated migrations.

## Review focus

1. A bulk operation fails after some requests, then Undo runs: only acknowledged
   changes are reversible, and a partial undo is reported honestly (Phases 1, 3).
2. Exclusion tags are unreadable or log groups change concurrently: confirmation,
   rollback, conflict resynchronization and fail-closed feedback survive (1, 2).
3. A user follows a deep link, navigates with the keyboard, filters columns or
   returns with browser history: URL, focus, hidden panels and sticky headers agree
   (1, 3, 4).
4. The same connections controller runs on the personal page: it cannot select an
   admin endpoint or expose admin actions; names remain text and errors remain
   visible without Toast (1, 2, 4).
5. A clean checkout or archive lacks a bundle, uses stale output, or loses test
   discovery: build, CI and packaging fail instead of shipping an incomplete UI
   (1, 4, 5).

## 1. Evidence and scope

Paths below are repository-relative. Sizes describe this baseline, not file limits.

| Current owner | Evidence | Consequence |
| --- | --- | --- |
| `mcp/js/admin-grants.js` (1,319 lines) | Matrix reads/writes, filters, permission presentation, bulk/undo state, service switch, endpoint copy, tags, OAuth clients, checkout limit, log access and log privacy. | Main separation target. Moving this body into a new bootstrap would not complete the work. |
| `mcp/js/admin-picker.js` (409) | Accessible multi-select with search, selection, optimistic save/rollback, confirmation, focus and status handling; exports `window.McpAdminPicker`. | Keep a cohesive component, replace the global with an explicit import. Size alone does not require splitting it. |
| `mcp/js/admin-tabs.js` (149) | ARIA tabs, roving focus, hash/history, retained query strings and legacy section anchors. | Keep navigation cohesive and independent of settings requests. |
| `mcp/js/connections.js` (245) | Admin/personal scope, pagination/search, timestamps, revocation and optional overview counter. | One final shared implementation; migrate both templates together. |
| `mcp/templates/admin.php` (267), `personal.php` | Admin loads picker, grants, connections, tabs in that order; personal loads connections. Admin starts with panels visible and the tab bar hidden. | Loader changes and no-JavaScript behavior are part of acceptance. |
| `mcp/css/admin.css` (750) | Shared administration/personal styles, matrix layers, picker and tabs; comments name the old tab/picker scripts. | Preserve declarations and selectors; update those comments during retirement. CSS separation is outside this plan. |
| `mcp/tests/Unit/Admin/AdminAssetsTest.php` | Many checks inspect fixed filenames/function text; script discovery uses only `js/*.js`. | Retain structural contracts, but replace behavior assertions with executed browser tests and cover the entire new source graph. |
| `mcp/tests/Unit/Mcp/Prompts/PromptCatalogTest.php` | Reads `js/admin-grants.js` to check the Files create permission description. | Migrate this consumer too; preserve the translated description. |
| `mcp/scripts/package.sh`, `check_package.php` | Copy `js/` recursively; validate literal PHP asset registrations. No JavaScript build or npm manifest exists. | Introduce an explicit build and verify exact final assets and development-file exclusion. |
| Root `.gitignore` | The broad `logs/` rule matches proposed `mcp/src/admin/logs/` and nested JavaScript log tests. | Add exact source/test exceptions before extraction; do not force-add otherwise ignored source or weaken runtime-log exclusions. |

The existing suite covers controllers, access policy, templates, packaging and
commands. It does not currently execute these UI interactions in a browser.
The initial planning run used `composer --working-dir=mcp test -- tests/Unit/Admin`:
**PHP 8.4.26, 68 tests, 636 assertions, passed**. The wider results in the linked
compatibility report and that planning run are historical evidence, not tests
rerun for this documentation review. Local Node
is **20.19.2**; no new dependency, browser or build tool was installed for planning.

### Loading decision and tradeoff

Use ES modules as the authored source and esbuild to emit two classic IIFE bundles,
`mcp/js/admin.js` and `mcp/js/personal.js`. Continue to register them with
`OCP\Util::addScript`; the inspected OCP API accepts an app/file and ordering options,
not a module-type argument. The two bundles have no imports, lazy chunks, CDN
runtime or globals exported by this application. Shared source may be included in
both bundles; it has one authored owner and the pages load only their own entry.

This adds a small development build dependency but avoids a global module registry,
script ordering between features, and unverified native-module loading/cache
behavior across host versions. Direct browser ES modules would need separate host
and cache validation; a framework would add a much larger change without solving
an identified need. esbuild's [bundling and output formats](https://esbuild.github.io/api/#format)
support the proposed output. Keep Node tooling out of the installed app.

Generated bundles are **not tracked or hand-edited**. The build uses an explicit
entry/output allowlist. It invalidates both final outputs before a build, writes
new output to an app-local staging directory and exposes the pair only after
both are successful. A failed build leaves neither a usable stale pair nor a
partial pair. Never wipe arbitrary files in `js/`. Production builds retain attribution, avoid
source maps and include no dependencies other than the app modules. Source maps,
if useful locally, go under ignored `mcp/build/`, outside the distributable.
A clean source checkout must build assets before serving settings or testing
asset existence. A release archive already contains both bundles and needs no Node.

## 2. Final ownership and dependency direction

```text
mcp/
  src/
    entries/
      admin.js                       # Root guard, host setup, mount admin page
      personal.js                    # Root guard, mount personal connections only
    platform/
      nextcloud.js                   # OC/OCP, t/n and notification fallback boundary
      http.js                        # URL, CSRF headers, JSON transport and HTTP errors
    settings/
      connections.js                 # Shared admin/personal connections controller/view
    admin/
      bootstrap.js                   # Compose sections and explicit callbacks only
      tabs.js                        # Tab navigation, hashes and keyboard/focus
      picker.js                      # Accessible selection/save lifecycle
      status-feedback.js             # Existing transient save/flash presentation
      overview/
        service.js                   # Service switch and save/rollback
        checkout-limit.js            # MiB input and configured/effective/PHP limit text
        endpoint-copy.js             # Clipboard action and feedback
      clients/
        oauth-clients.js             # Host list and native-client settings
      protection/
        excluded-tags.js             # Tag selection, invalid-setting alerts and repair
      logs/
        access-groups.js             # Versioned group selection and conflict recovery
        privacy.js                   # Hide-file-names setting and explanatory detail
      access/
        controller.js                # Matrix state, loading and single grant writes
        view.js                      # Rows, headers, cells and sticky-header observation
        presentation.js              # Labels, hints and displayed unavailability reasons
        filters.js                   # Search/group/page/compact/app-filter interaction
        bulk-plan.js                 # Pure current-page change and inverse request plans
        bulk-change.js               # Serialized bulk writes, completed prefix, reload
        undo-notice.js               # Single undo slot, expiry, focus/hover and busy state
  js/
    admin.js                         # Generated, ignored, packaged
    personal.js                      # Generated, ignored, packaged
  package.json                       # Private ESM development manifest
  package-lock.json
  playwright.config.js
  scripts/
    build-assets.mjs
  tests/
    JavaScript/
      admin/                         # Feature interactions and page integration
      settings/                      # Both connection scopes
      access/                        # Bulk planning and request sequencing
      support/                       # Test-only host/network fixtures and page server
```

Files within a cohesive owner may remain private functions; do not add a class,
interface or one-function module mechanically. `connections.js` remains together
unless implementation evidence shows a second independent reason to change it.
No `utils.js`, `common.js`, generic settings manager or catch-all shared store.

- Entries compose; features never import entries/bootstrap or other sections.
  Platform code does not import UI code. Pure bulk planning imports neither DOM
  nor HTTP. The dependency graph must be acyclic and every production source must
  be reachable from a final entry.
- Each feature owns its DOM events, transient state and async outcomes. Views
  receive data and callbacks; they do not send requests. Matrix controller owns
  the row snapshot, filters own filter state, and bulk-change owns its busy flag
  and acknowledged work. Pass snapshots/actions explicitly, not a mutable page bag.
- Bootstrap passes `reloadMatrix` to log access for successful saves and 409
  conflicts. It also passes the overview and protection-tab alert elements to
  excluded-tags; the feature does not discover unrelated section DOM implicitly.
  The connections callback updates the overview connection count only
  when the response supplies an integer. Do not add refreshes of other counters or
  cross-feature requests that the current scripts do not make.
- Tabs own tab navigation and retained query strings. Access filters interpret only
  the `app` query for `#access`, preserving unrelated parameters. Test their shared
  URL boundary; do not introduce a general router.
- Mounting is explicit after the page root exists; importing a feature performs no
  request, DOM lookup, listener registration or host-global access. A normal page
  mounts once. A mounted controller returns `destroy()` and only the actions its
  consumers need (`reload()` for matrix/connections). `destroy()` is idempotent:
  detach owned listeners/observers, cancel owned timers and ignore late outcomes
  after disposal. This lifecycle applies to the extracted modules, not the old
  characterization baseline; it must not introduce automatic retries, cancellation
  of accepted server mutations or new ordering rules on a normally mounted page.
- Do not merge the existing element builders blindly: picker attributes use
  `setAttribute`, while matrix/connections set properties. Keep small private
  builders or explicit element construction preserving those distinctions.

### Illustrative syntax

The following shows the intended boundary, not code to copy before authorization:

```js
export function mountLogAccess({ root, request, createPicker, notifyError, reloadMatrix }) {
    // Local version, selection and conflict recovery belong here.
}

export function planBulkChange({ users, module, operations, granted, unavailableReason }) {
    // Return per-operation before-images and affected/unavailable/unchanged counts.
    // No network, DOM or confirmation dialog.
}

// In the page composition, after creating the matrix controller:
mountLogAccess({
    root: adminRoot.querySelector('#mcp-block-logs'),
    request,
    createPicker,
    notifyError,
    reloadMatrix: matrix.reload,
})
```

Document public module inputs/returns with JSDoc. Keep transport narrow:
`request(method, path, body, options)` resolves parsed JSON and rejects HTTP/network
failures without deciding UI policy. Preserve existing status-message comparisons
(`'409'`, `'503'`) or migrate producer and all consumers atomically to a tested
status field. Preserve request headers, including the distinction between grants'
JSON content type and the connections requests, which currently omit it. Do not
add automatic retry of mutations. URL encoding, the request token and same-origin
app URL generation remain mandatory. `notifyError` preserves Toast when available,
otherwise alert for admin settings and the status region for connections. The
notification boundary receives an optional status element; it never searches the
page to decide the fallback. The HTTP boundary receives URL/token functions from
`nextcloud.js`, so feature modules do not access OC/OCP directly.

Keep literal `t('mcp', ...)` / `n('mcp', ...)` calls recognizable by translation
extraction, even when those functions are supplied by the platform boundary.
Names, tags, groups, hosts, redirects and error text use text nodes/properties,
never interpolated HTML. Confirmation interpolation keeps plain-text escaping.

## 3. Contract and test inventory

Characterize these behaviors against the old scripts before retiring them. Tests
use synthetic users/groups and test-only tokens; no production captures or secrets.

| Owner | Required observable cases |
| --- | --- |
| Tabs | Five tabs and eight blocks in their current order; initial/unknown hash; legacy section anchors and scrolling; per-tab query retention; back/forward; click, arrows, Home/End, roving tabindex and panel visibility; all panels/explanations readable without JS. |
| Matrix loading/filtering | Default `eligible`, 300 ms search debounce, group/filter reset page, both pagers, null versus known total, empty/error/retry; app filter hides columns without another API filter; invalid app clears with replaceState; localStorage unavailable; compact layout and header re-observation after render/tab visibility/resize. |
| Permission presentation/write | Catalog-driven operations; disabled account then eligibility then app/log availability precedence; unavailable differs from denied and preserves saved grants; single PUT path encodes UID, payloads and returned row remain authoritative; pending disable, failure rollback and focus restoration only if focus stayed. |
| Bulk planning/execution | Current page only, skip unavailable, count unchanged users, preserve before-images; zero-work and cancelled confirmation send nothing; eligibility revocation warns that undo does not reconnect clients; serial POST per operation, stop at first failure, undo only acknowledged prefix. |
| Undo notice | One slot and replacement message; 10-second expiry, hover/focus pause, full rearm after busy; busy prevents overlapping bulk/undo; completed values grouped by previous true/false; partial undo reports failure, reloads server state and does not offer an invented automatic retry. Last-write-wins behavior remains explicit. |
| Picker | Known IDs only, empty/no-match fallback, chips, search, all keyboard controls, combobox/listbox ARIA, unique IDs, outside click; confirmation before save, busy state, rollback before caller resynchronization, saved-status timer and focus behavior. |
| Tags | GET/PUT `/api/admin/tags`, string IDs, type badges and collaborative warning; invalid alerts in section/overview/tab; explicit confirmation before saving empty protection, including forced repair with zero available tags; 503 preserves prior selection and explains the failure; load failure visible. |
| Log access/privacy | GET/PUT `/api/logs-access` sends `groups`, `previous`, `version`; missing groups remain removable; one save at a time; successful response resynchronizes; 409 reloads current groups and matrix; other failures reload groups, failed reload shows fallback. `/api/logs-privacy` retains `hideFileNames`, load-failure disable, save rollback and accessible Learn more behavior. |
| OAuth/service/checkout/copy | OAuth GET and separate host/native PUT bodies, host trim/lowercase, authoritative response and native details; service PUT `{enabled}` rollback; checkout GET/PUT `{mib}`, minimum/integer checks, displayed rounding and 102400 HTML maximum backed by server checks; byte-to-MiB/effective/PHP text; clipboard success/rejection. |
| Connections | Admin `/api/connections` includes search; personal `/api/my/connections` does not. Single revoke uses the scoped base; revoke-user only uses admin `/api/connections/users/{uid}`. Confirm/cancel, encoded identifiers, empty/error/retry, previous page after final-row removal, revoke-user page reset, overview count, locale timestamp fallback, safe names and accessible action labels. |
| Page/transport | Existing request methods/bodies/headers and same-origin prefix; visible errors without Toast; one mount/request sequence per section; independent load failures do not prevent other sections mounting; no new forbidden Nextcloud globals or `window.McpAdminPicker`; actual final bundles load without uncaught errors. |

These are structural-migration contracts, not a promise to repair every existing
race. If characterization contradicts a proposed expectation, record the evidence
and resolve the scope before changing behavior. In particular, preserve the
current grant undo semantics rather than adding versioning/retry, and do not
pretend permission UI is an authorization boundary.

## 4. Ordered implementation phases

### Gate sequence and temporary coexistence

At the end of every phase, record commands, versions and outcomes in the execution
report before advancing. Phase 0 uses the existing Composer check. From Phase 1,
`check` must also invoke the complete `test:js` gate. In Phases 1–3 that suite runs
old-page characterization plus every available final-module test; it does not
build missing final entries. Phase 4 replaces this temporary runner mode with the
final build-then-test contract in Section 5. There is no permanent legacy mode,
environment flag or successful fallback when final inputs are absent.

Before each owner is extracted, its relevant Section 3 cases must pass on the old
page, not only the initially prioritized high-risk subset. During coexistence,
tests load old scripts and extracted modules in separate page contexts. Never
mount both on one DOM or make one implementation call the other. Record temporary
fixture/runner paths and remove their old-source loading branches in Phase 4;
keep the behavioral cases and durable final-module mounting support.

### Phase 0 — Freeze the behavioral baseline

1. Revalidate project/worktree identity and status; record the actual starting
   commit and unrelated changes. Reconcile the inventory above against source.
2. Run existing focused Admin, PromptCatalog and package/command tests, followed by
   `composer --working-dir=mcp check`. Capture versions, commands and results.
3. Map every existing source-sensitive assertion to a retained structural test or
   named future browser test. Inventory callers, translation extraction, template
   asset registrations and comments mentioning the four old scripts.

**Acceptance:** Baseline checks pass or a concrete pre-existing blocker is resolved
within the authorized migration scope; pause only for fixes that require a scope
change. No assertion disappears without replacement; source extraction has not
started. Historical counts are not substitutes for this run.

### Phase 1 — Add reproducible browser characterization and the build boundary

**Files:** `mcp/package.json`, lock, Playwright config, `scripts/build-assets.mjs`,
`tests/JavaScript/`, `tests/Unit/Scripts/DeveloperCommandsTest.php`, Composer
scripts, relevant `.github/workflows/`, development setup/testing docs and narrowly scoped ignore/REUSE entries as needed.

1. Select a maintained Node LTS and compatible esbuild/Playwright versions, verify
   their engine requirements, then pin the toolchain and commit the npm lock. Do
   not silently use this machine's Node 20. Record the exact Node/npm versions in
   setup docs and CI, and enforce the supported range in the manifest/build entry.
   Install with `npm ci`; checks must not update versions or install a missing
   runtime automatically. Record browser/system prerequisites. Configure Chromium,
   Firefox and WebKit now so their availability is established before extraction;
   reject focused-only tests, missing projects and skipped mandatory scenarios.
2. Add Playwright characterization against the currently loaded scripts. Render
   the **actual** admin and personal PHP templates using narrow test-only host
   adapters; do not maintain a duplicate hand-written page fixture. Keep generated
   pages, browser traces, screenshots and reports under ignored `mcp/build/`. Keep
   the PHP renderer outside PHPUnit test discovery by name and safe to lint. Serve
   only explicit fixture/asset routes on loopback; do not expose the checkout root.
3. Stub only the consumed host interfaces and intercept the exact API requests.
   Use [Playwright network interception](https://playwright.dev/docs/network) and
   its [managed test server](https://playwright.dev/docs/test-webserver); these are
   browser integration tests with synthetic APIs, not real Nextcloud validation.
   Derive page script URLs from the template's captured asset registrations, not
   a parallel hand-written script list. Unexpected API calls and external network
   requests fail the fixture; keep planned error responses explicit. Add the
   inventory's high-risk flows before moving their implementation.
4. Define the final two-entry build configuration and a temporary test-only way
   to exercise final modules independently. Do not emit a new production entry
   until its source exists. Keep old assets as the sole live implementation until
   Phase 4; no production facade or dual initialization is needed.
5. Introduce `composer --working-dir=mcp test:js` as the fixed complete JS suite,
   backed by npm, with nonzero failure on missing browser, server startup error,
   uncaught page error, failed assertion or zero selected tests. CI uses the same
   command. Add it to `check` now, preserving all existing gates and argument
   contracts. Update `DeveloperCommandsTest` in this phase to probe the new JS
   gate and preserve isolation of its temporary manifests. Final-entry building
   is connected in Phase 4. Missing tooling must
   fail with an actionable prerequisite message. Use no retry-to-green policy.
6. Before creating the new sources, add exact `.gitignore` exceptions for
   `/mcp/src/admin/logs/` and any nested log test directory actually introduced.
   Verify with `git check-ignore` and `git ls-files` that all authored sources/tests
   are versionable while runtime logs and generated artifacts remain ignored.
   Add ignores only for the two generated final JS paths, not all of `mcp/js/`.

**Acceptance:** Browser cases run on the old page, including failure/cancel paths;
no forbidden-global coverage is reduced. Toolchain and server exit statuses are
preserved. Build configuration and fixtures are testable without changing either
live template. `check` executes the JS gate and propagates failures; all three
browser projects are available and tested. Development files have correct
attribution and the new source paths are versionable; no new runtime package.

### Phase 2 — Extract reusable UI and bounded settings to final owners

**Files:** Final `platform/`, `settings/connections.js`, `admin/tabs.js`, `picker.js`,
`status-feedback.js`, overview/clients/protection/logs modules; corresponding tests.

1. Move transport/Nextcloud boundary, tabs, picker and connections into explicit
   exports. Keep admin/personal scope an explicit composition input and assert
   that personal mode never calls admin endpoints or renders admin-only actions.
2. Extract each smaller section listed in the tree. Preserve save ordering, error
   feedback, confirmation, fallback, disabled states and locale behavior. Inject
   log access's reload callback and the connections count callback.
3. Run the same relevant browser scenarios against the final modules through
   test-only mounting, while the old-page characterization continues to pass.
   Add transport, lifecycle and escaping tests at these boundaries. Do not publish
   a global picker alias to make old and new modules communicate.

**Acceptance:** Final modules have explicit dependencies and no import-time side
effects. Tags repair/503, log access 409 and rollback, both connections scopes,
keyboard navigation and notification fallback pass. New modules do not depend on
the old scripts; old scripts remain the only live assets pending the atomic switch.

### Phase 3 — Separate the permissions matrix and bulk/undo state

**Files:** `mcp/src/admin/access/` and matching JavaScript tests.

1. Extract presentation and pure bulk planning/inverse requests. Test mixed saved
   values, unavailable users and operation ordering without a DOM or network.
2. Extract view and filters, preserving catalog-driven columns, unavailable rows,
   sticky observation, both pagers, hash/history and compact storage behavior.
3. Implement the matrix controller's existing load and single-save orchestration
   with the new view. Keep selection snapshot ownership explicit.
4. Extract serialized bulk changes and the single undo notice. Use controllable
   responses/timers to test first failure, acknowledged prefix, partial undo,
   busy lock, replacement, expiry and focus. Do not parallelize bulk requests.

**Acceptance:** All matrix scenarios pass against final modules and old-page
characterization remains green. No new file owns both unrelated settings and
matrix state; view has no fetch, planning has no DOM, bootstrap will not own undo.
No new server endpoint, automatic retry or permission policy has been introduced.

### Phase 4 — Compose and switch both pages atomically; retire old sources

**Files:** Final entries/bootstrap, both PHP templates, Settings and CSS comments,
`AdminAssetsTest`, `PromptCatalogTest`, package/command tests and build integration.

1. Assemble the admin entry from final owners and the personal entry from shared
   connections. Handle root absence before host access; pass the two cross-section
   callbacks explicitly. Every section initializes once and handles its failures.
2. In one integration change, register `admin` in the admin template and `personal`
   in the personal template, keep the existing stylesheet and markup, and delete
   all four old scripts. Remove `window.McpAdminPicker`, old registration/comments,
   old test loaders and every compatibility path. The new bundles are build outputs,
   not copies or forwarders of old scripts.
3. Move structural checks to recursive authored-source discovery and test built
   assets separately. Preserve template IDs/tabs/no-JS, controller/routes, removed
   global, translation and Files create-hint coverage. Replace behavior-only source
   checks with their proven browser cases; retain all unrelated assertions.
4. Wire `assets:build`, `test:js`, `test`, `package` and `check` consistently as
   described below. Every clean CI job that runs PHPUnit asset checks or packages
   the app must install the locked Node dependencies and build first. PHP 8.2–8.5
   remain in CI; browser tests need not repeat for each PHP version.
5. Extend package tests to require both final bundles, reject all four retired
   scripts and reject Node dependencies, `src/`, npm/config/test files and source
   maps. Build before staging, and never allow stale assets after build failure.
   Invalidate only the current version's package output before starting; create
   the new archive at an app-local temporary path and publish the final filename
   only after its checks pass. A failed build/check must leave no old archive at
   the expected result path. Preserve production-only Composer vendor staging.
6. Keep the test bootstrap dependency graph one-way: Composer invokes npm build
   or test scripts, while the browser server/renderer never calls Composer test,
   check or package. Extend `DeveloperCommandsTest` probes again for the build
   prerequisite; their temporary manifests must not accidentally execute real
   frontend tools from a fixture directory.

**Acceptance:** Both pages load only their final entry, all browser tests target
final modules/bundles, and no old-script runtime/test adapter remains. Clean source
build and archive inspection pass. The personal bundle contains no administration
feature graph. The import graph is acyclic with no unreachable production modules;
reconcile esbuild input metadata against the authored source inventory. No authored
file is hidden by ignore rules or omitted from the eventual migration commit.

### Phase 5 — Verify closure and publish local evidence in documentation

1. From installed locked dependencies, run `composer --working-dir=mcp check`,
   including the full JS suite, PHP 8.2–8.5 checks available locally,
   and REUSE. Run browser scenarios in Chromium, Firefox and WebKit; record exact
   versions and any unavailable system prerequisites. Do not silently skip a browser.
2. Prove negative gates: missing source/import, failed build with old output
   present, packaging failure with an old target archive present, empty test
   selection, missing browser, malformed archive and a leaked development file
   must fail. Test the archive's actual generated scripts against
   the page fixture, not only the source tree copies.
3. On available supported Nextcloud installations, verify real loader/CSP/cache,
   request-token handling, locales, keyboard/focus, no-JS readability, narrow layout,
   both scopes and multi-window log group conflict. Do not deploy to production to
   obtain this evidence. Fixture tests do not prove host authentication or CSP.
4. Record final ownership, command prerequisites, assertion-to-test reconciliation,
   retired files, generated asset policy and results in a dated execution report.
   Update setup/testing/architecture navigation and references in current guides;
   preserve historical plans/reports as historical evidence.

**Acceptance:** No pending local migration step, failed required automated gate,
retired executable path or duplicate authored implementation remains. If a required
local gate cannot run, stop and report the blocker; do not declare migration complete.
Real-host checks unavailable in this environment may remain explicitly external
release gates, with host/version/scenario recorded. They never count as passing.

## 5. Final developer command and packaging contract

Composer remains the contributor entry point; npm owns only JavaScript dependency
resolution and implementation commands. Do not create a second general task runner.
These are proposed final commands, **not commands available in the current tree**:

| Command from the repository root | Final contract |
| --- | --- |
| `npm --prefix mcp ci` | Install locked development dependencies using the documented Node/npm versions. Browser installation is a separately documented reproducible prerequisite. |
| `composer --working-dir=mcp assets:build` | Fixed build of both entries; ignore extra arguments; fail on missing dependencies/source/imports and propagate builder errors. |
| `composer --working-dir=mcp test:js` | Build final assets, then run the complete browser/pure JS suite; ignore extra arguments. Focused contributor runs may use documented npm test arguments, never change this gate. |
| `composer --working-dir=mcp test -- …` | Build assets before existing PHPUnit discovery because asset tests consume them. Preserve all three PHP limits, empty-suite failure and PHPUnit-only argument forwarding. |
| `composer --working-dir=mcp package` | Always build before staging; include exactly final production assets; missing Node/tooling is a failure for source builds, never a reason to reuse output. |
| `composer --working-dir=mcp check` | Run validate, lint, analyse, PHPUnit, full JS suite and package, in that order; stop on first failure and ignore extra arguments at all fixed gates. No reduced scope on forwarding. |

The PHP-only `lint` and PHPStan scopes remain accurately named/documented; esbuild
is not a type checker or a replacement for PHP analysis. Repeated deterministic
asset builds through individual commands are acceptable initially; do not add
mtime-based shortcuts that can hide stale output. Test root/app working directories,
quoted PHPUnit filters and propagation of npm/build/browser failures through Composer.
The build prerequisite in `test` receives `@no_additional_args`; only PHPUnit gets
its caller's arguments. Keep `test:architecture` and `test:api` forwarding to `test`
unchanged. Preserve `Composer\Config::disableProcessTimeout` for the existing long
commands and add it to long JS/build gates. No interactive browser install during
verification and no recursive invocation of the top-level gates from test helpers.

Update `scripts/package.sh` itself, not just its Composer alias, so direct packaging
cannot bypass the build. It calls the npm build implementation directly, avoiding
recursion through `composer package`. Add exact development-path exclusions to the archive
checker rather than disabling checks wholesale. Preserve source/license availability
and SPDX headers; annotate new non-commentable manifests/fixtures narrowly under
REUSE. Generated output needs an attribution strategy verified in the archive.
The external devbox catalog already delegates to Composer; its command definitions
need no change. The execution environment still needs the pinned Node/npm and
browser prerequisites. Verify that separately and do not claim catalog readiness
from delegation alone.

## 6. Retirement and completion ledger

| Retired owner / coupling | Final owner / evidence |
| --- | --- |
| `mcp/js/admin-grants.js` | Admin feature modules + composition; all HTTP/DOM interaction cases mapped. |
| `mcp/js/admin-picker.js`, `window.McpAdminPicker` | `src/admin/picker.js` imported by tags/log access; no global facade. |
| `mcp/js/admin-tabs.js` | `src/admin/tabs.js`; final bundle navigation tests. |
| `mcp/js/connections.js` | `src/settings/connections.js`; both entries/templates tested. |
| Four-script load ordering | One generated entry per page, registered through the existing PHP loader. |
| Fixed old filenames and function-substring behavior assertions | Recursive structural checks, targeted presentation checks and executed behavior tests with a recorded mapping. |
| Test-only old-script loading / comparison mode | Removed in Phase 4, including temporary runner flags and fixture branches; durable fixtures load actual final page entries or final modules. |
| Old script names in Settings/template/CSS comments | Update to final authored or generated owners without changing PHP behavior or CSS declarations. |
| Ignore rules hiding new log modules/tests | Exact source/test exceptions, tracked-file reconciliation and no forced additions. |
| Catch-all source responsibility | Each feature and matrix sub-responsibility has its final owner; no abandoned source or transitional folder. |

Historical documentation may mention old paths. Executable imports, template asset
registrations, active guides, build inputs and tests must not depend on them; archive
rejection tests may name retired assets deliberately. An archive listing and a
production dependency-graph review are required, not just a successful text search.

**Can the entire scope finish without transition code?** Yes. It is a bounded
four-script graph with two template consumers and unchanged server endpoints. The
atomic page switch makes it possible to remove all old assets at once. Extraction
phases may temporarily contain old live scripts alongside final modules exercised
by tests; they are not releasable end states. No compatibility bridge is part of the
final design. Build outputs and test-only host adapters have permanent, explicit
roles and must not become alternate authored application implementations.

## 7. Planning review and remaining scope

The documentation review confirmed the two template consumers, PromptCatalog's
source reference, privacy/conflict behavior and the final retirement ledger. It
corrected these execution gaps:

- `git check-ignore -v --no-index` confirmed that the proposed log modules/tests
  match the broad `logs/` rule. Exact exceptions are now a prerequisite.
- The phase gates now include JavaScript in `check` from Phase 1 and explicitly
  retire the intermediate runner mode when both pages switch in Phase 4.
- Builds and packaging now invalidate stale outputs, publish only checked results,
  and exercise failures with previous output already present.
- The plan now covers CSS/Settings comments, captured template asset registrations,
  all three browser projects, command argument forwarding and test-helper recursion.
- Controller disposal and cross-section DOM references have explicit owners;
  baseline behavior tests remain distinct from the new module lifecycle tests.

The main tradeoff remains the frontend build/test toolchain. Phase 1 must prove its
reproducibility before extraction. Browser and real-host checks are future gates,
not claimed review results. This documentation-only review does not rerun the full
PHP suite or install frontend tooling; local links, whitespace, attribution and
commit scope are checked before committing.

This task changed documentation only. Runtime modules, templates, CSS, dependencies,
tests, Composer commands and CI remain unchanged. After review and authorization,
implementation starts at Phase 0. Broader CSS organization, PHP Settings/controller
refactoring, README relocation, documentation translations and existing external
release gates remain separately scoped work.
