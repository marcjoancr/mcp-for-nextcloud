<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Notes capability migration plan

> **For agentic workers:** Implementation requires separate authorization. Once
> authorized, use `superpowers:executing-plans` for ordered execution. Finish and
> validate each phase before starting the next; delegate only when authorized by
> the execution workflow. The checkboxes below record the implementation checkpoints.

**Status:** Implemented and independently reviewed locally; supported-version and
live-host release gates remain outstanding.
**Review:** Checked against source, consumers and tests at `c4f31fe`; implementation
was authorized and started from the reviewed plan commit `d51e4f4`.
**Evidence:** [Execution report](2026-10-16-notes-capability-migration-report.md).
**Date:** 2026-10-16.
**Planning baseline:** `c4f31fe`, after Files, Deck, Talk and Calendar/DAV/Contacts/Tasks.
**Goal:** Migrate all seven Notes tools and the `nc://notes/{id}` resource by
capability, preserving behavior and retiring every old Notes source and test owner.
**Architecture:** Keep the existing MCP contracts, registry and shared file guards.
Separate note storage/navigation, reading/search and four write workflows. Expose
resource reading through a feature-owned contract; keep tool publication and
presentation independent of filesystem access.
**Tech stack:** PHP >=8.2, Composer PSR-4 `OCA\Mcp`, PHPUnit 10.5, native Nextcloud
app `mcp`, declared Nextcloud 32–35 support. No dependency or database changes.
**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Files report](2026-10-16-files-capability-migration-report.md),
[Deck report](2026-10-16-deck-capability-migration-report.md),
[Talk report](2026-10-16-talk-capability-migration-report.md),
[DAV report](2026-10-16-calendar-dav-capability-migration-report.md) and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Keep identifiers, comments, PHPDoc and technical documentation in English.
  Preserve translated source strings, interpolation, timing and multilingual
  behavioral fixtures; reset translator state between tests.
- Preserve names, order, grants, app filtering, annotations, defaults, schema
  object/array shapes, raw metadata key order, results, plans, Markdown and guide
  notes. No new public arguments, tools, resource templates or recovery promises.
- Keep `ToolRegistry`, per-call grants/app checks and `WriteGate` mandatory on
  public tool requests. `ToolContext` supplies identity. Confirmed writes re-read
  current state instead of executing a saved preview.
- Preserve direct module-call semantics: Notes currently executes writes without
  checking `confirm`; only the registry supplies general approval. `confirm_shared`
  is still checked inside workflows. Do not copy Contacts/Tasks' direct-call plan
  fallback into Notes.
- Previews never create folders, write content, rename, delete or create backups.
  A preview may warn about a lock; execution refuses it according to current rules.
- Continue using the user's `IRootFolder` view and OCP file methods. No Notes app
  service API, direct SQL/storage mutation, DAV conversion or Files tool delegation.
- Keep dependencies explicit and lazy at composition. Definitions, guides and saved
  plan rendering perform no filesystem access and resolve no optional app services.
  Do not inject a container into a tool, capability or resource reader.
- Preserve failure precedence, visibility, ETags, locks and partial writes. Do not
  add retries, rollback, backups or stronger validation incidentally. Record any
  discovered functional defect separately from this structural migration.
- Shared `Tools/Common`, `ToolModule`, `PreviewsWrites`, `RendersPlans`,
  `ToolGuideNotes`, `ToolResult`, `ToolFailure`, `PlanText`, registry/policy APIs,
  `VisibilityGuard` and localization remain active shared owners. Do not relocate
  them, migrate People/Logs or change authentication/administration in this scope.
- Reuse the validated worktree and keep its branch name. Preserve unrelated work;
  no push, PR, deployment or real-user mutation is included. Keep execution scratch
  outside the repository; keep project build artifacts inside the worktree.

## Review focus

1. A lock or storage refusal arrives after content was saved but before rename:
   preserve partial-edit wording, structured lock data and the unchanged unexpected
   exception, with no false "nothing changed" claim (Phases 0 and 3).
2. Hidden roots/categories, repeated file IDs or changed visibility expose a note
   through tools or resources: preserve scoped lookup, guard placement, not-found
   responses and invalidation after mutation (Phases 0–3).
3. A preview, shared confirmation or same-category move creates a folder or changes
   failure precedence: pin missing roots, category `0`, collisions and simultaneous
   ACL/ETag/lock/trash failures before extraction (Phases 0, 1 and 3).
4. Search or plan extraction subtly changes bytes, Unicode, ordering or bounded
   traversal: retain 1 MiB, 2,000 candidates, full-content change detection and
   distinct read/search/preview treatment of content (Phases 0–3).
5. Resource construction, tests or a packaged classmap retain the old repository:
   test the actual registration closures, missing optional apps, isolated test
   processes and the production archive, not only a test factory (Phases 1 and 4).

## 1. Findings and completion boundary

A full migration without transition code is feasible as a single Notes scope,
including the resource consumer. There is no runtime dependency on Notes internals
from another feature module. The resource registry currently imports the broad
`NotesRepository`, so moving tools alone would leave a permanent dependency on it.

At the planning baseline there are four production files in `mcp/lib/Tools/Notes/`:

| Owner | Lines | Responsibilities to separate |
| --- | ---: | --- |
| `NotesModule.php` | 741 | Seven schemas, dispatch, listing/search, writes, previews, ACL/ETag/lock coordination, snapshots and trash checks. |
| `NotesRepository.php` | 262 | Configured root, lookup/enumeration, categories, naming, content limits and metadata. |
| `NotesPlanRenderer.php` | 214 | Pure Markdown rendering for four related write plans. |
| `NotesMessages.php` | 149 | Tool/parameter metadata and translated storage/write/plan messages. |

Line counts include comments, not an acceptance threshold. Retain the renderer as
one cohesive pure formatter: its four short formats share escaping/consequence
rules. Splitting it into four forwarding objects would not improve this scope.
Split the mixed module and repository; do not recreate either under a new name.

### Public surface

| Order | Tool | Operation | Final tool / capability |
| ---: | --- | --- | --- |
| 1 | `notes_list` | `read` | `Tools/ListNotes` → `Reading/NoteLister` |
| 2 | `notes_search` | `read` | `Tools/SearchNotes` → `Search/NoteSearch` |
| 3 | `notes_read` | `read` | `Tools/ReadNote` → `Reading/NoteReader` |
| 4 | `notes_create` | `create` | `Tools/CreateNote` → `Creation/NoteCreator` |
| 5 | `notes_edit` | `edit` | `Tools/EditNote` → `Editing/NoteEditor` |
| 6 | `notes_move` | `move` | `Tools/MoveNote` → `Moving/NoteMover` |
| 7 | `notes_delete` | `delete` | `Tools/DeleteNote` → `Deletion/NoteDeleter` |

All definitions retain module/app `notes`. The module stays second in
`Application::MODULES`, between Files and Calendar. The raw definition key order is
`name`, `description`, `module`, `operation`, `app`, `inputSchema`; the empty
`notes_list` properties value remains an object. `WriteGate` remains the owner of
published confirmation arguments and annotations.

The resource stays `nc://notes/{id}`, with the original URI and
`mimeType: text/markdown` even for `.txt` notes. Resource listing/template ordering,
grants, app filtering and protocol error mapping stay with the shared registry.

### Direct consumers and tests to retarget

- `mcp/lib/AppInfo/Application.php`: module list, new lazy tool factories, capability
  wiring and the resource-reader contract registration.
- `mcp/lib/Service/ResourceRegistry.php`: replace `NotesRepository` injection with
  `Modules/Notes/Contracts/NoteResourceReader`; retain grant/app checks before
  delegating. Move only Notes URI parsing and node reading into the implementation.
- `mcp/tests/Unit/Service/ResourceRegistryTest.php`: every registry constructor and
  privacy fixture; preserve resource and protocol assertions, not just import names.
- `mcp/tests/Unit/Tools/WritePlanContractTest.php`: module and renderer fixture map.
- `mcp/tests/Unit/Contract/VisibilityContractTest.php`: explicit seven-tool matrix,
  reflection-based construction and every Notes preview/execution scenario.
- `mcp/tests/Unit/Contract/ToolErrorsLanguagesTest.php` and
  `mcp/tests/Unit/L10nLanguagesTest.php`: constructors and final message owners.
- `mcp/tests/Unit/ApplicationRegistrationTest.php`: actual composition coverage.
- `mcp/tests/Unit/Contract/NextcloudApi/`: recursive source discovery should include
  the new owners automatically; inspect scanner/provider assertions rather than
  deleting generated API cases simply because paths moved.

The five old dedicated test files contain **86 test methods** (providers expand
this count): `NotesModuleTest` 41, `NotesPreviewTest` 14, `NotesPlanRendererTest` 18,
`NotesLockTest` 9 and `NotesTranslationTest` 4. Preserve all methods/provider cases
or document a genuine equivalent replacement. They currently live directly under
`tests/Unit/Tools/`, not in a `Notes/` subdirectory. Do not delete that shared directory.

## 2. Final ownership and interfaces

All paths below are under `mcp/lib/Modules/Notes/`; each listed class is its own PHP
file. Shared file APIs remain where they are; no new generic platform layer is needed.

```text
Notes/
  NotesModule.php
  NotesToolCatalog.php
  Tools/
    ListNotes.php, SearchNotes.php, ReadNote.php
    CreateNote.php, EditNote.php, MoveNote.php, DeleteNote.php
  Storage/
    NotesFolders.php, NoteLookup.php, NoteMetadata.php, NoteNames.php
    NamingMessages.php
  Content/
    NoteContent.php, ContentMessages.php
  Reading/
    NoteLister.php, NoteReader.php
  Search/
    NoteSearch.php
  Creation/
    NoteCreator.php
  Editing/
    NoteEditor.php, RenameTarget.php, PartialEditFailure.php
  Moving/
    NoteMover.php
  Deletion/
    NoteDeleter.php, TrashRecovery.php
  Execution/
    NoteWriteAccess.php, NoteSnapshot.php, NotePlanNotice.php, WriteMessages.php
  Presentation/
    NotesPlanMessages.php, NotesPlanRenderer.php
  Contracts/
    NoteResourceReader.php
  Resources/
    NextcloudNoteResourceReader.php
```

### Storage and content

These are focused concrete classes, not interfaces plus adapters for each operation.
Use existing OCP types (`File`, `Folder`, `Node`) and validated associative arrays.
Do not add generic requests, a prepared-write framework or a dependency bag.

| Class | Public surface and ownership |
| --- | --- |
| `NotesFolders` | `folder(string $userId, bool $create): ?Folder`, `existingAncestor(string $userId, string $category): Folder`, `existingCategory(?Folder $root, string $category): ?Folder`, `assertCategoryVisible(Folder $root, string $category): void`, `category(Folder $root, string $category): Folder`, `categoryName(Folder $root, Folder $folder): string`. Owns root config and category navigation/creation, with optional `VisibilityGuard` matching current semantics. |
| `NoteLookup` | `all(Folder $root): array`, `find(?Folder $root, int $id): File`, `static isNote(File $file): bool`. Owns recursive candidate traversal and ID-scoped readable/visible lookup, with optional visibility guard. |
| `NoteMetadata` | `info(File $note, Folder $root): array`. Keeps current visibility assertion and ordered metadata fields. |
| `NoteNames` | `freeName(Folder $folder, string $title): string`, `renamedName(File $note, string $title): string`. Owns sanitization, suffixes and extension preservation. |
| `NoteContent` | `read(File $note): string`, `assertSize(string $content): void`. Owns the 1 MiB read/write bounds and UTF-8 scrubbing on reads. |

Construction stays explicit: `NotesFolders` receives `IRootFolder`, `IConfig` and
`?VisibilityGuard`; `NoteLookup` and `NoteMetadata` each receive `?VisibilityGuard`.
`NoteNames` and `NoteContent` need no services. Production tool wiring always passes
the shared visibility guard; null remains only for the intentionally unfiltered
resource dependency instances and tests reproducing that baseline boundary.

Move constants to their actual owners: `NotesFolders::DEFAULT_FOLDER = 'Notes'`,
`NoteLookup::MAX_NOTES = 2000`, `NoteContent::MAX_BYTES = 1048576`, private note
extensions `['md', 'txt']` on lookup, private `MAX_SUFFIX = 100` on names. No legacy
module/repository constant aliases remain; migrate their consumers explicitly.

### Capability and write interfaces

Read capabilities return domain payload arrays, without `ToolResult` envelopes:

- `NoteLister::list(string $userId): array`.
- `NoteReader::read(string $userId, int $id): array`.
- `NoteSearch::search(string $userId, string $query, int $limit, string $category): array`.

Each of `NoteCreator`, `NoteEditor`, `NoteMover`, `NoteDeleter` exposes
`preview(array $arguments, string $userId): array` and
`execute(array $arguments, string $userId): array`. Keep preview and execution
within the same capability so their related rules change together; do not merge
checks whose order currently differs. Execution returns the domain result or
shared-confirmation payload. Tools supply the MCP envelope and `NodeAccess::run`.

Read capabilities receive only their used storage/content services and
`NodeAccessInfo`; search additionally receives the optional visibility guard used
by its existing explicit filter. Writes receive their used storage/content services
and the supporting owners below, never an aggregate service bundle. `NoteWriteAccess`
receives `SharedWriteGuard`, optional `LockAwareWrite` and optional `VisibilityGuard`;
`TrashRecovery` receives `IAppManager` and `IUserManager`. Production wiring supplies
the existing shared lock service, whose provider remains lazy. Creation keeps its
existing `NodeAccess::mutate(..., $visibilityGuard)` path; do not invent a File to
route a newly created note through the existing-note lock helper.

Supporting owners have specific purposes:

- `RenameTarget::resolve(File $note, string $title): ?string` owns naming and the
  rename permission/collision preflight, shared by edit preview and execution.
- `PartialEditFailure::map(\Throwable $failure): \Throwable` preserves the exact
  known-file-exception mapping, `LockWriteFailure::withNote` behavior and unexpected
  exception identity after content has been saved.
- `NoteWriteAccess::assertWritable(File $note, array $arguments): void` checks update
  before ETag. `confirmation(Node $note, string $userId, string $resource,
  bool $confirmed): ?array` delegates only shared confirmation. `assertUnlocked(File
  $note, string $userId): void` and `mutate(callable $write, File $note, string $userId,
  bool $untouched = true): mixed` preserve current lock and visibility invalidation.
  Keep operation ordering in each workflow, not a generic execute/prepare switch.
- `TrashRecovery::assertEnabled(string $userId): void` and
  `assertStorage(File $note): void` preserve the two distinct deletion gates;
  `TRASH_STORAGE = 'OCA\\Files_Trashbin\\Storage'` moves here.
- `NoteSnapshot::snapshot(Folder $root, File $note): array` and
  `excerpt(string $content): string` retain the size check, raw content and
  400-character excerpt. It lives in `Execution/` because it reads current state;
  do not implement snapshots through `NoteContent::read`, which scrubs content.
  `Presentation/` contains only translated plan text and pure saved-plan rendering.
- `NotePlanNotice::shared(Node $note, string $userId): array` and
  `lock(File $note, string $userId): array` supply existing per-user plan data.
  It lives in `Execution/`, using shared `NodeAccessInfo` and optional
  `LockAwareWrite` to read current access/lock state; retain no identity.
- `NotesPlanRenderer::render(string $tool, array $plan): ?string` remains pure and
  stateless, with its 200-character inline rendering and null fallback.

Move metadata strings to each static tool definition. `ContentMessages` owns the
two size refusals; `NamingMessages` owns the three naming/collision refusals;
`WriteMessages` owns unrecoverable deletion and partial-edit wording;
`NotesPlanMessages` owns four plan messages, deletion consequence and excerpt suffix.
Do not retain a broad `NotesMessages` facade or change translation sources.

### Resource boundary

`Contracts/NoteResourceReader::read(string $uri, string $userId): array` returns the
existing `contents` envelope. `Resources/NextcloudNoteResourceReader` implements it
using `NotesFolders`, `NoteLookup`, `NoteContent` and a required `VisibilityGuard`.

Preserve the current resource-specific wiring: root/lookup run without their optional
visibility filters, followed by the explicit mandatory `assertVisible($note)` before
content read. This matters for multiple results from `getById` and guard failure
precedence. Construct those unfiltered concrete dependencies explicitly inside the
resource registration closure; do not reuse the tool-scoped filtered lookup by
accident. This is one permanent resource implementation, not a second repository.

Move ID parsing and `NodeAccess::run` with the reader. Keep exactly `ctype_digit`,
the literal `'0'` rejection and integer conversion; do not silently replace them
with a stricter URI validator. The registry retains notes grant/app authorization
before calling it. Remove the registry's now-unused `VisibilityGuard` constructor
parameter only after checking that all remaining uses have moved; update every
constructor call. Files resource behavior remains unchanged.

### Root-resolution ownership and failure precedence

The original module resolves the root before dispatch (except create execution).
Moving that responsibility must preserve both call count and ordering for valid
registered tools. Each final capability owns the initial `folder($userId, false)`
read; neither the module nor the tool repeats it or stores a root between requests.

| Route | Initial root read relative to the existing checks |
| --- | --- |
| List, search and read execution | Once, before the operation's empty-query/category/ID logic. |
| Create execution | No initial read; size → existing ancestor → shared confirmation → `folder($userId, true)` → category → name → mutation. |
| Create preview | Once, before size and existing-ancestor/shared-notice evaluation. |
| Edit preview/execution | Once, before missing-change validation and note lookup. |
| Move preview/execution | Once, before note lookup and its existing checks. |
| Delete preview/execution | Once, before the trash-app check; that check still precedes note lookup. |
| Resource read | Grant/app rejection in registry → URI parsing → initial root read → scoped lookup → mandatory visibility assertion → content. |

During Phase 2, remove the old module's eager root read only for the three read
routes that now delegate to capabilities; keep it for the old write routes until
Phase 3 transfers ownership. Do not retain a preliminary read and then call a
capability that reads again. Add ordered-call tests with a failing root provider
plus another refusal (missing edit changes, unavailable trash or oversized create),
and assert exactly which failure wins. Root lookup and payload encoding remain
inside `NodeAccess::run` at the tool boundary, preserving mapped file exceptions.

### MCP composition and authoring

All seven tools implement `Mcp/Tools/Tool`; four writes also implement `WriteTool`.
Each owns `static definition(): ToolDefinition` and
`execute(array $arguments, ToolContext $context): array`; writes also own
`preview(array $arguments, ToolContext $context): array`. Execute wraps the capability
payload with `ToolResult::json` inside `NodeAccess::run`; preview uses only
`NodeAccess::run`. Do not double-encode results or add a second approval policy.

`NotesToolCatalog::classes(): array` lists the seven tools explicitly.
`definitions(?array $classes = null): array` projects definitions in the original
raw field order and rejects duplicate names and non-WriteTool writes. Do not use
`ToolDefinition::toArray()` blindly: its key order differs from Notes' baseline.
`NotesModule` accepts `array<string, Closure(): Tool>` factories, checks exact
catalog membership and resolves only the requested tool. Keep the existing four
shared module interfaces and seven guide notes; unknown tools/read previews still
throw `InvalidArgumentException('Unknown tool')` without resolving unrelated services.

`AppInfo/Application.php` is the composition root. Register every tool through a lazy
closure and give it concrete capability dependencies; explicitly wire the existing
visibility/lock services for tool workflows and the distinct resource reader above.
Do not introduce a new registration framework. Constructors must not traverse the
filesystem or invoke the optional Notes/lock app. Saved-plan rendering uses no tool
factory. The final authoring guide should show that changing one write normally
involves its tool, capability and focused tests, not the broad module.

## 3. Behavioral invariants to freeze before extraction

| Area | Required baseline cases and exact preservation |
| --- | --- |
| Roots and categories | Configured `notes/notesPath`, empty setting fallback, missing root, non-folder path, traversal/control rejection, empty category, `/`, nested path and literal `0`; no preview creates folders. Hidden ancestors refuse create/move. Preserve when normalization is skipped because root/category is absent. |
| Discovery | `.md` and `.txt`, case-insensitive extensions, scoped `getById`, unreadable/hidden/outside files; breadth-first collection caps matching visible note candidates at 2,000 before sorting and search's readability/content filtering. This is not a cap on folders visited or total directory entries scanned; retain current traversal order and do not add a scan budget. Do not add read-permission checks to listing simply because search has them. |
| Search | Descending modified order, stable ties, category-relative traversal but root-relative output; visibility before results/limit; title-only oversized matches do not read content; content search scrubs UTF-8. Content snippets use 50 characters of leading context and `120 + query length`; title-only snippets use 100 characters; preserve whitespace/ellipsis rules. |
| Content and names | Exact 1 MiB accepted, over-limit rejected; UTF-8 byte vs character distinction, title sanitization and 200-character cap, `.md` create, lowercased original extension on rename, suffixes 2 through 100 and exhaustion. |
| Create | Size and existing-ancestor shared guard before root/category creation; free name chosen at execution. Missing-category preview omits content/bytes/fileName exactly as today. Do not move title failure earlier if that removes existing folder-creation effects. |
| Edit | Missing changes; lookup → update ACL → ETag → lock → size → rename preflight → shared guard. Preflight failures leave content unchanged. Content first, rename second, lock checked before each mutation; preserve partial failure and fresh ID lookup for response. Preview checks size → ETag → update and warns about locks instead of executing. |
| Move | Shared/lock checks precede category creation; re-find before mutation; collision checked after category resolution. Same-category execution is a no-op, while preview may report `titleTaken` for the note itself. Do not harmonize the two. |
| Delete | Initial root resolution precedes the user trash app check, which precedes note lookup; execution uses update/ETag/lock/shared checks before delete permission/storage wrapper. Preview checks ETag/delete permission/storage and adds a lock notice, without importing execution's update check. Preserve receipt `{id, deleted:true, trash:true}`. |
| Plans and rendering | Compare full content for `changed`, not 400-character excerpts; raw snapshot content is not replaced by scrubbed read output. Exact arrays, messages, shared/team notices and lock data; Markdown escaping, long/equal rendered excerpts, malformed/unknown plan null fallback. |
| Public policy and errors | Every write through registry without/false `confirm` previews; confirmed requests re-evaluate current state. Shared acknowledgement is independent. Direct calls preserve execution behavior. Grants and app filtering precede access; localized errors and unexpected exceptions retain their current boundary. |
| Resources | URI and markdown envelope; ASCII numeric parsing, leading zeros, oversized numeric strings and invalid IDs characterized as-is; missing/hidden ancestors, multiple ID candidates, missing Notes app or grant, size limit and UTF-8 scrub. Explicit visibility assertion runs before reading bytes. |
| Request isolation | Interleave two users and locales on shared services; no identity, current root, lock state, translated plan or visibility result leaks between requests. Mutation invalidates visibility through the existing shared API. |

These cases preserve behavior, not endorse every baseline choice. The refactor
must not claim rollback or guaranteed restoration beyond existing checks. A defect
found by characterization is recorded separately and is not silently fixed here.

## 4. Ordered implementation phases

For execution, set `TASK_REPO` to the path returned by validated devbox context.
Commands below run from `"$TASK_REPO/mcp"` unless explicitly stated otherwise.
Capture full test output and method/provider inventories outside the repository.
Run new structural checks red before their owning extraction; baseline compatibility
captures should pass against the old code. A failing full gate blocks advancement.
Do not weaken expectations, remove providers or regenerate golden data from the
replacement to make a phase pass.

### Phase 0 — Capture complete compatibility and discovery evidence

**Files:** Create `mcp/tests/Unit/Contract/NotesMigrationCompatibilityTest.php` and
`mcp/tests/Support/Notes/Compatibility/{definitions.json,plans.json,results.json,README.md}`;
extend the existing five Notes suites and direct-consumer suites listed above where
an invariant lacks coverage. Reuse shared `FakeTree`, users and lock doubles.

**Interfaces:** Produces immutable baseline definitions, guides, four write plans,
rendered English/Portuguese text, representative results/errors/resource envelopes
and a complete method/provider inventory. Later phases consume these unchanged.

- [x] Capture all seven raw and published definitions, generated guide output and
  module order against the unmodified implementation, recording exact source commit.
- [x] Capture all four plans/renders before any extraction, including missing/existing
  category create, shared/locked operations and representative partial-edit errors.
  Fix IDs, mtimes and ETags in fixtures; document any unavoidable normalization.
- [x] Add `testNotesDirectWritesAndRegistryApprovalRemainDistinct`, covering all four
  direct executions, registry absent/false confirmation with no mutation, confirmed
  mutation and independent shared acknowledgement.
- [x] Add named cases for the review focus where existing assertions do not suffice:
  `testEditFailurePrecedenceAndPartialRename`, `testResourceVisibilityBeforeContent`,
  `testCategoryZeroMissingRootAndCollisionSideEffects`,
  `testSearchCandidateLimitAndUnicodeBoundaries`,
  `testInitialRootResolutionOrderAndCount`, and
  `testInterleavedUsersAndLocalesDoNotLeak`. Place them in the relevant existing
  Notes/resource suite. Assert the behavior matrix, not hypothetical improvements.
- [x] Save test discovery (methods plus expanded provider case identities), execute
  the focused command below and `devbox run test --cwd "$TASK_REPO" --json`.
- [x] Commit `test(notes): freeze tool resource and mutation contracts` only when
  both pass. Acceptance: snapshots prove compatibility before the first move;
  all 86 existing methods/providers are accounted for and no behavior changed.

### Phase 1 — Extract storage owners and complete resource migration

**Files:** Create `Storage/`, `Content/`, `Contracts/NoteResourceReader.php` and
`Resources/NextcloudNoteResourceReader.php` exactly as listed in Section 2. Modify
old `NotesModule.php` to use focused storage/content dependencies, plus Application,
ResourceRegistry and all their construction fixtures. Delete old `NotesRepository.php`.
Create `mcp/tests/Unit/Modules/Notes/Storage/NotesStorageTest.php` and
`mcp/tests/Unit/Modules/Notes/Resources/NotesResourceReaderTest.php`.

**Interfaces:** Produces Section 2 storage/content APIs and the permanent resource
contract. The still-active old tool module consumes them directly, not through a
new NotesRepository facade. Naming/content messages migrate with their owners.

- [x] Write focused tests using the proposed types; verify failure because final
  owners/contract registration do not exist, not because an old test was deleted.
- [x] Extract folder, lookup, metadata, naming and content responsibilities; retarget
  every old repository call. Move the old module's `existingCategory` and
  `categoryName` bodies into `NotesFolders`, replacing all calls and deleting the
  old helper methods. Retarget size/default/limit constants without legacy aliases.
  Move naming/content message methods and update every production/language-test
  caller in this phase; remove those methods from old NotesMessages, leaving only
  the still-active metadata/write/plan messages until Phase 3.
- [x] Move Notes resource parsing/read into the permanent contract implementation;
  retain registry authorization and resource-specific guard ordering. Remove only
  unused registry dependencies and retarget every constructor.
- [x] Assert missing/hidden/oversized resources never read bytes, filtered tool lookup
  and unfiltered resource lookup keep their distinct candidate handling, and Files
  resource tests retain their original results.
- [x] Run storage/resource suites, focused compatibility/consumer selection and full
  catalog test. Acceptance: no production/test reference to old NotesRepository;
  seven definitions, plans and resource outputs match; the 2,000-note candidate
  bound and original folder traversal are preserved.
- [x] Commit `refactor(notes): separate storage and resource reading`.

### Phase 2 — Extract reading and search capabilities

**Files:** Create `Reading/{NoteLister,NoteReader}.php`, `Search/NoteSearch.php` and
`mcp/tests/Unit/Modules/Notes/Reading/{NotesListerTest,NotesReaderTest}.php` and
`mcp/tests/Unit/Modules/Notes/Search/NotesSearchTest.php`. Modify only read
routes in the old NotesModule and their fixtures; remove the old private read/search
implementations rather than leaving duplicate algorithms.

**Interfaces:** Consumes Phase 1 APIs; produces the three payload-returning read
signatures in Section 2. The old module remains the sole registered publisher until
Phase 3 and calls these capabilities under its existing result/error boundary.

- [x] Write `NotesListerTest`, `NotesReaderTest` and `NotesSearchTest` against the final
  types and observe missing-owner failures.
- [x] Extract list/read/search unchanged. Keep search snippet logic here; it has a
  single consumer and does not justify a generic text utility. Transfer initial
  root-resolution ownership for those routes as specified above; assert one read
  per invocation and preserve the enclosing result/error boundary.
- [x] Verify candidate cap before sorting/limit, equal modified times, category `0`,
  oversized title matches without content reads, unreadable files, hidden candidates,
  invalid UTF-8 and exact snippet boundaries. Retain list/search permission differences.
- [x] Run the new read/search suites, frozen contracts, existing Notes/consumer suites
  and full catalog test. Acceptance: read results and guide/publication are identical;
  editing and previews still pass through the old active module without duplication.
- [x] Commit `refactor(notes): extract reading and search capabilities`.

### Phase 3 — Migrate four write capabilities and cut over all seven tools

**Files:** Create all remaining Notes classes in Section 2; modify Application and
consumer fixture builders; move the five old Notes test files to
`mcp/tests/Unit/Modules/Notes/` (renderer suite under `Presentation/`). Create permanent
shared setup at `mcp/tests/Support/Notes/NotesTestFactory.php` only for genuinely
repeated fixture wiring; retain the existing shared file/lock doubles in place.
Use these exact permanent test locations (relative to `mcp/tests/`):

| Old file in `Unit/Tools/` | Final file |
| --- | --- |
| `NotesModuleTest.php` | `Unit/Modules/Notes/NotesModuleTest.php` |
| `NotesPreviewTest.php` | `Unit/Modules/Notes/NotesPreviewTest.php` |
| `NotesLockTest.php` | `Unit/Modules/Notes/NotesLockTest.php` |
| `NotesTranslationTest.php` | `Unit/Modules/Notes/NotesTranslationTest.php` |
| `NotesPlanRendererTest.php` | `Unit/Modules/Notes/Presentation/NotesPlanRendererTest.php` |

Update namespaces and import `Tests/Unit/Tools/FakeTree`, `FakeUsers`,
`AssertsReadablePlans` and `Tests/Unit/Tools/Common/{FakeLock,FakeLockManager}`
explicitly where used; they previously resolved through the old test namespace.
The shared fixtures remain permanent shared owners. Do not introduce old-test
loaders, alias classes or imports of another feature's test cases to compensate.
Delete the remaining `mcp/lib/Tools/Notes/` files and old dedicated test paths.

**Interfaces:** Consumes Phases 1–2; produces four preview/execute capabilities,
seven Tool classes, catalog, module and pure rendering. Section 2 defines signatures.
Do not publish old and new modules simultaneously or wrap the old module.

- [x] Write capability cases against final types and observe missing-owner failures.
  Extract create first; run its preview/mutation cases, then extract edit and run
  all refusal/partial-lock tests before proceeding to move and delete.
- [x] Extract move and deletion with the separate shared, lock and recovery checks
  in their original order. Run their no-op, collision, hidden-target, ACL/ETag and
  trash-storage cases before wiring the module.
- [x] Move plan preparation to the owning workflow and the specified small support
  classes in `Execution/`. Transfer write-route root reads to capabilities with
  the documented precedence. Move the cohesive renderer unchanged; distribute message responsibilities
  and static metadata. Freeze raw/saved plan text across English, Portuguese and
  existing Spanish/error coverage; every fixture resets Translator in teardown.
- [x] Add `mcp/tests/Unit/Contract/NotesCatalogTest.php`: exact seven tools/order, four WriteTools, duplicate
  rejection, mismatched factory rejection, unknown names/read previews, no factory
  calls for definitions/guides/rendering. Match Notes' own raw key order.
- [x] Implement all seven tools and the final module; switch Application once and
  replace reflective test constructors with permanent capability/factory wiring.
  Remove old NotesModule, NotesMessages and NotesPlanRenderer, not compatibility
  aliases or forwarding subclasses. Update WritePlanContractTest's fixture map.
- [x] Run `php vendor/bin/phpunit tests/Unit/Modules/Notes` in isolation, then the
  focused contracts/consumers and full catalog test. Acceptance: all seven public
  tools use the final route, four direct/registry write paths match, no preview
  writes, no Notes source remains under Tools and all original cases remain active.
- [x] Commit `refactor(notes): migrate write capabilities and tool publication`.

### Phase 4 — Verify final boundaries, production composition and documentation

**Files:** Create `mcp/tests/Architecture/NotesBoundaryTest.php`; extend
`mcp/tests/Unit/ApplicationRegistrationTest.php` and the compatibility test. Update
`docs/en/development/architecture-specification.md`, `adding-a-tool.md`, both docs
indexes, this plan's status and a new execution report alongside this plan.
Inspect `mcp/tests/bootstrap.php`, Composer metadata, native/API scanner and package
scripts for real consumers; modify only when evidence identifies one.

**Interfaces:** The only Notes implementation dependency outside the feature is its
resource-reader contract, except Application's composition role. Shared policies,
Tool module registration and tests are explicit allowed consumers.

- [x] Write negative boundary cases for imports, fully qualified calls, serialized
  class strings, test paths and autoload entries; prove detection with synthetic
  forbidden examples before asserting the repository is clean. Allow the unrelated
  shared `Tools/ToolGuideNotes` name; do not match a mere Notes prefix.
- [x] Reject containers/service locators, dependencies on other features' internals,
  production references to test support and external references to Notes Storage/
  Content internals outside composition. Verify all retired files are absent and
  no `class_alias`, forwarding facade or alternate dispatch exists for Notes.
- [x] Exercise actual production registration closures: resolve all seven tools and
  the resource reader; list/render with filesystem and optional-service providers
  that throw if accessed. Execute representative read and all four preview/confirmed
  write routes through the registry using explicit host doubles. Assert authorization
  rejection before content access. Static catalog/module metadata must load
  without Notes app classes; public registry definitions/templates must omit Notes
  when the app is disabled for that user. Distinguish this filtering test from
  metadata discovery. Do not invoke throwing filesystem methods while merely
  constructing tools; execution tests use separate stateful host doubles.
- [x] Reconcile baseline methods and expanded provider cases with final discovery;
  all 86 original methods remain or have explained equivalent coverage. Every count
  difference needs evidence. Run the isolated feature suite and all final checks below.
- [x] Build and extract the actual production archive under the ignored build
  directory. In a fresh PHP process use only its production autoloader and minimal
  declared host fixtures: load every affected type, compare all seven definitions,
  guide output and four saved-plan renders; verify source bytes, module order,
  archive membership and clean classmaps. No test bootstrap or development vendor.
- [x] Self-review and perform the authorized final independent review; fix verified
  blocking findings and repeat affected checks. Record remaining external evidence,
  fixture reconciliation, every deviation and next scope in the execution report.
- [x] Update implemented documentation only after completion; commit
  `refactor(notes): verify final boundaries and document migration`.

**Acceptance:** No old Notes source/test path, compatibility class, obsolete fixture,
registration, loader or runtime string remains. Both tool and resource routes are
final, the full test/package gate passes and the production archive stands alone.
Local completion must still distinguish pending supported-version/live-host checks.

## 5. Verification commands and evidence

The following baseline selection was executed during planning on PHP 8.4.26 and
passed: **144 tests / 3,352 assertions** (PHPUnit 10.5.65). This is a focused planning
baseline, not a new full-suite or live-server validation claim.

```bash
cd "$TASK_REPO/mcp"
php -d memory_limit=1G vendor/bin/phpunit --filter 'Notes|ResourceRegistryTest|WritePlanContractTest|VisibilityContractTest|ToolErrorsLanguagesTest|L10nLanguagesTest'
```

Keep the `Notes` prefix in new test class names or extend this selection explicitly;
a filter passing fewer tests is not evidence of success. Run new suites directly
as well as through discovery. Final commands:

```bash
cd "$TASK_REPO/mcp"
composer dump-autoload --optimize
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Modules/Notes
php -d memory_limit=1G vendor/bin/phpunit --filter 'Notes|ResourceRegistryTest|WritePlanContractTest|VisibilityContractTest|ToolErrorsLanguagesTest|L10nLanguagesTest|ApplicationRegistrationTest'
php -d memory_limit=1G vendor/bin/phpunit tests/Architecture
php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Contract/NextcloudApi

devbox run check --cwd "$TASK_REPO" --json
git -C "$TASK_REPO" diff --check
```

Expected: every command succeeds; check runs the complete suite and package
validation. Record counts and runtime per phase, not fixed future totals. Review
Composer warnings and prove none become production package dependencies. Search
active source/tests/bootstrap/composer/scripts/appinfo/CI for old namespace/path
segments, including escaped strings; historical documents and negative scanner
fixtures may mention old names deliberately.

PHP 8.2–8.5 CI and disposable Nextcloud 32–35 checks remain separate evidence. Before
release, verify Notes enabled/disabled per user, configured/shared/team/external
folders, Text/files_lock behavior, own manual locks, concurrent ETag/rename changes,
trash-wrapper behavior, Basic/Bearer requests and resource privacy on real hosts.
Unit/API fixtures and the package probe do not prove native lock hooks or recovery.
Do not run destructive diagnostics against real accounts as part of this refactor.

### Documentation review outcome

The review resolved root-read ownership across intermediate and final routes,
separated state-reading plan preparation from pure presentation, made storage/helper
and message retirement explicit, and mapped every old Notes test to a final owner.
It also clarified that the 2,000-note limit does not bound directory scanning and
that loading static metadata differs from publishing tools for an app-disabled user.
The phase dependencies now transfer each responsibility once, without a replacement
repository facade or a surviving compatibility route. The 144-test focused baseline
was rerun successfully during review; full/package results are now recorded in the execution report; supported-version
and live-host gates remain external release evidence.

## 6. Retirement checklist and completion report

- [x] Delete `mcp/lib/Tools/Notes/` in full; no broad replacement NotesRepository.
- [x] Delete old `mcp/tests/Unit/Tools/Notes{Module,Preview,PlanRenderer,Lock,Translation}Test.php`
  locations after permanent moves; keep unrelated shared Tools tests and fixtures.
- [x] Retarget resource registry, application factories, cross-cutting contracts,
  language tests, renderer fixture map and every constant/namespace consumer.
- [x] Preserve seven tools, four writes, resource envelope, module position and
  original tests; no duplicate registry route or transition dispatch remains.
- [x] Document full/isolated gates, immutable fixtures, independent review,
  production-only package probe and actual external integration gaps.
- [x] Update status to implemented only when these checks pass. Further Notes
  improvements, People/Logs, shared MCP relocation, administration, developer
  commands and README/docs translations remain separate work.

Intermediate commits may use the old active module with already-extracted final
capabilities. They are checkpoints, not a completed migration. Do not introduce
compatibility wrappers to keep old imports alive, and do not stop after tool cutover
while the resource reader or fixtures still depend on a retired owner. No schema or
data migration is involved; rollback is through reviewed Git commits, not dual code.
