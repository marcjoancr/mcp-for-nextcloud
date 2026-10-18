<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Calendar, DAV, Contacts and Tasks capability migration plan

> **For agentic workers:** Implementation requires separate authorization. Once
> authorized, use `superpowers:executing-plans` for ordered execution; delegate only
> if requested. Finish and validate each phase before starting the next.

**Status:** Implemented after explicit authorization. All local gates passed; see
the [execution report](2026-10-16-calendar-dav-capability-migration-report.md) for
evidence, phase sequencing notes and pending remote integration checks.
**Date:** 2026-10-16.
**Planning baseline:** `1037f44`, after the complete Files, Deck and Talk migrations.
**Goal:** Migrate all 20 Calendar/Contacts/Tasks tools, their DAV infrastructure and
all direct consumers by capability, preserving behavior and leaving no old source
or test trees, compatibility aliases, forwarding adapters or duplicate dispatch.
**Architecture:** Reuse the existing MCP tool contracts and registry. Extract
shared DAV mechanics into focused platform facilities; keep event, contact and
task policies in their respective modules. Preserve the existing Calendar event
reference contract used by Talk.
**Tech stack:** PHP >=8.2, Composer PSR-4 `OCA\Mcp`, PHPUnit 10.5, native Nextcloud
app `mcp`, declared Nextcloud 32–35 support; no dependency or database changes.
**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Files report](2026-10-16-files-capability-migration-report.md),
[Deck report](2026-10-16-deck-capability-migration-report.md),
[Talk report](2026-10-16-talk-capability-migration-report.md) and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Keep identifiers, comments, PHPDoc and technical documentation in English.
  Preserve translated message IDs, interpolation, translation timing and meaningful
  multilingual fixtures. Persisted literals such as Calendar's PRODID and the
  confidential-event title `Ocupado` are compatibility data, not translation work.
- Preserve names, order, definitions, grants, app filters, annotations, defaults,
  schema object/array shapes, result blocks, plans, rendered text and guide notes.
  No new tool, public argument, confirmation state, recovery promise or endpoint.
- Keep `ToolRegistry`, per-call grants/app checks and `WriteGate` mandatory. Derive
  identity from `ToolContext`, never arguments. Reprepare confirmed requests from
  current state; previews never write, create backups or dispatch DAV operations.
- Keep native writes in the embedded Nextcloud DAV pipeline, including its ACL,
  validation, scheduling and sync behavior. No direct backend or SQL mutations.
  Existing fresh SQL reads are intentional and must remain fresh.
- Native dependencies remain lazy. Listing definitions, generating guides and
  rendering saved plans must not construct DAV servers, query a backend or resolve
  unavailable optional apps. Tools and capabilities receive explicit dependencies,
  never a container or general service locator.
- Preserve failure precedence and partial-write behavior. Do not add retries,
  automatic rollback, new validation, transactional guarantees or policy hardening
  as incidental refactoring. Record discovered functional issues separately.
- Shared `Tools/Common`, `ToolModule`, `PreviewsWrites`, `RendersPlans`,
  `ToolGuideNotes`, `ToolFailure`, `ArgumentValidationException`, `ToolResult`,
  `PlanText`, registry/policy APIs, localization and `Service/UserTimezone` remain
  active shared dependencies. Their repository-wide relocation is out of scope.
- Use the validated isolated worktree, preserve unrelated changes and do not rename
  an existing branch. No push, PR, release or deployment is included.

## Review focus

1. Shared DAV extraction changes which principal, path, response or error is used:
   freeze CalDAV and CardDAV separately, including simultaneous failures and fresh
   server state (Phases 0–2 and 7).
2. A new preparation abstraction bypasses approval, creates a backup during preview
   or changes direct-call behavior: exercise all 12 writes and their refusal order,
   including the controlled CLI consumer (Phases 0, 3–6).
3. Confidential events, private tasks or restricted accounts become visible:
   characterize classification, recurrence overrides, enumeration and reference
   errors rather than testing only happy paths (Phases 0, 3, 5–6).
4. A successful DAV status falsely proves recovery, movement or notification:
   preserve fresh read-back, trash verification, verified contact backup and honest
   scheduling reports, including failures after mutation (Phases 2–6).
5. Old code survives through a fixture, lazy closure, command or Composer classmap:
   reconcile test discovery, scan imports and serialized names, exercise real
   composition and probe the production archive (Phases 0 and 7).

## 1. Findings and completion boundary

A complete migration without transitional code is feasible **as one coordinated
scope covering all three modules, shared DAV and their direct consumers**. Moving
Calendar alone would leave Contacts and Tasks importing its implementation. Moving
only the transport would leave their broad module classes and data helpers intact.
The phases below provide reviewable checkpoints; they are not independently final
migration boundaries.

At `1037f44`, the affected old trees contain **67 production PHP files**:

| Tree | Files | Responsibility currently mixed into it |
| --- | ---: | --- |
| `mcp/lib/Tools/Calendar` | 46 | Seven handlers, schemas, event rules, approval, presentation, shared collection access, DAV reads/writes and transport. |
| `mcp/lib/Tools/Contacts` | 11 | Six tools, search, account-directory privacy, vCard patching, CardDAV and verified backup. |
| `mcp/lib/Tools/Tasks` | 5 | Seven tools, queries, VTODO policy, dates, categories, mutation and presentation. |
| `mcp/lib/Tools/Dav` | 1 | `CollectionSchema`, which delegates to Calendar's schema helper. |
| `mcp/lib/Service/Calendar` | 4 | CLI selftest orchestration, messages and native diagnostic reads. |

The existing `Modules/Calendar/Contracts/{EventReferenceReader,EventReferenceNotFound}`
and `References/CalendarEventReferenceReader` are permanent code. Retarget the
implementation; do not replace or duplicate the public reference contract.

Large change surfaces include `CalendarPlanRenderer` (541 lines),
`CalendarMessages` (448), `ContactsModule` (512), `TasksModule` (435),
`TaskData` (384), `TasksPlanRenderer` (395), `SystemContacts` (312) and
`CalendarSelftestService` (420). Counts include comments and indicate mixed work,
not arbitrary maximum-size limits. Transport dispatchers are already cohesive
protocol adapters; do not split their request lifecycle into a generic engine.

The old focused test trees contain **38 PHP files and 462 test methods**:
Calendar 27/309, Contacts 6/82, Tasks 3/59 and Calendar selftest 2/12. Files include
fixtures and helper classes. Data providers make executed test counts different.
Reference, command, registration, protocol and shared contract tests are additional.
These are source inventory counts, not a test-run result for this planning task.

### Exact tool inventory

Keep module positions in `Application::MODULES` and the following within-module
order. `Calendar` requires app `calendar`; `Contacts` requires `contacts`; `Tasks`
requires core `dav`, independently of the optional Tasks UI app.

| Module | Ordered public name | Operation | Final capability |
| --- | --- | --- | --- |
| Calendar | `calendar_list_calendars` | read | Reading |
| Calendar | `calendar_list_events` | read | Reading |
| Calendar | `calendar_create_event` | create | Creation |
| Calendar | `calendar_update_event` | edit | Editing |
| Calendar | `calendar_move_event` | move | Relocation |
| Calendar | `calendar_delete_event` | delete | Deletion |
| Calendar | `calendar_transfer_event` | transfer | Relocation |
| Contacts | `contacts_list_addressbooks` | read | AddressBooks |
| Contacts | `contacts_search_contacts` | read | Search |
| Contacts | `contacts_read_contact` | read | Reading |
| Contacts | `contacts_create_contact` | create | Creation |
| Contacts | `contacts_edit_contact` | edit | Editing |
| Contacts | `contacts_delete_contact` | delete | Deletion |
| Tasks | `tasks_list_calendars` | read | Reading |
| Tasks | `tasks_list_tasks` | read | Reading |
| Tasks | `tasks_read_task` | read | Reading |
| Tasks | `tasks_create_task` | create | Creation |
| Tasks | `tasks_edit_task` | edit | Editing |
| Tasks | `tasks_complete_task` | edit | Completion |
| Tasks | `tasks_delete_task` | delete | Deletion |

### Direct consumers included in completion

- `AppInfo/Application.php`: module positions, factories, read/write ports,
  native providers, reference binding and selftest registrations.
- `Command/CalendarSelftest.php`: keep command name/options/output/exit codes and
  framework registration; depend on the final diagnostic runner.
- Calendar reference implementation and its tests; Talk's `ReferenceLinker` tests
  and boundary scanner. Remove the scanner's current Calendar-internal allowlist
  and its traversal of the old Calendar tree; Talk still imports only contracts.
- `ApplicationRegistrationTest`, `McpProtocolTest`, `McpControllerTest`,
  `ToolsListContractTest`, `WriteGateContractTest`, `VisibilityContractTest`,
  `ToolErrorsLanguagesTest`, `WritePlanContractTest`, the selftest command test and
  `Contract/NextcloudApi/NextcloudApiUsage.php` with its discovery/contract tests.
- Cross-module fixtures, explicit `require_once` calls, test namespaces and native
  class aliases, bootstrap/autoload configuration, packaging and class-name strings.
  A text search for imports alone does not establish completeness.

## 2. Behavior to freeze before extraction

### Publication, approval and errors

Freeze both raw module definitions and registry-published definitions. Calendar's
module appends its own approval sentence and `confirm_shared` property; Contacts
and Tasks use `CollectionSchema` with a different sentence and property description.
The registry separately adds `confirm`, titles and annotations. Preserve all three
layers without appending descriptions twice or changing empty schema objects.

Calendar's `call()` resolves an unknown tool before its exception boundary. Known
Calendar failures become MCP error envelopes; `CalendarArgumentException` passes
through as structured argument validation; other `InvalidArgumentException`s
inside execution become `RuntimeException('Calendar backend failure', ..., cause)`.
Its direct `preview()` does not use that wrapping boundary. Contacts and Tasks
instead propagate their current failures and **check `WriteGate::confirmed()` in
their direct write calls**, returning a plan when absent. Preserve that distinction
in the new entry tools. Do not copy another migrated module's error/availability
boundary without checking these paths.

Calendar approval forces shared acknowledgement only during preparation, then
collects source/destination notices itself; execution refuses missing actual
acknowledgement. Warning calculation happens during preview, not confirmed dispatch.
Prepared writes are request-local closures, never serialized approvals. Existing
Calendar create/update/delete dispatch returns an MCP envelope; relocation returns
plain data which approval wraps. Preserve exactly one envelope on every route,
including CLI execution. Internal move preparation gates shared source/target only
for same-owner moves; internal transfer preparation has no shared-acknowledgement
gate of its own. The public approval path adds that gate. Preserve this distinction
for CLI diagnostics rather than adding a blanket guard to EventWriteOperation.
Contacts/Tasks plans and results use `ToolResult::json()`;
Calendar's variadic result helper permits multiple blocks. Preserve JSON flags,
block order, `isError`, field absence versus null, and original exception causes.

### Shared collection and native DAV mechanics

- The current `Calendar` value is also used for address books. Preserve its public
  fields/order (`id`, `uri`, `name`, `ownerId`, `ownerPrincipal`, `writable`, `path`)
  in the neutral `Collection` value; retain `ownedByOther(string): bool`.
- CalDAV visibility filters deleted collections and supported components. Empty
  component sets retain their current interpretation. Birthday calendars, including
  shared birthday URIs, remain non-writable. Calendar uses VEVENT and Tasks VTODO.
  Contacts keeps its own personal/shared-book resolver and read-only system path;
  do not impose CalDAV path parsing on address books.
- Read adapters preserve native row mapping, quoted ETags, resource-stream bodies,
  live-only UID/batch queries, and trash-inclusive object lookup. Current fresh
  `calendarobjects` reads avoid the native backend cache after a separate embedded
  server writes. Never replace these with cached `getCalendarObject()` results.
- Every write uses a fresh server, checks the authenticated session and pins the
  native principal. Preserve the separate actor and owner paths of the ACL probe.
  Request/response objects must be the ones attached to the server, with matching
  base URLs. Capture/reset early SAPI responses without emitting headers or bodies.
- CalDAV validates paths/size before session resolution; CardDAV checks session
  first. Their path character rules and exception mappings differ. Preserve
  encoded segments, webroot handling, content types, `If-None-Match: *`, `If-Match`,
  `Overwrite: F`, accepted statuses, nullable response ETags and error causes.
- CalDAV uses `IConfig` event size configuration (default 10 MiB); CardDAV uses
  `IAppConfig` card size configuration (default 5 MiB). Keep existing empty/zero
  handling and safe log type/code fields. Never log bodies or arbitrary errors.
- Preserve CalDAV's native server selection and older-server fallback, including
  conditional iMIP installation. This is supported runtime compatibility, not a
  migration adapter to delete. Keep CardDAV's distinct ACL, validation, rate-limit,
  photo/export, locking, event and application-plugin lifecycle.
- Scheduling suppression includes the request header and the DELETE listener
  handling on a fresh server. Tasks always disable scheduling. Do not merge
  CalDAV/CardDAV dispatchers or expand their current guarantees.

### Calendar policies and references

Keep ISO parsing and UTC conversion, date-only civil days, account timezone in
plans, IANA aliases, the seven-day default/366-day maximum listing window,
recurrence expansion limits (500 occurrences/100000 iterations), overrides and
cancellations. Preserve the most restrictive classification across the series:
private content hidden from other owners; confidential content represented as busy.
That whole-object rule applies to changes and references. Event listings instead
classify each occurrence, with an override inheriting the master CLASS only when
its own is absent; an unknown CLASS is hidden. Preserve the 2000-item listing cap
and its second truncation-metadata result block, malformed-object skipping and
ordering. A private override must not hide unrelated public occurrences.

Preserve attendee resolution, event patching and unrelated iCalendar properties,
UID/object URI identity, ETag comparison, sequence/timestamps and scheduling reports.
A requested invitation or native `SCHEDULE-STATUS` is not proof of email delivery.
Keep collision/availability warnings and shared-calendar suggestions informational,
including their failure handling and confidentiality constraints.

MOVE is same-owner; transfer requires different owners and refuses attendees in
any event override. Both refuse overwrite and verify destination presence **and**
source absence after the native MOVE, even if it returned success. No retry on a
verification failure. Deletion refuses disabled retention, checks native trash
including renamed objects, and retains the existing point at which retention is
checked. Do not silently add Tasks' second retention check to Calendar.

The reference reader keeps `read(string $userId, string $calendarPath, string $uid)`
returning `array{title:string,url:string}`. Missing/deleted/hidden events remain
indistinguishable. Map only the expected access/read/parse failures inside the
existing catch scope to `EventReferenceNotFound`; do not swallow unrelated failures
or broaden classification/URL-generation catches. Preserve empty master titles,
busy titles, shared-view URLs, encoded URI and webroot.

### Contacts policies and recovery

Preserve personal/shared-book access, exact actor-relative path matching, and the
read-only system catalog. Keep account minimum query length 2, native cap 200,
enumeration/full-match settings, UID/email matching flags, own-account handling,
guest rejection, group restriction and phone-enumeration fallback. In particular,
exact matches while enumeration is disabled follow their existing group behavior;
do not unify this with enumeration policy. Return only the current public fields.

Search keeps personal results first, email deduplication with attached `accountId`,
malformed-card handling and pagination over the **combined matching** stream.
Catalog reads return neither vCard nor ETag. Preserve full-card reads and editing
of unknown properties, ORG trailing components and email/phone parameters; omitted
fields preserve values, explicit empty lists clear them.

Delete preparation is read-only. Confirmed execution saves a unique full vCard in
the actor's existing Contacts backup location, holds/releases its application
mutex, and verifies byte equality before deletion. After backup it re-resolves
book access/identity/owner and rechecks ETag; native DELETE also uses `If-Match`.
Backup failure prevents deletion. Later failure can leave a valid backup; do not
retry or remove it automatically. Keep locked-folder errors, filename sanitation,
account timezone, non-overwrite behavior and manual-import recovery wording.
Deletion remains permanent with `recoverable: false` and `backupPath`. Native
create/edit read-back must report normalized card data even without a response ETag.

### Tasks policies and recovery

VTODO queries have no event time window and include undated tasks. Keep chunks of
100 objects, completed-task defaults, category filtering before offsets, malformed
or protected-item handling, `hasMore`/`nextOffset`, and omission of raw iCalendar
from lists only. Private **and confidential** shared tasks are hidden completely.

Readable recurrence is not editable recurrence. Preserve refusal of multiple task
components, RRULE/RDATE/EXDATE, organizer or attendees; reject mixed VEVENT/VTODO
objects. Keep unknown properties and alarms, date type/order checks, DURATION/DUE
replacement, relative-reminder validation, UTC timestamps and completion/reopening
semantics. Category writes allow 50 items, each 1–255 characters after trimming,
refuse control/invalid UTF-8 input and use Unicode case folding; preserve first
spelling, escaped commas and omitted-versus-empty semantics.

Create plans omit provisional UID/iCalendar; confirmed execution generates its
own values. Plans carry account timezone and no per-user renderer state. Task
deletion checks retention during preparation **and immediately before dispatch**,
always suppresses scheduling, verifies trash, and never falls back to permanent
deletion. Failed post-write reads remain failures after possible mutation.

## 3. Final ownership and syntax

Paths are relative to `mcp/lib/`. The tree identifies responsibilities, not empty
folders to scaffold. Use one explicit static definition per tool and small concrete
collaborators; add an interface where there is an actual integration or consumer
boundary. No generic operation bus, universal prepared-write engine or broad DAV
service should replace the current broad module classes.

```text
Mcp/
  Tools/DavCollectionSchema.php             # shared pure schema fragments only
  Confirmation/SharedCollectionNotice.php   # plain shared-owner notice, no mutation
  Confirmation/SharedCollectionMessages.php
Platform/Dav/
  Collections/Collection.php
  Transport/{Session,CapturingSapi,DavResult}.php
  CalDav/
    {CollectionStore,ObjectStore,CollectionAccess,CalDavWriter}.php
    {DavReadStore,EmbeddedCalDavDispatcher,EmbeddedCalDavServerFactory}.php
    {DavPathBuilder,CalDavFailure,CalDavMessages}.php
    {CalendarAdministration,CalendarAclProbe,TrashPolicy,TrashedObject}.php
  CardDav/{ContactDav,EmbeddedCardDavDispatcher,EmbeddedCardDavServer}.php
  Icalendar/{IsoDateInput,DateArgumentException,DateMessages}.php
Modules/
  Calendar/
    {CalendarModule,CalendarToolCatalog}.php
    Tools/                                 # seven Tool/WriteTool implementations
    Reading/{CalendarReader,EventReader,EventRangeQuery}.php
    Creation/EventCreator.php
    Editing/EventEditor.php
    Relocation/{EventRelocator,EventMover,EventTransfer}.php
    Deletion/EventDeleter.php
    Events/                                # content, timing, classification, lookup
    Scheduling/                            # attendees, reports and warning analyses
    Execution/{EventWriteOperation,PreparedEventWrite,EventApproval}.php
    Execution/{CalendarException,CalendarArgumentException}.php
    Presentation/                          # schema, CalendarResult, guide, renderers/messages
    Integration/{DavEventRangeQuery,DavCalendarSelftestReader}.php
    Selftest/                              # runner, fixtures, assertions, cleanup, reader
    Contracts/{EventReferenceReader,EventReferenceNotFound}.php  # existing
    References/CalendarEventReferenceReader.php                 # existing
  Contacts/
    {ContactsModule,ContactsToolCatalog}.php
    Tools/                                 # six tool entry points
    AddressBooks/                          # access, listing and read-store port
    Reading/                               # card reads and read-only catalog route
    Search/                                # personal/account merge and pagination
    Directory/                             # account lookup, visibility, public fields
    Cards/ContactCard.php                   # cohesive vCard parsing/patching
    Creation/ContactCreator.php
    Editing/ContactEditor.php
    Deletion/{ContactDeleter,ContactBackup}.php
    Execution/                             # shared contact preparation, if needed
    Presentation/                          # schema, guide and save/delete renderers
    Integration/DavContactStore.php
  Tasks/
    {TasksModule,TasksToolCatalog}.php
    Tools/                                 # seven tool entry points
    Reading/{TaskCalendarReader,TaskReader,TaskQuery}.php
    Creation/TaskCreator.php
    Editing/TaskEditor.php
    Completion/TaskCompleter.php
    Deletion/TaskDeleter.php
    Content/{TaskDocument,TaskVisibility,TaskPatch,TaskDates,TaskCategories}.php
    Execution/                             # task-specific preparation/persistence
    Presentation/                          # schema, guide and save/delete renderers
    Integration/DavTaskQuery.php
Command/CalendarSelftest.php                # framework entry point retained
```

Shared ownership is intentionally narrow:

- `Platform/Dav` owns native request mechanics, read adapters, collection addressing,
  protocol errors, trash lookup and shared ISO parsing. It imports no module,
  feature tool, plan renderer or test helper. Scheduling decisions, event windows,
  VTODO rules and account-directory policy never enter it.
- `CollectionAccess` is CalDAV-specific. Contacts retains `ContactAccess` with its
  own rules; sharing a value type does not imply sharing resolution policy.
- `CollectionStore` owns collection enumeration/shares; `ObjectStore` owns object
  reads. `EventRangeQuery` belongs to Calendar and `TaskQuery` to Tasks. Their
  integration adapters receive named lazy native providers from composition.
- `CalDavWriter` exposes object put/update/delete/move. Selftest administration
  (`mkcalendar`, `deleteCalendar`) and foreign-owner ACL probing (`probeWrite`)
  have separate ports, implemented by the same cohesive dispatcher. Ordinary
  capabilities cannot receive the administrative/probe interfaces.
- CardDAV keeps its own writer and dispatcher. Only the genuinely identical
  session/result/SAPI mechanics are shared with CalDAV.
- `IsoDateInput` owns `isDateOnly(string): bool` and
  `parse(string $input, string $label): DateTimeImmutable`. Event windows, all-day
  timing and timezone choices remain in `Calendar/Events/EventDates`; task temporal
  dependencies remain in `Tasks/Content/TaskDates`.
- `CalDavFailure extends ToolFailure` and `DateArgumentException extends
  ArgumentValidationException` are neutral replacements for the shared portions
  of Calendar's failures. They preserve safe messages, codes, fields/details and
  causes. Calendar retains its event-specific exception types. Its call boundary,
  reference reader and selftest must explicitly handle the newly separated types
  at the same points as their old equivalents. The shared base exceptions are
  active APIs, not permission to catch all validation or runtime exceptions.
- `SharedCollectionNotice::forCollection(Collection $collection, string $userId):
  ?array` returns the current plain notice, including owner display-name fallback.
  Callers retain their own acknowledgement/refusal order and MCP encoding. It
  never validates a stored approval or dispatches a write.
- `DavCollectionSchema` supplies only reused field fragments (`text`, `calendar`,
  `uid`, `etag`, `offset`, `limit` and the Contacts/Tasks shared-confirmation field).
  Each tool owns its definition and exact description suffix. Calendar retains
  its distinct confirmation field. No shared helper generates a feature catalog.
- Native providers are fixed-purpose closures, such as
  `Closure(): CalDavBackend` and `Closure(): IDBConnection`, not a closure accepting
  arbitrary class names. Preserve intentional backend caching versus fresh object
  reads. No per-user result or principal is cached in shared services.
- Move the CardDAV native assembly into its named platform adapter. Replace direct
  `OCP\Server::get()` calls with explicit dependencies/lazy providers; supply the
  native PluginManager factory from composition. A native constructor's own host
  lookups are outside this app's injection boundary, not a reason to put a service
  locator back into capabilities.

### Read ports and tool contracts

Keep the current row shapes documented in PHPDoc. The storage split is:

```php
interface CollectionStore
{
    public function calendarsForPrincipal(string $principalUri): array;
    public function sharesOf(int $calendarId): array;
}
interface ObjectStore
{
    public function objects(int $calendarId, array $uris): array;
    public function object(int $calendarId, string $uri): ?array; // includes trash
    public function objectByUid(int $calendarId, string $uid): ?array; // live only
}
interface EventRangeQuery
{
    public function eventUrisInRange(
        int $calendarId, DateTimeImmutable $from, DateTimeImmutable $to,
    ): array;
}
interface TaskQuery
{
    public function uris(int $calendarId): array; // VTODO, including undated tasks
}
```

`DavReadStore` implements the first two ports. The writer signatures and defaults
are copied from current `CalendarDav`/`ContactDav`, partitioned by the method
ownership above; preserve scheduling, optional size overrides, accepted status
lists and optional/mandatory ETag distinctions. Narrowing injection must not erase
selftest capabilities or change protocol defaults.

Every public tool uses the implemented contracts:

```php
public static function definition(): ToolDefinition;
public function execute(array $arguments, ToolContext $context): array;
// WriteTool only: domain plan, before the registry's plan envelope.
public function preview(array $arguments, ToolContext $context): array;
```

The three catalogs list their tool classes explicitly and reject duplicate names
and non-read definitions without `WriteTool`. Modules retain the existing
`ToolModule`, `PreviewsWrites`, `ToolGuideNotes` and `RendersPlans` adapters with
small explicit factory dispatch. There is one definition and one runtime route per
tool. Metadata, guides and rendering do not instantiate operation factories.

Use a small `Presentation/*Schema::definitionArray(ToolDefinition): array` in each
module where necessary to preserve the baseline definition key order. Current DAV
modules emit `name`, `description`, `inputSchema`, `module`, `operation`, `app`,
with `destructiveHint: true` appended for Calendar create and update only. Preserve
that explicit hint and its absence on the other raw definitions; publication may
derive annotations separately. `ToolDefinition::toArray()` emits a different order. Do not change the shared
ToolDefinition contract or rebuild static definitions by constructing old modules.
Calendar's new static definitions represent the **current module definitions**,
including its approval suffix and final shared-confirmation property. They are not
copies of the less-complete internal handler definitions. The module must not append
those fields a second time; the registry still owns its existing publication layer.

`Calendar/Presentation/CalendarResult::json(mixed ...$blocks): array` preserves
Calendar's multiple JSON text blocks by composing the current `ToolResult::json()`
encoding per block. Errors use the existing `ToolResult::error()` shape. This is a
small final encoding owner, not a new ToolSchema facade or a change to ToolResult's
shared public API. Keep the 2000-item truncation branch covered explicitly.

Calendar's existing request-local preparation is justified by **two real consumers**:
MCP approval and CLI diagnostics. Retain an event-only `EventWriteOperation` port
with `prepare(array $arguments, string $userId): PreparedEventWrite|array` and
`execute(array $arguments, string $userId): array`, without tool metadata. The
array preparation branch retains the internal shared-notice refusal. Creation,
editing, deletion and the two relocation modes implement this boundary.
`Relocation/EventMover` and `Relocation/EventTransfer` share one `EventRelocator`
and retain their different preparation/acknowledgement behavior.
`PreparedEventWrite` preserves source/target/before/after plus `dispatch(): array`.
The approval collaborator no longer reads `$tool->definition()['name']`. Its final
signatures are:

```php
// Calendar/Execution/EventApproval
public function preview(
    string $toolName, EventWriteOperation $operation,
    array $arguments, string $userId,
): array;
public function execute(
    EventWriteOperation $operation, array $arguments, string $userId,
): array;
```

Each write tool passes its own static definition's name to `preview()`; no name
comes from user arguments and EventWriteOperation acquires no metadata method.
That name selects the existing `action`, deletion consequence and warning rules.
Approval execution calls `prepare()` and dispatches the prepared write; it does
not call the capability's direct `execute()` and accidentally double-wrap relocation
results. The capability's direct `execute()` returns the existing MCP envelope for
CLI diagnostics, wrapping relocation data once. Diagnostics do not use public
preview routing. Do not expose a bypass argument or reuse this port for Contacts/Tasks.

Contacts/Tasks creators, editors, completers and deleters expose their own
`preview(array, string): array` and `execute(array, string): array` entry methods.
Use shared preparation inside each feature only where the before/after validation
is genuinely common. Keep their direct unconfirmed-call plan behavior in the tool
entry point, and fresh checks in execution. No replacement `prepare($toolName)`
switch should accumulate every feature's operation again.

Split rendering by meaningful plan family: Calendar event changes, relocation and
delete, with focused participants/warnings formatting; Contacts save/delete; Tasks
save/delete. A thin renderer dispatches pure family renderers. Split messages by
these owners and event/scheduling/transport concerns rather than moving the entire
old `CalendarMessages` into another catch-all. Plan rendering must remain stateless.

## 4. Source and test retirement ledger

This ledger covers all 67 old production files. Grouped names are basenames under
the source prefix in the first column. “Split” means move behavior into the stated
owners and delete the source, not retain a forwarding class.

| Old source prefix and files | Final owner / action |
| --- | --- |
| `Tools/Calendar`: `Calendar`, `CalendarAccess` | `Platform/Dav/Collections/Collection`, `CalDav/CollectionAccess`. |
| `Tools/Calendar`: `CalendarStore`, `DavCalendarStore` | Split collection/object ports and adapter under `Platform/Dav/CalDav`; range query/adapter under Calendar Reading/Integration. |
| `Tools/Calendar`: `CalendarDav`, `EmbeddedDavDispatcher`, `EmbeddedCalDavServerFactory`, `DavPathBuilder` | Platform CalDAV writer/admin/probe ports and native adapters. |
| `Tools/Calendar`: `Session`, `CapturingSapi`, `DavResult` | Platform DAV Transport. |
| `Tools/Calendar`: `TrashPolicy`, `TrashedObject` | Platform CalDAV retention and trash lookup. |
| `Tools/Calendar`: `DateInput`, `CalendarArgumentException`, `CalendarException` | Split shared date/transport failures and messages from Calendar event dates/errors. |
| `Tools/Calendar`: `SharedGuard` | MCP shared collection notice; acknowledgement/encoding stay at each caller. |
| `Tools/Calendar`: `ToolSchema`, `CalendarMessages` | Shared field fragments/result API plus Calendar schema and concern-owned messages; no replacement broad facade. |
| `Tools/Calendar`: `CalendarModule`, `CalendarTool`, `CalendarWriteTool` | Final module/catalog and generic MCP Tool/WriteTool; delete old interfaces. EventWriteOperation has only capability preparation/execution. |
| `Tools/Calendar/Handler`: all seven files | Seven final tools plus Reading, Creation, Editing, Relocation and Deletion capabilities. |
| `Tools/Calendar`: `CalendarDraftApproval`, `PreparedCalendarWrite` | Calendar Execution event approval and PreparedEventWrite. |
| `Tools/Calendar`: `Classification`, `EventBuilder`, `EventExpander`, `EventMapper`, `EventRepository`, `EventTiming`, `StoredEvent` | Calendar Events; dependencies use split read ports. Keep cohesive existing content helpers. |
| `Tools/Calendar`: `EventRelocator` | Calendar Relocation. |
| `Tools/Calendar`: `AttendeeResolver`, `Scheduling`; `Scheduling/{AvailabilityCheck,CollisionCheck,PlanWarnings,SharedCalendarFinder}` | Calendar Scheduling: attendee resolution, outcome report, collision/availability/shared suggestions and warning assembly. |
| `Tools/Calendar`: `CalendarPlanRenderer` | Calendar Presentation family renderers and thin dispatch. |
| `Tools/Contacts`: `ContactAccess`, `ContactStore`, `DavContactStore` | Contacts AddressBooks and Integration. |
| `Tools/Contacts`: `ContactDav`, `EmbeddedCardDavDispatcher`, `EmbeddedCardDavServer` | Platform DAV CardDAV. |
| `Tools/Contacts`: `ContactCard`, `ContactBackup` | Contacts Cards and Deletion respectively. |
| `Tools/Contacts`: `SystemContacts` | Split Directory lookup, visibility policy and public-field mapping. |
| `Tools/Contacts`: `ContactsModule`, `ContactsPlanRenderer` | Thin final module/catalog/tools, capability workflows and focused presentation. |
| `Tools/Tasks`: `TaskStore`, `DavTaskStore` | Tasks Reading/TaskQuery and Integration/DavTaskQuery. |
| `Tools/Tasks`: `TaskData` | Split Content document parsing/mapping, visibility/complexity, patching, dates and categories. |
| `Tools/Tasks`: `TasksModule`, `TasksPlanRenderer` | Thin final module/catalog/tools, capability workflows and focused presentation. |
| `Tools/Dav`: `CollectionSchema` | Shared fragments in Mcp/Tools; full definitions live with tools. Delete the old tree. |
| `Service/Calendar`: `CalendarSelftestService`, `CalendarSelftestMessages`, `CalendarSelftestReader`, `DavCalendarSelftestReader` | Calendar Selftest runner, scenario helpers, messages/reader port and Integration adapter. Keep the command in Command. |

Move tests beside their new owners: `tests/Unit/Modules/{Calendar,Contacts,Tasks}`,
`tests/Unit/Platform/Dav`, `tests/Unit/Mcp/Tools` and
`tests/Unit/Mcp/Confirmation`. Use permanent fixtures under `tests/Support/Dav` for
shared native backends and storage doubles, and feature support for event-specific
fixtures. `CalendarTestCase`, `FakeCalendarDav`, `FakeCalendarStore`,
`MemoryCalDavBackend`, `MemoryPrincipalBackend` and `CalDavBackendMethods` must be
partitioned/retargeted, never pulled from an old feature tree by surviving tests.
Keep native alias tests isolated where required; fixtures must load without relying
on an unrelated test having run first. Reconcile all 462 original methods/cases
and every additional direct-consumer test; do not satisfy the count with empty tests.

Final removal includes all five production trees in the inventory and these four
test trees: `tests/Unit/Tools/{Calendar,Contacts,Tasks}` and
`tests/Unit/Service/Calendar`. Historical documentation may mention retired paths;
active source, configuration, factories, autoload and tests may not depend on them.

## 5. Ordered implementation phases

During intermediate phases, still-unmigrated original consumers may use final
shared facilities. New final facilities must never import old feature code. Move
each dependency closure and retarget all current consumers together; do not create
an alias, compatibility wrapper or new mixed-dispatch module to bridge phases.
Delete each retired class when its last consumer moves. The completed checkboxes
below record implementation; capture timing exceptions are documented in the report. After each accepted
phase, make a focused local commit recording its checks; do not defer verification
until a single final rewrite. A failed gate stops advancement and is diagnosed
within that phase. Roll back with focused code commits; do not retain old runtime
routes as a fallback. This structural migration performs no data/schema conversion.

### Test ownership during execution

Use the following existing suites as the starting evidence for each batch. Move
and split tests with behavior, preserving providers/assertions; the common phase
command below always includes all surviving old and new suites plus consumers.
Names in this table are relative to `mcp/tests/Unit/`.

| Phase | Existing focused evidence | Final test owner |
| --- | --- | --- |
| 1 | `Tools/Calendar/{DateInputTest,CalendarApprovalTest}.php`; Contacts/Tasks module and plan tests; `Contract/ToolErrorsLanguagesTest.php` | Shared ISO/notice/schema tests under Platform/Dav and Mcp; event-specific dates under Modules/Calendar. |
| 2 | `Tools/Calendar/{DavCalendarStoreTest,CalDavBackendContractTest,EmbeddedCalDavServerFactoryTest,EmbeddedDavDispatcherTest}.php`; `Tools/Contacts/EmbeddedCardDavDispatcherTest.php`; `Service/Calendar/DavCalendarSelftestReaderTest.php` | Platform/Dav native transport/store contracts; feature-owned range/VTODO/contact queries under their Modules integration tests. |
| 3 | `Tools/Calendar/{ListCalendarsTest,ListEventsTest,ClassificationTest,CreateEventTest,UpdateEventTest,MoveEventTest,TransferEventTest,DeleteEventTest,AttendeeResolverTest,CalendarApprovalTest,CalendarModuleTest,CalendarPlanRendererTest,PlanWarningsTest}.php`, `Tools/Calendar/Scheduling/*Test.php`; `Modules/Calendar/References/CalendarEventReferenceReaderTest.php` | Modules/Calendar capability, composition, presentation and reference tests; API tests remain shared. |
| 4 | `Service/Calendar/{CalendarSelftestServiceTest,DavCalendarSelftestReaderTest}.php`; `Command/CalendarSelftestCommandTest.php` | Modules/Calendar/Selftest and Integration; command test stays framework-owned. |
| 5 | `Tools/Contacts/{ContactsModuleTest,ContactsPolicyTest,SystemContactsTest,ContactBackupTest,ContactsPlanRendererTest}.php` | Modules/Contacts capability/privacy/recovery/presentation tests. |
| 6 | `Tools/Tasks/{TasksModuleTest,TaskCategoriesTest,TasksPlanRendererTest}.php` | Modules/Tasks Reading, Content, write and presentation tests. |
| 7 | `ApplicationRegistrationTest.php`, protocol/controller/contract suites, all architecture tests and test-discovery inventory | Same shared consumers plus final Calendar/Contacts/Tasks/DAV architecture tests and compatibility fixtures. |

### Dependency handoff gates

The following are phase exit conditions, not cleanup deferred to Phase 7:

| Phase exit | Required state before advancing |
| --- | --- |
| 1 | Contacts, Tasks and Tools/Dav no longer import Calendar ToolSchema, DateInput, SharedGuard, Calendar value or transport values. Their current workflows may remain in old modules, consuming final shared facilities. |
| 2 | New read adapters implement only final ports; all callers use them. Shared native fixtures have permanent owners. No old transport/store/collection-access class is kept as an alias. |
| 3 | No executable or fixture dependency on Tools/Calendar remains anywhere, including Tools/Dav, selftest, Contacts/Tasks and Talk's scanner. CalendarTestCase's consumers have moved with its fixture. |
| 4 | Service/Calendar and its test tree are absent; command and composition use only final diagnostics. |
| 5 | Tools/Contacts and its test tree are absent. Tools/Dav can remain only as active definition assembly for the original Tasks module. |
| 6 | Tools/Tasks, its test tree and Tools/Dav are absent. The neutral schema helper has no definition builder or feature dependency. |
| 7 | Package, autoload, composition, API discovery and all retired-reference audits prove the same final ownership; no source cleanup remains. |

An intermediate phase may retain an original implementation that still owns real
behavior. It may not retain a class whose only purpose is to forward to its final
replacement. Adapt the existing boundary tests as dependencies move, keeping their
protections active at every phase rather than disabling them until the final audit.

### Phase 0 — Freeze contracts and establish the baseline

- [x] Validate project/worktree identity and clean/known status. Re-read this plan
  against HEAD and update the inventory if intervening changes alter scope.
- [x] Run the current focused suites plus registration, reference, CLI, protocol,
  policy and API contracts; run `devbox run test`. Record commands, PHP version,
  counts and any baseline failure before modifying production code.
- [x] Add `tests/Unit/Contract/DavMigrationCompatibilityTest.php` and committed
  baseline fixtures under `tests/Support/Dav/Compatibility/`, captured from old
  behavior: all 20 raw/published definitions, ordered guide notes/generated guide,
  previews for all 12 write tools, rendered plans and representative success/error envelopes.
  Capture fixed identities/time/data, not real account data. Never regenerate
  expectations from the replacement implementation to make a mismatch pass.
- [x] Fill meaningful gaps from section 2: direct versus registry calls, multi-fault
  precedence, preview non-mutation, alternating user/language/timezone, failed
  post-write verification, privacy and consumer exception scope. Reuse existing
  detailed cases instead of duplicating implementation-shaped tests.
- [x] Save a method/provider inventory and native dependency/API usage inventory
  for final reconciliation. Establish boundary scanner negative cases for imports,
  fully qualified calls, serialized class strings and test-support dependencies.

**Accept:** The old implementation passes characterization and the full baseline;
all 20 tools/12 writes and direct consumers have explicit evidence. No production
refactoring has started, and no unexplained baseline failure is carried forward.

### Phase 1 — Extract neutral values, dates and MCP fragments

- [x] Create Collection, transport value/session/SAPI facilities, shared ISO parser,
  date/protocol failure types and their narrowly owned message sources.
- [x] Extract shared field fragments and plain shared-owner notice. Retarget
  Calendar/Contacts/Tasks callers; preserve their distinct acknowledgement order,
  encoding and schema suffixes. Remove **all** Calendar ToolSchema imports from
  Contacts, Tasks and Tools/Dav in this phase, including `CollectionSchema`'s
  definition-building dependency. Its existing definition assembly may remain
  active for the unmigrated modules using neutral fields; it must not forward to
  a retired schema or become a new compatibility facade. Static per-tool definition
  ownership moves in Phases 3, 5 and 6; their public schemas stay unchanged.
- [x] Switch Tasks' current TaskData directly to IsoDateInput now. Do not let it
  import final Calendar/Events/EventDates or keep old DateInput alive until Phase 6.
- [x] Retarget all affected catches, type declarations, static references and test
  doubles, including the reference reader and selftest. Update both selftest
  `expectRefusal()` and `safe()` for the separated safe failure types; generic
  exceptions must retain their existing sanitized reporting. Calendar's active event
  window/timing helper may call the new parser until it moves in Phase 3; the new
  parser cannot call back into Calendar. Delete fully replaced source classes.
- [x] Test cross-module error code/message/cause/field/detail equivalence, date
  parsing, notice payloads, unknown owner names and interleaved user requests.

**Accept:** Neutral shared code imports no old Calendar implementation. All three
modules and both Calendar consumers still pass their contracts; source removals
have no alias. Run the phase verification gate before moving on.

### Phase 2 — Move storage and native DAV transport

- [x] Split CalendarStore and its adapter into the agreed collection/object/range
  ports. Move ContactStore to Contacts/AddressBooks and TaskStore to
  Tasks/Reading/TaskQuery **with** their adapters in this phase, so neither new
  integration adapter implements an interface in an old tree. Retarget all old
  module constructors, registrations and doubles to these final ports.
  Bind lazy backend/database providers in Application. Retarget existing consumers
  to narrow ports now; remove the old store interfaces/adapters once unused.
- [x] Move both native dispatchers and server assembly. Partition object writes,
  calendar administration and ACL probes into the final interfaces; rebind the
  selftest to them. Move trash policy/lookup and CalDAV collection access.
- [x] Preserve plugin selection, native fallback, request/response sharing, SAPI
  capture, size/path/session order, preconditions, status handling and safe logging.
  Remove app-owned general service lookup from native adapters.
- [x] Move native adapter tests and shared fixtures; execute them alone and with
  all consumers. Move MemoryPrincipalBackend and shared native/storage doubles
  now, updating consumers such as Contacts' dispatcher test. Test stale backend
  cache versus fresh SQL read-back, native
  success with missing/misplaced objects, missing ETags, early responses, failed
  principal pinning, scheduling suppression and fresh plugin state.

**Accept:** All shared transport/storage replacements are permanent and no final
platform class imports Calendar/Contacts/Tasks. Reads remain non-mutating; every
write still uses native DAV. Both original and already-retargeted consumers pass
focused/API/full tests. Metadata construction resolves no native service.

### Phase 3 — Migrate Calendar tools, event capabilities and references

- [x] Move cohesive event content helpers, splitting shared dates from event timing.
  Migrate Reading, Creation, Editing, Relocation and Deletion in that order, running
  their focused tests after each batch. Keep complete-series policies and failure
  order; retarget the existing reference implementation to the final owners.
- [x] Introduce the seven generic Tool/WriteTool entries, catalog and explicit
  factories. Extract EventWriteOperation/PreparedEventWrite and EventApproval
  with the explicit preview tool-name parameter described in section 3;
  update CLI consumers to the capability operations in the same change so they
  no longer call tool handlers. Test all five preview action names, deletion
  consequences, warning selection and CLI relocation envelopes. Do not leave old
  handlers as forwarding adapters.
- [x] Move scheduling/warning collaborators and split presentation/messages by
  concern, including the final CalendarResult encoder and ordered definition
  projection. Keep warning analysis in preview only and all renderers pure.
- [x] Switch Calendar's Application module entry once to its final module. Preserve
  unknown-tool lookup and the call/preview exception asymmetry; freeze direct
  dispatch versus approval envelope wrapping with tests.
- [x] Verify recurrence/classification, both relocation modes, whole-series ETags,
  invitation honesty, disabled retention/trash checks and all reference cases.
  Retarget Talk's boundary check to final Calendar ownership without weakening
  Talk's contract-only restriction. Remove the old Calendar source/test tree after
  every remaining dependency has been retargeted, including CalendarTestCase and
  the still-unmigrated selftest test. Move the event fixture to permanent Calendar
  test support and update explicit requires before removing its original tree.
  This cannot be deferred to Phase 4 or the Contacts/Tasks phases.

**Accept:** Seven tools have one definition/dispatch route each, exact metadata and
behavior. Talk still imports only EventReferenceReader/EventReferenceNotFound.
Calendar production/test trees are retired; the still-active old selftest service
uses final capabilities and ports until its own decomposition in Phase 4. Full
phase tests, including selftest and Talk references, pass.

### Phase 4 — Complete the Calendar diagnostic consumer

- [x] Move the selftest reader port/messages and integration adapter. Split the
  runner's scenario orchestration from fixture creation/assertions/cleanup without
  moving diagnostic privileges into public tool entries or a universal runner.
- [x] Retarget Command/CalendarSelftest and registrations. Keep CLI options/output,
  success/failure/skip reporting, exit behavior and session restoration in `finally`.
- [x] Preserve generated-calendar reservation before MKCALENDAR, cleanup after a
  mutation that throws, ownership checks, external/shared objects and invitation
  cleanup. Exercise stale tool/DAV ETags, sync tokens, suppression, overwrite,
  transfer and foreign-user ACL refusal through the same final capabilities.
- [x] Move all selftest tests and remove `Service/Calendar` plus its old test tree.
  Verify ordinary tools cannot request administrative/probe ports.

**Accept:** The CLI has no dependency on any tool handler or old source path, uses
native writes only, and all diagnostic/cleanup/session tests pass together with
the full phase gate. No real-user diagnostic is run merely to validate a refactor.

### Phase 5 — Complete Contacts by capability

- [x] Extract AddressBooks and Reading, then Directory privacy/mapping and Search
  merge/pagination. Preserve exact catalog matching and data exposure; do not route
  system books through personal writable resolution. Run their focused tests.
- [x] Extract Creation and Editing around ContactCard; extract Deletion and verified
  ContactBackup with its post-backup access/identity/ETag recheck. Keep read-back
  after native save and strict no-mutation preview behavior. Run write/recovery tests.
- [x] Create six tool entries/catalog, pure guide/schema and save/delete renderers;
  switch the module registration once. Preserve direct-call confirmation and
  exception behavior. Test mutex release, locked backup folders, byte mismatch,
  changed owner/book/ETag after backup and failures after native mutation.
- [x] Retarget remaining imports/fixtures, delete the old Contacts source/test tree
  and prove isolated tests do not load Calendar fixtures through retired paths.

**Accept:** All six tools match frozen metadata/plans/results; directory privacy
and verified-before-delete behavior are covered. No broad ContactsModule workflow
switch or old tree remains. Shared contract, Files backup-related and full tests pass.

### Phase 6 — Complete Tasks by capability

- [x] Extract Reading with VTODO-only queries, undated tasks, privacy and pagination;
  split TaskData into the specified document/policy/patch/date/category owners.
  Preserve reusable content logic without a renamed all-purpose TaskData facade.
- [x] Extract Creation, Editing, Completion and Deletion. Share task preparation
  and persistence only where cohesive; retain read-back, second retention check,
  scheduling suppression, provisional-ID omission and confirmation differences.
- [x] Create seven tool entries/catalog, pure guide/schema and family renderers;
  switch the module registration once. Test date/DURATION/alarm interactions,
  recurrence/participants refusal, progress reopening and Unicode categories.
- [x] Retarget all remaining imports and fixtures, delete the Tasks source/test
  tree and ensure the old `Tools/Dav` schema tree has no remaining consumer.

**Accept:** Seven tools match the baseline and work with core DAV independently of
Tasks UI availability. Private/confidential tasks cannot leak through lists, reads
or plans. Content survives supported edits. No old TaskData/module facade or source/test tree remains. The focused/shared/full phase gate passes.

### Phase 7 — Verify final composition, package and removal

- [x] Add final architecture tests for all three modules and DAV: acyclic module
  dependencies, contract-only cross-module access, no native lookup in tools,
  no platform feature imports, no metadata-time dependency resolution and no old
  paths, including production dependencies on old test-support paths. Keep existing
  Files/Deck/Talk boundary tests enabled. Test the scanners
  themselves with deliberately forbidden imports and escaped class strings.
- [x] Exercise every production registration/factory with strict native fakes,
  construct all 20 tools and the reference/diagnostic consumers, and run read,
  preview and confirmed execution through the actual registry. Fake adapters alone
  do not verify production wiring. Include Basic/Bearer and grants/app visibility.
- [x] Reconcile all baseline tests/providers with their final locations. Run each
  feature and shared DAV suite alone, then together, to catch hidden `require_once`
  and alias-order dependencies. Delete stale empty directories and test loaders.
- [x] Remove all references to the five retired source trees/four test trees from
  active source, factories, tests, scripts/configuration and Composer classmaps.
  There are no `Legacy`, `Compat`, `Bridge`, aliases, wrappers, TODO cleanup routes
  or mixed dispatch entries introduced by this migration.
- [x] Update Adding a tool, architecture status and documentation indexes to the
  implemented state only now. Add an execution report recording phases, test
  counts, fixture reconciliation, native/API limits, package probe and pending gates.
- [x] Run final focused tests, the full catalog check, removal audit and fresh
  production package probe below. Commit implementation only after local gates pass.

**Accept:** All 20 tools, shared DAV and their direct consumers satisfy the plan;
no additional cleanup task is needed to remove transition code. Local completion
and outstanding CI/real-server checks are reported separately.

## 6. Verification commands and evidence

Set `TASK_REPO` to the validated task worktree. The commands below describe the implementation gates; actual results are recorded
in the execution report. From `mcp/`, after
the Phase 0 compatibility test exists, use this Bash discovery list during phases:

```sh
dav_tests=(
  tests/Unit/Contract/DavMigrationCompatibilityTest.php
  tests/Unit/ApplicationRegistrationTest.php
  tests/Unit/Contract/ToolsListContractTest.php
  tests/Unit/Contract/WriteGateContractTest.php
  tests/Unit/Contract/VisibilityContractTest.php
  tests/Unit/Contract/ToolErrorsLanguagesTest.php
  tests/Unit/Tools/WritePlanContractTest.php
  tests/Unit/Command/CalendarSelftestCommandTest.php
  tests/Unit/Modules/Talk/References
  tests/Unit/Contract/NextcloudApi
  tests/Unit/McpProtocolTest.php
  tests/Unit/McpControllerTest.php
  tests/Architecture
)
for suite in \
  tests/Unit/Tools/Calendar tests/Unit/Tools/Contacts tests/Unit/Tools/Tasks \
  tests/Unit/Service/Calendar tests/Unit/Modules/Calendar \
  tests/Unit/Modules/Contacts tests/Unit/Modules/Tasks tests/Unit/Platform/Dav \
  tests/Unit/Mcp/Tools tests/Unit/Mcp/Confirmation; do
  if [ -d "$suite" ]; then
    dav_tests+=("$suite")
  fi
done
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G \
  vendor/bin/phpunit --do-not-cache-result "${dav_tests[@]}"
```

For Phase 0's initial baseline, omit only the not-yet-created compatibility test.
Run changed adapter/capability tests alone first. Before advancing **every phase**:

```sh
devbox run test --cwd "$TASK_REPO" --json
```

The optional directory loop is for intermediate states only. At completion, assert
that `tests/Unit/Modules/{Calendar,Contacts,Tasks}`, `tests/Unit/Platform/Dav` and
the new MCP test directories exist and are nonempty, so a misspelled/missing suite
cannot silently pass. Rebuild the development autoloader from `mcp/`, rerun the
focused command, then perform the final catalog/package gate:

```sh
composer dump-autoload --optimize
devbox run check --cwd "$TASK_REPO" --json
git -C "$TASK_REPO" diff --check
```

From the repository root, all these directory checks must pass:

```sh
for retired in \
  mcp/lib/Tools/Calendar mcp/lib/Tools/Contacts mcp/lib/Tools/Tasks \
  mcp/lib/Tools/Dav mcp/lib/Service/Calendar \
  mcp/tests/Unit/Tools/Calendar mcp/tests/Unit/Tools/Contacts \
  mcp/tests/Unit/Tools/Tasks mcp/tests/Unit/Service/Calendar; do
  test ! -d "$retired" || exit 1
done
rg -n '(Tools[\\/]+(Calendar|Contacts|Tasks|Dav)|Service[\\/]+Calendar)\b' \
  mcp/lib mcp/tests mcp/appinfo mcp/scripts .github \
  mcp/composer.json mcp/composer.lock mcp/phpunit.xml
```

Expected search output is empty except deliberate negative scanner fixtures,
which must be individually accounted for. `rg` exit 1 means no matches; exit 2
is an audit failure. Supplement with token-aware scans of imports, fully qualified
names and escaped string literals, plus archive/classmap inspection. Match namespace
/path segments, not arbitrary prefixes: final `Mcp/Tools/DavCollectionSchema` must
not be mistaken for retired `Tools/Dav`. Include that allowed-name case in scanner
tests alongside forbidden old namespaces and paths. Historical
Markdown and tests asserting removal are not executable compatibility loaders.

Probe the **actual archive produced by check** in a fresh process with its packaged
production autoloader, not development `vendor/`. Keep the extraction under this
worktree's ignored build directory and compare packaged source bytes with working
source. Inventory/load every new production type with minimal host fixtures; no
PHPUnit, app test support or unlisted development dependency may be required.
Metadata/guide/render checks run with native DAV implementations unavailable;
native adapter construction uses explicit host fakes in a separate probe. Verify
all 20 raw and published definitions, exact guide/render fixtures and module order.
Inspect the archive and classmaps for all retired trees and accidental test files.
Do not classify unit tests using development Sabre packages as a production probe.

Record the implementation baseline and phase commits, all exact commands/results,
PHP version, test and assertion counts, original-to-final test reconciliation,
source-removal evidence, package path/content/probe result and unresolved gates in
`2026-10-16-calendar-dav-capability-migration-report.md` when implemented.

## 7. Integration gates and subsequent work

Local source completion requires every phase and local gate above; it must never
leave cleanup code for a later project. Release confidence additionally requires
remote CI across the repository's PHP 8.2–8.5 matrix and disposable supported
Nextcloud environments. API fixtures/stubs describe assumptions; they do not prove
the full native lifecycle on every declared supported version.

A separately provisioned disposable environment should verify Basic/Bearer session
pinning, CalDAV and CardDAV native plugin construction, precondition conflicts,
sync tokens, scheduling suppression/outcomes, MOVE/transfer/trash behavior,
Contacts account privacy and verified backup, VTODO without the Tasks UI app,
reference URLs under a webroot, and selftest cleanup/session restoration. Exercise
normal native server selection and the retained fallback where applicable. Do not
run mutation diagnostics against real users or claim invitation delivery from a
unit test. Record unavailable integration environments as pending evidence, not as
permission to leave source migration unfinished.

After implementation, review the execution report before selecting the next scope.
Notes, People, Logs, administration, broader shared policy/registry relocation,
developer command standardization and documentation translation/README relocation
remain separate work. No other feature migration, documentation translation tree
or new developer tooling is authorized by this plan.
