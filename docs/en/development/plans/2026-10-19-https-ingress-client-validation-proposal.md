<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# HTTPS ingress and automated client acceptance proposal

Status: **proposal only**. No infrastructure, runtime, account connection or
application change is authorized by this document. No push or PR opening.
ChatGPT has not been tested. Human reviewers remain unassigned/pending. The owner already accepted devbox,
short-campaign resources and operation/recovery responsibility; those decisions
need no repetition. The previous HTTPS window is closed, not a reusable live lease.

## Recommendation and evidence sufficiency

Preserve the frozen application `b96884631bf9bda245ce9895facfa8380ab08998` and
[artifact identity](2026-10-19-complete-pr-local-qualification.md). Complete a
small automated transport/client delta, then one bounded real ChatGPT journey.
Do not repeat the entire PHP/database matrix for documentation or ingress-only
changes, or require a person to perform deterministic test assertions.

| Claim | Existing evidence | Decision |
| --- | --- | --- |
| Portable implementation, static analysis and package | Clean-clone check, 7,660 PHP tests on PHP 8.2–8.5, 609 browser cases and package verification | Sufficient for this unchanged candidate; carry results forward with their identity, not as a fresh run. |
| Native Nextcloud/database behavior | 14 native matrix cells, including upgrades/replacement; bounded SQLite recovery | Strong automated evidence. Does not certify standing MariaDB recovery or independent backup custody. |
| Real settings/consent UI | Closed Nextcloud browser exercise and investigated interrupted-load errors | Bounded technical UI evidence exists. Accessibility/language verdicts remain human. |
| Basic and registered OAuth client | Inspector 2.11.0 passed local discovery, read/plan/write/readback and revocation checks | Reuse. Complete the missing negative cases and issuer-warning diagnosis below. |
| Public HTTPS and hosted ChatGPT | GitHub gateway returned HTML; route revoked; ChatGPT not exercised | Insufficient to claim either works. This is an ingress blocker, not an established application defect. |

The current evidence is sufficient to support the implementation review and local
PR draft. It is **not sufficient to close the user's agreed pre-publication
client/proxy validation**. Recommend the incremental gates P1–P4 below for this
short campaign. They do not redefine S7, certify every documented client or waive
human reviews. Claude/Gemini and standing-target scenarios remain explicitly
unexecuted; narrowing the eventual publication criteria requires the owner's
explicit decision, not a silent change to the older acceptance plan.

Tests should run without manual decisions once their fixture and access are ready.
Account authentication, MFA, account/workspace permission and consent are access
prerequisites, not test assertions. Human security, accessibility and translation
review is a separate judgment and cannot be converted into a passing test.

## Confirmed blocker and protocol boundaries

The [reviewed Inspector record](2026-10-19-inspector-client-acceptance.md) and pinned
harness `v0.9.3-ce46c0f` show that every generated preview route uses
`preview-protected`. Its GitHub forward-auth intercepts unauthenticated requests
before Nextcloud. The existing `devbox preview expose` has no application-auth mode.
Changing Nextcloud `trusted_domains` alone will not fix that gateway.

Inspect the actual challenge, not just an origin-root well-known URL. This app
advertises `/apps/mcp/.well-known/oauth-protected-resource`; its authorization
metadata lives under `/apps/mcp/.well-known/`. The earlier root-path probe only
proved gateway interception, not that root discovery is implemented or required.
See [OAuth reference](../../reference/oauth.md),
[metadata controller](../../../../mcp/lib/Controller/MetadataController.php) and
[resource URLs](../../../../mcp/lib/OAuth/ResourceUrl.php).

The [MCP controller](../../../../mcp/lib/Controller/McpController.php) returns JSON
for POST and authenticated `405` for GET/DELETE: there is no SSE stream or server
session to resume. Test preserved responses, headers, connection cancellation and
bounded timeouts. Do not require an SSE stream, invent session recovery semantics,
or downgrade protocol/authentication to make a client pass.

## Proposed topology and concrete values

```text
Inspector / ChatGPT / consent browser
    -> existing devbox TLS ingress :443, one leased exact hostname
    -> new application-auth preview policy, only for that exposure
    -> request-filtering proxy at 127.0.0.1:18081
    -> retained Nextcloud relay at 127.0.0.1:18080
    -> isolated Nextcloud + internal database network

Operator automation -> private loopback administration / occ
Nextcloud -> constrained outbound HTTPS -> chatgpt.com metadata only
```

This needs a supported devbox capability implemented in its owning project/session.
Do not edit installed `/opt` code, hand-edit generated routes, change
`preview-protected` globally, or publish through an untracked alternative tunnel.
The exact infrastructure repository must be resolved through devbox context before
that future task; this repository contains only the consumer proposal.

| Setting | Proposed value / rule |
| --- | --- |
| Public hostname | Allocate a free existing managed preview slot at execution; persist its exact exposure ID/hostname. Do not assume the historical `p01` is available or reserve DNS now. |
| Public listener | Reuse existing TLS ingress; no new public port, firewall rule or `0.0.0.0` service. |
| Policy | Explicit opt-in application authentication, ordinary previews retain GitHub protection. Machine endpoints use Nextcloud OAuth/Basic; browser login/consent uses Nextcloud sessions and CSRF protections. |
| Origin | `https://<allocated-host>/apps/mcp/` resource, issuer without its trailing slash. Keep the hostname stable for one campaign; a changed origin requires fresh authorization. |
| Loopback ports | Retain 18080; propose 18081 for the route filter and 18443 for private TLS tests. Verify availability before binding; never stop an unrelated listener. |
| Duration | Maximum 120 minutes from exposure creation, independent closure armed before publication for 110 minutes; close immediately on completion/blocker. Failure to arm closure prevents publication. |
| Additional proxy budget | Proposed cap 0.25 CPU / 128 MiB; retain the accepted Nextcloud/database limits. Record exact proxy version/image digest before execution. |
| Request budgets | Preserve MCP's 1 MiB body limit; proposed 60-second MCP-call deadline and 120-second proxy response deadline. Give the interactive OAuth journey its own bounded five-minute budget; retain the server's five-second CIMD fetch timeout. Expected timeout tests pass only on the asserted failure; unexpected timeouts fail. No unlimited retries or extension of the exposure deadline. |
| Fixtures | Dedicated synthetic account; only a named test file and its backups writable. No administrator login or real user data in the hosted client. |
| State | External private run directory, mode 0700; secrets mode 0600 or encrypted store. Sanitized results/hashes only in Git. |

Route filtering must be generated from a reviewed path/method manifest before
publication, tested locally against the actual Nextcloud login/consent dependencies:

- Permit MCP POST/GET/DELETE only at `/apps/mcp/`; retain native authentication and
  method rejection. Permit only the four existing metadata GET endpoints and
  OAuth authorize GET/POST and token POST from `mcp/appinfo/routes.php`.
- Permit the actual Nextcloud login/consent page dependencies, including required
  static assets and session endpoints, by reviewed paths/methods. Capture these
  automatically during P1. Keep the same HTTPS origin, secure cookies and CSRF.
- Deny all other paths by default, including public administration, DAV/OCS,
  configuration/data access, MCP grant-management APIs and checkout/upload URLs.
  Do not promote every background browser request into the allowlist: distinguish
  essential login/consent dependencies from optional failing requests. If an
  essential dependency conflicts with a denied category, stop for a scoped policy
  revision instead of silently broadening access. This limits the client exercise
  to Files read/edit through MCP; native DAV backup checks use the private route.
- Test normalized and encoded paths, `/index.php` variants, method changes and
  trailing slashes so another router or path spelling cannot bypass the filter.
  If login needs another route, review it explicitly; do not widen to all of `/apps`.

Use loopback administration/occ for setup and revocation. If browser administration
needs the canonical HTTPS hostname, use private browser DNS/proxy routing to a
loopback-only full-access listener, with separate operator cookies. Do not weaken
the public path policy to let an administrator navigate through it.

Configure only the retained instance's exact trusted host/proxy peer(s), HTTPS
scheme and canonical host. Verify the peer actually seen through both relay hops;
never trust all addresses or client-supplied forwarding headers. The outer proxy
must replace untrusted forwarded headers, preserve `Authorization`, `Origin`, MCP
headers and `WWW-Authenticate`, and avoid HTML error substitution or token caching.
Retain Nextcloud CSP; do not reuse an incompatible preview CSP without testing it.

Keep the database network internal. A separately bounded egress proxy must allow
only the Nextcloud process to retrieve HTTPS metadata from exact `chatgpt.com:443`
with certificate verification and controlled DNS; deny private/link-local/metadata
addresses and arbitrary CONNECT targets. The app's
[metadata fetcher](../../../../mcp/lib/OAuth/ClientMetadataFetcher.php) already
checks the host before cache lookup, disallows redirects and bounds documents;
network policy must not bypass those checks. Test the actual HTTP client's proxy
support before opening the public window. If that route fails, stop and report it,
rather than attaching the database to an Internet-enabled network.

No raw request/query/header/body logging at ingress, filter or browser tracing:
authorization codes, state, cookies, tokens and checkout URLs may be sensitive.
Retain status, elapsed time, scenario ID, redacted error and artifact hashes.

## Phases and acceptance criteria

### P0 — Prepare a reproducible delta (no exposure)

- [ ] Reuse the pinned Inspector 2.11.0 lock and frozen application archive; check
  hashes and fixture state. Record the selected proxy binary/image before use.
- [ ] Define one runner with bounded deadlines, actual exit codes, per-case JSON
  results, cleanup on failure and a nonzero exit if a required case is unexecuted.
- [ ] Preserve genuine CLI/browser traffic. Automate synthetic login/consent with
  Playwright and the actual CLI PTY; never inject successful protocol responses.

Acceptance: fixtures, identities, expected results and exact cleanup targets are
machine-verifiable; no account credentials appear in fixtures or output. A later
implementation task should integrate the runner with the unified catalog after
review, not scatter undocumented shell scripts or add harness state to this repo.
Reusable test code may be versioned; generated certificates, credentials, runtime
state and host policy remain external. Record before/after fixture configuration
and restore only changes owned by this run, without overwriting concurrent edits.

### P1 — Automated private TLS and missing client cases

- [ ] Run a loopback TLS proxy with a test-only CA trusted explicitly by Inspector
  and the test browser. Use a reserved `.test` hostname and private resolution.
  Do not disable TLS validation or modify global host trust. This does not qualify
  a public certificate, real DNS, the shared ingress or ChatGPT.
- [ ] Assert challenge-driven discovery, exact HTTPS issuer/resource/endpoints,
  forwarding integrity and rejection of hostile Origin/Host/forwarded headers.
  Verify authenticated GET/DELETE stay 405; public denied paths stay inaccessible.
- [ ] Automate real Inspector Basic/OAuth read, plan-without-write, confirm/ETag,
  readback and backup checks. Automate consent denial, wrong registered callback
  and wrong port, client disable/delete/redirect removal and fresh reconnection.
- [ ] Isolate negative controls: prove Basic succeeds immediately before each
  independent disconnect/password-revocation check, restoring a fresh fixture
  between them. Validate grant withdrawal before and after re-enable; old
  codes/tokens must not revive. Reuse existing native coverage where it already
  proves a case, and distinguish raw server probes from Inspector's local refusals.
- [ ] Reproduce the SEP-2352 issuer-stamp warning with fresh encrypted client state;
  trace provider/SDK persistence without printing credentials. Test the client
  credential store against a different issuer in a separate controlled fixture;
  report separately from the real-server acceptance runs. Do not fabricate an
  `iss` response or mutate the frozen server to satisfy a client expectation.
  Record whether the fault is client storage or server behavior;
  no suppression, protocol downgrade or assertion of safety based only on exit 3.

Acceptance: all required delta cases pass or produce a named blocking defect with
retained evidence. No unresolved issuer-isolation concern is silently waived.
A reproducible external client defect may be documented with impact/disposition,
but does not count as a passing security property.

### P2 — Add the supported ingress capability in its own scope

- [ ] Implement and test per-exposure application-auth policy in the owning devbox
  project. Store policy, target, owner, lease and exposure ID together; reconciliation
  and restart must preserve the policy and expiration, not restore expired routes.
- [ ] Test that default previews stay protected, another host cannot select this
  backend, path normalization cannot evade filtering, and expired/revoked exposure
  IDs remove only their route. A failing policy render must fail closed.
- [ ] Validate the path manifest, private administration, Nextcloud proxy settings,
  egress controls, timer and exact rollback plan before publishing anything.

Acceptance: reviewed supported command/API exists with lifecycle/revocation support;
ordinary preview authentication remains unchanged. No hypothetical CLI flags in
this document are executable instructions; the current command cannot do this.

### P3 — Bounded public Inspector qualification

P0/P1 and P2 must pass before P3. ChatGPT availability is not a prerequisite for
Inspector. Before opening a combined window, check whether P4 account access is
ready; otherwise run P3 alone and close it. Reopening requires a new recorded
window within the authorized scope, never an automatic lease renewal.

- [ ] Start the independent timer, allocate the exposure and record its exact origin.
  Confirm certificate/hostname validation using an external vantage, then verify
  metadata/challenge reach Nextcloud without a GitHub cookie.
- [ ] Repeat only transport-dependent P1 journeys with actual Inspector against
  this origin. Confirm login assets/consent, no private-origin redirects, response
  headers, denied routes and timeout/cancellation behavior through the real ingress.
- [ ] Keep Internet egress closed for registered Inspector OAuth. Test the egress
  policy itself before P4; actual ChatGPT CIMD retrieval belongs to P4 because its
  identity is obtained from that connection, not guessed in advance.

Acceptance: external HTTPS evidence names the candidate, client, route and actual
checks. If no external probe is available, mark that check unavailable. On any
blocker revoke the route and preserve the diagnosis; do not leave it open waiting
for an account owner or reviewer.

### P4 — Real ChatGPT smoke test, then closure

Current official guidance supports a public HTTPS endpoint or Secure MCP Tunnel
and requires account/workspace permission to add a custom MCP server. Inspector
success is a prerequisite, not a substitute for testing tool selection in ChatGPT.
See [Connect and test](https://developers.openai.com/apps-sdk/deploy/connect-chatgpt)
(accessed 2026-10-19). No account entitlement is inferred from “I use ChatGPT”.

For this app, select OAuth CIMD with public-client `none` and PKCE S256, as
advertised by the frozen server. Obtain the exact client metadata and callback
from the actual connection. Current OpenAI guidance supports callback-specific
identities when issuer identification is not advertised; do not hard-code the
stable callback or add an unsupported `iss` declaration to make setup pass.
See [Authentication](https://developers.openai.com/plugins/build/auth).

- [ ] Confirm the intended ChatGPT account/workspace can add a private custom MCP
  connection. The owner handles login/MFA or organization authorization if no
  authorized browser session exists; never ask for passwords, tokens or terminal work.
- [ ] Before the ChatGPT OAuth journey, enable only the validated egress path.
  Capture the connection's exact CIMD identity privately, verify the app retrieves
  and validates that document and uses its allowed callback, and retain sanitized
  outcomes. Do not substitute a predefined client for the CIMD acceptance claim.
- [ ] Use available authorized browser controls to configure and capture discovery,
  synthetic Nextcloud consent, read, edit-plan without mutation, explicitly approved
  fixture edit and readback. A conversational request is not proof of a tool call:
  record the actual tool, arguments and result, plus independent fixture readback.
- [ ] Test cancellation/denial and revoked-connection refusal, then reconnect freshly.
  Remove `chatgpt.com` from the app allowlist with a different allowed host retained
  and prove cached metadata does not preserve admission. Restore scoped settings.
- [ ] Run the unconditional closure procedure below, whether P4 succeeds, fails
  or cannot start. Removing a ChatGPT connection requires actual account access;
  if unavailable, record it pending while cutting server credentials and ingress.

Acceptance: the real hosted-client journey is recorded, or ChatGPT remains pending
with its exact access limitation. Consent/account setup may require the owner;
subsequent deterministic checks and cleanup are agent-run. If ChatGPT interaction
cannot be automated in the available session, disclose that boundary rather than
claim an API model call or synthetic client is equivalent. Human reviewers remain
pending even when every technical case passes.

## Unconditional closure and evidence accounting

Cleanup is a runner finalizer for P0/P1 and every public window, not a dependency
on reaching P4. On completion, interruption, deadline or failure:

1. Revoke the exact exposure and stop its loopback backend/filter so failure of
   route removal cannot keep Nextcloud reachable. If revocation is uncertain,
   retain the timer, report the route unresolved and verify the backend is stopped;
   never claim confirmed revocation from an attempted command alone.
2. Revoke only fixture grants/passwords, disable the fixture MCP service, restore
   scoped configuration and remove the owned egress path. Perform account-side
   ChatGPT removal when access permits; server-side cutoff must not depend on it.
3. Stop owned listeners/containers, verify installed application hashes, confirm
   loopback ports closed and exact route absence, and retain private state/evidence.
   Cancel the independent timer only after confirmed closure. A shared hostname's
   fallback page is not evidence that the project route remains active.

Keep separate results for each selected suite: P1, P3 and P4. Each required case
records pass/fail/blocked/not-run, source candidate, runtime/client versions and
sanitized evidence. A selected suite with a blocked or missing required case exits
nonzero; an unselected P4 stays pending and cannot become an aggregate pass merely
because P1/P3 passed. Expected refusals require their precise assertions and a
positive control. Human review statuses remain separate and pending.

## Alternatives and decisions

| Option | Assessment |
| --- | --- |
| Stop with current evidence | Adequate for reviewing the implementation locally; not enough for the agreed public-client claim. Requires an explicit publication-scope decision, not a pass. |
| Private TLS + supported per-exposure HTTPS policy | Recommended: automates the missing server/Inspector checks and tests the actual production-style OAuth route with ChatGPT. Requires a separately scoped infrastructure change. |
| Secure MCP Tunnel | Official private-transport alternative. Requires Platform tunnel permissions, a runtime key and workspace association. OAuth authorization-server reachability is not automatically supplied; this app hosts OAuth in Nextcloud, so the login/token route still needs proof. Does not qualify devbox public HTTPS. See [Secure MCP Tunnel](https://developers.openai.com/api/docs/guides/secure-mcp-tunnels). |
| Remove shared SSO globally or copy its cookie into Inspector | Reject: affects unrelated previews or substitutes operator browser access for client compatibility. |

Recommended next action: implement P0/P1 as an automated test delta, after approval
of this proposal. They need no user-operated assertions or public ingress. Prepare
P2 in the owning infrastructure project only when explicitly authorized there;
this session must not change its project binding or installed shared runtime.
Before P4, only account/workspace availability and secure owner authentication may
need user input. No reviewer assignment is required to execute P0/P1.

## Validation of this proposal

This is a documentation-only design, not newly executed acceptance evidence.
Documentation review corrected phase dependencies, separate call/OAuth deadlines,
issuer-test scope and unconditional cleanup. No acceptance phase was executed.
Validation repeated on 2026-10-19 passed: `docs:check` (193 Markdown files / 110 package
sources), `test:docs` (29 passed, none skipped), `docs:translations` (valid
provenance; all 96 entries remain review-required), REUSE 5.1.1 (1,531/1,531 files)
and `git diff --check`. Commands used the unified Composer scripts under `mcp/`.
Carry previous application/native results forward unchanged. Exact hostname, proxy
pin and public path manifest are execution prerequisites, not fabricated completed
values. No infrastructure, services, exposure, egress or account was changed.
