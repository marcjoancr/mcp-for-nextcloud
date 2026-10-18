<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Deck capability migration execution report

Status: implementation, local validation and independent review complete. Remote CI and disposable-server verification
remain integration gates. Execution base: `12e8fe2`. Scope: Deck and its direct
consumers only. Branch retained: `refactor/files-read-edit-pilot`.

## Phase evidence

Each phase passed the full suite before the next phase began. Commands ran in the
validated worktree, with PHP 8.4.26 and the existing dependency lock.

| Phase | Local validation | Commit |
| --- | --- | --- |
| 0: contracts and prerequisites | Baseline devbox check: 5,125 tests / 31,753 assertions; package valid. Characterization/controller/registration: 36 tests / 155 assertions. Exact definitions and guide frozen. | `ed8d09b` |
| 1: access and execution foundations | Session mismatch regression red → green; metadata works in a separate process without Deck stubs. Full suite: 5,129 tests / 31,839 assertions. | `83b3bfe` |
| 2: reading, follow-up and Talk | Limited reference payload and denied-card short circuit verified. Full suite: 5,131 tests / 31,892 assertions. | `effeaba` |
| 3: card writes, assignments and previews | Confirmations, read-back evidence, assignments and card rendering preserved. Full suite: 5,131 tests / 31,997 assertions. | `5f9a234` |
| 4: creation and blueprint provisioning | Partial outcomes and probable-parent restrictions preserved. Full suite: 5,131 tests / 32,048 assertions. | `13c0501` |
| 5: deletion and recovery | Empty checks, concurrent cards, restores and fresh verification preserved. Full suite: 5,131 tests / 32,081 assertions. | `475ceda` |
| 6: permanent composition and removal | Final reviewed devbox check: **5,144 tests / 32,912 assertions**, with no test warnings or deprecations; rebuilt production archive valid. | Closing migration commit |

The complete registered check is:

```sh
devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/devex-architecture-docs --json
```

The closing run passed **5,144 tests / 32,912 assertions**, with no warnings or
deprecations, and rebuilt/validated the production archive.
After `composer dump-autoload --optimize`, the focused Deck, architecture,
registration and tools/list suites pass **479 tests / 6,403 assertions**. Composer
reports pre-existing non-PSR stub declarations; those fixtures are explicitly
required by tests and are excluded from the production archive.

The independently extracted `mcp-1.0.8.tar.gz` contains all **83 Deck PHP sources**,
no old Deck tree, no test suite and no PHPUnit dependency. Its production-only
autoloader loads all 83 Deck classes/interfaces in a fresh process, with the native
Deck app absent. All 13 definitions and the guide match the frozen baseline.
Generated archives/extraction stay in the ignored worktree build directory.

## Final architecture

```text
lib/Modules/Deck/
  DeckModule.php                 # availability, lazy dispatch, presentation adapter
  DeckToolCatalog.php            # explicit ordered metadata
  Tools/                        # 13 Tool/WriteTool entry points
  Reading/                      # board/list/card queries and the Talk reference adapter
  Followup/                     # bounded queries and filtering
  Cards/                        # validation, writes, read-back, operations and plans
  Assignments/                  # account access, changes and partial-result reporting
  Structure/                    # blueprint, creation, counts, deletion and recovery
  Access/                       # caller binding, app availability, ownership, confirmation
  Execution/                    # safe results, previews, verified writes and exceptions
  Presentation/                 # formatting, schemas, guide, messages and renderers
  Contracts/CardReferenceReader.php

tests/Unit/Modules/Deck/         # capability and public-tool tests
tests/Support/Deck/             # typed doubles, explicit fixtures and shared stubs
tests/Architecture/DeckBoundaryTest.php
```

The two former source/test directories are absent. There is no gateway interface,
gateway implementation, abstract handler, alias loader or alternate old dispatch.
Supported Deck API fallbacks remain intentional production behavior.

Tools own metadata and protocol entry points. Eight small operation classes keep
write orchestration separate from native adapters and plans. Five read tools use
one typed query closure bound to the exact reader method. Application is the only
composition point that captures the container. Native providers stay lazy; the
module validates its factory keys without invoking them.

## Decisions and compatibility

- `DeckCaller` requires the actual session. The earlier module-specific bypass is
  gone. The existing controller binds Bearer identity before dispatch and clears
  it afterwards; session authentication remains unchanged. Native service graphs
  are request scoped and must not be rebound across different users.
- `VerifiedWrite` retains confirmed/probable/unconfirmed outcomes. A probable
  board/list receives no children, and a probable card receives no assignments.
  Partial failures preserve already-created resources and safe recovery guidance.
- `StructureCardCounter` returns a resource-bound recount closure only after the
  original MANAGE or owner check. Deletion uses the same closure before and after
  soft deletion, while raw count methods remain private. A new permission check
  against a deleted parent would incorrectly prevent the recovery recount.
- `CardDate`, `CardState` and `AssignedUsers` hold shared pure rules. Presentation
  does not depend on follow-up orchestration, and exceptions do not depend on
  structure workflows. Dates, null/omitted values and optional native update
  parameters retain their existing behavior.
- `WritePlanContractTest` maps renderer tests to required methods; it did not use
  shared sample data. Its reference was updated without inventing a `PlanFixtures`
  class. Typed test collections expose narrow collaborators, not gateway methods.
- The external working ledger stayed outside the repository, as required by the
  devbox instructions. The durable phase evidence and rulings are recorded here.

## Source and consumer reconciliation

| Former owner(s) | Final owner(s) |
| --- | --- |
| DeckToolModule | DeckModule, DeckToolCatalog, Tools, plan builders, DeckGuide and DeckSchema |
| DeckServiceGateway / DeckGatewayInterface | Reading, FollowupQuery, CardWriter, CardStateReader, AssignmentAccess, OwnershipReader and Structure adapters; broad interface removed |
| AbstractHandler | ResultBoundary, VerifiedWrite, SharedBoardConfirmation, AssignmentAccess and StructureStateReader |
| Five read handlers | ListBoards, ListStacks, ListCards, ReadCard and FollowupCards |
| Four card write handlers | CreateCard/EditCard/MoveCard/DeleteCard, their operations and plan builders |
| Four structure handlers | CreateBoard/CreateStack/DeleteStack/DeleteBoard, their operations, plans and BlueprintProvisioner |
| CardCriteria / CardInput / AssigneeChanges | Followup/CardCriteria, Cards/CardInput, AssignmentChanges, AssignedUsers, CardDate and CardState |
| BoardBlueprint / CardFormatter | Structure/BoardBlueprint and Presentation/DeckFormatter with its factory |
| DeckPlanRenderer / DeckMessages | CardPlanRenderer, StructurePlanRenderer, PlanText, dispatcher and focused message owners |
| DeckErrors / DeckResult / four exceptions | Execution |
| Talk ReferenceLinker | Only CardReferenceReader; unchanged validation/grants and canonical link behavior |
| Application and generic module builders | Final catalog/factories; no old gateway registration or reflective gateway injection |
| API usage inventory | Formatter, FollowupQuery, CardUpdateArguments and CardStateReader |
| Shared Deck helpers/stubs | tests/Support/Deck; explicit requires and no old-tree loader |

All 30 old production files are accounted for. The original 29 test files are
moved or split by capability; shared helper/stubs have final owners. Comparing
PHPUnit test identifiers before/after relocation finds no missing behavior case.
The single replaced identifier is the old gateway-factory construction test,
replaced by `testDeckFactoriesBuildEveryToolLazilyWithRequiredContext`.

Raw metadata/guide compatibility, published tools/list contracts, grants, write
confirmation, translated errors and plan rendering remain covered. Boundary tests
inspect tokens and reject containers or other module internals in Deck. A separate
scan includes executable/support code, service-ID strings, autoload paths, package
scripts and CI configuration. Talk may import only the small Deck contract.

## Independent review

A fresh reviewer examined the migration from `12e8fe2` through the final worktree,
including capability boundaries, native composition, failure recovery and Talk.
The review found an unintended direct-preview availability check. A regression
first failed on that extra check and on resolving a non-writing preview. The fix
keeps availability in the existing direct-call/registry boundaries and preserves
the original localized refusal for unknown/read-only preview names before tool
resolution. Focused preview/dispatch tests passed after the fix; the reviewer
independently verified 28 tests / 164 assertions, including exact compatibility.
The final full check above includes the correction.

The reviewer also independently ran Deck, boundary, metadata and Talk checks
(477 tests / 3,901 assertions before the final preview regression), confirmed all
391 original Deck test method identifiers remain, and reported no unresolved
correctness, security or specification findings. Whitespace cleanup and local
documentation-link checks are complete. The final archive's 83 Deck source files
match the worktree byte for byte.

## Remaining work

1. **Integration gates:** run the PHP 8.2–8.5 CI matrix when publication is authorized.
   Exercise supported Nextcloud/Deck versions on an authorized disposable instance:
   app absent/disabled, Bearer/session identity, grants, own/shared resources, Talk
   references, timezone dates, assignments, blueprint partial outcomes and deletion
   races/restoration. Local fixtures and API contracts do not prove live behavior.
2. **Existing guidance inconsistency:** the frozen Deck guide says movement is
   same-list only, whereas implementation/schema/tests support cross-list and
   cross-board movement. Correct that public guidance in a separately scoped
   follow-up; no behavior was silently changed during this migration.
3. **Next module:** analyze Talk first. Its Deck dependency is now narrow; its
   Calendar reference path and remaining responsibilities still need inventory and
   a reviewed capability plan before implementation. Other modules remain under
   their existing owners.
4. **Broader DevEx:** command aliases, static analysis/formatting policy, operational
   README relocation and actual translated docs remain separate work. English
   sources and GitHub-friendly language-directory conventions already apply.

No push, pull request, deployment or migration of another module was performed.
