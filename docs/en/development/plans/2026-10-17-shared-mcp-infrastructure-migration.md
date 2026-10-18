<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Shared MCP infrastructure migration plan

> **Execution:** Authorized and executed with `superpowers:executing-plans`.
> See the [execution report](2026-10-17-shared-mcp-infrastructure-migration-report.md) for evidence and remaining release gates.

**Status:** Implemented, validated and independently reviewed; no transition code remains.
**Review baseline:** Source, consumers and fixtures checked against `2d644da`; see section 7.
**Date:** 2026-10-17.
**Baseline:** `2d644da1ea282123103701cae62e9548244afdda`, after Logs completion.
**Goal:** Give shared MCP, access policy and native file facilities explicit owners,
reduce mixed responsibilities, and retire the entire old Tools/Service source and
unit-test trees without compatibility code or weakened behavior.
**Architecture:** Keep one protocol entry point and one tool registry. Extract
request handlers, publication, execution and guide-schema rendering behind them;
move policy and native facilities to the existing architecture's focused areas.
**Tech stack:** PHP >=8.2, Composer PSR-4 `OCA\Mcp`, PHPUnit 10.5, native Nextcloud
app `mcp`, declared Nextcloud 32–35 support; no new dependency.
**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Logs execution](2026-10-17-logs-capability-migration-report.md),
[Files execution](2026-10-16-files-capability-migration-report.md),
[DAV execution](2026-10-16-calendar-dav-capability-migration-report.md), and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Code, comments, PHPDoc and canonical documentation remain English. Preserve
  translated messages and meaningful multilingual fixtures, including literal keys.
- Preserve all 74 feature tools, nine-module order, `mcp_guide`, `mcp_status`, prompts,
  resources, schemas, titles, annotations, result shapes and JSON/Markdown key order.
  A fully enabled protocol list contains 76 tools, not 74: status, guide, then features.
- Preserve authentication, controller routes/headers, grant defaults, token revocation,
  native ACLs, privacy, audit, confirmation and failure boundaries. Do not fix unrelated
  behavior while relocating it. Record any independently found defect separately.
- Preserve stored config keys, app ID, HMAC payload/secret derivation, database schemas,
  group CAS/locking and OAuth token format. No persisted class-name/data migration.
- No container/global locator in new infrastructure owners. Composition stays in
  Application; optional feature factories remain lazy and resolve only when used.
- No old-namespace wrappers, class aliases, dual service IDs, migration forwarding constants,
  alternate registries, compatibility loaders, or empty transition folders. Delete
  each retired owner with its consumer update; rollback uses Git commits. Existing
  functional constants such as ResourceRegistry's reader limits remain valid; they
  are not aliases preserving a retired class or API owner.
- Reuse the validated worktree and branch. Keep harness scratch external and generated
  artifacts under ignored `mcp/build`. No branch rename, push, PR or deployment.
- Administrative JavaScript, endpoint behavior, OAuth internals, feature algorithms,
  PHP/tooling upgrades, operational README relocation and translation rollout are
  separate work. Necessary import/type/composition/test updates are in scope.

## Review focus

1. A role, grant, optional app or locale changes between discovery and execution:
   recheck the original gates in the same order without resolving an unavailable
   app or leaking hidden tools/resources (Phases 0, 2–4).
2. Restricted audit, contingency logging or unexpected-error logging fails: preserve
   outcome ordering, exception propagation and withholding of unaudited successful
   data; never log arguments, file names or exception messages (0, 3).
3. Confirmed state changes, sharing changes or locks change after a preview: preserve
   independent `confirm`, `confirm_shared`, ETag and `plan_state` checks and prevent
   newly introduced writes during previews or refusals (0–3).
4. Local share aliases, corrupt tags, partial writes and native mutation events hit
   one shared visibility instance: preserve fail-closed decisions, retries and cache
   invalidation without copying hidden content into an unprotected backup (0, 2, 5).
5. Malformed JSON, IDs, version/header mismatches, resource failures and hostile
   argument paths cross both protocol eras: preserve HTTP status, RPC codes, error
   details, empty objects, cache hints and safe diagnostics (0, 4–5).

## 1. Findings and scope

At this baseline `lib/Tools` has **26 PHP files**, `lib/Service` has **13**, and
`lib/Mcp` already has **7** focused contract/schema/confirmation files. The first
39 owners can be retired completely. The seven existing Mcp files keep their paths.

| Current owner | Finding | Decision |
| --- | --- | --- |
| `Service/McpProtocol.php` (527 lines) | Parsing/version negotiation, method dispatch, resource adapters, prompt adapters, tool adapters and response helpers share one file. | Keep parsing/negotiation in the entry point; extract three request families, protocol metadata and response assembly. |
| `Tools/ToolRegistry.php` (279) | Discovery, grant/app checks, schema presentation, execution, audit and a reusable log formatter are coupled. | Keep ordered lookup/guide access; extract publication, availability and execution. Move the log formatter to a narrow shared owner. |
| `Tools/ToolGuide.php` (414) | Guide navigation and schema-to-parameter rendering change for different reasons. | Extract schema rendering; keep module/tool selection and guide output together. |
| `Tools/Common/CommonMessages.php` (141) | Confirmation, resource failures and file-sharing descriptions share a catch-all name. | Distribute literals into three named message owners, without rewriting translations. |
| `Service/VisibilityGuard.php` (634) | A coupled visibility algorithm includes mounted/owner paths, backup mirrors and cache invalidation. | Relocate intact to Access/Visibility; splitting its cache lifecycle is not needed to retire infrastructure and would enlarge privacy risk. |
| `Tools/Common/LockAwareWrite.php` (436) | Native lock assessment and safe explanation form one bounded policy. | Relocate intact to Platform/FileAccess/Locks. Size alone does not justify splitting the algorithm. |
| Other shared classes | Most already have one purpose but lack an explicit owner. | Relocate intact, update all direct and indirect consumers. |

The plan is more than a directory rename: it separates four actual mixed owners
(protocol, registry, guide and messages). It deliberately retains cohesive security
algorithms. No file-length threshold, universal repository/gateway layer or generic
middleware pipeline is introduced.

ConnectionList and GrantMatrix are administration-facing consumers found in Service.
Their ownership relocation is included to remove Service completely; their endpoint,
paging/revocation behavior and UI are unchanged. Existing OAuth, Db, Controller,
Settings and Migration trees remain genuine owners, not transition folders.

## 2. Final ownership map

All paths in this section are relative to `mcp/lib`. Braces enumerate exact files,
not suggested future folders. Existing `Platform/Dav`, file backup primitives and
feature modules remain at their current paths.

| Retired source | Final source |
| --- | --- |
| `Tools/{ToolModule,RestrictedModule}.php` | `Mcp/Tools/{ToolModule,RestrictedModule}.php` |
| `Tools/{ArgumentValidator,ArgumentValidationException,UnknownToolException}.php` | `Mcp/Tools/Validation/{ArgumentValidator,ArgumentValidationException,UnknownToolException}.php` |
| `Tools/{ToolResult,ToolFailure}.php` | `Mcp/Tools/Results/{ToolResult,ToolFailure}.php` |
| `Tools/ToolPresentation.php` | `Mcp/Tools/Discovery/ToolPresentation.php` |
| `Tools/ToolRegistry.php` | `Mcp/Tools/ToolRegistry.php`, with extracted owners below |
| `Tools/{ToolGuide,ToolGuideNotes}.php` | `Mcp/Guide/{ToolGuide,ToolGuideNotes}.php` |
| `Tools/{WriteGate,PreviewsWrites,RendersPlans,PlanState,PlanChanged,PlanRenderer,PlanText}.php` | `Mcp/Confirmation/{WriteGate,PreviewsWrites,RendersPlans,PlanState,PlanChanged,PlanRenderer,PlanText}.php` |
| `Tools/Common/NodeAccess.php` | `Platform/FileAccess/Nodes/NodeAccess.php` |
| `Tools/Common/PathGuard.php` | `Platform/FileAccess/Paths/PathGuard.php` |
| `Tools/Common/{NodeAccessInfo,SharedWriteGuard}.php` | `Platform/FileAccess/Sharing/{NodeAccessInfo,SharedWriteGuard}.php` |
| `Tools/Common/{LockAwareWrite,LockWriteFailure,LockMessages}.php` | `Platform/FileAccess/Locks/{LockAwareWrite,LockWriteFailure,LockMessages}.php` |
| `Tools/Common/CommonMessages.php` | Three final message owners described below; no CommonMessages class remains |
| `Service/{GrantPolicy,GrantMatrix}.php` | `Access/Grants/{GrantPolicy,GrantMatrix}.php` |
| `Service/{VisibilityGuard,HiddenTagsUnavailableException}.php` | `Access/Visibility/{VisibilityGuard,HiddenTagsUnavailableException}.php` |
| `Service/{LogsAccess,LogsGroupsConflict,LogFileNames}.php` | `Access/Logs/{LogsAccess,LogsGroupsConflict,LogFileNames}.php` |
| `Service/Compat/AppEnablement.php` | `Access/AppAvailability/AppEnablement.php` |
| `Service/UserTimezone.php` | `Platform/Time/UserTimezone.php` |
| `Service/ConnectionList.php` | `OAuth/Connections/ConnectionList.php` |
| `Service/ResourceRegistry.php` | `Mcp/Resources/ResourceRegistry.php` |
| `Service/PromptCatalog.php` | `Mcp/Prompts/PromptCatalog.php` |
| `Service/McpProtocol.php` | `Mcp/Protocol/McpProtocol.php`, with extracted owners below |

`CommonMessages` distribution is exhaustive; preserve public member names/values
at the new semantic owner and update callers directly:

- `Mcp/Confirmation/ConfirmationMessages`: `confirmParameter`, `planStateParameter`,
  `planNothingChanged`, `confirmAdvice`, `confirmAdviceCheckout`, `confirmAdviceUpload`,
  and constants `CONFIRM_ADVICE`, `CONFIRM_ADVICE_CHECKOUT`.
- `Platform/FileAccess/FileAccessMessages`: `notFound`, `forbidden`, `locked`,
  `conflict`, `insufficientQuota`, `notAFile`, `fileInTargetFolderPath`.
- `Platform/FileAccess/Sharing/SharingMessages`: `unidentified`, `teamFolder`,
  `sharedBy`, `externalStorage`, and constant `UNIDENTIFIED`.

Additional production owners created by extraction:

```text
Mcp/
  Protocol/
    ProtocolMetadata.php
    JsonRpcResponse.php
    ToolRequests.php
    PromptRequests.php
    ResourceRequests.php
  Tools/
    Discovery/ToolPublication.php
    Discovery/ToolAvailability.php
    Execution/ToolExecutor.php
  Guide/GuideSchema.php
Platform/Logging/ExceptionSummary.php
```

### Interfaces and extraction seams

All moved APIs retain their signatures except the constructors and extracted
members explicitly listed here. The application-facing `handle`, `list`,
`definitions`, `call`, `guide`, resource and prompt APIs remain available at their
final owners. Retaining these functional entry points is not retaining an adapter.

- `ToolPublication::present(array $definition): array` is static and owns the exact
  existing `ToolRegistry::present` projection, including WriteGate schema publication,
  optional fields and key order. Delete the old private method.
- `ToolAvailability(GrantPolicy $policy, IAppManager $appManager,
  IUserManager $userManager)` exposes `permits(ToolModule $module, string $userId): bool`
  and `allowed(array $definition, string $userId): bool` as instance methods. Move the two current
  decisions verbatim, retaining role-first ordering at both callers. Do not merge
  ResourceRegistry's separate Notes app check into this decision.
- `ToolExecutor(ToolAvailability $availability, LoggerInterface $logger)` exposes
  `call(ToolModule $module, array $definition, array $arguments, string $userId): array`.
  It owns the matched-tool branch: denial, validation, preview/execution, catches and
  audit. A denied match throws UnknownToolException after audit; no second module is
  tried. Keep the audit closure local, capturing the current arguments by reference.
  Keep publication outside the validator catch, and keep preview rendering, module
  calls and each exception handler within their original catch boundaries. An error
  thrown inside a catch (including logger or changed-plan renderer failure) must
  not be caught by a newly introduced outer catch.
- `ToolRegistry(array $modules, ToolAvailability $availability, ToolExecutor $executor)`
  owns ordered discovery/lookup and the special guide route, and lazily caches one
  ToolGuide instance. It does not cache permission decisions or published definitions.
  Preserve first-match dispatch even for synthetic duplicate definitions: matching
  happens before role checks in call(), while discovery checks role before asking
  that module for definitions. Do not replace the loops with a prebuilt tool index.
- `ExceptionSummary::loggable(\Throwable $e): string` owns the former registry static
  formatter (`Class (code N)`, never the original message). Update TalkErrors and its
  Logs test consumers directly; remove `ToolRegistry::loggable`, with no alias.
- `GuideSchema::parameters(array $schema): array` and
  `GuideSchema::parameterLine(array $parameter): string` are static, pure projections.
  Move `type`, `constraints`, `value` as private static helpers. ToolGuide keeps its
  `__construct(array $modules)`, navigation, extra gates, notes and final envelope.
- `ProtocolMetadata` owns every existing public McpProtocol constant, with identical
  names and values. Move references directly; no copied forwarding constants remain.
- `JsonRpcResponse::result(int|string $id, array|\stdClass $result): array`,
  `error(int|string|null $id, int $code, string $message, int $status = 200): array`,
  and `safeMessage(string $message): string` are static. Transfer the existing bodies
  without broadening the safe-message regex or normalizing JSON types.
- `ToolRequests(ToolRegistry $tools)` exposes `list(string $userId): array` and
  `call(int|string $id, array $params, string $userId, bool $modern): array`. Move
  diagnostic publication/execution and existing tool error adaptation here.
- `PromptRequests(PromptCatalog $prompts, GrantPolicy $policy)` exposes
  `list(string $userId): array` and
  `get(int|string $id, array $params, string $userId, bool $modern): array`. Keep
  current argument acceptance, permission filtering and result wrapping.
- `ResourceRequests(?ResourceRegistry $resources)` exposes `list`, `templates`,
  `read`, each `(int|string $id, array $params, string $userId, bool $modern): array`.
  Consolidate each legacy/modern pair around the same native call, preserving the
  different status/error codes and envelope order. Null still means unavailable.
  Keep absence checks before cursor/URI validation. Preserve the resource read split:
  ToolFailure is -32002 in legacy and -32602 in modern; missing handlers/unknown
  methods are -32601 with HTTP 200 in legacy and 404 in modern. Ordinary invalid
  arguments retain HTTP 200 in both. Both list methods preserve their cursor casts.
- `McpProtocol(ToolRequests $tools, PromptRequests $prompts, ResourceRequests $resources,
  LoggerInterface $logger)` retains
  `handle(string $raw, string $headerVersion, string $userId, array $headers = []): array`.
  It owns parsing, notification/ID checks, era choice, version/header checks, dispatch
  and rejection diagnostics. Keep `legacy`/`modern` as supported protocol branches:
  these names refer to client protocol versions, not migration scaffolding.

### Dependency rules

- Protocol request handlers depend on registries/catalogs and leaf contracts, never
  feature implementations. ResourceRegistry retains the existing Files/Notes public
  reader contracts. Application remains the composition exception.
- Feature modules may use Tool contracts, guide/confirmation contracts, validation,
  result primitives, access policy and named native facilities. They must not import
  ToolRegistry, ToolExecutor or protocol handlers. TalkErrors uses ExceptionSummary.
- Access policy must not import tool/feature implementations or protocol dispatch.
  Preserve existing leaf dependencies explicitly: OAuthStore for revocation,
  LogsGroupsMapper for CAS, file backup metadata/messages for visibility, and
  ToolFailure/FileAccessMessages for safe refusals. Do not demand artificial purity
  by wrapping every dependency in a new interface.
- Native file facilities may use visibility, time, sharing/confirmation messages and
  leaf MCP validation/failure types. They cannot invoke tool registries or handlers.
  Enforce allowed class-level edges; broad Access/Platform/OAuth directory labels
  do not imply a strictly layered graph when shared leaf contracts cross them.
- Availability and execution share the same GrantPolicy and role instances. Files,
  Notes, resources, checkout and Logs privacy receive the same VisibilityGuard
  instance from Application. Never construct private guards to satisfy new types.

## 3. Consumers and test ownership

This change reaches all nine modules, not only shared infrastructure constructors.
At the baseline, 182 feature production files name an old Tools/Service namespace.
Inspect imports, FQCN calls, escaped strings, PHPDoc, service IDs, hardcoded paths,
reflection, factories and implicit same-namespace references; a textual prefix
replacement alone cannot perform the message split or constructor changes.

Specific consumers requiring explicit attention:

- Application module factories, ToolRegistry/McpProtocol/ResourceRegistry construction,
  and `ToolRegistry::guide()` identity. Keep the nine-module order and all lazy hooks.
- Controller imports/types (MCP, OAuth/consent, grants, connections, checkout), both
  Settings classes, OAuth/UserRevocationListener, Db/LogsGroupsMapper, Platform file
  backup services and every feature factory. Framework routes/service behavior stay.
- All three global reflective builders in ToolsListContractTest,
  WriteGateContractTest and VisibilityContractTest, plus constructor sites in
  feature compatibility tests, NotesTestCase, protocol/resource/prompt tests,
  ApplicationRegistrationTest and shared fixture builders. Keep Logs' explicitly
  permissive role fixture in the publication builder.
- TalkMigrationCompatibilityTest and DavMigrationCompatibilityTest reflect private
  `ToolRegistry::present`; call the new ToolPublication directly. McpProtocolTest's
  `safeMessage` reflection becomes a direct JsonRpcResponse call. Preserve assertions.
- McpModernProtocolTest and McpControllerTest call McpProtocolTest::protocol(). Move
  both protocol test classes together and add the final explicit import to the
  controller test; do not leave a root-namespace test alias or forwarding helper.
- NodeAccessInfoTest reads old Common source paths; PromptCatalogTest reads the old
  GrantPolicy path. Update their inspected owners, including all split message owners.
- NextcloudApiUsage's GrantPolicy fallback `by` class, its explanatory source strings,
  NextcloudApiContractTest's own-class exclusion assertion, all feature boundary
  allowlists, and historical negative scanner fixtures. Do not broaden allowlists
  to every class under Mcp or remove native API/provider coverage.
- FilesBoundaryTest currently treats the entire `Mcp/Tools` subtree as pure contracts.
  Replace that premise with an explicit contract/schema file list in Phase 1 when
  ToolPresentation/validation/results arrive. Retarget all other feature allowlists
  in the phase that moves the allowed class; preserve feature-import rejection.
  DavBoundaryTest's recursive discovery of Unit/Mcp/Tools and Confirmation stays;
  it must not demand that new classes are feature implementations.

### Complete test/support retirement

Move tests with their owners and preserve every original method/provider. All paths
below are relative to `mcp/tests`; unlisted tests stay where they are and update
imports or fixture wiring only.

| Current test/support | Final owner |
| --- | --- |
| `Unit/Tools/ToolRegistryTest.php` | `Unit/Mcp/Tools/ToolRegistryTest.php` |
| `Unit/Tools/ArgumentValidatorTest.php` | `Unit/Mcp/Tools/Validation/ArgumentValidatorTest.php` |
| `Unit/Tools/ToolPresentationTest.php` | `Unit/Mcp/Tools/Discovery/ToolPresentationTest.php` |
| `Unit/Tools/ToolGuideTest.php` | `Unit/Mcp/Guide/ToolGuideTest.php` |
| `Unit/Tools/{PlanState,PlanRenderer,PlanText}Test.php` | `Unit/Mcp/Confirmation/{PlanState,PlanRenderer,PlanText}Test.php` |
| `Unit/Tools/WritePlanContractTest.php` | `Unit/Contract/WritePlanContractTest.php` |
| `Unit/Tools/NoFileDeletionTest.php` | `Architecture/NoFileDeletionTest.php` |
| `Unit/Tools/Common/LockAwareWriteTest.php` | `Unit/Platform/FileAccess/Locks/LockAwareWriteTest.php` |
| `Unit/Tools/Common/{NodeAccessInfo,SharedWriteGuard}Test.php` | `Unit/Platform/FileAccess/Sharing/{NodeAccessInfo,SharedWriteGuard}Test.php` |
| `Unit/Tools/FakeTree.php` | `Support/Platform/FileAccess/FakeTree.php` |
| `Unit/Tools/FakeUsers.php` | `Support/Identity/FakeUsers.php` |
| `Unit/Tools/FakeRestrictedModule.php` | `Support/Mcp/FakeRestrictedModule.php` |
| `Unit/Tools/AssertsReadablePlans.php` | `Support/Mcp/AssertsReadablePlans.php` |
| `Unit/Tools/Common/{FakeLock,FakeLockManager}.php` | `Support/Platform/FileAccess/Locks/{FakeLock,FakeLockManager}.php` |
| `Unit/Service/{ResourceRegistry,PromptCatalog}Test.php` | `Unit/Mcp/{Resources/ResourceRegistry,Prompts/PromptCatalog}Test.php` |
| `Unit/Service/LogsAccessTest.php` | `Unit/Access/Logs/LogsAccessTest.php` |
| `Unit/Service/VisibilityGuardTest.php` | `Unit/Access/Visibility/VisibilityGuardTest.php` |
| `Unit/Service/Compat/AppEnablementTest.php` | `Unit/Access/AppAvailability/AppEnablementTest.php` |
| `Unit/{McpProtocol,McpModernProtocol}Test.php` | `Unit/Mcp/Protocol/{McpProtocol,McpModernProtocol}Test.php` |
| `Unit/GrantPolicyTest.php`, `Unit/Admin/GrantMatrixTest.php` | `Unit/Access/Grants/{GrantPolicy,GrantMatrix}Test.php` |
| `Unit/UserTimezoneTest.php` | `Unit/Platform/Time/UserTimezoneTest.php` |

GrantMatrixTest keeps using Admin/MatrixFixture through an explicit final import:
that fixture tests the admin consumer and is not a retired loader. Connection controller tests remain under
Unit/Connections. Logs' feature privacy tests still belong to Logs. Keep existing
InMemoryConfig, InMemoryAppConfig, InMemoryLocks and native API stubs; their relocation
is not required to remove the retired trees. Update all support consumers globally.

### Frozen fixture exception: internal class identity

The source inventory found exactly two retired FQCN values in existing JSON captures:
`tests/Support/Notes/Compatibility/results.json`, at `/missing/type` and
`/too_large/type`. NotesMigrationCompatibilityTest records `$e::class` there for
**direct PHP refusals**; these are not fields returned by MCP. Both values currently
name `OCA\Mcp\Tools\ToolFailure`. Keeping them unchanged while removing that class
would force either a failing test or forbidden compatibility code.

During Phase 1 only, edit those two values to
`OCA\Mcp\Mcp\Tools\Results\ToolFailure`, without recapturing the file. Verify the
JSON diff contains precisely those two paths and the byte diff only those two value
replacements; messages, all other fields and all other capture files must remain
unchanged. Amend the Notes fixture README to record the original capture commit,
execution baseline and this explicit class relocation. Record the Phase 1 commit
in the final execution report, rather than trying to embed a commit in itself. This is the only planned capture
exception; record before/after hashes and retain the original snapshot in Git.

Keep the comparison exact after that edit: no namespace normalization, replacement
map in test execution, alias or old-class loader. New Phase 0 snapshots must not store
internal exception FQCNs as observable output; assert exception types directly in PHP
using their imported class, while freezing client messages/results. If another
captured implementation identity is discovered, inspect and document its exact
provenance before proposing a similarly narrow exception; never regenerate captures
from the refactored implementation.

## 4. Ordered execution phases

The production retirement allocation is disjoint: Phase 1 retires 16 root Tools
files plus CommonMessages (17); Phase 2 retires seven remaining Common files and
ten Service files (17); Phase 3 retires ToolRegistry/ToolGuide (2); Phase 4 retires
the three MCP Service files (3). Total: all 39 original owners, exactly once.

Every phase ends with a focused run and `devbox run test --cwd "$TASK_REPO" --json`
(or the stronger `check` in the last phase), inspection of success, then a local
commit. Rebuild optimized autoload after every move. Never advance with an unexplained
failure or hide a failure by narrowing the test selection. Phase 0 characterization
passes against unchanged production; new extraction tests run red before the owners
exist, then green. Relocation tests may change only imports/construction, not outcomes.

### Phase 0 — Freeze shared contracts and inventory

**Files:** Add `tests/Unit/Contract/SharedMcpMigrationCompatibilityTest.php` and
`tests/Support/Mcp/Compatibility/{README.md,publication.json,protocol.json,results.json}`.
Inventory all old owners and expanded PHPUnit cases in task-owned external scratch.
**Interfaces:** Immutable synthetic output/orchestration evidence for all later phases.

- [x] Record the exact execution commit, all original test methods/providers and full
  expanded discovery. The previous completed suite has 5,234 cases; recapture rather
  than hardcoding that as the future count. Hash every existing feature compatibility
  fixture. Record the two approved Notes type-field replacements separately;
  never regenerate fixtures to fit this migration.
- [x] Capture raw/published definitions, both built-ins, complete guide/resource/prompt
  output and representative write/changed-plan/read/error results in en, pt_BR and es.
  Cover default grants, no grants, all grants, alternative share/link grants, optional
  apps off/unsupported/allowed, and Logs role denied/allowed. Capture raw PHP result
  shapes as well as encoded wire output where objects differ. For invalid UTF-8,
  construct raw bytes in PHP assertions and freeze only the encoded representation
  in JSON; JSON substitution must not normalize the original PHP inputs/results.
- [x] Add `testSharedPublicationRechecksIdentityGrantsAppsAndLocale`: reuse one registry,
  change authenticated UID, grant, role, app allowlist/version and Translator between
  calls; assert order, counts, hidden calls denied before factory resolution, and the
  same guide content via mcp_guide and mcp://guide. Reset Translator in teardown.
- [x] Add `testSharedExecutionPreservesAuditAndFailureOrder`: spy on validation, preview,
  call, logger and audit; freeze denied/invalid/read_error/success, post-validation
  arguments, audit dispatcher/contingency failures and unexpected-error logger throws.
  Role/availability exceptions before the execution catch must stay outside it.
- [x] Explicitly include the existing VisibilityGuardTest and LockAwareWriteTest
  contracts in Phase 0: partial-mutation failure, every native event, mapper events,
  share aliases, corrupt configuration, backup tag copying and lock rechecks. Record
  their discovered case identities, so review focus 4 has pre-change evidence rather
  than relying only on the later composition test. Add a case only for an actual gap.
- [x] Add `testSharedConfirmationRemainsStatelessAndBound`: absent/false/true/invalid
  confirm, generic/specific preview, PlanChanged, account/tool/state mismatches, ETag,
  confirm_shared and stale locks. Assert no writes on refusal and exact envelopes.
  Preserve existing return-before-audit behavior of preview/PlanChanged branches;
  do not invent success auditing for them during extraction.
- [x] Add `testSharedProtocolPreservesBothEraBoundaries`: freeze parse/notification/ID
  precedence, absent/malformed/mismatched versions, mirrored base64 names, structured
  argument errors, null-resource handlers and resource error codes in both eras.
  Include invalid UTF-8 and strict empty-object versus empty-array preservation.
- [x] Run frozen contracts, the focused gate and full suite with the configured PHP
  limits below. Commit `test(mcp): freeze shared infrastructure contracts`.

**Acceptance:** Pre-change evidence covers each review focus; no production changed,
no live user data or secrets in fixtures, and all baseline cases are accounted for.

### Phase 1 — Establish final contracts, presentation primitives and support

**Files:** Move the 16 root `Tools/*.php` owners other than ToolRegistry/ToolGuide;
split only `Tools/Common/CommonMessages.php`. The seven other Common classes stay
until Phase 2. Retain the seven existing Mcp paths. Move affected unit tests, all six
shared support files, WritePlanContractTest and NoFileDeletionTest now. Create
`tests/Unit/Mcp/Confirmation/ConfirmationMessagesTest.php`; amend only the two Notes
fixture type values and their README as specified above.
**Interfaces:** Final validation, result, confirmation and module contracts; shared
messages become direct dependencies of every current consumer.

- [x] Retarget existing tests/support and add `ConfirmationMessagesTest::testExactInstructions`
  for the member distribution and literal/translated schema behavior. Run before moves.
  Relocate support one-to-one; do not change its behavior or create duplicate fakes.
- [x] Move the owners and split literals exactly as mapped. Retarget imports, fully
  qualified calls and member references throughout lib/tests, including current
  Service and the temporarily unmoved registry/guide. Translate only explanatory
  comments/assertion diagnostics; keep user-facing copy and fixture language intact.
  After moving ToolFailure, make and verify the two precise Notes fixture substitutions;
  keep its strict comparison and every behavioral field unchanged.
- [x] Update NoFileDeletionTest's path calculations and recursive coverage. Only
  BatchUndo::undoBatch may remove its own empty created folder; preserve that narrow
  exception. Update the boundary contract file lists and explicit shared allowlists
  now: the new Discovery/validation/result classes already make the old whole-subtree
  contract scan invalid. Retarget moved contracts in every module in this same phase.
- [x] Rebuild autoload; run confirmation/validation/guide, file-sharing/locks, all feature
  compatibility contracts, architecture, focused and full gates. Commit
  `refactor(mcp): relocate shared contracts and confirmation primitives`.

**Acceptance:** Retired contract/message/support types do not resolve; no source or
support forwarders. All nine modules consume identical final contracts. Old registry
and guide remain the sole active owners only until Phase 3, without duplicate copies.

### Phase 2 — Move access policy and native file facilities

**Files:** Move the seven remaining Tools/Common classes, all ten Service classes
other than McpProtocol/PromptCatalog/ResourceRegistry, and their mapped tests.
Update Application, controller/settings/OAuth/Db consumers and native API metadata.
**Interfaces:** Unchanged policy, CAS, visibility, revocation, time and file APIs at
final namespaces. No security algorithm extraction in this phase.

- [x] Retarget tests before moves and run red. Add
  `ApplicationRegistrationTest::testSharedPolicyFactoriesPreserveInstanceIdentity`
  using real registration closures and host doubles: one guard reaches tools/resources/
  checkout/Logs; mutations and native events invalidate it; corrupt configuration
  refuses access; permitted requests retain native ACL checks.
- [x] Move and wire final owners without changing lifetimes/defaults, optional constructor
  arguments, event listeners or private cache fields. Keep Grants/Connections pagination,
  revocation, role CAS and stored keys unchanged. Remove Tools/Common and Service/Compat.
- [x] Exercise all original visibility/share-alias/backup tests, Locks, Grants, Connections,
  LogsAccess, Logs compatibility, admin consumers and API contracts. Check malformed
  tags, assignTags partial failure, lock-provider failure and concurrent-save refusal.
- [x] Rebuild autoload; pass focused/full gates; commit
  `refactor(access): assign policy and native file infrastructure owners`.

**Acceptance:** No old policy/native-file route or duplicate visibility instance.
Nextcloud APIs and exceptions still have the same meanings; no administrative UI,
DB migration or permission widening is introduced.

### Phase 3 — Separate discovery, execution and guide schema rendering

**Files:** Replace Tools/ToolRegistry and Tools/ToolGuide with their final owners;
create ToolPublication, ToolAvailability, ToolExecutor, GuideSchema and ExceptionSummary.
Add mirrored unit tests for the new seams and update all registry construction sites.
**Interfaces:** The exact signatures in section 2; ToolRegistry still supplies one
shared guide to ResourceRegistry through Application.

- [x] Add `ToolAvailabilityTest::testRoleGrantAndAppChecksKeepTheirOrder`,
  `ToolExecutorTest::testAuditUsesCurrentArgumentsAndPreservesFailureBoundaries`,
  `ToolPublicationTest::testProjectionKeepsOrderObjectsAndConfirmation`, and
  `GuideSchemaTest::testNestedTypesConstraintsAndFalseDefaultsStayReadable`. Use the
  Phase 0 expected values; run red before extraction.
- [x] Extract the existing bodies around the listed seams. Preserve `grantAnyOf`
  short-circuit behavior, unknown-tool shape, execution catch order, logger propagation,
  lock mapping, no-cache decisions and localized publication. Never introduce a second
  grant/audit check or direct controller-to-executor path.
- [x] Update Application and all direct/reflective builders to the final constructor.
  Replace private-method reflection with ToolPublication calls in Talk/DAV captures;
  update TalkErrors to ExceptionSummary. Keep captures byte-identical to the reviewed
  Phase 1 state, with no further exceptions.
- [x] Retire the last production files under lib/Tools. Rebuild autoload, verify
  registry/guide/confirmation contracts, all feature captures, focused/full gates.
  Commit `refactor(mcp): separate tool publication execution and guide rendering`.

**Acceptance:** One active registry/executor chain; modules cannot import orchestrators.
Denied/schema-invalid Logs calls never construct readers, and successful unaudited
reads never escape. Discovery and resource guide share one guide instance.

### Phase 4 — Separate protocol request families and retire Service

**Files:** Move McpProtocol, PromptCatalog and ResourceRegistry; add ProtocolMetadata,
JsonRpcResponse and three request handlers. Move protocol/resource/prompt tests;
add request-family unit tests and extend real Application registration coverage.
**Interfaces:** Final protocol/request signatures from section 2, with nullable
ResourceRegistry explicitly supplied to ResourceRequests for unavailable-resource tests.

- [x] Add `ToolRequestsTest::testDiagnosticAndValidationEnvelopesMatchBothEras`,
  `ResourceRequestsTest::testMissingHiddenAndInvalidResourcesKeepEraSpecificErrors`,
  `PromptRequestsTest::testGrantChangesAndArgumentAcceptanceAreUnchanged`, and
  `JsonRpcResponseTest::testSafeFieldsNeverExposeServerState`; run red.
- [x] Move constants to their only owner and split handlers. Keep the entry point's
  validation and era-selection order, including notification acknowledgement before
  version checking, initialize header behavior, missing mirrored-header tolerance,
  private zero-TTL list/resource hints and different prompt/tool envelope shapes.
  Preserve existing null-coalescing/coercion behavior; do not normalize it incidentally.
- [x] Update controller/protocol constant consumers, fixtures, actual Application
  factories and every `new McpProtocol`. Retain ResourceRegistry's existing reader
  contracts and Notes availability check; no new optional app dependency resolution.
  Update same-namespace test helpers and McpControllerTest's protocol-test import in
  this phase, without keeping the retired root test class.
- [x] Delete lib/Service and tests/Unit/Service completely. Rebuild autoload; run
  both era suites, controller/auth/OAuth/resource/prompt contracts, focused/full gates.
  Commit `refactor(mcp): separate protocol request handlers and retire service owners`.

**Acceptance:** HTTP/RPC/output compatibility is unchanged for both supported branches.
Old service IDs/constants/paths are absent. Authentication stays in the HTTP adapter;
protocol handlers do not impersonate users or construct feature implementations.

### Phase 5 — Prove final composition, boundaries and package; record completion

**Files:** Add `tests/Architecture/SharedMcpBoundaryTest.php`; update existing boundary
scanners and application-composition tests as needed. Update this plan, specification,
authoring guide, docs indexes and a matching execution report.
**Interfaces:** Final ownership only; original feature contracts and observable output.

- [x] Add negative scanner examples for old imports, escaped service strings, require/
  autoload paths, aliases, container/global locators including bare OC, and a module
  importing the new executor/protocol. Enforce final class-level dependency edges.
  Distinguish `OCA\Mcp\Tools` from valid `OCA\Mcp\Mcp\Tools` and module-local Tools.
- [x] Scan lib/tests/scripts/appinfo/composer/phpunit/CI. Delete remaining Unit/Tools
  directories/loaders after proving all mapped tests/support consumers moved. Historical
  docs and deliberate negative fragments are the only old-route references permitted;
  no blanket exemption for boundary-test files or compatibility fixture directories.
- [x] Reconcile full XML discovery using the exact test-class mapping: retain every
  original method/provider case, list additions and explain any count difference.
  Compare all capture hashes against Phase 0, allowing only the two recorded Notes
  values (and its provenance README) from Phase 1; reject any other difference.
  Run architecture, native API,
  focused, full and production-package gates; inspect each result before proceeding.
- [x] Use the actual archive in a fresh PHP process with its production autoloader and
  minimal host declarations. Load every final mapped/extracted type and compare its
  bytes plus Application with the worktree (`class_exists` or `interface_exists`
  according to the manifest); reject old Tools/Service trees/classes,
  test dependencies and classmap paths outside the extracted package. Compare static
  catalogs/publication/guide output with frozen fixtures using throwing feature
  factories. Verify 74 feature definitions plus the two protocol built-ins in the
  synthetic full-access protocol fixture. No development vendor or bootstrap allowed.
- [x] Exercise real Application closures through controller/protocol/registry with
  explicit host doubles in
  `ApplicationRegistrationTest::testSharedProtocolFactoriesPreserveComposition`:
  missing optional apps remain lazy, new owners share policy
  and guard instances, and Logs refusal/audit paths are independent of reads. Keep
  real-host evidence separate from stub composition and archive autoload evidence.
- [x] Self-review and perform one independent final review; resolve verified blockers
  and rerun affected gates. Record phase commits, fixture provenance, discovery,
  decisions and external limits. Mark implemented only after these pass; commit
  `refactor(mcp): verify shared infrastructure boundaries and retirement`.

**Acceptance:** `lib/Tools`, `lib/Service`, `tests/Unit/Tools` and
`tests/Unit/Service` do not exist. No old runtime/test namespace, shim, alias,
migration forwarding constant, alternative publisher or stale optimized classmap remains. The seven
pre-existing Mcp contract/schema/confirmation owners remain at their intended paths.

## 5. Verification commands and evidence

Set `TASK_REPO` to the validated task worktree; run PHPUnit from its repository root.
Planning validation on `2d644da`, PHP 8.4.26 / PHPUnit 10.5.65:
**639 tests / 21,893 assertions**, passing in **2.731 s** with:

```bash
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G \
  mcp/vendor/bin/phpunit -c mcp/phpunit.xml --do-not-cache-result \
  --filter 'McpProtocolTest|McpModernProtocolTest|McpControllerTest|ToolRegistryTest|ToolGuideTest|ToolPresentationTest|ArgumentValidatorTest|Plan(State|Text|Renderer)Test|WritePlanContractTest|GrantPolicyTest|GrantMatrixTest|VisibilityGuardTest|LogsAccessTest|AppEnablementTest|ResourceRegistryTest|PromptCatalogTest|UserTimezoneTest|LockAwareWriteTest|NodeAccessInfoTest|SharedWriteGuardTest|ConnectionsControllerTest|MyConnectionsControllerTest|ApplicationRegistrationTest|ToolsListContractTest|WriteGateContractTest|VisibilityContractTest|LogsMigrationCompatibilityTest|BoundaryTest'
```

The initial run without upload-limit overrides had one failure (639 tests / 21,891
assertions): FilesPlanRendererTest expected the configured 50 MB upload limit but
PHP's default `post_max_size=8M` capped it at 8 MB. Repository CI and the selected
devbox test/check tasks already set both upload limits to 1G. Repeating the same
selection with those settings passed. No code or assertion was changed. Preserve
these settings in all direct execution commands; do not treat this as a migration
regression or weaken the test.

During execution, extend that filter with the new seam and compatibility test class
names as they land. Never use its old count as evidence that new tests ran. Also
run all `*CompatibilityTest` classes (including the Files pilot) after shared contract
changes and select owner test directories directly for each phase. After moves:

```bash
composer --working-dir=mcp dump-autoload --optimize
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G \
  mcp/vendor/bin/phpunit -c mcp/phpunit.xml --do-not-cache-result mcp/tests/Architecture
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G \
  mcp/vendor/bin/phpunit -c mcp/phpunit.xml --do-not-cache-result mcp/tests/Unit/Contract/NextcloudApi
devbox run test --cwd "$TASK_REPO" --json
# Final gate additionally builds and validates the production archive:
devbox run check --cwd "$TASK_REPO" --json
git diff --check
```

Expected: all selected tests are discovered and pass, full catalog task reports
success and the production archive validates. Existing PSR-4 notices for explicitly
loaded external test stubs must never become production dependencies. An extraction
can change scanned method names; explain changes in Nextcloud API discovery rather
than removing API providers or silently expanding exemptions.

Before release, run the PHP 8.2–8.5 CI matrix and disposable Nextcloud 32–35 tests
with synthetic users/files/logs for Basic/Bearer identity, both protocol eras,
permissions/revocation, optional app allowlists/versions, resources, confirmations,
local shares/hidden tags, native events, locks and audit failure. No actual user logs
or secrets are needed. This plan preserves the repository's current protocol
contract; it does not upgrade or certify compliance with a newer external MCP spec.

## 6. Feasibility and continuation

A complete migration without transition code is feasible: the consumers are inside
the app/tests and composition, with no discovered external namespace extension API
or persisted FQCN requiring a bridge. Execution must confirm that inventory at its
actual baseline. Each phase updates consumers atomically; the brief mixed tree only
contains unmigrated active owners and is gone at completion. No adapter is needed.

Retained native API compatibility checks such as AppEnablement's supported-version
logic are product behavior, not migration shims. Existing feature contracts, Db
migrations and OAuth storage remain required. Do not remove them under a broad
"cleanup compatibility" rule.

After this scope, administration UI separation, stable developer commands/static
analysis and README/docs navigation/i18n remain. Visibility and lock algorithms
may be reviewed independently when there is a measured responsibility or testing
problem; their size alone is not unfinished infrastructure migration. The authorized execution completed the phase chain. Further scopes still require
separate plans and authorization.


## 7. Documentation review outcome

Historical record of the pre-implementation review. Execution evidence and the
completed final review are recorded in the linked execution report.

The source map was mechanically reconciled with the current tree: every one of the
39 Tools/Service PHP files has exactly one retirement entry. Test/support ownership
covers both complete retired test directories; unchanged shared fixtures and native
stubs have explicit retained owners. The phase chain is consistent: final leaf
contracts/messages precede policy/file facilities, those precede registry extraction,
and final registries precede protocol extraction. Changed constructors and static
member owners have named consumers and test updates.

The review corrected four execution gaps: overlap in Phase 1/2 source ownership,
the Notes capture's internal exception identity, early boundary allowlist/contract
updates, and implicit protocol test-helper consumers. It also pins registry
first-match/catch ordering and both eras' resource error/status precedence.

All five review-focus conditions now have Phase 0 characterization and a later
owning phase. No transitional API or test normalization bridge is required.
Documentation links and `git diff --check` passed; only this plan, the two indexes
and the specification's plan references changed. The review reran the same focused
selection: **639 tests / 21,893 assertions**, passing in **2.858 s**. Notes capture
contracts separately passed **6 tests / 99 assertions** in **0.326 s**. The current
fixtures and production/tests remain unchanged; the two Notes value edits belong
only to future authorized implementation. These are planning checks, not execution
of the migration or a replacement for its future full/package/live-host gates.
