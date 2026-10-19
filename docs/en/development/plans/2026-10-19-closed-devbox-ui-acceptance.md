<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Closed devbox UI acceptance of the qualified candidate

Status: **bounded technical UI exercise passed; external clients, HTTPS/proxy,
operational recovery and human acceptance remain pending**. The user authorized
creation of a temporary closed devbox instance and real Nextcloud UI testing on
2026-10-19, explicitly deferring clients and HTTPS exposure. No push or PR opening
was authorized or performed. This does not activate the standing S1 recipe or close
S0/S7; accepted owner/resource decisions remain in force.

## Inputs and isolation

- Frozen application: `b96884631bf9bda245ce9895facfa8380ab08998`, already qualified
  by the [local report](2026-10-19-complete-pr-local-qualification.md).
- Documentation head at the start: `6270340c429e5eece4451c227a6da114de8650d3`.
- Installed archive SHA-256:
  `de71b3cbf223764033e97fc59c3122e4c11944730dabe8b79a7f44fe9eb5d751`.
  All **863** deployed regular files matched before and after the exercise.
- Nextcloud **35.0.1**, MariaDB **10.11.19**, fixed image digests from the candidate's
  native matrix. The separate reviewed r3 standing-staging bundle remains unchanged;
  its older application artifact was not used for this exercise.
- One isolated internal Docker network and two owned data volumes. Nextcloud has
  2 CPU / 2,560 MiB ceilings; MariaDB has 1 CPU / 1,024 MiB. No privileged containers,
  host networking, repository/home/Docker-socket mounts or automatic restarts.
- The internal network did not activate Docker's requested published port. A scoped
  host TCP relay supplied **127.0.0.1:18080** while retaining blocked container egress.
  No listener bound a public interface and no devbox exposure was created.
- Synthetic administrator, three browser-specific users, one denied user and one
  test group. Random credentials remain in restricted external state; installation
  used hidden prompts, not password arguments. Access logging omits URL/query,
  headers and bodies. Private raw evidence is not a publication bundle.

The [machine-readable evidence](../contracts/closed-ui-acceptance-b968846.json)
records image identities, versions, case names, script/result hashes, attempts,
server diagnostics and closure. The runtime container flags describe the active
exercise snapshot; the closure record describes the later stopped state. Runtime recipes and private evidence remain under
`/home/dev/.local/state/mcp-for-nextcloud-ui/mcp-ui-20261019091134-3c0746/`.
Selected synthetic screenshots are copied to ignored
`mcp/build/verification/closed-ui-b968846/`; no credentials, raw logs, session state
or browser traces belong in Git.

This instance uses Nextcloud's bundled apps plus MCP. Optional Calendar, Contacts,
Deck, Notes, Talk, Tasks and Group folders packages were not added. These results
cover MCP administration/personal integration on this baseline, not those apps'
own frontends or every optional-app combination. LDAP, federation, Redis, SMTP,
independent backups and a production reverse proxy are outside this exercise.

## Executed checks

Playwright **1.64.1**, Node **24.21.0** and the cached locked browser builds drove
actual HTTP pages, sessions, production bundles and database writes. Application
API responses were not mocked. The browser version/revision registry is pinned in
the evidence; these Linux engines do not certify every branded browser/device.

| Check | Result |
| --- | --- |
| Main checks in Chromium, Firefox and WebKit | **48/48 passed**, 16 per engine in `acceptance-qualified`. |
| Resource-scope editor, inspector and protection picker | **9/9 passed**, three per engine in `supplement-qualified`. |
| English, German, Spanish and Brazilian Portuguese, light/dark | **8/8 rendering checks passed** in Chromium, each visiting administration and personal settings. Recorded computed backgrounds differed as expected; the evidence review checked their values. No linguistic verdict is implied. |
| Installed artifact, service state and runtime closure | **863 files unchanged**; MCP disabled, containers and local relay stopped, both local test ports closed; data retained. |

The 16 main cases cover production assets and the enforced CSP header; five tabs,
hash navigation and keyboard movement; persisted service/upload-limit changes;
individual eligibility and Files read grants; refusal of an ineligible user and
non-admin API access; personal connect/disconnect; refused missing/invalid CSRF;
persisted log privacy; custom-client registration/deletion; consent denial and
acceptance; visible connections and personal revocation; refused refresh after
revocation; and a 390-pixel viewport. The final complete run recorded **no JavaScript
exceptions or CSP violations**. Navigation-aborted background requests are retained
in diagnostics, not silently dropped.

Consent uses a controlled callback listener at **127.0.0.1:18999** plus direct
protocol requests with synthetic credentials kept in memory. It verifies the real
consent/connection UI and its server outcomes. It is **not** Claude, ChatGPT, Gemini,
or acceptance of C3/C4 as actual chosen MCP applications. No external account was
used and no remote callback origin was contacted.

The supplemental scope case saved an empty restricted Files selection, verified its
server readback, and restored unrestricted scope. The inspector's detailed table
was explicitly expanded. The tag case verified a valid empty-tag baseline; it does
not claim all exclusion-tag or external-storage scenarios. Keyboard checks, small
viewport screenshots and theme rendering do not constitute screen-reader, zoom,
contrast or comprehensive accessibility acceptance.

## Failures and corrections retained

The first installer attempt ran before the image finished copying Nextcloud.
The same owned resources were resumed after HTTP readiness; no application fix
was needed. That failed log remains preserved separately.

Earlier browser attempts exposed incorrect fixture assumptions: directly clicking
Nextcloud's offscreen checkbox inputs, using the default eligible-only filter for
new users, intercepting a redirected callback without a listener, matching a generic
revoke name instead of its client-specific accessible name, reading a protected
endpoint without CSRF, and expecting a collapsed inspector table to be visible.
The fixtures were corrected and rerun; no forced clicks, application-response
mocks, skipped failed cases or security-policy changes were introduced.

One attempt reused a same-document hash navigation as if it were a fresh page.
The stale client deletion was correctly refused with **409**, with a visible conflict
message and no overwrite. A real document reload established a fresh snapshot.

Firefox reproducibly emitted Nextcloud and MCP initialization exceptions when the
test script reloaded the personal page while its document was still `interactive`.
Waiting for `load` before reloading removed those exceptions. WebKit emitted a
Nextcloud notification exception while leaving the initial dashboard with an
unfinished request; waiting for initial network idle removed that observation.
Both failed runs and stacks remain private evidence. The final
`navigationReadiness` samples were captured before the explicit `load` wait, so an
`interactive` sample does not mean the subsequent reload bypassed that wait. Final results qualify completed
navigation journeys; they do not establish robustness under every deliberately
interrupted page load. No production code was changed to address those observations.

Repeated intentional invalid-grant requests reached Nextcloud's brute-force limit:
one WebKit run received **429**, not the required **400 invalid_grant**. That run
remains failed. The supported `occ security:bruteforce:reset` command cleared only
the isolated fixture bridge IP's accumulated attempts before the final full run.
No protection was disabled or allowlisted. All three final revocation assertions
then received the required invalid-grant response.

The server log contains one level-2 cron warning for the disabled FirstRunWizard
background-job class. The final cron invocation succeeded and that warning count
remained one. There were no level-3/4 log entries in the collected file. This warning
is retained rather than presenting the entire setup as warning-free.

## Closure and remaining work

At **09:37:14 UTC on 2026-10-19**, the MCP service was disabled, the scoped relay
was terminated and both owned containers were stopped with restart policy `no`.
Ports **18080 and 18999** refused local connections. The network, data volumes,
private credentials and evidence were retained for a later authorized session;
no state or other project's resources were deleted. Retained same-host data is
not an independent recovery backup or an accepted S6 rehearsal.

The [interrupted-load investigation](2026-10-19-interrupted-page-load-investigation.md)
is now complete: no required MCP correction was identified within its tested scope.
The reproduced host/browser departure exceptions remain a documented limitation,
not a fix.

Still pending:

- Actual clients C1a–C1e/C2–C4, their available accounts/builds and interactive testers.
- Separately authorized HTTPS exposure, canonical external origin, real proxy/TLS,
  callback/streaming behavior and external-access closure checks.
- Optional-app UI combinations, broader S4/S5 topology, LDAP/federation/external
  storage and the previously proposed independent operational recovery.
- All human security/technical, operational, accessibility and language verdicts.
  All 96 documentation translations remain review-required; rendered locale checks
  do not approve German or any other translation.
- Publication curation, release version, history/provenance strategy and GitHub CI.
  No PR opening or push follows from these technical results.

## Documentation verification

The documentation review on 2026-10-19 independently matched 19 private evidence
file hashes, the archive hash and its 863-file manifest, all 65 final case results,
and the counts/failures of all 12 earlier browser attempts against the JSON record.
It also matched the server, fixture-throttle reset and closure records, and inspected
the two final browser scripts. The recorded light/dark backgrounds were checked
against `#ffffff` and `#171717`; these values were captured by the browser script,
not asserted there. This review reran neither the browser journeys nor the instance.
Historical runtime flags and pre-wait readiness samples are now explicitly labelled;
interrupted-load behavior was pending at that review; the linked follow-up now
records the investigation and its bounded correction decision.

Documentation checks:

- `composer --working-dir=mcp docs:check`: passed, 190 Markdown files and 110 package sources.
- `composer --working-dir=mcp test:docs`: 29 tests passed, none skipped.
- `composer --working-dir=mcp docs:translations`: passed provenance validation;
  all 96 translations remain `review-required`.
- REUSE 5.1.1: all 1,526 files have copyright and license information; no errors.
- `git diff --check`: passed.

Only documentation changed in the worktree. The PHP/native qualification is the
separately recorded earlier evidence; those suites were not rerun for these prose
changes. The frozen package was not rebuilt or changed.
