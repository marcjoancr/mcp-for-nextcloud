<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Interrupted page-load investigation

Status: **both original symptoms reproduced and attributed to departing-document
behavior; no production MCP correction justified by the observed impact**.
The exceptions remain reproducible. They are not fixed, suppressed, or counted as
error-free acceptance. Clients, HTTPS and all human reviews remain pending.

This follows the [closed UI acceptance report](2026-10-19-closed-devbox-ui-acceptance.md)
and the user's 2026-10-19 request to investigate its interrupted-load errors.
The same closed instance was temporarily resumed with the unchanged application
`b96884631bf9bda245ce9895facfa8380ab08998`; documentation started at `c04946d`.
Nextcloud 35.0.1, the image/browser pins and synthetic accounts are unchanged.
Only the scoped `127.0.0.1:18080` relay was restarted; no external client, callback
listener, HTTPS exposure or public interface was introduced.

## Method and evidence

The [structured record](../contracts/interrupted-page-load-b968846.json) contains
script/result hashes, per-engine/path/condition counts, host-source hashes and the
new closure record. Private scripts and event timelines are retained under the
original run's `interrupted-load-investigation/` subdirectory. Earlier qualification
files and their hashes were preserved. No credentials or browser session state are
included in the versioned evidence.

The probes logged document identities, `beforeunload`, `pagehide`, readiness/load
transitions, presence of `OC`/`t`, JavaScript exceptions and failed requests. They
compared `/settings/user/mcp` with `/settings/user`, confirming zero MCP script tags
on the latter, and also navigated away from `/apps/dashboard/` to user settings.
These are local diagnostic experiments, not a replacement for the 65 acceptance
checks or a human visual/accessibility review.

Reproduction procedure:

1. Log in with the synthetic administrator and let the initial login settle.
2. For a control, load the target fully, then reload and wait for its final load
   and network idle. For an interruption, navigate only to document commit, wait
   for the rendered MCP root or host body and an interactive-or-complete document,
   then reload immediately. Record the actual readiness; the requested early mode
   does not guarantee that the browser is still loading.
3. Compare MCP and host-only settings in Chromium, Firefox and WebKit, four trials
   per reload condition/path. Add four early dashboard departures per engine.
4. For the Firefox hypothesis check, delay the real `/dist/core-common.js` request
   by 500 ms before continuing it, using three trials per condition/path. No script
   contents or API responses are substituted, and no security control is relaxed.
5. Inspect both the departing and replacement documents. Verify terminal host APIs
   and MCP scope/connection readiness from the recorded snapshots; preserve all
   exceptions instead of excluding them from collection.

The first exploratory script used nonexistent `#app-content` and `#app-dashboard`
selectors for host controls. Its eight completed MCP trials and twelve selector
timeouts remain recorded; the matrix was interrupted and is not a passing run.
The corrected probe used the actual host body and explicit readiness sampling.
One of the initial four early MCP reloads already reproduced all six Firefox
exceptions without injected latency.

## Results

| Experiment | Completed diagnostics | Observed exceptions |
| --- | --- | --- |
| Natural timing, full matrix | 60/60 | Nine exceptions in seven trials: five WebKit notification exceptions, plus four Firefox dashboard/Photos exceptions in two departures. |
| Firefox, 500 ms core delay | 12/12 | Six interrupted trials emitted 27 exceptions; all six fully loaded controls emitted none. |
| Subset: fully loaded controls across both matrices | 30/30 | No JavaScript exceptions. |

The two completed matrices contain 72 trials in total; the 30 controls are a
subset, not additional trials. “Completed” means the diagnostic navigation
finished, not that its console was error-free. Every exception in the two completed matrices occurred before the
replacement document's initialization event. All 60 reload trials (48 natural,
12 delayed) ended with `readyState=complete`, `OC` present and `t` callable. All
30 MCP reload trials also ended with empty scope status and the connections table
no longer busy. Dashboard departures completed and settled; they did not capture
the same terminal UI snapshot and are not included in those 60/30 counts.

Chromium's eight natural early-reload snapshots were already `complete`.
Its zero-error result therefore does not prove resilience to pre-initialization
interruption. These small local samples are reproductions, not failure-rate estimates.

### Firefox: host globals absent during departure

In the natural reproduction, the outgoing document was `interactive` with `OC` and
`t` undefined. After its `beforeunload`, Nextcloud's mimetype, theming, sharing and
user-status scripts threw initialization errors; the MCP scope/connection loaders
also reported `host.t is not a function`. The replacement document subsequently
initialized those globals and rendered the MCP panels normally.

The latency experiment reproduced the cascade in **3/3 interrupted MCP trials**
(six exceptions each) and **3/3 host-only settings trials** (three exceptions each,
including `OC is not defined`). The host-only pages contained zero MCP script tags.
With identical latency but a completed initial load, **6/6 controls had no errors**.
This supports interruption of host initialization as the trigger in these runs.
No normal-load ordering failure was observed in the controls; this bounded
comparison does not rule out every possible MCP regression.

The inspected Nextcloud template emits deferred classic scripts and module scripts.
MCP registers its personal bundle through `OCP\Util::addScript` and consumes host
APIs through [the adapter](../../../../mcp/src/platform/nextcloud.js) and
[personal entry point](../../../../mcp/src/entries/personal.js). The scope loader
calls translation before its request; the connection error path also translates.
That explains the two MCP exceptions when the host globals are absent. The locally
available upstream commit `0911f9123a661968e707fc4c391adcf9d37c3e84` also directly
uses global `t`/`OC` in `mcp/js/personal-scope.js`; this is source comparison, not a
runtime qualification of that upstream revision.

The evidence supports a host/browser cancellation cascade in the outgoing page.
It does not identify a specific Firefox-internal defect or prove every possible
aborted-navigation behavior safe.

### WebKit: cancelled notification request

The original “due to access control checks” exception reproduced in **3/4 early
MCP reloads** and **2/4 early host-only reloads**, with stacks in Nextcloud's
Notifications bundle. In the host-only case, the record shows zero MCP scripts,
then `beforeunload`, the exception, `pagehide`, and a healthy replacement document.
The URL is the same loopback origin. No HTTPS/proxy origin, external client or
cross-origin callback participated in these probes.

This supports a cancellation/unload problem in the host notification flow, not
an MCP CORS configuration defect. The precise engine-internal cause is not proven.
Do not loosen CORS/CSP or change MCP authorization based on that exception wording.

Two Firefox dashboard departures additionally produced Photos/fetch exceptions
before the replacement document initialized. They remain in the record as host
observations; the investigation does not claim a fix for those applications.

## Correction decision and remaining work

No application, generated bundle, test suite or runtime security policy was changed.
The evidence does **not** justify a required production patch to MCP for these
symptoms: they occur while abandoning incomplete host initialization, reproduce
without MCP, and do not persist in the destination pages observed here.

Do not add a silent translation fallback, blanket exception filter, or unconditional
`beforeunload` suppression. Those would hide a genuinely missing host dependency
on a page that remains active, and would not repair Nextcloud's own failures.
The acceptance fixture's existing full-load synchronization remains appropriate
for completed-navigation checks; these diagnostic interruptions remain visible
separately and are not reclassified as successful functional checks.

The bounded investigation is complete. The residual host/browser behavior is a
known limitation suitable for a focused maintainer report, with the host-only
reproduction and exact versions, if such reporting is separately requested. No
external issue or message was sent. A future observation on a fully loaded page,
or a broken destination page, would require a new MCP regression investigation.

Actual clients, HTTPS/proxy acceptance, optional-app combinations, independent
recovery and every human security/technical, accessibility, operational and language
verdict remain pending. The 96 documentation translations are still review-required.

## Closure and validation

At **10:07:22 UTC on 2026-10-19**, MCP was still disabled, all **863 installed files**
matched the frozen manifest, the investigation relay was stopped, and both owned
containers were stopped with restart policy `no`. Ports 18080 and 18999 were closed.
Data, volumes and both generations of evidence were retained. No push or PR opening
occurred. The archive was not rebuilt.

- `composer --working-dir=mcp docs:check`: passed, 191 Markdown files and 110 package sources.
- `composer --working-dir=mcp test:docs`: all 29 tests passed, none skipped.
- `composer --working-dir=mcp docs:translations`: provenance valid; 96 entries remain
  `review-required`.
- REUSE 5.1.1: all 1,528 files have copyright and license information; no errors.
- `git diff --check`: passed.

The versioned summary was checked against the raw diagnostic records. Earlier
acceptance evidence hashes still match their committed record. These prose changes
required no package rebuild or repeat of the earlier PHP/native qualification.

The pre-commit documentation review rechecked all six diagnostic script/result
hashes, the closure hash, every group count/readiness distribution, and the
recorded terminal states and exception timing. It clarified the control subset
and the limits of causal attribution. No browser experiment or instance startup
was repeated for this review; clients, HTTPS and human verdicts remain pending.
