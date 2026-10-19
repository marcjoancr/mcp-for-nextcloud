<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# MCP Inspector session and HTTPS ingress blocker

Status: **local real-client checks passed within the scope below; HTTPS MCP
acceptance is blocked by devbox's GitHub sign-in gateway**. No C3/C4 or S3 closure
is claimed. ChatGPT is the user's selected next hosted client; it was not tested.
All human reviews remain pending.

The user authorized a temporary HTTPS window of at most two hours and the outbound
access needed for OAuth, starting with MCP Inspector. The tested application is
still `b96884631bf9bda245ce9895facfa8380ab08998`, with the same 863-file archive and
Nextcloud 35.0.1 baseline as the [closed UI exercise](2026-10-19-closed-devbox-ui-acceptance.md).
Documentation started at `b478587`. No application source, bundle or shared proxy
configuration was changed.

## Client and evidence

MCP Inspector **2.11.0** was installed with an exact npm pin and lockfile under
`/home/dev/.local/share/mcp-for-nextcloud-clients/inspector-2.11.0/`, outside the
repository. Node is **24.21.0**. The installed client's CLI was inspected directly;
its negotiated MCP protocol was **2025-06-18**. The npm production-dependency audit
reported zero vulnerabilities at execution time; that does not replace security
review or certify the client.

The [structured record](../contracts/inspector-client-acceptance-b968846.json)
contains the package integrity, lock and evidence hashes, actual CLI exit codes,
setup failures, acceptance limits and closure. Private artifacts remain in the
original run's `inspector-session/` directory. Raw OAuth output contains transient
credentials/authorization URLs and must not be published. Configs, passwords and
session stores are excluded from Git; only hashes of selected evidence are recorded.
The successful OAuth run used a pseudo-terminal, which merged the CLI streams into
`oauth-pty.stdout`. Its wrapper received no separate stderr data: the recorded
stderr hash is the empty-string hash and no `oauth-pty.stderr` file was created.
It does not establish that the CLI emitted no diagnostic messages.

## HTTPS: actual result and boundary

The exposure was created with the supported `devbox preview expose` command at
**10:28:40 UTC on 2026-10-19**. An independent user-systemd closure timer was armed
before publication with a **110-minute deadline**; devbox's default eight-hour
lease was not used as the two-hour safety bound. Its ID was retained for exact revocation.

HTTPS requests with certificate verification succeeded at the transport layer,
but `/apps/mcp/` and `/.well-known/oauth-authorization-server` both returned **401
HTML containing the GitHub login page**, with no MCP `WWW-Authenticate` challenge.
Actual Inspector `initialize` exited **3 / auth_required** against that route.
These are failed/blocked MCP admission observations, not successful OAuth checks.

The inspected devbox route unconditionally applies `preview-protected`, whose
forward-auth middleware requires a GitHub browser session before forwarding to
Nextcloud. Traefik access logging is not configured and the authentication proxy's
request logging is disabled; this probe sent no application credentials. No
cookie was copied from an operator session into Inspector and no middleware was
removed or bypassed.

The exposure was revoked immediately after confirming the blocker: the successful
revocation response was saved at **10:29:35 UTC**, approximately **55 seconds** after
creation. The URL is historical evidence, not an invitation to use an active
Nextcloud preview. Devbox may still serve its shared login/fallback page on that
hostname after revocation; that does not imply a project route remains active.

Canonical external origin, forwarded headers, OAuth metadata through HTTPS,
Authorization forwarding, streaming and proxy timeout behavior remain unqualified.
Configuring Nextcloud trust or changing MCP OAuth cannot remove this earlier gateway.

The existing internal Docker network remained internal. No egress was added:
Inspector's registered client and loopback callback did not require an Internet
fetch. Authorization to add necessary OAuth egress remains recorded, but hosted
client identity-document retrieval has not been exercised.

## Local real-client observations

After revoking HTTPS, Inspector used `http://127.0.0.1:18080/apps/mcp/`. The actual
Inspector CLI performed MCP requests; the existing browser automation handled the
synthetic Nextcloud UI and consent. No mock OAuth server, token injection in place
of Inspector's authorization flow, or API-response replacement was used.

| Case | Observation |
| --- | --- |
| Basic initialization and discovery | Success; MCP 2025-06-18 and 19 tools for the synthetic user's permissions. |
| Basic read and edit | Read the fixture, obtained a confirmation plan, verified the plan left content unchanged, confirmed with its ETag, and read the changed content back. |
| Backup | Supplementary native WebDAV read verified that the returned backup contained the original file. |
| Registered OAuth | Inspector's real interactive CLI used PKCE S256, the registered `http://127.0.0.1:6276/oauth/callback`, browser consent and tool discovery. |
| OAuth read and edit | The Inspector-obtained credential read the changed fixture, planned another edit, confirmed with its ETag and read the new content back. |
| Unavailable operation | `files_mkdir` was absent from the advertised tools. Inspector refused it with exit 5 / `tool_not_found`. This is a client-side refusal, not proof of a raw server-side authorization check. |
| OAuth revocation | After revocation in personal settings, Inspector's refresh received `invalid_grant` / “Invalid refresh token”, then exited 3 / `auth_required`; interactive recovery was disabled for this check. |
| Personal disconnect | Inspector Basic initialization was refused, while a native DAV read with the same app password still returned 200. |
| Native app-password revocation | Deleted only the named fixture token. Native DAV changed from 200 to 401, independently verifying revocation. Inspector remained refused after the earlier personal disconnect; that refusal alone cannot isolate password revocation. |

Fixture writes were confined to `/Inspector-fixture.txt` and its automatic backups
in the synthetic `ui-firefox` account. The registered client, connection, permission
and service changes used Nextcloud's actual UI. The app password was created and
revoked with Nextcloud's native commands, using private input rather than secret
arguments. No real user data, notification recipient or external account was used.

These local checks contribute evidence toward C3/C4, but do not satisfy their
required HTTPS route or the complete callback/client-withdrawal matrix. No conclusion
about ChatGPT or any Claude/Gemini surface follows from Inspector's success.

## Diagnostics retained and follow-up

- The first app-password parser assumed a single output line. Nextcloud emits a
  label and then the token; extraction was corrected using its installed command
  source, reusing the same token without displaying it.
- Two CLI attempts exited 1 because `MCP_CATALOG_PATH` and `--config` are mutually
  exclusive. The runner then removed that inherited catalog selection while
  retaining explicit isolated storage/config paths. Both failures remain evidence.
- The first OAuth attempt was refused because Inspector requires an interactive
  terminal. The next attempt ran the actual CLI in a pseudo-terminal; browser
  automation followed its authorization URL and consent, and the flow succeeded.
- Basic runs reported that the OS keychain was unavailable and a mode-0600 private
  file store would be used. OAuth was subsequently configured with an encrypted
  mode-0600 file store and a separate private key. The warning is not omitted from
  the record; these local stores are not independent recovery backups.
- During revoked-credential recovery, the Inspector SDK emitted a **SEP-2352 missing
  issuer-stamp warning**, stating that issuer isolation was inactive for that read.
  The refresh was nevertheless refused. This is an unresolved client/SDK storage
  interoperability observation, not proof of an authorization bypass or a server
  defect. Issuer-isolation behavior must be investigated before claiming that
  security property qualified. No warning filter or protocol downgrade was added.

Next work is a concrete devbox ingress design that lets an MCP client reach
Nextcloud's discovery/authentication endpoints without requiring an unrelated
GitHub browser session first, while preserving protection for other projects and
an operator-only route. The current preview command offers no such mode. The
user has already authorized the short HTTPS campaign: this blocker is a capability
of the shared ingress, not missing authorization for the attempted window. Changes
to that shared mechanism need their proper project/configuration scope; this session
did not edit it or create an alternative tunnel.

After resolving that route, repeat Inspector through the real HTTPS origin and
complete missing C3/C4 negative cases. Then confirm the user's ChatGPT surface and
custom-MCP feature availability and perform that real hosted-client flow. Its
CIMD discovery, if required by the selected integration, will need the authorized
outbound path; the test network is still closed. The Inspector issuer-stamp warning also needs a focused follow-up.

## Closure and remaining acceptance

At **10:38:22 UTC**, the project preview inventory was empty, MCP was disabled,
the synthetic OAuth client and connection were removed/revoked, the synthetic user
was disconnected and its fixture app password was revoked. The temporary Files
edit grant was removed. Both owned containers and the relay were stopped; ports
18080, 18999 and 6276 were closed. All **863 installed application files** still
matched the frozen manifest. The closure timer was cancelled only after manual
closure was verified. Volumes, synthetic files/backups and private evidence were
retained.

External clients, HTTPS/proxy acceptance, the remaining S3 matrix, optional-app
combinations, independent recovery and every human technical/security, operational,
accessibility and language verdict remain pending. All 96 documentation translations
remain review-required. No push, PR opening or message to an external maintainer
occurred.

The documentation review rechecked the recorded evidence hashes and CLI results,
the client pin/lock and recorded audit, OAuth capture semantics, HTTPS exposure and
revocation, and plan/readback observations. A read-only runtime check confirmed an
empty project preview inventory, both containers stopped with restart disabled,
the internal network unchanged and all three loopback ports closed. No exposure
or client exercise was restarted.

Documentation validation was repeated after review:

- `composer --working-dir=mcp docs:check`: passed, 192 Markdown files and 110 package sources.
- `composer --working-dir=mcp test:docs`: 29 tests passed, none skipped.
- `composer --working-dir=mcp docs:translations`: provenance valid; all 96 entries
  remain `review-required`.
- REUSE 5.1.1: copyright and license information present for all 1,530 files.
- `git diff --check`: passed.

The CLI results were inspected semantically as well as by exit code: a confirmation
plan is not a write, an unavailable tool is not a server authorization probe, and
an `auth_required` response from the GitHub gateway is not Nextcloud OAuth evidence.
The frozen package was not rebuilt and the earlier PHP/native qualification was
not rerun for these documentation-only changes.
