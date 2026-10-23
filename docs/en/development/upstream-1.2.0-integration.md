<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Selected upstream 1.2.0 integration

Status: selected integration completed locally on 2026-10-23. Batches A-E of the
[reviewed inventory](upstream-1.2.0-inventory.md) were implemented in order, with
validation before advancing and final qualification recorded below. F3
identity/version/platform decisions and human reviewers remain pending.
No push or deployment is authorized; PR #14 remains a reference.

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
| D | Complete optional filename policy and administration | Complete | 8,151 PHP tests / 69,862 assertions; native 20 / 270 in MariaDB and SQLite; recovery, analyses and review pass |
| E | Documentation, metadata and final qualification | Complete locally | Final PHP suite 8,174 / 69,939; 687 browser cases; 16 unfiltered native cells; interpreter, analysis, documentation, package, attribution and agent review gates pass |

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

## Batch D evidence

Implemented the complete W01 capability with required `Access/FileWritePolicy`
and `Platform/FileAccess/Policy` collaborators, existing Files/Notes consumers,
and the existing administration bundle. The
[write-sink inventory](file-write-policy-sinks.md) maps every public mutation and
controlled internal exemption. No nullable guard, public bypass argument,
container fallback, legacy alias or third JavaScript entry was introduced.

Only absent configuration means off. Invalid, unreadable, wrongly typed and lazy
state blocks covered writes; wrong-type repair owns its transaction and verifies
read-back. The administrative GET/PUT endpoint retains admin and CSRF checks,
explicit field errors and sanitized unavailable responses. The UI distinguishes
saved state, preserved unsaved input and uncertain outcomes, including late replies.
The policy remains off by default; tests enabled it only inside their fixtures.

The pinned native AppConfig source exposed a subtle distinction: an all-key
presence check loads lazy values, and a subsequent non-lazy string read can still
return that cached value. A regression first reproduced acceptance of a canonical
lazy policy; explicit non-lazy membership now makes that state unavailable. The
native recovery scenario verifies rejection and administrator repair. Cache
invalidation/rereading does not make policy changes and filesystem mutations atomic.

Active folder copies use a bounded validated manifest, recheck current policy and
visibility, omit later additions and retain partial output. A visibility change
withholds stale child paths/counts. A growth refusal no longer falsely claims that
nothing was copied after directory creation. Native folder moves retain the
explicit insertion race described in the inventory. Blocked undo keeps its data
and receipt. Filename checks do not inspect MIME/content or prevent prompt injection.

Independent review found a lost post-copy visibility assertion in the ordinary
file/policy-off-folder path, despite the initial native tests passing. Three new
regressions reproduced metadata disclosure when a mutation hook hides the new
copy. The assertion is restored before receipt formatting, with a generic refusal
and no deletion of copied data. Scoped review approved the correction. The active
folder-manifest checks were preserved.

| Gate | Result |
| --- | --- |
| Final complete PHP suite including the visibility correction | 8,151 tests / 69,862 assertions; PHP 8.4.27 |
| Focused copy/policy/architecture after correction | 193 tests / 17,105 assertions |
| Full architecture before the narrow correction | 44 tests / 16,686 assertions |
| PHP lint and both fixed analyses after correction | 1,259 files; both scopes pass without new suppressions |
| Relevant rebuilt admin browser tests | 87 cases across Chromium, Firefox and WebKit; the final batch repeats the complete browser suite |
| Localization/admin after the final UI changes | 180 tests / 10,834 assertions |
| API contracts | 2,384 tests / 4,887 assertions; also included in the complete PHP suite |
| Independent review and scoped correction | No actionable findings remain; human review remains pending |
| Final native diagnostics | 20 tests / 270 assertions in each NC35 MariaDB and SQLite cell; SQLite coordinated recovery passes |

Initial regression failures, the nonstandard PHP upload-limit invocation, the
missing route-contract entries and a derived-source attribution correction were
resolved without disabling assertions or weakening existing contracts. The new
architecture inventory detects unlisted mutation owners; it complements behavioral
tests and does not infer safety merely from a guard name appearing in source.

### Native policy diagnostics

The exact filter is
`FileWritePolicyRecoveryTest|FileWritePolicyAdminHttpTest|FileWriteCheckoutTest|FileTypeEditRestoreTest|FileWriteTreePolicyTest|NotesFileWritePolicyTest|ContactBackupLockTest`.
It selects twenty cases: configuration/repair, real administrative HTTP,
checkout, edit/restore, tree copy/move/undo, Notes and Contacts. The Contacts
case refuses a public vCard upload under an md-only policy while verifying the
mandatory internal vCard backup. The SQLite cell additionally rehearses coordinated
app/database recovery and invalidates old credentials before reopening access.

Both final native invocations completed with exit 0 and no errors, failures or
skips. Exact run-label checks found no remaining containers or networks. These
are focused diagnostics on the working tree based on `605dfd0` with batch D
and its visibility correction; they are not full matrix cells.

- 35:mariadb:fresh: `mcp-it-20261023114825-8654ea4108c2`; production archive SHA256
  `298368e94512ece16ce397babbb9c29f6838c2b7d71bc14ba2e2b7f251c96f6f`.
- 35:sqlite:fresh: `mcp-it-20261023114825-609c008c9a0a`; production archive SHA256
  `e9762f174a5f367bae50fe2c71fc8d4a00e4eb8c1fa8dc25f7233cceb26c92d2`.


The initial pre-correction runs were
`mcp-it-20261023113735-6dbcfc4aa419` (MariaDB) and
`mcp-it-20261023113736-6dfb76643acb` (SQLite), each with twenty tests and
270 assertions passing. Those results did not establish the missing post-copy
visibility invariant; the focused regressions and repeated native runs above
cover the corrected candidate. The wider native qualification remains batch E.

## Batch E local evidence

Canonical guides, configuration, metadata and architecture references now describe
only the selected implemented ports. The maximum catalog has 81 module tools plus
two diagnostics; three Deck dependency tools are conditional, and app availability
and account grants narrow discovery further. Existing 1.1.2 manifest paragraphs
remain intact. No app ID, public name, release version, dependency or migration
change was made.

| Gate | Result |
| --- | --- |
| Complete catalog `devbox run check` | Exit 0; delegated `composer check` exit 0 |
| Full PHP suite on PHP 8.4.27 | 8,151 tests / 69,862 assertions |
| Complete browser suite | 687 tests across Chromium, Firefox and WebKit |
| Syntax and both fixed static-analysis scopes | 1,259 PHP files; both analyses pass |
| Documentation and client-runner safety tests | 29 and 10 cases, respectively; no skips/failures |
| Documentation and production package checks at the batch E checkpoint | 149 Markdown files; 110 package sources staged and verified |
| Translation provenance | 96 drafts remain review-required; no invalid provenance |
| REUSE 6.2.0 | All 1,615 files pass declared copyright/license checks |
| Focused metadata/discovery/localization/API/provisioning contracts | 84 tests / 9,031 assertions |

The three missing-module build diagnostics in browser output are intentional
negative fixtures; all three cases pass. Their stderr was preserved. Final
prose-only corrections were followed by fresh docs, provenance, documentation-test
and package checks. No runtime source changed during that gate.

English, Brazilian Portuguese and Spanish store additions retain upstream
provenance and explicitly pending human review. Spanish, Catalan, Brazilian and
European Portuguese operational translations keep their actual source revisions;
no revision was advanced to hide stale content. German UI review remains pending,
and the deferred German README was not added.

The local package at this batch E checkpoint has SHA256
`197933a884afeb3e0bc812c038bd5e1ae2ddf42fd97a86f0eb676d592b3ccc3d`.
The wider interpreter/native qualification and whole-integration review were still
pending at this checkpoint; their completed results are recorded below. The candidate retains version 1.1.2: its existing
upgrade baselines do not qualify an upgrade from installed upstream 1.2.0.
F3 must select the release version and qualify the actual installation path.

## Final review and qualification

The whole-integration review identified avoidable high-fanout Deck lookups before
the readable graph limits were applied. The correction checks the existing visible
node/edge/depth budgets immediately after each freshly authorized target. Native
adjacency materialization and filtering hidden relations remain outside those
visible budgets; no raw hidden-edge limit or partial graph was introduced.

The same limits now apply to complete origin snapshots. A subsequent scoped
review caught a predictable boundary problem: an addition could exceed the
post-write verification limit. Proposed additions are now checked before approval
or native mutation, after target authorization. Exact-limit additions, no-ops and
removals retain their behavior. Native-boundary regressions reproduced both bugs,
including the previously invoked overflowing write. Final scoped review found no
remaining actionable findings; this is not human security approval.

The immutable runtime correction is `9c7efe4`. QA-only commits `3de717b`,
`8c1aeb7` and `17a6ea1` address the native failures described below. The final
native matrix uses `17a6ea1`; later documentation-only commits do not change that
runtime or its test fixtures.

| Gate | Final evidence |
| --- | --- |
| Complete PHP suite, PHP 8.4.27 | 8,174 tests / 69,939 assertions; exit 0, including five new executable harness regressions |
| Deck scope after both budget corrections | 603 tests / 4,408 assertions |
| PHP 8.2.29, 8.3.35 and 8.5.11 | Each complete pre-budget-fix suite passed 8,151 tests / 69,862 assertions; each final changed-boundary rerun passed 58 tests / 179 assertions, and each supplementary harness rerun passed 14 tests / 129 assertions |
| Syntax and fixed static analysis | Full syntax checks cover 1,259 PHP files; both analysis scopes pass after the runtime corrections; subsequent QA-only files pass syntax and harness ShellCheck |
| Browsers | All 687 cases passed before the final PHP-only and native-fixture corrections; JavaScript and templates did not change afterward |
| Attribution | REUSE 6.2.0 passes all 1,615 files; independent human license review remains pending |

The additional PHP interpreters ran as no-network CLI containers. The new harness
regressions require real Git for source identity and executable temporary fixture
scripts. Early supplementary CLI attempts failed because that minimal environment
lacked Git and mounted its temporary directory without execution; those attempts
were retained as failures. Read-only real Git/tool-library/metadata mounts and an
executable private temporary filesystem supplied the missing prerequisites without
changing repository code, assertions or the running native matrix. A previously
used PHP 8.2 image reference was also unavailable locally; its exact official
repository-qualified digest was recovered before the successful rerun. The cached
image supplying PHP 8.2 was used only as an interpreter, never as a Nextcloud 29
service or a claim of support for that major. PHP 8.5 emitted retained deprecation
notices in the host Composer 2.8.8 dependencies (`React/Promise` and `JsonSchema`),
before application tests. The application suites passed without a PHPUnit
deprecation result; the old external Composer toolchain is not claimed warning-free.

### Native failures and corrections

The first NC32 upgrade attempt, `mcp-it-20261023123552-4de49fefa4ef`, failed before
the suite: Nextcloud migrated the candidate to 1.1.2 and then automatically
replaced it with App Store 1.2.0. The installed-version assertion correctly refused
to certify that different application. The disposable harness now sets and verifies
boolean `appstoreenabled=false` before any baseline/candidate operation. Real
`occ upgrade`, version and package-hash checks remain mandatory. Five behavioral
harness tests first failed and then passed, including configuration write/readback
failure cases. This test-only control does not implement F3 production update
controls; the observed replacement makes that remaining release requirement concrete.

The first full NC32 fresh run, `mcp-it-20261023123551-f0e980de32f1`, failed with
264 cases, 3,459 assertions, 17 failures and nine matrix-specific skips. It is not
passing evidence. Fourteen failures concerned missing external mounts and three
concerned the new policy fixtures' authentication/admission.

The new policy fixtures reused shared accounts after other suites removed their
eligibility and revoked cached bearer credentials. They now use the existing
disposable-user fixture, explicit necessary grants and fresh credentials. Every
policy refusal, positive control and undo assertion was retained. An ordered
native regression that primes the old fixture, revokes the client and clears
shared eligibility passes seven tests / 76 assertions in
`mcp-it-20261023125638-821ab933c34d`.

Controlled mount diagnostics disproved an initial Contacts-order hypothesis:
provisioning alone passed four tests / 19 assertions
(`mcp-it-20261023125234-95f02cdfdbd7`), and Contacts followed by provisioning passed
seven / 50 (`mcp-it-20261023125232-d0831f69b5c3`). Inspector followed by provisioning
reproduced the missing mounts: eight / 56, one failure
(`mcp-it-20261023125638-a2348059c837`). The inspector test reset the global native
filesystem in a long-lived process but left warm setup caches. It now explicitly
rebuilds both fixture users through native `setupForUser()` and checks all three
external mount points for both users, preserving the share/exclusion proof.
The formerly failing sequence passes eight / 71 in
`mcp-it-20261023130017-3edb839cd467`. No production exclusion or authorization
logic was relaxed to resolve these fixture failures.

### Complete native matrix

All 16 unfiltered cells passed against
`17a6ea1b53910af0362a817ec2b4242ab3953a8c`: 4,224 test cases across the matrix,
61,415 assertions and 102 explicitly inapplicable cases skipped. Every command
and collected runner result exited 0, with zero errors/failures.
No diagnostic filter, qualification-version override or missing-app skip was used.
Each retained archive was independently hashed against its recorded SHA256, and
every source identity matched the commit above. Exact run-label checks found no
remaining containers or networks. No host port was published.

The reproducible command is `composer --working-dir=mcp test:integration -- NC DB MODE`,
with the cell values below and the pinned inputs recorded afterward. Sanitized
local evidence is retained under `mcp/build/integration/RUN/mcp-it/artifacts/`:
`result.json`, `junit.xml`, `source-commit.txt`, `packages.sha256`, stage logs
and the environment report. These ignored local artifacts are not committed or
publicly available; the tables preserve their result and identity.

| Cell | Tests / assertions / skips | Run | Candidate archive SHA256 |
| --- | --- | --- | --- |
| `32:mariadb:fresh` | 264 / 3622 / 9 | `mcp-it-20261023130511-e8065b22bd8a` | `4ba96991bda753a3cf1a02ddac72cba140cc99fe8d7f65ef7e45cec2e6669146` |
| `32:mariadb:upgrade` | 264 / 3826 / 3 | `mcp-it-20261023130510-7adf73eacbd3` | `199282a79a19bb0a1e7263951678ce3f093369791172b5ea77dc6e0bb890c0ea` |
| `33:mariadb:fresh` | 264 / 3622 / 9 | `mcp-it-20261023131200-0a3a2d888f16` | `153c98fb8e89d7621743e86a4fc9ed83eb71d4f6b792c9fcb1f9865af2a88a33` |
| `33:mariadb:upgrade` | 264 / 3826 / 3 | `mcp-it-20261023131208-934699cf639b` | `eb35ce6a0f534b4a95dc1207448aed6018f9cd951ed774edfd66c053ba1746b4` |
| `34:mariadb:fresh` | 264 / 3695 / 9 | `mcp-it-20261023131818-8d601d90e933` | `2c68be5000386ef5d380bed1489c224fc2b331c80d05fb94481504a2bd464888` |
| `34:mariadb:upgrade` | 264 / 3899 / 3 | `mcp-it-20261023131856-529ab98f604b` | `b1603c89cb860bb281fc77aec38cb8aaf85c07407ee56309624dc3223c2562bf` |
| `35:mariadb:fresh` | 264 / 3696 / 9 | `mcp-it-20261023132531-53589d876793` | `6b68dc6fb571d3cd24c5669cddf28cd5ac84c306c1e9fe805d2ad041ddebde29` |
| `35:mariadb:upgrade` | 264 / 3899 / 3 | `mcp-it-20261023132623-6848125b7320` | `084fdc1991622b4e1b30d3cc31ffa31a35c085a6ca60b18ba89f26a7548375e9` |
| `35:mariadb:upgrade-local` | 264 / 3899 / 3 | `mcp-it-20261023133322-254c425f28f5` | `d9b4cd77bb59938ec51a9225714cc73b0e3c10f3cdfe39f5cfbf8bc083ee836e` |
| `35:mariadb:upgrade-upstream` | 264 / 3696 / 9 | `mcp-it-20261023133450-42e3d45a7e8d` | `f0c20f980407655b5ea4cb95eaffbe6a02f0ab2698274ebb25d229cc56a7f4e8` |
| `35:mariadb:upgrade-upstream-111` | 264 / 3695 / 9 | `mcp-it-20261023134145-07eb1d677b6d` | `41a711c948641233ed04cfe80035bc2a71ef5ae9565057fc34542b0eef2c0ee8` |
| `35:mariadb:replace-upstream` | 264 / 3695 / 9 | `mcp-it-20261023134238-a0c4832baa64` | `4214763a8f86a76a347af64da9d243cf388586b8f95598307896311d81c43550` |
| `35:pgsql:fresh` | 264 / 4312 / 6 | `mcp-it-20261023135204-1a43b01df9e9` | `5e24fcb8fd561d590f085df1e9c5e0819a9a403f1aa5923e1c1a9062264b8af4` |
| `35:pgsql:upgrade` | 264 / 4517 / 0 | `mcp-it-20261023135203-cde856682017` | `7fe11bfcb3daf453c4eb72e0363702fb914a77419f2bc27f17487fda69b587d6` |
| `35:sqlite:fresh` | 264 / 3656 / 12 | `mcp-it-20261023140103-40061ddd5f7a` | `0ce64352cf2b757c08efadc79dfdd90155f2f64d38bc39b2040f7a5294322b64` |
| `35:sqlite:upgrade` | 264 / 3860 / 6 | `mcp-it-20261023140121-ccc6880227c8` | `878878e2b6b38fe91d84e62199ef0ff2bbbe8d28e422b1315af0c3f759d5e16f` |

The image inputs were pinned, rather than resolved from floating tags:

| Image | Exact reference |
| --- | --- |
| nextcloud:32.0.15-apache | `nextcloud:32.0.15-apache@sha256:ea5ecac73dfe90f7bf80e4867edfcf3f0fcbf4e5d83ea75cf433f7ef545ed412` |
| mariadb:10.11.19 | `mariadb:10.11.19@sha256:7db29378d4fdab73f8123bbc2b48905c90d1a4b00cf848b028f1e81e623257f2` |
| nextcloud:33.0.9-apache | `nextcloud:33.0.9-apache@sha256:8f46dc79e9abf4f1e330ca9a7d9d9b8a8811ecc28f18018c1951281e522499e8` |
| nextcloud:34.0.4-apache | `nextcloud:34.0.4-apache@sha256:0e5688ba0988b9112cf03f9c6bab53eccb466d278435f6596f16c1f25acacf74` |
| nextcloud:35.0.1-apache | `nextcloud:35.0.1-apache@sha256:8f2f0e93aca5e9457019933936dea1f313052e72c696b4aecb31580a208b859f` |
| postgres:16.15 | `postgres:16.15@sha256:ca0bd484cb98bf4b24eb1010e73fb3fcbd6714d240fbc1a10eea5b7dbecb641d` |

Upgrade baselines were immutable local objects:

| Mode | Baseline commit |
| --- | --- |
| `upgrade` | `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` |
| `upgrade-local` | `1f0b4232e18b2c778d3585b878116d1cabb93d05` |
| `upgrade-upstream` | `0911f9123a661968e707fc4c391adcf9d37c3e84` |
| `upgrade-upstream-111` | `65e4e08b68c3f8d1ba78398a082ec567b0b0f4e4` |
| `replace-upstream` | `27da5e107ca06d92b7af9b8a80ad9c77c12ba91e` |

Deck was pinned to 1.16.8 / 1.17.7 / 1.18.5 / 1.19.0 for NC32 / 33 / 34 / 35;
Contacts to 8.3.21 on NC32 and 8.9.2 on NC33-35. Native dependency refusals on
NC32/33 are asserted results, not skipped coverage.

The intentional skips are the three PostgreSQL query-log tests outside PostgreSQL;
six legacy snapshot/credential tests where that mode has no 1.0.8 fixture; and
SQLite's two real-deadlock cases and one concurrent group-lifecycle case, whose
database serialization semantics differ. SQLite lock-wait/retry behavior still
runs in `UserCausesTest`.
Historical upstream modes still execute the harness's version, preservation and
replacement checks; skipped legacy fixture tests must not be represented as run.
The upgrade and replacement modes do not establish an upgrade from installed 1.2.0.

The NC35/SQLite/fresh run `mcp-it-20261023140103-40061ddd5f7a` also passed
coordinated app/database recovery: restored assets matched, old access/refresh/code credentials were rejected,
explicit reconnection worked and the service remained closed. This disposable
rehearsal does not establish a backup or recovery procedure for the user's host.

### Final documentation and package gate

After the matrix, `docs:check`, `docs:translations`, `test:docs` and `package`
all exited 0. Documentation validation covers 149 Markdown files and 110 package
sources; all 29 documentation tests pass with no skips. The 96 translation drafts
remain review-required. The production package was rebuilt and its 110 staged
sources verified; SHA256 is
`8a027beeb004ab3676135f0d7f3bef30c53ea95282b6a78954082e87e9d8f1fa`.
This final development report and status updates are excluded from the package.
The final prose update was followed by a fresh documentation check and clean diff
check. No runtime or test fixture changed after the qualified revision above.

### Local implementation history

| Commit | Change |
| --- | --- |
| `2d2ae6d` | fix(upstream): integrate selected 1.2.0 safety and compatibility fixes |
| `c3b6c99` | feat(upstream): add partial note edits and timezone-safe Deck deadlines |
| `605dfd0` | feat(deck): add labels, visible files and native dependencies |
| `f556268` | feat(files): enforce an optional filename policy across writes |
| `26f20cf` | docs(upstream): reconcile selected 1.2.0 capabilities and qualification |
| `9c7efe4` | fix(deck): enforce readable dependency budgets before further lookups |
| `3de717b` | test(integration): prevent App Store replacement of native candidates |
| `8c1aeb7` | test(policy): isolate native write-policy accounts and credentials |
| `17a6ea1` | test(inspector): rebuild native mounts after fixture teardown |

### Acceptance boundaries

All results refer to the selected ports, not complete upstream 1.2.0 parity.
D06 native linking/recipient expansion, Colima engine control and German README
expansion remain deliberately excluded. The app still declares 1.1.2: the
existing upgrade baselines do not establish an upgrade from installed upstream
1.2.0. F3 must choose public identity/version/platform scope, implement release
source availability and production update controls, and qualify the actual host
and installation path before release/deployment.

Technical/security, accessibility, documentation-language and rendered German UI
reviewers remain unassigned/pending. All 96 operational translation drafts remain
review-required with their real English provenance. Public HTTPS remains pending;
Inspector's public journey and the earlier encrypted-storage isolation result of
14/15 with nonzero exit remain unresolved; ChatGPT consent/tool/revocation
acceptance remains pending. Local automated results do not certify those paths.
No push, deployment, release or PR change was performed; PR #14 remains a historical
reference and the removed upstream remote was not restored.
