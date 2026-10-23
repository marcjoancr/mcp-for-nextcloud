<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Selected upstream 1.2.0 integration

Status: implementation in progress. The user authorized batches A-E of the
[reviewed inventory](upstream-1.2.0-inventory.md), in order, with validation before
advancing. F3 identity/version/platform decisions and human reviewers remain
pending. No push or deployment is authorized; PR #14 remains a reference.

## Inputs and scope

The fork starts at `5e86968` on `fork/main`. Source behavior is taken from upstream
`02526119b845ccc1d8399be002da3e30b5527df6`, compared with
`27da5e107ca06d92b7af9b8a80ad9c77c12ba91e`. The
[source ledger](upstream-1.2.0-source-ledger.md) records provenance and decisions.

The selected corrections and capabilities are adapted to existing module,
access, platform and MCP owners. Native linking/recipient expansion (D06),
Colima/engine management and the German README addition remain excluded.
No complete upstream 1.2.0 parity is claimed. App ID, public name, version and
existing migration IDs remain unchanged pending F3.

## Execution and gates

| Batch | Scope | State | Validation |
| --- | --- | --- | --- |
| Baseline | Existing fork runtime | Passed | 7,691 PHP tests, 65,394 assertions on PHP 8.4.27 |
| A | Existing privacy, resource-safety and compatibility fixes | Complete | 7,752 PHP tests / 66,518 assertions; focused native 4 / 60; browser 6; architecture, analyses and attribution pass |
| B | Notes partial edits and Deck deadlines | Pending | Not run |
| C | Selected Deck labels, files and dependencies | Pending | Not run |
| D | Complete optional filename policy and administration | Pending | Not run |
| E | Documentation, metadata and final qualification | Pending | Not run |

Each batch requires focused regression checks, architecture/unit tests and static
analysis before the next batch. Native tests use disposable run-owned resources;
local test instances are not a deployment. A missing native prerequisite or
unavailable external artifact is recorded as a blocker, never a passing result.
The final gate includes documentation, browser tests, packaging and attribution.

The initial baseline invocation used the wrong case for the suite name (`Unit`
instead of `unit`); empty discovery correctly failed. Running the configured
complete suite passed with no tests skipped. No tests or assertions were disabled.

## Batch A evidence

Implemented D01, F01/F02, O01, C01, M01, T01 and OBS in their existing owners.
The shared matcher lives in `Platform/Text`; dependency output remains absent.
The native Contacts prerequisite brought its pinned app provisioning and manifest
verification forward from supporting QA. No later runtime capability was enabled.

The initial native diagnostic failed at the first Contacts deletion with a
sanitized CardDAV `TypeError`. Inspection of the pinned native source showed that
`PluginManager` requires the global server container, while the composition root
supplied the app-local PSR container. The correction resolves the public
`IServerContainer` binding. Existing API fixture entries were preserved; only
source-derived interface entries were added for the five existing fixture majors.
The same native tests then passed without weakening assertions or error privacy.

| Gate | Result |
| --- | --- |
| Final complete PHP suite, including the DAV correction | 7,752 tests / 66,518 assertions; PHP 8.4.27 |
| Focused AppInfo/DAV/Contacts/API/architecture after correction | 2,490 tests / 22,309 assertions |
| Both static-analysis scopes | Passed after the correction |
| Architecture and lint | 42 tests / 15,622 assertions and 1,169 PHP files before the narrow DAV correction; focused architecture and changed-source syntax passed afterward |
| Local OAuth navigation in Chromium, Firefox and WebKit | 6 tests passed |
| NC35/MariaDB/fresh diagnostic, PHP 8.5.11 | 4 tests / 60 assertions; Contacts backup/delete and OAuth consent HTTP |
| Independent code review and scoped DAV follow-up | No actionable findings; human review remains pending |
| REUSE 6.2.0 | Passed: 1,517 files before interface fixture additions; final batch E repeats repository-wide attribution validation |

The successful native run is `mcp-it-20261023093339-77deba0f0e5f`, using filter
`ContactBackupLockTest|ConsentHttpTest`. Its production archive SHA256 is
`3ecb77e4352621b199d5ca43f9d12136070a645287d62895ecdc3b4d6e34b2a0`.
It tested the working tree based on `5e86968` with the batch A changes; the
recorded base commit alone is not the tested runtime. Sanitized evidence lives
under `mcp/build/integration/<run>/mcp-it/artifacts`. The preceding failed run
`mcp-it-20261023092053-5d9f95397900` remains a failed diagnostic. These filtered
runs do not qualify the entire native cell. Each run cleaned its own containers.

Compatibility captures were edited only for the approved schema/wording/version
changes. A missing translation was corrected. The licensing checker now accepts
multiple copyright lines while still requiring the original attribution and exact
AGPL license; positive and negative regressions passed. No valid author credit
was removed to satisfy its prior single-author assumption. Superseded incomplete
or failing test attempts are not counted as green evidence.

Real Deck attachment/tagged-alias coverage remains dependent on batch C's native
fixtures. Controlled local OAuth browser checks do not certify public HTTPS or
hosted clients.

## Remaining acceptance

F2 is not complete until every selected batch and its required gates are recorded.
F3 decisions, technical/security review, accessibility and language review remain
pending. Public HTTPS, Inspector's public journey and encrypted-storage 14/15
finding, and ChatGPT consent/tool/revocation acceptance remain unresolved. Local
unit, browser or native checks do not silently certify those external paths.
