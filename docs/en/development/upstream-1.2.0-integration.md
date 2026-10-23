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
| B | Notes partial edits and Deck deadlines | Complete | 7,810 PHP tests / 66,899 assertions; native 16 / 429; architecture, analyses and review pass |
| C | Selected Deck labels, files and dependencies | Complete | 8,002 PHP tests / 68,714 assertions; four native diagnostics, 17 tests each; analyses and review pass |
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

## Batch B evidence

Implemented N01 and D02 through the existing Notes and Deck owners. Notes adds
exclusive append/replace modes without changing the seven tool names or existing
full-content/title-only contracts. Partial confirmations bind the current content,
ETag and effect arguments with the existing opaque plan state. The operation
rereads content inside its lock-aware write boundary. Nextcloud has no content
compare-and-swap here: a competing write after that read remains a documented
race, not an atomicity guarantee.

Deck converts deadlines to UTC before native persistence and presents the account
offset in `dueAt`, while retaining day-based `duedate`, overdue and `dueBefore`.
Explicit nonexistent local times are refused; ambiguous times select the earliest
instant unless an offset disambiguates them. Date-only midnight normalization is
preserved. Unrelated edits retain existing instants; old affected deadlines require
manual correction rather than a speculative bulk shift.

Pinned Deck provisioning and dates-only native fixtures moved forward from QA to
validate D02. Contacts and Deck share one strict app manifest verifier with no
forwarding wrappers. The first native attempt failed before tests because both
callers omitted its new app-ID argument. An executable contract test reproduced
and fixed that wiring defect. The next attempt ran 16 cases with one failure: an
adapted upstream test expected `notes_read.path`, absent from the existing fork
contract. That test now verifies the published identity/content/ETag fields;
production behavior and frozen Notes read captures were preserved.

| Gate | Result |
| --- | --- |
| Final complete PHP suite including the QA corrections | 7,810 tests / 66,899 assertions; PHP 8.4.27 |
| Focused Notes/Deck/contracts | 653 tests / 8,067 assertions |
| Architecture | 42 tests / 15,789 assertions |
| PHP lint and both static-analysis scopes | Passed |
| Manifest caller regression after correction | 2 tests / 36 assertions; shell and PHP syntax passed |
| Frozen Notes compatibility after native-test correction | 6 tests / 99 assertions; PHP syntax passed |
| NC35/MariaDB/fresh diagnostic, PHP 8.5.11 | 16 tests / 429 assertions, passed |
| Independent review and scoped follow-ups | No actionable findings remain; human review remains pending |

The successful native run is `mcp-it-20261023101342-4a029eccdea6`, using filter
`NotesPartialEditTest|CardDueDatePersistenceTest`. Its production archive SHA256 is
`c84b5a39bb3e4ff5a63fe490861d79fe0f754b4ba54977d0fbc1834682229eb3`.
It tested the working tree based on `2d2ae6d` with batch B changes. Sanitized
evidence follows the artifact layout above. This is a focused diagnostic, not a
complete matrix cell. Run-owned containers and the network were cleaned.

The preceding failed diagnostics are
`mcp-it-20261023100227-9d204dfb40b9` (provisioning, no tests) and
`mcp-it-20261023101034-28078fc5d811` (16 tests / 424 assertions, one failure).
Neither is counted as passing. Existing Notes plan/result captures remain intact;
contract edits are limited to selected schemas, descriptions and guides. Batch C
capabilities were not enabled by this batch.

## Batch C evidence

Implemented D03-D05 in `Modules/Deck/Labels`, `Files` and `Dependencies`, with
seven thin tool adapters and explicit composition. The original thirteen Deck
tools remain. Subject to normal grants and app availability, seventeen tools are
available without dependency support and twenty with a verified native API.
Dependency discovery stays lazy; preview and execution recheck availability.
NC34 admits Deck >=1.18.5 and <1.19.0; NC35 admits >=1.19.0 and <1.20.0,
together with the required native members. Only the pinned versions below were
qualified; no claim covers every patch in those admission ranges.

Label edits remain incremental and board-local. File operations require current
Files read access and every alias to satisfy scope, exclusions, Guests and native
READ checks. Unlink removes only the association and verifies that association
directly after writing; loss of file visibility alone cannot establish success.
Plans explain the possible loss of the association's access/mount and the absence
of automatic recovery. Uncertain results disclose no obsolete file metadata and
do not encourage a blind retry. D06 linking and recipient expansion remain absent.

Dependency traversal is bounded to 100 readable nodes, 500 edges and depth 20;
mutations require origin EDIT and target READ, reject detectable cycles and
revalidate confirmations. Hidden or concurrently changing relations prevent any
claim of a globally acyclic graph. Native file/label/dependency adapters remain
separate from tools and presentation, with no compatibility facades.

| Gate | Result |
| --- | --- |
| Complete PHP suite before the narrow parser/comment cleanup | 8,002 tests / 68,714 assertions; PHP 8.4.27 |
| Architecture | 42 tests / 16,300 assertions |
| Localization | 5 tests / 5,624 assertions |
| PHP lint | 1,232 files |
| Both fixed static-analysis scopes | Passed, without new suppressions |
| API/parser after cleanup | 2,374 tests / 4,852 assertions |
| Cleanup syntax and whitespace | Five changed files pass syntax; tracked and all fifty new files checked for whitespace |
| Independent review | No actionable findings remain after scoped cleanup review; human review remains pending |

The cleanup removed an unused Circles parser mapping outside the selected scope,
corrected constructor PHPDoc and removed whitespace-only lines. Comparing PHP
tokens confirmed that production instructions were unchanged. New host declarations
retain official source signatures and digests. Compatibility captures add only the
four unconditional tools and the corresponding Deck guide fields; the three gated
tools have separate contracts. Existing tool objects, identity and frozen result
captures were preserved. Earlier failing/stalled capture comparisons are not
counted as passing runs.

### Native capability diagnostics

All four cells ran the following filter with seventeen tests and no PHPUnit skips:
`McpDeckLabelsTest|McpDeckCardFilesTest|McpDeckDependenciesTest|McpDeckDependencyWritesTest|GuestDeckParityTest`.
NC32/33 assert that dependency tools are absent and direct calls are refused;
NC34/35 exercise the native graph and write paths. These tests also cover labels,
file association visibility/unlinking and Guests parity across Basic, Bearer and
native WebDAV alias access. They are filtered diagnostics, not complete matrix
cells. Each run tested the working tree based on `c3b6c99` with batch C changes.

| Cell | Deck | Assertions | Run |
| --- | --- | --- | --- |
| NC32 / MariaDB / fresh | 1.16.8 | 513 | `mcp-it-20261023104653-b86c7c856abb` |
| NC33 / MariaDB / fresh | 1.17.7 | 513 | `mcp-it-20261023104920-53b9e2620428` |
| NC34 / MariaDB / fresh | 1.18.5 | 586 | `mcp-it-20261023104653-b4bdf4ee8d1d` |
| NC35 / MariaDB / fresh | 1.19.0 | 586 | `mcp-it-20261023104943-1f14fdd709be` |

Production archive SHA256 values, in the same order:

- NC32: `30b662b3ba427f183aa31a3d557bcb48a937a3288aefb75518530de6122eaf08`.
- NC33: `22db4a89e92a5a97e075ca5ebbfbc78fc9bf2529158ad15454938c851bc57003`.
- NC34: `8fb98f7a5c8106c06a46d166e72345d9e63591d5fdcd25c0a31e917adf98c73a`.
- NC35: `94056bf29b5b2517bd1f319546c9cfb0c6278d3f1a6431bfd78871c74d785e28`.

Each native invocation and collected `result.json` recorded exit 0; independent
JUnit checks confirmed no errors, failures or skips, and exact run-label checks
found no remaining containers or networks. The outer process coordinating
NC34/35 nevertheless reported exit 143 after both child results had been fully
recorded. Its cause is unconfirmed: the wrapper itself is not reported as passing.
The per-cell evidence and cleanup were verified independently. Later qualification
uses individual cell invocations to keep orchestration status separate.

## Remaining acceptance

F2 is not complete until every selected batch and its required gates are recorded.
F3 decisions, technical/security review, accessibility and language review remain
pending. Public HTTPS, Inspector's public journey and encrypted-storage 14/15
finding, and ChatGPT consent/tool/revocation acceptance remain unresolved. Local
unit, browser or native checks do not silently certify those external paths.
