<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.0 inventory and fork selection

Status: analysis and selection completed on 2026-10-23; implementation and runtime
acceptance are pending. This executes only the inventory portion of F2 in the
[fork continuation plan](fork-continuation-plan.md). It does not close F2's porting
or test criteria, authorize deployment, or claim full 1.2.0 parity.

## Immutable scope and method

| Input | Revision |
| --- | --- |
| Original 1.1.2 baseline | `27da5e107ca06d92b7af9b8a80ad9c77c12ba91e` |
| Original released 1.2.0 | `02526119b845ccc1d8399be002da3e30b5527df6` |
| Fork evaluated | `3d06e7b87a7d310b6e165231e4c2284dc452ef72` on `fork/main` |
| Frozen PR #14 reference | `14a76df26817fb8a4540eb9c9d1d0043e3e87506` |

The [release comparison](https://github.com/sintax-tech/mcp-for-nextcloud/compare/27da5e107ca06d92b7af9b8a80ad9c77c12ba91e...02526119b845ccc1d8399be002da3e30b5527df6)
contains **284 changed files**, **67 commits**, including **44 non-merge commits**
and **23 merge commits**, with 28,651 insertions and 2,100 deletions. There are
121 runtime PHP paths, 141 test/harness/fixture paths and 22 other paths.
The [source ledger](upstream-1.2.0-source-ledger.md) accounts for every changed
path and every commit, including integration commits rather than treating their
subjects as additional features.

The exact release commit was fetched by explicit original-repository URL, without
adding a remote, updating a branch or writing FETCH_HEAD. The tag/commit mapping
was verified in F0. Source inspection used the final release tree, the complete
1.1.2-to-1.2.0 delta, and the fork's existing capability owners. Upstream tests were
read as specifications; they were not executed or assumed to pass in the fork.
The later `7c5ef6d6e183cfacc5626609857631f84f7c8b2a` commit only corrects reporter
credits in CHANGELOG and remains separately identified, outside this release diff.

## Selection rules

- **Port/adapt:** selected behavior, adapted to our existing architecture with its
  regression tests; no production change has been made yet.
- **Already present:** retain the fork behavior and tests rather than copying an
  older implementation or repeating a completed refactor.
- **Defer:** explicitly excluded from this integration until the stated gate is
  resolved. Do not advertise it in manifests, discovery or guides.
- **Not applicable:** an upstream packaging/layout/history choice that does not
  fit the maintained fork; preserve its underlying attribution where applicable.

P0 below identifies privacy or resource-safety corrections in existing behavior;
P1 covers compatibility/correctness fixes; P2 adds optional capabilities. These
are implementation priorities, not CVE ratings or a claim of observed exploitation.
Prioritize the selected fixes before optional additions. No direct merge or broad
cherry-pick is selected: upstream's `Service/` and `Tools/` files would conflict
with the fork's explicit `Access/`, `Platform/`, `Mcp/` and `Modules/` ownership.

## Capability decisions

| ID / priority | Decision and upstream sources | Fork finding and intended owner |
| --- | --- | --- |
| D01 / P0 | Port Deck output privacy from `e0a6b39` and its reference-boundary tests | `Modules/Deck/Presentation/DeckFormatter.php` still emits `attachmentCount`. Remove it deliberately from every producing path. The fork does not yet serialize dependency relations; preserve that whitelist in batch A and add filtered relation output only with D05 in batch C. Preserve existing scoped Talk card references. |
| F01 / P0 | Port reported-size preflight from `35683b5` | `Modules/Files/Editing/TextReplacer.php` buffers before checking the size. Check finite, nonnegative reported size against the existing 20 MiB read limit before reading, then check returned bytes, in preview and confirmation. |
| F02 / P0 | Port bounded overlap-aware matching from `4909e05`; retain cleanup `43c1657` | Current `substr_count` misses overlapping ambiguity. Apply the 64 KiB byte limit to `old`, match overlaps and stop once ambiguity is established; never expose a match count. Share the algorithm with Notes through a focused proposed `Platform/Text` owner, not a new generic Common directory. |
| O01 / P1 | Port OAuth consent navigation from `5cb19f1` | `Controller/OAuthController.php` still returns a redirect after consent POST and expands form-action. Adapt its response to a fresh blank navigation document and escaped fallback link in `templates/oauth-redirect.php`, retaining request revalidation and the current OAuth services. |
| C01 / P1 | Port contact backup lock bound from `9cd158d` | `Modules/Contacts/Deletion/ContactBackup.php` constructs an 83-character key for a 64-character database column. Introduce a narrowly owned key builder with the prefix included in the limit, preserving verified backup-before-delete and release in all paths. |
| M01 / P1 | Port installed-app identity from `aa7a30d` | `Mcp/Protocol/ProtocolMetadata.php` hardcodes 1.1.2. Introduce one injected server identity provider backed by cached `IAppManager::getAppVersion('mcp')`; use it in protocol, tool, prompt and resource responses. Do not restore upstream's monolithic protocol service or choose a new product name in F2. |
| T01 / P1 | Port empty category-filter explanation from `5f67546`; retain existing decomposition instead of `ab905c0` | `Modules/Tasks/Reading/TaskLister.php` already filters before pagination and `TaskRepository::readable()` already hides protected rows. Add the no-match note and literal-filter schema wording in `Tools/ListTasks.php`, using a focused presentation helper. |
| N01 / P2 | Port partial Notes edits from `4909e05` | Extend `Modules/Notes/Tools/EditNote.php` and `Editing/NoteEditor.php` through focused planning/confirmation helpers, retaining whole-content and title-only behavior. Share F02's bounded matcher. |
| D02 / P1 then P2 | Port date-only UTC correction `ec2f276`, then time-of-day support `144945b` | Adapt `Modules/Deck/Cards` input/write ownership and presentation. Correct date-only persistence first; then add offset/local-time inputs and `dueAt` output while keeping day-based `duedate`, overdue and `dueBefore`. |
| D03 / P2 | Port label discovery and incremental edits `e0a6b39`, `a007fe8` | Add capability-owned `Modules/Deck/Labels` operations and two tool adapters; preserve unrelated labels and reject foreign-board labels. |
| D04 / P2 | Port visible card-file listing/unlink from `fb39797`, plus alias/Guests tests `704bdce`, `b9b131e` | Add `Modules/Deck/Files` operations. Use explicit Files visibility access rather than treating Deck access as Files access. Unlink removes an association only, never the original file. |
| D05 / P2 | Port dependency read/change `7ce8ee2`, `f4609bf` | Add `Modules/Deck/Dependencies` operations and a native API capability check. Advertise and execute only on verified compatible NC34/35 Deck APIs; no synthetic fallback graph. |
| D06 / deferred | Defer new native file linking and recipient expansion `7fef90b`, `da7ff40` | `deck_link_card_file` creates read-only access for current and future board/group/team members. Creation-time checks do not continuously enforce later scope/policy changes. No sharing-audience acceptance exists for this fork; do not register the tool, its write path or link-only recipient infrastructure yet. |
| W01 / P2, complete unit | Port optional filename policy `42a44f7`, `772f81f`, `18fe511`, `2f90d5f`, `a55d554`, `5a88b32`, `29b1761`, with corrections `3ceabe2`, `ecba970`, `e41cafd`, `5822966` | Add a proposed `Access/FileWritePolicy` owner for configuration/decisions and `Platform/FileAccess` boundary primitives, consumed by existing Files and Notes operations. Complete the sink coverage before exposing an enabled policy. |
| OBS / P1 | Port narrow expected-refusal logging from `55c23d4` | Adapt Deck integration failures and `Modules/Files/Sharing/ShareWriter.php` / `ShareRemover.php`; only proven expected refusals or AlreadyShared cases move to debug. Uncertain/partial writes and unexpected failures keep warnings/errors. |
| QA / supporting | Adapt native pins/provisioning `d526e2f`, `229a1c8`, API/parser and selected feature tests | Extend existing test owners and the fork's disposable matrix. Preserve our full-history, recovery, isolation and redaction behavior; tests are selected by adopted behavior, not copied by directory. |
| QQUEUE / deferred | Defer engine queue/Colima runner `b909649` | The fork uses a shared Docker daemon and run-owned labeled resources. Do not add Colima startup/shutdown, shared engine control or its external state layout. No current measured contention justifies changing this topology. |
| DOC / mixed | Adapt matching guide/messages/translation changes `97752bd`, `55c23d4`, `31720e1`; retain our canonical docs layout | Existing Spanish README and corrected Deck move guide are already present. Preserve Catalan and European Portuguese. German README expansion is deferred pending locale scope/review; upstream README/HISTORY layout replacement is not applicable. |
| META / mixed | Adapt feature descriptions, tool titles and tests only after the matching ports; defer upstream version bumps `6fb38e4`, `0252611` | Update `Mcp/Tools/Discovery` and module definitions through their current contracts. Keep app ID and migration history. F3 owns the independent fork release number; copying 1.2.0 or RC metadata is not selected. |

Commit IDs identify source provenance, not instructions to cherry-pick. The final
release tree includes later fixes and merge resolutions; port that resolved behavior.
A shared test or fixture can support multiple IDs, including a deferred feature.
Select only the required surface and retain explicit provenance.

## Contract and security acceptance details

### Existing behavior fixes

D01 intentionally removes a response field: native `attachmentCount` can include
files outside the caller's scope or hidden by tags. Update exact response-shape
assertions and release notes explicitly; do not regenerate all compatibility
captures to conceal this security-motivated difference. Add two-user and hidden
alias tests across every card producer. Keep dependency IDs absent in batch A;
D05 must introduce its reference filter and any corresponding output together,
including board blueprints and create/edit/read/list/follow-up paths. This avoids
adding unused filter infrastructure or incorrectly describing an existing
dependency-ID disclosure. The Talk card-link payload uses ID, board
ID and title, not this count. Cross-board card moves and the fork's move guide are
already correct; no movement implementation port is selected.

F01 is a preflight and returned-size check, not a hard streaming cap against a
storage provider that understates its size. Preserve backups, locks, exclusion
and scope checks around replacement. F02 must reject overlapping cases such as
`ana` in `banana`, missing and empty snippets, invalid UTF-8, oversized byte
sequences and stale confirmation. JSON Schema maxLength counts characters;
enforce the 64 KiB byte limit again at the operation boundary for multibyte input. Benchmark/pathological tests should retain the upstream linear search
rationale instead of adding repeated unbounded substring scans.

O01 must keep exact registered callback validation, state/issuer/PKCE behavior,
single-use pending sessions and consent revalidation. The navigation page needs
no-store/no-cache and no-referrer headers, escaped targets and a strict CSP; do
not broaden form-action to unknown downstream origins. Cover Allow, Deny,
temporarily-unavailable, expired/replayed requests and malicious callback input.
Adapt the real HTTP consent fixture and add browser redirect-chain coverage using
local controlled destinations. This does not certify ChatGPT, Mistral or public
HTTPS; no hosted-client connection is executed during this inventory.

C01 must prove a second deletion in the same address book succeeds with database
locking and no Redis, while backup failure still prevents deletion. Retain user
and folder separation, deterministic keys, release-on-failure and full vCard
read-back. Existing native data remains unchanged; no database schema migration
is selected for this correction.

M01 needs a sentinel installed version different from the manifest/test constants
and coverage of legacy initialize plus every modern result metadata path,
including prompt/resource responses split into separate fork classes. Retain
protocol-version negotiation and the diagnostic tool contract.

T01 adds a note only when a completed visible scan has zero matches for the
supplied category, not merely when an offset produces an empty page. Preserve
case-insensitive literal comparison, symbol-only category validity, hidden-row
indistinguishability and pagination. Explain whether completed tasks were searched
without disclosing their existence or enumerating other categories. Preserve the
fork's output filtering for task file references.

### Selected new capabilities

N01 permits mutually exclusive full `content`, `append`, or paired `old`/`new`
modes. Partial modes require ETags and an opaque plan-state binding to content and
effect-changing arguments; reread and recompute at confirmation to catch changed
content even with an unchanged ETag. Preserve line endings, existing title-only
writes, shared confirmations, lock rules and explicit partial success if a later
rename fails. Add schemas and plan presentation through existing tool/operation
owners, not a larger NoteEditor with every responsibility.

D02 must cover UTC conversion, positive/negative offsets, DST gaps and ambiguous
local times, create/edit/read/list round trips and unchanged day-based filters.
Preserve the precise upstream time contract: offset-free values use the account
timezone, explicit local times in DST gaps are refused, and repeated local times
select the earliest instant unless an offset disambiguates them. Date-only input
retains native midnight normalization; do not apply the explicit-time gap rule to
that existing mode. Do not automatically shift old deadlines: an existing stored
instant does not reliably identify the intended date. Document manual correction of affected dates.

D03 keeps labels board-local, bounded and incremental, checks preview/confirmation
state, preserves other labels and reports partial/uncertain outcomes without blind
retry. D04 exposes only visible native file associations; omit hidden IDs, counts,
legacy attachment entries and folders. Unlink requires Deck edit and Files read,
current association/permission revalidation and the relevant confirmation/state
receipt; do not require Files share solely to unlink and never delete the file.
Unlink can remove another editor's association and the access/mount provided by
that association, including the caller's own last mount. Show that consequence
and the lack of automatic recovery in the plan; retain shared-board confirmation.
Distinguish the share/attachment ID from the original file ID, validate its exact
card/type binding and verify the raw association after writing. A file becoming
unreadable is not proof that the association disappeared. Do not echo obsolete
file metadata or claim safe retries after an uncertain result. Recovery may need
the native Deck UI while D06 remains excluded. Hidden and missing associations
share a generic refusal. A folder-specific refusal is permitted only after its
visibility is established; retain the corrected native regression from `d43c0f9`.

D05 hides unsupported tools rather than returning a misleading empty graph. Use
verified native API shapes/versions: NC34 requires Deck >=1.18.5 and <1.19.0;
NC35 requires >=1.19.0 and <1.20.0, plus all required native methods/properties.
These are upstream admission ranges, not evidence that every patch was tested.
The pinned versions are qualification targets; NC32/33 and unknown combinations
must keep the three dependency tools unavailable. Keep probes lazy, without
instantiating Deck services during discovery, and recheck capability at preview
and execution so cached client catalogs cannot bypass it. App availability,
per-user Guests restrictions and grants still apply independently. Integrate
filtering into the existing Deck catalog/module boundary and shared tool guide,
without weakening the catalog/factory consistency contract. Bound graph traversal
(100 nodes, 500 edges, depth 20), filter unreadable relations, reject incomplete validation and detect
self/cyclic additions. Require edit permission on the origin and read permission
on the target, fresh plan-state/shared-board confirmation and read-back of the
actual result. Test grant revocation, archived parents, stale plans and uncertain
writes as well as graph traversal. An accessible bounded graph is not proof that
no hidden or concurrently changing dependency can exist. Preserve honest limitations.

D06 is deliberately deferred, including its link-only recipient fixtures/tests.
Reconsider only with an approved sharing-audience contract, documented future
membership/revocation behavior, tests for guest/unknown/remote recipients,
restricted groups/teams, share permissions and expiry, and a native race review.
Do not import dormant link handlers as transition code. D04 remains useful without
D06. A release that omits D06 must not claim complete upstream 1.2.0 functionality.

### Optional file-write policy as one complete capability

W01 introduces app-config key `file_write_policy`, version 1, fields `mode`
(`off` or `allowlist`), `extensions` and `allowExtensionless`, with at most 128
normalized extension entries. Missing configuration intentionally means off on
new installations and upgrades. Present-but-invalid, unreadable, lazy/wrongly
typed or malformed state fails closed for guarded writes; never convert those
errors to an off policy. Preserve verified read-back and transaction ownership
when repairing a wrongly typed key; do not commit a caller's transaction.

The selected endpoint contract is admin-only, CSRF-protected
`GET/PUT /api/file-write-policy`. Preserve explicit 400 invalid-field and 503
unavailable responses without backend details. Put its UI under the existing
`mcp/src/admin/protection/` ownership with focused state/view functions and the
current lifecycle/HTTP helpers; build it into the existing administration bundle.
Do not copy the standalone `mcp/js/admin-file-write-policy.js` script into source
or create a third production entry. Keep uncertain-save, stale response and retry
semantics consistent with our existing administration controls.

Enforce the final resolved filename at preview and freshly at the mutation
boundary for create/upload/checkout, edit/replace, version restore, file and folder
copy/move, move batches/undo, and Notes create/edit/move including partial edits.
Use one snapshot for the projected sequence, then recheck at the sink; retain
existing native permissions, Guests restrictions, scope, exclusions, locks and
confirmation checks. Policy never grants access. Administrator writes are not
exempt. Only mandatory internal file/contact backups are exempt, through internal
ownership that cannot be requested as a tool argument.

An enabled folder copy uses bounded preflight and a validated list, checks each
entry again, omits later additions, preserves normal file-copy receipts and
reports partial/vanished-item outcomes without deleting partial data. Folder move
is still a native operation after preflight: an inserted file can race into the
move. Do not claim atomic type enforcement. Undo follows the current policy and
retains its data/receipt when blocked. The policy checks names/extensions, not MIME
or content; it does not eliminate prompt injection.

Port and adapt the write-sink inventory, including internal backups, Notes,
checkout and alias paths, to prove no covered mutation bypasses the guard. Do not
switch on a partially integrated policy or ship a stub guard. A future requirement
for MIME scanning or stronger transactional enforcement is a new design, not an
unannounced promise of this port.

## Supporting architecture, tooling and documentation

The existing Tasks listing/repository split, Files checkout writer and capability
catalogs already satisfy the upstream handler-extraction intent. Retain them;
`ab905c0`, `fc28ab9`, `725773f` and the structural part of `ecba970` are not reasons
to recreate old `Service/`, `Tools/Common` or a forwarding compatibility layer.

For QA, adapt the official Deck matrix (NC32/Deck1.16.8, NC33/1.17.7,
NC34/1.18.5, NC35/1.19.0) and Contacts provisioning (NC32/8.3.21,
NC33-35/8.9.2) with immutable archive hashes and manifest checks. Preserve existing
container labels, unique credentials, network isolation, cleanup ownership,
coordinated recovery and redaction. The queue/runner replacement is deferred;
extract selected provisioning behavior into our harness rather than substituting
upstream's host management scripts.

Adapt API parsing for files with multiple declarations by selecting the requested
class. Apply new API surfaces/provenance only when their consumer is selected.
Circles/recipient-only surfaces and their generation helpers belong to deferred
D06; do not inflate the fork's supported API claims by importing unused fixtures.
Retain current analysis scopes, PHP 8.2-8.5 test compatibility and native major
fixtures; run the contract tests for supported and unsupported capability paths.

Map new messages/titles into `Mcp/Tools/Discovery` and the current module
presentation owners. Update canonical English docs, app descriptions and localized
UI only for implemented behavior. Preserve Spanish/Catalan/Brazilian/European
Portuguese documentation provenance and review-required labels; upstream Spanish
README is not a replacement for ours. German README introduction is deferred,
while selected German UI additions remain in scope with human review pending.
Keep accurate historical contributor credits (including XERJ) in the existing
credit/history ownership; reproducing an upstream badge or duplicate HISTORY
files is not required to preserve attribution. Do not import competing manuals.

## Dependency, migration and license impact

The release diff changes no Composer manifest/lock, project license/NOTICE,
REUSE configuration, CI workflow, or database migration file. All 11 existing
migration files are unchanged. There is no new packaged runtime dependency or
schema migration selected by this inventory. New test app downloads and API
fixtures still require pinned provenance and independent license review.

W01's new app-config value does not justify renumbering migrations. Dynamic
MCP server identity is selected; changing `info.xml` to upstream 1.2.0, publishing
an RC, or accepting the fork's proposed 2.0.0 version remains outside F2.
Retain original Sintax/Jhonatan SPDX attribution on adapted source and tests,
applicable generated-source notices, our contributor notices and the visible
NOTICE requirements. Source-offer implementation and public identity remain F3.

Calendar/DAV, People, Logs and Talk have no runtime changes in this release delta;
Talk's translation contract test changes with shared messages. Preserve our prior
Guests/Files/Talk alias hardening and stronger access guards while adapting new
cross-capability consumers. Unchanged 1.1.2 behavior is not reimplemented merely
to match upstream class names.

## Implementation order for a later authorization

| Batch | Selected work | Acceptance before continuing |
| --- | --- | --- |
| A | D01, F01, F02, C01, O01, M01, T01, OBS | Focused privacy, size, ambiguity, locking, OAuth, metadata, task-page and logging regressions pass; public shape changes explicitly reviewed; all existing architecture and unit checks pass |
| B | N01 and D02 | ETag/plan-state/partial-result and timezone round-trip tests pass, including existing modes and date-only compatibility |
| C | D03, D04, D05 with required QA surfaces | Discovery gating, visibility/Guests aliases, incremental writes, native graph/file semantics and supported Deck matrix tests pass; no D06 write path is registered |
| D | W01 core, sink inventory, routes/UI, then every Files/Notes sink | Coverage inventory has no unexplained sink; off-mode compatibility and unavailable-state refusal verified; replay/undo/copy/move/race/partial-result tests pass before enabling |
| E | Selected DOC/META changes and final QA reconciliation | Only implemented features appear in tools, manifest and guides; source provenance, translations, API contracts, browser suite, complete Composer check, package and REUSE validation pass; native qualification covers all changed integration boundaries |

D03-D05 select seven new tool names: `deck_list_labels`,
`deck_edit_card_labels`, `deck_list_card_files`, `deck_unlink_card_file`,
`deck_read_card_dependencies`, `deck_add_card_dependency` and
`deck_remove_card_dependency`. The last three are conditionally available as
specified above. Keep all 13 existing Deck tools and their names; the eighth
upstream addition, `deck_link_card_file`, is deferred. Test discovery and direct
calls for missing grants, disabled apps and unsupported versions, including the
Files read requirement for D04. Do not translate discovery filtering into a new
permission grant or authorize calls merely because a tool was listed earlier.

The batches are ordered for risk and dependencies, not separate PRs to the original
repository. Within a batch, add/port meaningful regressions and validate each
capability before advancing. Keep docs alongside implemented changes rather than
leaving misleading descriptions until the last batch. Do not weaken tests or
regenerate broad snapshots; review precise contract deltas. Preserve commit
provenance and remove no existing behavior without an explicit recorded reason.

F2 can close only after the selected ports and their tests actually pass. F3
identity/version/platform decisions and all human reviewer assignments remain
pending. Public HTTPS, Inspector's outstanding public journey and encrypted-store
14/15 finding, and ChatGPT consent/tool/revocation acceptance remain explicit;
this source analysis does not resolve them. No runtime tests for 1.2.0 were run,
no original-project PR was updated, and no push or deployment is authorized.

## Review and validation of this inventory

The documentation review rechecked the pinned source contracts, the fork's card
response whitelist, module discovery/dispatch and unchanged dependency/migration
inputs. It clarified existing versus newly introduced Deck reference output,
exact date and native API compatibility semantics, unlink access-loss/read-back
behavior, and byte-versus-character snippet limits. Every non-merge source commit
now has an explicit disposition in the ledger; mixed files remain subject to the
capability decisions rather than blanket import. No source behavior was ported.
F3 decisions and human reviewers remain pending; this agent review is not their
approval.

Recorded on 2026-10-23 with Node 24.21.0 and the existing locked toolchain:

| Check | Result |
| --- | --- |
| `composer --working-dir=mcp docs:check` | Passed: 147 Markdown files, 110 operational package sources |
| `composer --working-dir=mcp docs:translations` | Passed provenance checks; all 96 existing translations remain review-required |
| `composer --working-dir=mcp test:docs` | Passed: 29 tests, zero failures/skips, including documentation staging and archive safety contracts |
| Source ledger coverage | Exact ordered match against `git diff --name-status` and `git rev-list --reverse` for the pinned range: 284 distinct paths, 67 commits |
| `git diff --check` | Passed |
| Scope | Reviewed input is `3d06e7b` on `fork/main`; the documentation commit changes no `mcp/` or CI workflow path; only `origin` is configured |

During initial inventory validation, the restricted test invocation failed
before useful suite execution;
a direct diagnostic exposed `EROFS` while creating fixtures under `mcp/build`.
The same suite passed with authorized worktree writes. No test, environment guard
or assertion was disabled to obtain that result. These are documentation/tooling
checks only: no production package was rebuilt, no native integration or runtime
suite was executed for the selected 1.2.0 behavior, and none is claimed to pass.
