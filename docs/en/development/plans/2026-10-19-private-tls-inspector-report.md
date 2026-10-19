<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Private TLS and automated Inspector execution

Status: **P0 implemented; the selected P1 local suite passed (12/12)** on
2026-10-19. This is bounded technical evidence, not closure of every P1 security
property or the overall acceptance campaign. The external Inspector registration
warning remains documented below. Public HTTPS, ChatGPT and all human verdicts
remain pending. No shared infrastructure, exposure, outbound policy, push or PR
was changed. The owner authorized this private runtime exercise after the
[reviewed proposal](2026-10-19-https-ingress-client-validation-proposal.md).

## Identity and reproduction

The installed application remains candidate
`b96884631bf9bda245ce9895facfa8380ab08998`, with **863 regular files unchanged**.
The frozen archive SHA-256 is
`de71b3cbf223764033e97fc59c3122e4c11944730dabe8b79a7f44fe9eb5d751`.
This runner/test/documentation delta is separate from that application identity.
A newly built development archive contains the revised Composer manifest; it is
not the frozen artifact exercised in Nextcloud.

The retained synthetic runtime used Nextcloud 35.0.1 / PHP 8.5.11, MariaDB
10.11.19, Node 24.21.0, Inspector 2.11.0 and the project's locked Playwright browsers.
The reviewed run `p1-e36JQG` lasted from 12:00:22 to 12:01:37 UTC. The
[sanitized evidence](../contracts/private-tls-inspector-b968846.json) records
runtime image IDs, Inspector lock hash, runner source hashes, certificate/tool
hashes, 24 CLI invocations (including eight expected nonzero refusals) and private stream
hashes. Raw records remain under
`~/.local/state/mcp-client-tests/p1-e36JQG/`; they contain credentials and OAuth
material and **must not be uploaded**. Only the curated JSON is suitable for Git.

The [runner guide](../../../../mcp/tests/ClientAcceptance/README.md) defines the
three required absolute paths and exact fixture checks. Unified commands are:

```sh
composer --working-dir=mcp test:clients:unit
composer --working-dir=mcp test:clients -- /absolute/private/fixture /absolute/inspector-install /absolute/certutil
```

`check` includes only the ten safe runner tests. The real-client command is
explicit, requires an existing stopped fixture and never starts public ingress.
The external devbox catalog is unchanged; its existing `check` delegates to Composer.
No production code or installed dependency was modified.

## Execution and assertions

| Selected case | Result and evidence boundary |
| --- | --- |
| Preflight | PASS: candidate, installed files, pinned Inspector lock, fixture ownership, stopped containers, internal network and available loopback ports. |
| Private trust | PASS: test-only CA; Firefox NSS profile and Inspector process trust it explicitly. No global trust, hosts-file change or disabled certificate verification. |
| TLS boundary | PASS: challenge-driven HTTPS metadata, issuer/resource endpoints, untrusted CA refusal, hostile Host/Origin refusal and replaced forwarding headers. Denied administration/DAV/OCS paths and path aliases remain inaccessible. |
| Basic roundtrip | PASS: genuine Inspector initialize (protocol 2025-06-18), 19 tools, Files read, plan without mutation, confirmed ETag edit, private DAV readback and backup; authenticated GET/DELETE remain 405. |
| OAuth consent | PASS: real CLI PTY, browser login/Allow, PKCE S256 and exact callback; read/plan/confirm/readback plus independent DAV content and backup checks, without protocol substitution. |
| Callback refusal | PASS: authenticated browser probes wrong host/port; Nextcloud shows a local error, no redirect or Allow action. This is server evidence, not an Inspector-generated callback variation. |
| Consent denial | PASS: browser Cancel in an actual Inspector journey returns access_denied. |
| Client withdrawal | PASS: disable/re-enable, redirect removal/restoration and deletion refuse old client state; fresh consent/reconnection succeeds between independent controls. |
| Basic disconnect | PASS: positive control before disconnect, MCP refusal while native DAV still succeeds, fresh reconnect succeeds. |
| Native password revocation | PASS with bounded native-cache semantics described below; independent positive control and final Inspector refusal. |
| Issuer diagnostic | PASS for exact extracted installed provider/storage/SDK components and synthetic issuer-keyed tokens only; registration warning remains unresolved externally. |
| Closure | PASS: scoped UI grants restored, owned custom clients and named app password removed, MCP disabled, original system configuration restored, containers stopped, ports 18080/18443/6276 closed and watchdog canceled. |

The private proxy is repository test code executed by the pinned Node runtime,
listening only on `127.0.0.1:18443`. Its path/method policy is versioned in
`mcp/tests/ClientAcceptance/policy.mjs`; it is not a policy for the shared ingress.
Optional browser requests rejected by this policy are listed in the sanitized
record. They were not promoted to allowed routes merely because a browser sent
them. Actual login and consent succeeded. A user-local extraction of Debian's
`libnss3-tools` supplied `certutil`; no system package was installed. Its package
SHA-256 was `6504101943837ed2ee0089c9d4d72d69b96db0c51aa21249e0757855905af82e`.

An independent 25-minute watchdog bounds the owned fixture runtime. All temporary
CA keys, profiles, encrypted OAuth stores and before-configuration snapshots are
private. The named synthetic file and automatic backups remain in the stopped
fixture for evidence. No operational-data deletion was needed.

## Findings and limits

### Native password revocation is not instantaneous

Native `occ user:auth-tokens:delete` removed the exact password ID and native token
listing confirmed absence. An immediate DAV request nevertheless returned **200**.
The installed Nextcloud `PublicKeyTokenProvider` defines `TOKEN_CACHE_TTL = 10`
and uses a local `authtoken_` cache; the CLI and web processes have separate local
caches. After a single eleven-second wait, DAV returned **401** and Inspector
refused the credential (exit 3). No cache flush, restart or retry loop forced this
result. It is a native Nextcloud characteristic, not an MCP fix or evidence of
immediate cutoff. Security judgment about that window remains pending.

### Inspector SEP-2352 warning has a bounded diagnosis

The exact installed Inspector provider returns preregistered client information
before issuer-bound registration. That path returns an unstamped client identifier
and the SDK emits the observed warning even after an issuer-bound registration is
saved. The diagnostic reproduces this with synthetic values. Issuer-keyed tokens
survive a memory-backend save/reload, a different issuer cannot retrieve them, and
the SDK rejects a mismatched stamped token.

This isolates the observed registration-warning path to client provider behavior;
it does **not** justify adding an invented `iss` response to the frozen server.
The warning is retained, not filtered or downgraded. The real OAuth tests use
fresh encrypted stores, but the cross-issuer component diagnostic uses in-memory
persistence. At the time of this run, encrypted-store qualification across issuers was
**pending**. The subsequent [storage integration report](2026-10-19-inspector-encrypted-storage-report.md)
now verifies real encrypted persistence in fresh processes: issuer-bound paths
pass, while preregistered confidential-client isolation fails. This follow-up does
not claim a network journey with two real authorization servers. No claim is made that every credential-store security property
passes. Future client-version qualification or an upstream Inspector disposition
is needed before treating that concern as closed. P1's broader issuer criterion
therefore remains qualified; the 12-case verdict is not a blanket security verdict.

### Runner review and failure handling

An independent automated review found three important failure-path issues:
credential creation could persist before its ID was captured, partial proxy setup
could prevent configuration restoration, and a retained watchdog could race a
later run after an early lock release. All three received failing regression tests
before correction; all eight runner checks then passed. Cleanup reconciles native
passwords and UI clients by unique run names, restores only original/owned config
values and refuses concurrent changes, and retains the lock on cleanup failure.
The watchdog additionally verifies the run identity. A stale lock must be resolved
with its exact timer/service and private snapshot before another invocation.
The pre-commit review added a source-level conflict check: parsed `$CONFIG`
values alone could miss a concurrent PHP comment or other source edit. Cleanup
now checks the exact last known file bytes and verifies the restored snapshot;
an uncertain write fails closed and retains the lock. A separate regression
rejects an already-aborted subprocess before it can start. Both tests were
observed failing before correction; the safe runner suite now has ten passing
cases. The real TLS case also asserts the challenge's resource document, all
advertised HTTPS endpoints, OIDC alias and JWKS. OAuth confirmation now checks
its backup and independent DAV content as well as the client's readback.

These automated reviews do not assign or replace any human reviewer.

## Attempt history retained

Earlier failures were runner/fixture assumptions, not successful acceptance:

| Private run | Outcome and correction |
| --- | --- |
| `p1-rV8HkZ` | Preflight rejected the fixture's existing loopback port binding; require the exact existing binding instead. |
| `p1-dZi7uX` | Internal Docker endpoint had no gateway value; derive the actual proxy peer from network IPAM and verify it. |
| `p1-GUKHKl` | Login navigation waited for complete page load; use destination DOM readiness and subsequent explicit UI assertions. |
| `p1-DdYpgj` | Spoofed Host also changed Node's TLS SNI; keep the verified real SNI while independently testing hostile HTTP Host. |
| `p1-5FbvgO` | Native password creation used the wrong CLI syntax; use the installed command's `--name` option. |
| `p1-1poa8l` | Stale administration revision correctly produced a conflict; reload before client mutation. |
| `p1-NO7wiF` | Correct callback-error expectations and actual Cancel/checkbox controls; terminate the owned waiting CLI after the browser failure. Native-cache observation prompted the bounded revocation test. Overall run failed. |
| `p1-hWlgUa` | First complete 12-case pass; pre-review source snapshot retained privately. |
| `p1-dIY5F6` | Full repeat after the first three cleanup corrections: 12/12, exit 0 and confirmed closure. |
| `p1-e36JQG` | Reviewed runner with exact configuration-file guard, cancellation regression, full metadata assertions and independent OAuth readback/backup: 12/12, exit 0 and confirmed closure. |

Missing required cases or cleanup failures return nonzero. Earlier failed attempts
remain failed; later passes do not rewrite their evidence. Subprocess timeout and
abort tests assert failure and process termination rather than treating timeouts as
successful acceptance.

## Repository validation and remaining work

Fresh validation after all runner corrections passed:

| Gate | Result |
| --- | --- |
| `composer check` | Exit 0: manifest/lock, documentation/provenance, ten runner checks, PHP syntax, both static-analysis scopes, **7,660 PHP tests / 65,230 assertions**, **609 JavaScript/browser cases**, production package build and verification. |
| Final documentation checks | **195 Markdown files / 110 package sources**, 29 tooling tests passed, none skipped; final link check repeated after the report update. |
| Translation provenance | Valid; **96 review-required**, no human approval inferred. |
| REUSE 5.1.1 | **1,546/1,546** files with copyright and license information, no errors. |
| `git diff --check` | Passed. |

The full gate log is retained privately as
`~/.local/state/mcp-client-tests/repository-check-review.log`. Build error messages
for the intentionally missing `./missing.js` belong to existing negative build
tests; their assertions passed. They were not hidden. Package output remains
inside this worktree and the frozen archive was separately hash-verified unchanged.

The unchanged candidate's earlier PHP 8.2–8.5 and 14 native matrix cells are carried
forward by identity, not claimed as newly executed. This delta changes only test
infrastructure, Composer commands and documentation; no application matrix rerun
is justified by it.

Pending work remains explicit:

- P1: encrypted issuer-bound persistence has since passed the linked storage
  qualification. The static registration warning and failed cross-issuer
  confidential-client boundary remain open external-client findings; do not
  treat the broader security criterion as closed.
- P2/P3: supported per-exposure application-auth ingress remains unavailable;
  shared GitHub forward-auth still prevents machine access. Any fix belongs in
  the infrastructure project's separately authorized scope. Real public DNS,
  certificate/ingress, external vantage and ingress timeout/cancellation remain untested.
- P4: ChatGPT account/workspace access, actual CIMD metadata/callback, bounded
  outbound policy, real tool calls and connection cleanup remain pending.
- Human security, accessibility and language review remain unassigned/pending;
  all 96 translated documentation entries remain review-required. Other hosted
  clients and standing-target/recovery obligations remain as recorded in the
  [acceptance plan](2026-10-18-upstream-1.1.0-staging-acceptance.md).

No push, PR opening or public deployment is part of this execution. Local automated
coverage is now stronger; any narrowing of the agreed publication criteria still
requires an explicit owner decision.
