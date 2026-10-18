<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Deck capability migration implementation plan

> **For agentic workers:** After explicit implementation authorization, use
> `superpowers:executing-plans` for ordered execution, or
> `superpowers:subagent-driven-development` if delegated execution is requested.
> Complete each phase's checks before advancing. Implementation was subsequently authorized and executed in phase order.

**Status:** Implementation, local validation and independent review complete. Remote CI and disposable-server checks remain integration gates.
**Evidence:** [Execution report](2026-10-16-deck-capability-migration-report.md).
**Date:** 2026-10-16.
**Planning baseline:** `f925b2f`, branch `refactor/files-read-edit-pilot`.

**Goal:** Migrate all 13 Deck tools and their direct consumers to cohesive
capabilities, preserving public contracts and leaving no Deck transition code,
old namespaces, compatibility loaders, or temporary test directories.

**Architecture:** Reuse the tool contracts proven by Files, keep the registry and
confirmation gate, and replace Deck's broad gateway and handler inheritance with
explicit collaborators. Keep Deck-specific integration inside `Modules/Deck` and
expose only a small card-reference contract to Talk.

**Tech stack:** PHP >=8.2, Composer PSR-4 namespace `OCA\Mcp`, PHPUnit 10.5,
native Nextcloud app `mcp`, declared Nextcloud 32–35 support. No new dependency.

**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Files execution report](2026-10-16-files-capability-migration-report.md)
and [Adding a tool](../adding-a-tool.md).

## Global constraints

- Code, identifiers, comments, PHPDoc and reference documentation are English.
  Preserve user-facing localization, message IDs and translation timing.
- Preserve tool names, order, schemas, defaults, result envelopes, grants,
  annotations, previews, confirmation behavior, routes and stored plan compatibility.
- Keep authentication, authorization, validation and confirmation mandatory at their
  existing public boundaries. Capabilities do not replace registry policy.
- Keep framework-facing directories, app ID, database schema and production
  dependencies unchanged. No new framework, automatic discovery or generic workflow engine.
- No container, service locator or user session in tool constructors. Optional Deck
  services must remain lazy; listing tools must work with Deck absent or disabled.
- Use the selected isolated worktree; do not rename the existing branch. No push,
  PR, deployment or migration of other modules is included.
- Shared `Tools/Common`, registry, `WriteGate`, `PlanState`, translation and timezone
  services remain where they are. They are active infrastructure, not Deck transition code.
- Do not apply the Files no-deletion rule to Deck: Deck deliberately soft-deletes
  cards and empty structures and sometimes restores structures after a race.

## Review focus

1. Missing/disabled Deck and a mismatched caller/session: metadata remains usable;
   no optional service or protected entity is reached before its access check (Phases 0–2).
2. A write persists before an event listener throws: report confirmed, probable or
   unconfirmed outcomes correctly; never encourage duplicate creation (Phases 1, 3–4).
3. A card arrives during empty-structure deletion or restoration throws after saving:
   recount, restore and verify using fresh reads; retain the documented residual race (Phase 5).
4. Missing versus null dates, DST, changing users/locales and newer optional Deck
   parameters: preserve fields and resolve presentation for the current caller (Phases 2–3).
5. Talk references, moved fixtures and package-only autoload: access checks and test
   discovery survive the removal of every old Deck path (Phases 2, 6).

## 1. Evidence, feasibility and decisions

A complete migration is feasible within Deck and its direct consumers. It is more
than moving the 13 existing handlers: `lib/Tools/Deck` has 30 PHP files, and
`tests/Unit/Tools/Deck` has 29 test files plus a helper and three stub files.
At the planning baseline, the main responsibilities overlapped:

| Current owner | Responsibilities that must be separated |
| --- | --- |
| `DeckToolModule` | 13 schemas, dispatch, construction, eight previews, guide and rendering entry point |
| `DeckServiceGateway` / `DeckGatewayInterface` | 28 methods covering reads, permissions, assignments, mutations and read-back evidence; generic container resolution |
| `Handler/AbstractHandler` | Result/error mapping, logging, shared-board confirmation, verified writes, assignment lookup and creation heuristics |
| `Handler/CreateBoardHandler` | Board creation, blueprint execution, child creation, assignment and partial-result reporting |
| `DeckPlanRenderer` | Eight rendering branches and shared formatting |
| `DeckMessages` | Tool/schema text, error text, capability messages and recovery guidance |

The production consumer outside Deck is `Tools/Talk/ReferenceLinker`; it imports
both the gateway interface and the module's app constant. `AppInfo/Application`
registers the module and gateway. Tests also reference these types in application
registration, generic write plans, translated errors, Nextcloud API assumptions,
and a Files boundary negative case. These are part of the migration inventory.

### Two existing inconsistencies

**Session composition:** `Application` supplies `ISession` to its gateway factory,
but `DeckToolModule::gateway()` directly constructs another gateway without it.
Consequently, the latter skips the gateway's optional session equality check.
`McpController` already binds the authenticated Bearer user before dispatch and
releases that temporary session afterwards. The plan proposes one required-session
`Access/DeckCaller` for all final adapters, with no bypass flag or second composition.
Phase 0 must characterize Basic/session and Bearer dispatch, previews and Talk
before accepting that convergence. A mismatch must fail before Deck service
resolution. This deliberately closes an internal guard discrepancy; it must not
silently change a supported authenticated flow. If characterization finds such a
flow, stop and record a separately reviewable prerequisite fix before migration.
No controller/authentication redesign is included in this plan.

**Move guidance:** `guideNotes()` says only same-list repositioning is supported;
the tool description, handler, gateway and `MoveCardHandlerTest` support moving
between lists and boards. Preserve that tested execution behavior. A guide-text
correction is separate follow-up work, not a migration prerequisite or a hidden
exception to exact message compatibility. Unless separately authorized and completed before the baseline is
captured, retain the existing guide text and record the discrepancy in the report.
Neither inconsistency is fixed by this documentation change.

### Planning-time validation

From `mcp/`, on PHP 8.4.26:

```sh
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --do-not-cache-result tests/Unit/Tools/Deck tests/Unit/Tools/Talk/ReferenceLinkerTest.php tests/Unit/ApplicationRegistrationTest.php
```

Result: **468 tests, 3,058 assertions, passing**. This establishes the current
focused baseline only. A separate run with the same PHP flags and
`tests/Unit/McpControllerTest.php` passed **26 tests, 72 assertions**, including
existing Bearer/session lifecycle cases. These runs do not verify the proposed
architecture, exercise a real Deck server, or complete the PHP 8.2–8.5 matrix.
The Files report's integration gates remain open independently of this plan.

## 2. Final ownership and structure

Paths below are relative to `mcp/`. Create only files needed by the phase being
implemented; do not scaffold empty capabilities. `Tools/` holds the 13 small MCP
entry points, matching Files. Workflow and integration code belongs to the named
capabilities, with direct dependencies visible in constructors.

```text
lib/Modules/Deck/
  DeckModule.php
  DeckToolCatalog.php
  Tools/                         # 13 Tool/WriteTool entry points listed below
  Contracts/CardReferenceReader.php
  Reading/
    BoardReader.php
    StackReader.php
    CardReader.php
    DeckCardReferenceReader.php
  Followup/
    FollowupQuery.php
    CardCriteria.php
  Cards/
    CardCreation.php
    CardEditing.php
    CardMovement.php
    CardDeletion.php
    CardWriter.php
    CardStateReader.php
    CardInput.php
    CardDate.php
    CardState.php
    CardUpdateArguments.php
    CreateCardPlan.php
    EditCardPlan.php
    MoveCardPlan.php
    DeleteCardPlan.php
    CardMessages.php
  Assignments/
    AssignedUsers.php
    AssignmentAccess.php
    AssignmentChanges.php
    AssignmentEditor.php
    AssignmentPlan.php
  Structure/
    BoardCreation.php
    StackCreation.php
    BoardDeletion.php
    StackDeletion.php
    BoardCreator.php
    StackCreator.php
    BoardBlueprint.php
    BlueprintProvisioner.php
    StructureStateReader.php
    StructureCardCounter.php
    EmptyStructureDeletion.php
    CreateBoardPlan.php
    CreateStackPlan.php
    DeleteStackPlan.php
    DeleteBoardPlan.php
    StructureMessages.php
  Access/
    DeckCaller.php
    DeckAvailability.php
    OwnershipReader.php
    SharedBoardConfirmation.php
  Execution/
    DeckResult.php
    DeckErrors.php
    ResultBoundary.php
    PreviewBoundary.php
    VerifiedWrite.php
    DeckConflictException.php
    DeckRefusalException.php
    DeckSessionException.php
    DeckUnconfirmedException.php
    ErrorMessages.php
    WriteMessages.php
  Presentation/
    DeckFormatter.php
    DeckFormatterFactory.php
    DeckGuide.php
    DeckSchema.php
    ToolMessages.php
    PlanLocation.php
    PlanText.php
    CardPlanRenderer.php
    StructurePlanRenderer.php
    DeckPlanRenderer.php

tests/Unit/Modules/Deck/          # tests mirror capability ownership
  Tools/
  Reading/
  Followup/
  Cards/
  Assignments/
  Structure/
  Access/
  Execution/
  Presentation/
tests/Support/Deck/
  DeckTestHelpers.php
  DeckDoubles.php
  DeckToolFixtures.php
  DeckNativeFixtures.php
  NativeDeckAdapterFixtures.php
  DeckStructureAdapterFixtures.php
  DeckVerificationAdapterFixtures.php
  Stubs/deck_stubs.php
  Stubs/deck_entities.php
  Stubs/deck_services.php
tests/Architecture/DeckBoundaryTest.php
tests/Unit/Contract/DeckMigrationCompatibilityTest.php
```

Do not create one interface/DTO/factory per class. Capability classes may be
injected directly and use typed lazy providers for their external Deck services.
Native `OCA\Deck` entities may remain internal to Deck; they must not escape its
cross-feature contract. Provider closures are named for the specific service and
PHPDoc-typed, e.g. `Closure(): CardService`; no `get(string $class)` replacement.

### Public tools and catalog order

Every class below lives in `Tools/<Class>.php`. Each owns its static definition,
`execute(array $arguments, ToolContext $context): array`, and, for writes,
`preview(array $arguments, ToolContext $context): array`.

| Order | Public name | Class | Operation | Capability / preview owner |
| --- | --- | --- | --- | --- |
| 1 | `deck_list_boards` | `ListBoards` | read | Reading |
| 2 | `deck_list_stacks` | `ListStacks` | read | Reading |
| 3 | `deck_list_cards` | `ListCards` | read | Reading |
| 4 | `deck_read_card` | `ReadCard` | read | Reading |
| 5 | `deck_create_card` | `CreateCard` | create | Cards / `CreateCardPlan` |
| 6 | `deck_edit_card` | `EditCard` | edit | Cards + Assignments / `EditCardPlan` |
| 7 | `deck_move_card` | `MoveCard` | move | Cards / `MoveCardPlan` |
| 8 | `deck_delete_card` | `DeleteCard` | delete | Cards / `DeleteCardPlan` |
| 9 | `deck_followup_cards` | `FollowupCards` | read | Followup |
| 10 | `deck_create_board` | `CreateBoard` | create | Structure / `CreateBoardPlan` |
| 11 | `deck_create_stack` | `CreateStack` | create | Structure / `CreateStackPlan` |
| 12 | `deck_delete_stack` | `DeleteStack` | delete | Structure / `DeleteStackPlan` |
| 13 | `deck_delete_board` | `DeleteBoard` | delete | Structure / `DeleteBoardPlan` |

All definitions retain `module: deck` and `app: deck`. The catalog validates unique
names and write interfaces without constructing tools. It preserves raw Deck key
order (`name`, `description`, `inputSchema`, `module`, `operation`, `app`) through
an explicit metadata projection of `ToolDefinition`; do not change the shared
`ToolDefinition::toArray()` order and thereby change Files. Keep absent optional
metadata absent. Put that projection in
`Presentation/DeckSchema::definitionArray(ToolDefinition $definition): array`,
used by interim definition substitution and the final catalog. Raw key order must
remain compatible in Phase 2, not only after Phase 6. The existing publisher still
owns annotations and `confirm`.

### Exhaustive gateway method destinations

These 28 methods keep their current parameter and result types at their new owner
unless the explicitly described Talk contract replaces the caller-facing shape.
This is an ownership map, not an instruction to retain the umbrella interface.

| Final owner | Current methods / logic |
| --- | --- |
| `Reading/BoardReader` | `listBoards` |
| `Reading/StackReader` | `listStacks` |
| `Reading/CardReader` | `listCards`, `findCard`, `cardBoardId`; batched assignment hydration for list reads |
| `Followup/FollowupQuery` | `followupCards`, managed-board selection, grouped/fallback stack scans; use AssignmentAccess for batch hydration |
| `Cards/CardWriter` | `createCard`, `updateCard`, `moveCard`, `deleteCard`; order calculation |
| `Cards/CardStateReader` | `cardState`, `findCreatedCard`; fresh reads and creation time window |
| `Assignments/AssignmentAccess` | `validateAssignees`, `assignCardUser`, `unassignCardUser`, `cardAssignments`; batch assignment retrieval for authorized card sets |
| `Access/OwnershipReader` | `stackOwnership`, `cardOwnership`, `boardOwnership`; checked owner/title reads |
| `Structure/BoardCreator` | `createBoard` |
| `Structure/StackCreator` | `createStack`; default stack order calculation |
| `Structure/StructureCardCounter` | `stackCardCount`, `boardCardCount`; active and archived card counts |
| `Structure/EmptyStructureDeletion` | `deleteEmptyStack`, `deleteEmptyBoard`; recount, restore and verification |
| `Structure/StructureStateReader` | `stacksOf`, `deletedStack`, `ownedBoards`; fresh reads including deleted state |
| `Access/DeckCaller` | Session equality check and BoardService user binding; no generic service cache |
| `Cards/CardUpdateArguments` | `fieldsAfterDone` compatibility logic for appended `startdate` and `color` parameters |
| `Cards/CardDate` | `localMidnight`, `dueDay` and caller-zone conversion shared by execution, plans and follow-up |
| `Cards/CardState` | Pure `isOpen` / `isOverdue` rules used by filtering and presentation |
| `Assignments/AssignedUsers` | `CardInput::assignees` normalization and `CardCriteria::assignedUids` extraction; no I/O |

Hydration is implemented once in AssignmentAccess; CardReader applies its results
to authorized cards. FollowupQuery uses the same batch primitive. That primitive
is feature-internal, has no consumer in Talk, and never supplies an unchecked
public card lookup. Permission checks remain at the query's entry point.

### Remaining source destinations

- Replace every `Handler/*Handler` with its corresponding Tool; remove the
  `AbstractHandler` hierarchy. Do not keep an old handler under a new Tool wrapper.
- Split `AbstractHandler::run` into `Execution/ResultBoundary` and its reusable
  result/error types; `afterWrite` and `afterApproximateWrite` become `VerifiedWrite`.
  `confirmShared` belongs to `Access/SharedBoardConfirmation`; `assignmentOf` belongs
  to AssignmentAccess; `newStack` belongs to StructureStateReader.
- Move card field validation to Cards/CardInput and AssigneeChanges to
  `Assignments/AssignmentChanges`. Split CardCriteria: only `matches` and status
  constants remain in Followup/CardCriteria; shared day conversion belongs to
  CardDate, open/overdue classification to CardState, and user-assignment extraction
  to AssignedUsers. Move `CardInput::assignees` to AssignedUsers as well. Presentation
  and Assignments must not depend on Followup; assignment normalization must not
  create a Cards ↔ Assignments cycle. Blueprint validation belongs to Structure.
  BlueprintProvisioner owns child sequencing and partial summaries; it calls the
  same CardWriter, StackCreator, verification and assignment services as other tools.
- Retain CardFormatter's payload behavior as Presentation/DeckFormatter. Its
  per-user factory prevents a cached formatter from retaining the first caller's zone.
- Split the existing renderer into card and structure renderers; DeckPlanRenderer
  only routes rendering and preserves null-on-malformed-plan fallback. Shared
  escaping, date and bounded-text formatting belong to Presentation/PlanText,
  which reuses the existing shared `Tools/PlanText` primitives.
- Extract previews to their eight named plan builders. PlanLocation owns checked
  board/list display data and current list-title lookup semantics; do not optimize
  the visible-board traversal during extraction. AssignmentPlan owns assignment deltas.
- Split DeckMessages: tool/schema constants to ToolMessages; common error mapping
  to ErrorMessages (all `error*` methods, including structure refusals); post-write
  warnings to WriteMessages; card plan text to CardMessages; structure plan and
  consequence text to StructureMessages. Execution exceptions use ErrorMessages
  and WriteMessages only, so moving refusal factories does not create an
  Execution → Structure → Execution dependency cycle.
  Preserve every source string and translation call; DeckGuide owns guide notes.
- Delete DeckToolModule, DeckServiceGateway and DeckGatewayInterface after their
  last consumers move. No `Legacy`, `Bridge`, `Compat`, forwarding alias or facade
  aggregating the 28 methods survives under another name.

### Contracts, composition and dependency direction

Use the existing `Mcp/Tools/{Tool,WriteTool,ToolDefinition,ToolContext}` contracts.
A Tool's context carries the authenticated UID, not a container or user-scoped
service graph. The permanent DeckModule accepts `array<string, Closure(): Tool>`
and a small DeckAvailability collaborator, validates exact catalog coverage and
selected tool identity, then delegates. Keep its existing direct-call app-availability
check and error wording; do not accidentally add that check to previews where the
registry currently owns it. Definitions, guide and renderer dispatch resolve no
Deck service. PreviewBoundary adds caller timezone and preserves preview exception
translation/logging; it does not perform workflow or persistence.

The cross-feature contract is exactly:

```php
namespace OCA\Mcp\Modules\Deck\Contracts;

interface CardReferenceReader {
    /** @return array{cardId: int, boardId: int, title: string} */
    public function read(string $userId, int $cardId): array;
}
```

`Reading/DeckCardReferenceReader` uses CardReader's permission-checked card and
board lookup. It does not expose a Deck entity or convert failures to MCP results.
Talk retains argument validation, module/grant/app checks, optional board-ID match,
generic inaccessible/missing error, title normalization and canonical URL building.
Replace its module-constant import with its existing literal module/app ID `deck`;
Talk imports only the new contract, never DeckModule or a concrete capability.

`Access/DeckCaller::bind(string $userId): BoardService` checks the required session
before resolving its typed BoardService provider and calls `setUserId` on every
operation, preserving permission-cache reset. Each Deck adapter calls it before
using its own providers. Application composes providers and request-scoped services;
no current UID is captured at module construction or in process-global state.
Native CardService, AssignmentService and ActivityManager can capture the session
UID when constructed; BoardService::setUserId does not rebind those instances.
Resolve them only after controller session binding and never reuse them across
authenticated request scopes. Test successive callers with separate request-scoped
Deck service graphs, and test pure metadata/formatter locale isolation separately;
do not implement cross-user rebinding of a cached native service graph.
`DeckFormatterFactory::forUser(string $userId): DeckFormatter` resolves timezone
per invocation. Plans receive UID explicitly and call the same CardDate conversion.

`ResultBoundary::run(string $tool, string $readWith, callable $operation): array`
keeps MCP wrapping, exception distinctions and log levels. VerifiedWrite keeps
separate `afterWrite` / `afterApproximateWrite` methods with the existing callable,
warning-list and probable-result semantics, adding explicit tool/read-hint parameters
instead of inherited state. Evidence predicates stay in the owning capability.
Do not turn verification into retries or a transaction manager.

Dependency direction is Tools → capabilities → their Deck providers/DeckCaller.
BlueprintProvisioner may depend on Cards and Assignments, but Cards never depends
on Structure. Presenters do not mutate. Cross-feature consumers use Contracts only.
The shared protocol and Platform layers gain no Deck imports. Application is the
composition root; its changes consist of factories and registrations, not workflows.

## 3. Compatibility invariants

Preserve these with existing tests before adding focused missing cases:

- List caps: boards/stacks 200; list-cards default 50, maximum 200; follow-up
  default 100, maximum 500. Follow-up caps visible boards at 100 before filtering
  manageable boards, respects board/status/assignee/due bounds, and preserves
  grouping and truncation (limit applies before multi-assignee repetition).
- Card titles 255 characters, descriptions 100,000; board/list titles 100;
  blueprints at most 20 lists and 100 cards total, with only the new board's owner
  assignable. Preserve validation paths, normalized colors and partial creation.
- Check READ/EDIT/MANAGE at the same query boundaries before disclosing protected
  data. Ownership checks differ by resource. Only the owner deletes a board.
- `confirm_shared` is a normal confirmation result with no mutation; origin and
  destination checks retain their order. It does not replace `WriteGate` confirmation.
- Missing edit fields remain unchanged; explicit null clears the due date.
  `lastModified` conflict and assignee validation precede the first mutation.
  Assign/unassign lists are capped at 100 each and cannot overlap.
- Preserve `done`, start date, color and other untouched fields across the API
  signatures already represented by NextcloudApiUsage and the Deck stubs. Keep
  grouped-query fallback for Deck versions without `findAllForStacks`.
- Application-origin refusal/conflict/session/validation exceptions bypass
  read-back. Exceptions raised by Deck listeners, including ordinary
  InvalidArgumentException, still trigger read-back after a possible persisted write.
- Preserve the 120-second recent-card match window, caller/title matching, newest-ID
  selection and known-ID exclusions; do not broaden the evidence heuristic.
- A heuristic creation match is only `confirmed: probable`: do not assign it or
  create children beneath it. Unconfirmed writes remain non-error results with a
  read hint. No-op edits must not be mistaken for evidence of persistence.
- Partial assignment and blueprint failures retain successes and warning details;
  unconfirmed items are not falsely categorized as definitely failed.
- Deletion uses Deck's soft-delete services. Empty checks count active and archived
  cards. Recount and restore remain after deletion; fresh verification distinguishes
  restored, still deleted and unknown. Do not promise atomicity against concurrent
  Deck writes or remove the documented residual race after the second count.
  StructureCardCounter separates initial permission-checked `stackCardCount` /
  `boardCardCount` from internal `cardsInStack` / `cardsInBoard` recounts used only
  by EmptyStructureDeletion after its access/ownership check. Do not implement
  the post-delete recount by calling the initial public count: Deck may refuse
  permission on the now-deleted parent, preventing the required restore. Restrict
  unchecked recount consumers to that authorized deletion workflow; they are not
  a tool or cross-feature API.
- Card-delete preview remains `recoverable: false` (this app has no restore tool);
  structure-delete previews retain their recovery/consequence messages.
- Preserve privacy: no raw exception messages, SQL, content or credentials in
  diagnostics. Preserve safe tool/exception-class logging and generic access errors.

## 4. Ordered implementation phases

Each phase follows: pin missing behavior with a focused regression test, observe
that it catches the proposed defect, implement the bounded extraction, run focused
and shared checks, review the diff, then make a coherent local commit. Existing
behavior tests should pass before and after a move; do not rewrite assertions just
to fit a new class graph. All commands run from the selected worktree.

### Cutover rules for every phase

- New production code never imports `Tools/Deck`; temporary dependency direction
  is old caller → final capability only. A migrated method has one implementation;
  any remaining old entry point delegates without duplicating its algorithm.
- When a class, fixture or registration changes, update all its consumers in the
  same phase. Phase 6 audits completeness; it is not a queue for broken imports.
  This includes reflection-based tests and generic contract providers.
- Move shared Deck stubs/helpers to `tests/Support/Deck` in Phase 1 and update all
  old and new tests' requires immediately. Retarget WritePlanContractTest's renderer
  class-to-test-method mapping when DeckPlanRendererTest moves. That contract does
  not consume sample data, so no PlanFixtures class is needed. Leave no loader shims.
- Before removing each Handler, replace its `::TOOL` references in the interim
  module's definitions, preview action values, dispatch and tests with the final
  Tool constant. Preserve exactly one published definition and active route.
- Move tests when their owner moves. Retain mixed tests only while they still test
  unmigrated behavior; split them as needed and reconcile their cases. New tests
  cannot depend on a retired class or an old test directory.
- Run the phase's focused tests plus the shared contracts before advancing. Commit
  only a coherent passing phase; remove its temporary delegates as soon as their
  final old consumer migrates. No phase is complete with deferred broken tests.

### Phase 0 — Freeze contracts and resolve prerequisites

**Files:** existing Deck tests, `tests/Unit/McpControllerTest.php`,
`tests/Unit/ApplicationRegistrationTest.php`, Talk ReferenceLinker tests;
new `tests/Unit/Contract/DeckMigrationCompatibilityTest.php`.

- [x] Inventory all 30 sources, 29 test files, four support files, gateway methods,
  consumers, constructor/reflection fixtures and generic module discovery. Capture
  PHPUnit test identifiers so split/moved suites can be reconciled at completion.
- [x] Characterize actual Application → controller/registry → Deck and Talk session
  binding, preview and disabled-app paths. Record the direct module discrepancy
  without making its bypass a required compatibility behavior. Prove supported
  public flows supply the session before dispatch; otherwise resolve the separately
  reviewed prerequisite before advancing. Phase 1 owns the failing regression test
  and implementation of mismatch-before-resolution for the common binder.
- [x] Freeze all 13 raw and published definitions, order, schemas and annotations;
  representative successful/refused/partial results, eight previews and rendered
  plans, guide notes, translated errors and locale changes. Store stable expected
  fixtures in final test support ownership, not a second copy of the implementation.
- [x] Use a baseline ledger outside the repository for temporary differential runs;
  never execute both implementations against live state. Record baseline commit,
  commands and results. Do not depend on that ledger for permanent tests.
- [x] Run focused Deck/Talk/registration/controller tests and shared contract tests;
  run the catalog `check` task for the full starting baseline. Commit characterization
  tests only after they pass against the documented baseline or approved prerequisite.

**Acceptance:** complete inventory; explicit session decision supported by entry-point
checks; no accidental guide correction; reproducible public baseline. A failing
prerequisite blocks Phase 1, not permission to retain a permanent bypass.

### Phase 1 — Establish composable execution and lazy access

**Files:** Access and Execution owners above; Presentation/DeckSchema,
ToolMessages, DeckFormatter and DeckFormatterFactory; Cards/CardDate and CardState,
Assignments/AssignedUsers, capability-owned message classes; Application factories;
`tests/Support/Deck`, WritePlanContractTest; `tests/Architecture/DeckBoundaryTest.php`.

- [x] Extract result/error mapping, shared confirmation and verified-write policy
  from AbstractHandler into composition. Keep application exceptions distinct from
  listener failures, unknown results non-error, and logging payloads unchanged.
- [x] Extract DeckCaller and DeckAvailability; implement named lazy providers only
  where first used. Test missing Deck without loading its service classes, failed
  availability without resolution, and UID mismatch before any Deck call. Exercise
  absent-app metadata in a fresh PHP process without Deck test stubs so a previously
  loaded fixture cannot hide eager class/service resolution. First
  demonstrate that the mismatch regression detects the old module bypass, then
  make it pass with the common binder; Phase 0 need not commit a failing suite.
- [x] Pin pure date, state and assignment rules before extracting formatter/schema/
  message helpers. Both old filtering and final formatting delegate to those rules;
  move their tests with them, preserving user/group/circle filtering and UID order.
- [x] Test successive authenticated requests with separate Deck service scopes and
  verify resolution occurs after session binding; test metadata and formatter reuse
  across users/locales without cached timezone or translated output. Preserve the
  per-operation permission-cache reset without attempting to rebind native services.
- [x] Move shared fixtures and stubs now. Preserve the renderer contract mapping
  without an artificial sample-data class. Update every require/import and shared contract consumer atomically;
  keep only still-needed legacy gateway builders in old tests until their owners move.
- [x] Adapt still-active handlers/gateway to delegate extracted shared behavior;
  list these temporary delegates in the removal inventory. Never duplicate safety
  algorithms. Start boundary checks for new code; permit old code only at explicitly
  named interim composition edges, to be removed in Phase 6.
- [x] Run execution/translation tests, all existing Deck tests, registration,
  controller session tests and generic contracts. Review and commit the foundation.

**Acceptance:** small independently testable execution collaborators; no unguarded
service resolution; no functional dependency on a second implementation of recovery.

### Phase 2 — Reading, follow-up and the Talk reference boundary

**Files:** five read Tools; Reading and Followup; AssignmentAccess's batch-read
primitive; CardDate; relevant final tests; `Tools/Talk/ReferenceLinker.php`;
Application, `tests/Unit/Tools/Talk/ReferenceLinkerTest.php` and
`tests/Unit/Contract/ToolErrorsLanguagesTest.php`.

- [x] Move list/read tests to their final owners and add gaps for permission-before-
  query, hidden/deleted cards, no Deck installation, assignment batching, 100-board
  cap and both grouped/fallback scans. Preserve ordering and duplicate group behavior.
- [x] Extract readers and follow-up query/filtering; use a single batch assignment
  implementation. Keep date and formatting helpers shared with later card writes.
- [x] Implement CardReferenceReader and switch Talk atomically with its factory/tests.
  Verify denied grants/app, mismatched board ID, generic not-found result, one-line
  title, canonical link and unchanged Calendar references.
- [x] Route the five migrated tools through the existing active module as a temporary
  delegator. Replace their definition entries with static Tool definitions; do not
  register two publishers or duplicate tool names. Remove their old handlers and
  retire old gateway read bodies once remaining callers delegate to final readers.
  Update ToolErrorsLanguagesTest to inject the final read collaborator instead of
  reflecting the old gateway property; keep its end-to-end translated error assertion.
- [x] Run moved and remaining Deck suites, Talk reference tests, registration,
  visibility, tool-list and language contracts. Review and commit.

**Acceptance:** five read tools and Talk use final capabilities; Talk has no old Deck
imports or native Deck entities. No read workflow is duplicated across trees.

### Phase 3 — Card writes, assignments and their plans

**Files:** four card WriteTools; Cards; remaining Assignments; card plan/rendering
owners; PreviewBoundary; matching final tests and compatibility fixtures.

- [x] Move card write, assignment, preview and after-write cases. Add missing combined
  cases for same-value edits after listener failure, emoji/storage normalization,
  date null versus omission, DST and untouched startdate/color across stub signatures.
- [x] Extract CardWriter, CardStateReader, CardUpdateArguments, AssignmentEditor and
  AssignmentPlan. Keep operation-specific evidence predicates beside the operation;
  preserve prevalidation and partial assignment reporting.
- [x] Extract four card plan builders and CardPlanRenderer. Keep plans side-effect
  free, preserve timezone envelope and exception mapping, and render existing stored
  plan shapes. Do not replace the whole shared PlanRenderer/WriteGate system.
- [x] Route execute and preview together for each migrated write. Test write-gate
  dry-run versus confirmed execution, shared-board refusal, cross-list/board movement,
  listener failures and heuristic matches that must never receive assignments.
- [x] Remove old card handlers/preview bodies and duplicated gateway bodies; pending
  structure callers delegate to the new Card/Assignment services. Run all Deck,
  shared write-plan/gate, localization and Nextcloud API scanner tests. Review and commit.

**Acceptance:** all four card writes use final services and previews; no successful,
probable, unconfirmed or partial outcome changes its public meaning.

### Phase 4 — Board/list creation and blueprint provisioning

**Files:** CreateBoard/CreateStack tools; Structure creation, blueprint/state
owners; creation plans; StructurePlanRenderer; associated final tests.

- [x] Move blueprint and creation cases, including invalid child input before any
  mutation, duplicate titles, known-ID exclusions and board label/event failures.
- [x] Extract BoardCreator, StackCreator and StructureStateReader; creation evidence
  uses fresh reads. Move child orchestration into BlueprintProvisioner with explicit
  dependencies on card, assignment and verification services, never on other Tools.
- [x] Extract the two creation plans/rendering branches and route both write tools.
  Verify one confirmation covers the blueprint, probable parents receive no children,
  probable cards receive no assignments, and partial/unknown results retain their hints.
- [x] Remove old creation handlers and preview bodies. Reconcile moved tests and run
  all Deck suites, shared write contracts, registration and translated structure
  output checks. Review and commit.

**Acceptance:** creation/provisioning is cohesive; one tested implementation of each
child mutation and recovery rule; no BlueprintProvisioner container or gateway facade.

### Phase 5 — Empty-structure deletion and recovery

**Files:** DeleteStack/DeleteBoard tools; StructureCardCounter,
EmptyStructureDeletion, state readers, deletion plans/rendering; final tests.

- [x] Move emptiness, owner/manage permission, deletion and restore cases. Add missing
  cases for archived cards, cards arriving after initial count, restore persisting
  before an exception, stale entity caches and unreadable verification state. Test
  that post-delete recount still runs when normal access to a deleted parent would
  be refused, and that an unauthorized initial request never reaches that recount.
- [x] Extract counts and deletion/recovery without replacing service calls with direct
  SQL or claiming a transaction closes the concurrent insertion window. Preserve
  pre-write refusal versus after-write partial/unconfirmed result distinctions.
- [x] Extract deletion previews and renderer branches; execution rechecks emptiness
  and ownership rather than trusting a saved preview. Keep residual-race guidance.
- [x] Switch the final two tools and remove their old handlers/preview methods and
  gateway bodies. Run every Deck suite, full shared write contracts, Talk and
  application registration. Review and commit.

**Acceptance:** all 13 tools execute through final capabilities; restore verification
is fresh and permission-checked; no new deletion behavior or lost recovery warning.

### Phase 6 — Permanent composition, removal and final audit

**Files:** DeckModule, DeckToolCatalog, DeckGuide, renderer routing; Application;
all remaining old Deck paths and consumers; boundary/contract tests; docs.

- [x] Replace the interim module with the permanent factory adapter. Validate all
  13 catalog keys, exact tool identity and eight WriteTool implementations without
  eagerly constructing them. Test unknown names and mismatched factories.
- [x] Remove `lib/Tools/Deck` completely, including its gateway/interface, abstract
  handler, renderer, schema helpers and messages. No dual dispatch or legacy map.
- [x] Move all remaining tests and audit final support ownership. Replace tests of private legacy wiring
  with public behavior/provider boundary tests; account for every old test identifier.
  Verify all temporary gateway builders have been replaced with focused
  ownership/service fixtures; retaining a mock of the deleted umbrella interface
  is not a completed test move.
  Confirm Phase 1 support files are the sole fixture owners. Remove
  `tests/Unit/Tools/Deck`, old requires and autoload/class aliases entirely.
- [x] Audit the consumer updates already made in their owning phases: application
  registration, WritePlanContractTest, ToolErrorsLanguagesTest and NextcloudApiUsage.
  Finish final-module assertions and FilesBoundaryTest's negative Deck example. Keep
  historical API coverage (including older fixtures) even where minimum app support
  is newer; update source ownership rather than weakening expectations.
- [x] Enforce Deck boundaries: no container/other-module internals in capabilities,
  no shared protocol/Platform dependency on Deck, Talk imports only Contracts, no
  aggregate gateway or legacy handler hierarchy. Scan namespaced references, dynamic
  class strings, `new`, reflection, require/include, Composer maps, tests, scripts,
  package configuration and CI. Negative fixtures may name retired paths solely to
  assert rejection; document that narrow exception, never an active allowlist.
- [x] Reconcile source/test inventories and compare final raw/published contracts with
  Phase 0. Run full local checks and a freshly built package; verify every final Deck
  source and autoload target is included, no old tree or test dependency is shipped.
- [x] Self-review the complete diff for authorization, mutation/preview boundaries,
  compatibility, privacy, dependency cycles and responsibility concentration. Obtain
  independent review when requested; resolve findings before marking local completion.
- [x] Update this plan's evidence/status, add an execution report and extend the tool
  authoring guide with a Deck example. Update documentation indexes, validate local
  links/SPDX, retain baseline names in historical prose rather than broken source links.
- [x] Commit the coherent composition/removal/documentation changes after checks pass.
  Record outstanding remote matrix and disposable-server gates explicitly.

**Acceptance:** the final source, tests and package contain no Deck transition
implementation. A passing suite cannot conceal dropped tests or a missing packaged class.

### Documentation review outcome

The review accounted for all 28 gateway methods and 13 tools, separated pure
shared rules from follow-up queries, pinned the post-delete recount boundary,
and made test/support cutovers part of their owning phases. Session convergence
remains conditional on Phase 0 evidence for supported authentication flows; the
move-guide correction is non-blocking follow-up. These are plan decisions, not
implemented behavior or passing evidence for the proposed services.

Review validation reran the planning-time command with
`tests/Unit/McpControllerTest.php` included: **494 tests, 3,130 assertions, passing**
on PHP 8.4.26. Local Markdown targets and the documentation-only diff were checked.
No migration code or tests were changed by the documentation review.

## 5. Validation commands and final acceptance

Prefer the registered catalog task for the complete local gate:

```sh
devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/devex-architecture-docs --json
```

Focused test commands run from `mcp/`. During Phases 1–5, include both the remaining
old Deck test directory and each new capability test directory that exists. After
removal, use only final paths. For example, the final focused gate is:

```sh
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit tests/Unit/Modules/Deck tests/Unit/Tools/Talk/ReferenceLinkerTest.php tests/Unit/ApplicationRegistrationTest.php tests/Unit/McpControllerTest.php tests/Unit/Contract tests/Unit/Tools/WritePlanContractTest.php tests/Architecture
```

The repository's full PHPUnit command uses the same PHP limits without path
arguments. `sh scripts/package.sh`, also from `mcp/`, builds and invokes the package
validator. Inspect the resulting archive and optimized production autoload from a
fresh package, not a development tree that may still autoload removed classes.
Do not add a CI workflow or developer command merely to rename existing checks.

- [x] All 13 tools and eight previews have final owners and compatible public output.
- [x] Catalog, lazy optional-app wiring, user/locale isolation and Talk boundary pass.
- [x] Recovery, partial failure, permissions and empty-deletion race behavior pass.
- [x] No old Deck production/test folders, broad gateway, alias, migration fallback,
  duplicate implementation, legacy factory or temporary fixture loader remains.
  Supported external-API behavior such as the grouped-query fallback, optional
  parameter handling and malformed-plan rendering fallback remains: those are
  permanent compatibility requirements, not migration scaffolding.
- [x] Every old source/test and external consumer is accounted for; boundaries and
  fresh-package checks pass; local review has no unresolved blocker.
- [ ] PHP 8.2–8.5 CI is verified when publication is explicitly authorized. Validate
  the supported Nextcloud/Deck API combinations represented by the repository's
  contract fixtures; local stubs are not proof against an installed Deck release.
- [ ] On an authorized disposable Nextcloud instance, check Deck disabled/absent,
  Bearer/session identity, grants, own/shared board reads and writes, Talk references,
  dates, assignments, blueprint partial outcomes and empty-structure deletion/restore.
  Record unavailable scenarios as integration gates, not as successful verification.
- [x] Report local completion separately from production readiness and list the next
  migration candidates without starting another module.

Temporary delegation may exist only inside the implementation series. Final removal
is part of this migration's acceptance, not follow-up work. Roll back coherent
capability commits together with their registration/consumer changes; do not restore
half a namespace move or add compatibility shims as a rollback mechanism. No data
migration is planned.

After Deck, evaluate Talk separately: its Deck reference dependency will already
use a narrow contract, while its Calendar dependency and remaining responsibilities
stay outside this plan. Files' integration gates and repository-wide documentation,
i18n and developer-command work remain distinct unfinished work.


## Execution decisions

The [execution report](2026-10-16-deck-capability-migration-report.md) records phase
checks and final ownership. Cohesive operation classes keep the eight write tools
small and separate orchestration from native adapters. Five read tools accept one
typed query closure bound to their exact capability method; no generic locator or
operation map enters a tool.

`WritePlanContractTest` maps renderer test classes to required test methods. It did
not consume shared sample data; its mapping now points to the final Deck renderer
test. No artificial `PlanFixtures` class was introduced.

`StructureCardCounter` issues a resource-bound recount closure after the original
MANAGE or owner check. `EmptyStructureDeletion` uses it before and after soft
deletion. Raw counts remain private; the post-delete count does not repeat a check
that may reject the deleted parent and skip restoration. The accepted residual
race window remains unchanged.
