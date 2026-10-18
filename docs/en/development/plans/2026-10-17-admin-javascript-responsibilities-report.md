<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Administration JavaScript separation execution report

**Status:** Locally complete; external release gates remain below. **Baseline:** `e331abc`.
**Plan:** [Reviewed separation plan](2026-10-17-admin-javascript-responsibilities.md).
**Scope:** Administration and personal connections JavaScript, its build/test boundaries
and documentation. No deployment, push or external catalog mutation.

## Execution ledger

- Phase 0: complete. Selected project/worktree verified; starting tree clean.
  Focused Admin, PromptCatalog and script tests: PHP 8.4.26, **130 tests / 849
  assertions, passed**. Full catalog check passed: **5,268 tests / 44,813 assertions**, both static-analysis
  scopes, PHP lint and production package. See ignored
  `mcp/build/admin-phase0-check.log` for local command output.

## Interface review and rulings

| Producer / consumer | Contract / review |
| --- | --- |
| Phase 1 browser harness / Phases 2–3 extraction | Render real PHP templates; test old and extracted implementations in separate contexts. |
| Platform / every feature | Inject translation, URL/token and error feedback; preserve body/header/status behavior. |
| Log access / matrix | Explicit reload callback after success/409; no shared mutable page state. |
| Excluded tags / page shell | Explicit overview/tab alert references; fail-closed repair is unchanged. |
| Phase 4 entries / templates and package | Two final classic bundles; one per page; four previous assets removed atomically. |
| Build / Composer commands | Source builds fail on missing tooling/input; package stages only checked output. |

Ruling: use this report as the durable phase ledger and ignored app build output
for verification logs, rather than creating a skill workspace inside the repository.
The user requires harness metadata to remain external; the report is requested
project documentation, not harness configuration.

Ruling: characterize existing behavior before extraction and use focused negative
controls for the test harness. The migration preserves behavior; intentionally
changing production behavior just to obtain a red characterization test would
contradict the specification. New module/lifecycle/build contracts get failing
checks before implementation.

## Pending

Phases 3–5, final independent review and real-host availability assessment.

- Phase 1: complete. Official npm metadata verified esbuild 0.28.2 and
  Playwright 1.64.1; selected installed Node 24.21.0 / npm 11.19.0.

Ruling (Phase 1): the isolated fixture includes app CSS, not Nextcloud's complete
stylesheet stack. Existing `.mcp-picker-box` / `.mcp-picker-option` display rules
override the browser's default `[hidden]` style in that fixture. Characterization
asserts the real hidden attributes and selectable filtered options, not an invented
host CSS rule. Visual hiding with the complete host styles remains an explicit
real-host check; production CSS is not changed to mask this baseline limitation.

Initial browser failures also exposed a missing test-only `print_unescaped` helper,
a dialog-handler deadlock and checkbox assertions that raced an immediate rollback.
The harness now renders the actual template fully, handles dialogs during actions,
and checks the observable final checkbox state. Clock-controlled debounce checks
pause time rather than mixing elapsed real time with fast-forwarded time.

## Source assertion reconciliation

`AdminAssetsTest` contracts are retained or transferred as follows; Phase 4 applies
these moves only after the browser scenarios pass against final modules.

| Existing assertion group | Durable owner / behavioral evidence |
| --- | --- |
| Removed frontend globals | Recursive authored JS scan plus built scripts; preserve the forbidden-global list. |
| Error notification fallbacks | Connections failed-revoke/status test and admin service-failure/alert test. |
| Registered script/style existence | Both real templates and final package entries. |
| Unique IDs / balanced markup | Retained template checks and browser rendering of actual templates. |
| OAuth placement and translated keys | Retained template placement and canonical source/catalog checks. |
| Tabs, eight blocks, panel containment, no-JS fallback | Retained PHP structure checks and navigation browser cases. |
| App-only filtering and query preservation | Navigation unknown-app/history and matrix filtering/query tests. |
| Allowed/denied/unavailable, bulk and undo | Matrix tests: saved-value presentation, acknowledgement prefix, cancel, busy, expiry, replacement and partial undo. |
| Sticky layers and toggle clearance | Retained CSS constraints; layout remains a real-host gate as well. |
| Personal connection block | Retained template form/block checks and both scoped connection browser cases. |
| Connections accessibility and narrow screens | Retained stylesheet constraints and named revoke actions; real-host viewport checks remain external. |
| Checkout HTML/controller maximum | Retained PHP maximum check and browser input/rounding/failure cases. |
| Routes and checkout methods | Retained PHP route tests; no controller changes. |
| Log-group previous/version and serialization | Conflict resynchronization and in-flight picker lock browser cases. |
| Privacy switch/detail markup | Retained PHP structure plus browser load failure, save and keyboard detail cases. |
| Empty-tags forced repair and invalid overview/tab alerts | Browser cancel/confirm/empty repair, failed save rollback, and all three alert states. |
| PromptCatalog Files create description | Retain its semantic description check at the final presentation owner. |

Phase 1 gate: pinned-Node `devbox run check` passed: **5,268 PHPUnit tests /
44,821 assertions**, **99 JavaScript cases** across Chromium, Firefox and WebKit,
PHP syntax/static analysis and package validation. The builder's missing-module
red test preceded implementation; its stale-output, orphan and cycle probes now
pass. Empty JavaScript discovery and an absent browser were separately confirmed
to return failure. Logs are under ignored `mcp/build/admin-phase1-*`.

Phase 2: complete; production assets remain unchanged while final modules are
extracted and tested independently.

Ruling (Phase 2): add `src/platform/lifecycle.js` as a narrow shared owner for
listeners, timers, disposal and late-response rejection. Repeating this lifecycle
in every async section would create inconsistent cleanup; it owns no feature
state, DOM rendering or mutation policy. Accepted writes are not cancelled.

Phase 2 regression: the first tabs extraction used selector lookup for a fragment,
which broke IDs containing brackets/colons. The isolated literal-ID test failed
with the browser's invalid-selector error before restoring `getElementById` on
the root's owner document. No URL contract was changed.

Phase 2 complete gate: **5,268 PHP tests / 44,821 assertions**, **129 JavaScript
cases** across all three browsers, lint, both PHPStan scopes and package validation
passed (`mcp/build/admin-phase2-check.log`). New modules are imported/mounted in
separate bare-template contexts; the old live pages still pass their original
characterization. A cleanup edit briefly removed private connection render helpers;
inspection caught it and the helpers were restored before this passing gate.

Phase 3: complete. Seven matrix owners separate presentation, filters, DOM/focus,
server snapshots, pure planning, serialized writes and undo lifetime. The pure
planner import failed before extraction; its two cases and all eight matrix cases
then passed against the final modules. The temporary `matrix-isolated.spec.js`
comparison suite will be removed during the atomic switch (Phase 4).

Phase 3 gate: **5,268 PHP tests / 44,821 assertions**, **159 JavaScript cases**
in Chromium, Firefox and WebKit; lint, both analysis scopes and package passed
(`mcp/build/admin-phase3-check.log`). The old scripts remain the sole live assets.

Phase 4: complete. Both templates load their final entry; all four original
scripts and the temporary comparison suite are deleted. Composer test/build/package
contracts and all affected CI jobs use locked frontend dependencies. The package
checker requires both bundles and rejects development inputs and retired scripts.

Phase 4 findings: the full gate exposed ASCII escaping in the generated bundles
(`Saving\u2026` versus the literal catalog key). The build now emits UTF-8, and
L10n parity scans recursive authored sources as well as generated bundles and
templates. No catalog key or translation was changed. The rendered-page test checks
all IDs for uniqueness; literal PHP branches legitimately repeat the OCR ID, so
the PHP check retains exactly-once checks for queried IDs instead.

Ruling (Phase 4 translation inventory): the old dynamic-call detector was activated
by the removed file's comment `t('mcp', …)`, not executable code. Preserve the
existing catalog-ID translation requirement explicitly from `GrantPolicy::CATALOG`
and remove that misleading detector. Literal-key parity still checks both directions;
no translation keys are deleted, no placeholder runtime calls are introduced.

Phase 4 gate: **5,279 PHP tests / 45,130 assertions**, **141 JavaScript cases**
(47 cases in each browser), lint, both PHPStan scopes and fresh package passed
(`mcp/build/admin-phase4-check.log`). Count changes reflect three behavior-only
PHP methods moved to browser coverage and fourteen package/command cases added;
the eight temporary matrix comparison cases were retired after the final page
switch. The build graph accounts for every authored module with no cycles or
administration feature inputs in the personal output. No CSS declaration changed.

Phase 5: complete. Archive scripts, negative gates, available local compatibility,
license metadata and the independent review are validated; unavailable full runtime
and real-host checks remain explicit external gates.

## Closure evidence and remaining gates

- Package shell probes exercise build failure and checker rejection with an old
  version result already present. Both fail, remove the current result, and retain
  an unrelated older-version archive. Real malformed/leaked tar cases remain in
  `CheckPackageTest`; fixture stubs do not replace that checker.
- Matrix lifetime coverage checks debounce cancellation, observer disconnect and
  re-observation, sticky offset and exactly one query listener after remount.
- REUSE initially rejected a literal SPDX assertion as a malformed license tag.
  Splitting the assertion's marker preserves its exact expected output and avoids
  creating a second metadata declaration; no file is excluded from attribution.
- PHP **8.2.34** syntax checks passed in the cached official image, offline with
  read-only source. Its `intl`, `gd` and `zip` extensions are absent; PHP 8.3/8.5
  runtimes and the previous task's prepared runtime tags are no longer installed.
  Full PHP 8.2/8.3/8.5 execution therefore remains external for this revision;
  the previous compatibility report is historical evidence, not a rerun here.

No supported disposable Nextcloud host is configured for this worktree. Before
release, verify **Nextcloud 32, 33, 34 and 35**: native asset loader/CSP/cache,
request-token/authentication handling, supported locales, narrow and themed layouts,
keyboard/focus, no-JS readability, admin/personal scopes and concurrent log-group
conflicts. The picker visibility limitation of the isolated fixture remains part
of that visual gate. No deployment was made to manufacture host evidence.

GitHub Actions must run on the integrated revision when publication is authorized.
Its PHP matrix and package jobs install Node/locked npm dependencies before the
Composer commands, and the browser job installs all three required engines.
The external catalog still delegates to Composer; this session passed the pinned
Node directory explicitly. A new shell must activate that documented toolchain.

Remaining separately scoped development: broaden static analysis deliberately;
complete README/documentation navigation and translation policy implementation;
evaluate CSS organization and PHP Settings/controllers on their own merits.
None requires a JavaScript compatibility shim or transition directory.

Final independent review found one important lifecycle issue: remount reset the
matrix/connection query but retained visible search/group values and appended
matrix groups twice. Both new browser regressions failed before correction. Mount
now resets visible query controls and removes previously rendered group options,
keeping the original translated all-users option. The unused feedback scope
parameter/wrappers were also removed without changing save feedback behavior.

Reviewer exclusions accepted: earlier backend migrations are the baseline; real
host checks and unavailable full PHP runtimes remain external; concurrent GET
ordering and last-write-wins undo are preserved pre-existing semantics rather
than silently expanded migration scope. No other actionable finding was reported.

The cached PHP 8.2.34 image additionally passed the affected Admin, L10n and
PromptCatalog subset directly against built assets: **87 tests / 2,815 assertions**,
with all three canonical PHP limits, no result cache, no network and read-only
sources. This is a focused check, not the complete Composer suite on that runtime.

The first full gate after feedback-helper cleanup caught one missed privacy call
site still using the old signature (three scenarios failed in each browser).
That caller now imports the same direct alias as the other settings. The existing
privacy success/failure tests caught the regression; none was skipped or weakened.

## Final results

| Gate on the final revision | Result |
| --- | --- |
| Pinned-Node `devbox run check` | Pass: 5,279 PHPUnit tests / 45,130 assertions; PHP lint, both PHPStan scopes, 156 JS cases and fresh package. |
| Browser suite with final archive scripts/styles | Pass: 156 cases (52 each in Chromium, Firefox and WebKit); no retries or skipped cases. |
| PHP 8.2.34 | Syntax passes; affected Admin/L10n/PromptCatalog subset: 87 tests / 2,815 assertions, no deprecations. |
| REUSE | Pass: 1,080 / 1,080 files attributed; no missing/bad/unused licenses or read errors. |
| Empty JS selection / absent Chromium binary | Both fail with exit 1 and the expected diagnostics. |
| Missing import / stale output / unreachable module / cycle | Required build tests pass by rejecting each invalid input; stale final entries are removed. |
| Failed package build/check with existing result | Both fail, remove the current-version result and preserve an unrelated older archive. |
| Malformed archive / development leaks / retired scripts / missing final bundle | Rejected by the PHP package checks. |
| Source graph and retirement | 24 authored modules accounted for; no cycles, external runtime dependencies, admin features in personal output, original scripts or comparison loader. |
| Documentation links and whitespace | Pass. Translation catalogs and CSS declarations are unchanged. |

The SHA-256 digests of both generated entries match the bytes extracted from the
final checked archive. The unused ignore exception for a test directory never
introduced was removed; authored log settings remain tracked and generated entries
remain ignored. Logs for successful and deliberately failing probes remain in
ignored `mcp/build/admin-phase*` files.

Local history separates characterization (`ff97aa2`), bounded owners (`872b700`),
matrix owners (`ac9e58b`), atomic integration (`f6e3021`) and the final review fix
(`f8ec2d7`). Closure tests and this report are committed separately. The existing
`docs/talk-capability-plan` branch and its worktree are retained; no push, merge,
preview publication or deployment was performed. There is no remaining local
JavaScript migration step or transition implementation.
