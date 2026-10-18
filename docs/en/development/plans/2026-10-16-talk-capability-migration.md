<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Talk capability migration implementation plan

> **For agentic workers:** After explicit implementation authorization, use
> `superpowers:executing-plans` for ordered execution. Use delegated execution only
> if requested. Complete each phase's verification before advancing.

**Status:** Implemented and locally validated. All phases are complete; remote CI and
disposable-server integration remain open. See the [execution report](2026-10-16-talk-capability-migration-report.md).
**Date:** 2026-10-16.
**Planning baseline:** `d4f8e82`, after the completed Files and Deck migrations.
**Goal:** Migrate all eight Talk tools and their direct consumers to cohesive
capabilities, preserving behavior and removing every old Talk source/test path,
compatibility alias, temporary adapter and duplicate dispatch route at completion.
**Architecture:** Reuse `Tool`, `WriteTool`, `ToolDefinition` and `ToolContext`;
keep the existing registry and confirmation gate. Split orchestration by capability,
make optional Talk dependencies lazy and explicit, and expose Calendar event
references through a small public contract, as Deck already does for cards.
**Tech stack:** PHP >=8.2, Composer PSR-4 `OCA\Mcp`, PHPUnit 10.5, native Nextcloud
app `mcp`, declared Nextcloud 32–35 support; no new production dependency.
**Spec:** [Architecture specification](../architecture-specification.md).
**Lessons:** [Files report](2026-10-16-files-capability-migration-report.md),
[Deck report](2026-10-16-deck-capability-migration-report.md), and
[Adding a tool](../adding-a-tool.md).

## Global constraints

- Source, identifiers, comments, PHPDoc and documentation are English. Preserve
  localized message IDs, interpolation, translation timing and meaningful multilingual fixtures.
- Preserve tool order, names, schemas, defaults, grants, annotations, results,
  previews, rendered plans and generated guide text. No feature or policy redesign.
- Preserve the registry's schema validation, per-user app/grant checks and
  `WriteGate`. Talk has no persisted approval, expiry, fingerprint or `PlanState`.
  `confirm: true` alone permits execution; adding state would change its contract.
- Recheck resource access during execution. A preview is not an authorization
  token, and does not guarantee that a title or permission stays unchanged.
- Keep native optional services lazy. Tool listing, guide generation and plan
  rendering must work without loading `OCA\Talk` or unrelated optional apps.
- No service locator, container or user session in tools/capability constructors.
  Framework composition may use the container to bind named lazy providers.
- Keep app ID, routes, database, dependencies, API defaults and package layout.
  Do not add retries, compensating deletion, new grants or a generic workflow engine.
- Work in the selected isolated worktree. Do not rename an existing branch.
  No push, PR, release or deployment is included.
- Shared registry, `Tools/Common`, `Tools/PlanText`, policy, localization,
  `VisibilityGuard` and active Calendar infrastructure remain shared dependencies;
  they are not Talk transition code. Migrating them requires a separate scope.

## Review focus

1. Missing/disabled/too-old Talk, unknown users and user changes: metadata stays
   available, and optional services resolve only after their existing checks (Phases 0–2, 6).
2. Previews accidentally publish messages, shares or rooms, or execution starts
   requiring a stored preview: preserve both sides of the confirmation boundary (Phases 0, 3–5).
3. A partial batch, failed caption or failed invitation hides something already
   published: keep exact results and logging; never retry automatically (Phases 3–5).
4. Cross-room quotes, hidden files or private events reveal information: preserve
   access checks, indistinguishable refusals and the new reference boundary (Phases 2–4).
5. A source move leaves old imports, undiscovered tests, stale classmaps or eager
   native service construction: verify real composition and production-only packaging (Phase 6).

## 1. Analysis and feasibility

A complete Talk migration is feasible without waiting for the whole Calendar
migration. The scope includes one permanent Calendar reference capability, because
leaving Calendar storage assembly inside Talk would violate the agreed boundary.
The remaining Calendar implementation stays active and has no duplicate replacement
in this task. There is no reason to retain an old Talk facade when the migration ends.

The baseline contains **22 production PHP files** in `mcp/lib/Tools/Talk` and
**15 test files with 271 test methods** in `mcp/tests/Unit/Tools/Talk`. Data providers
make the executed test count different from the method count.

| Current owner | Observation and intended separation |
| --- | --- |
| `TalkModule` (837 lines) | Eight definitions, dispatch, argument extraction, six previews, workflows, error conversion and partial-write logging. Keep only explicit module composition/dispatch in its replacement. |
| `TalkServices` (139 lines) | Per-user enablement, native class detection, constants and arbitrary container lookup. Replace with availability, constants and individually bound native providers. |
| `ConversationWriter` (232 lines) | Text sending, quote-target lookup, batches, attachment system messages and normalization. Separate text delivery, quote lookup, batch orchestration and attachment publication. |
| `WritePreview` (256 lines) | Six write plans plus batch normalization and excerpt extraction. Move each preview beside its capability; retain only small, genuinely shared plan helpers. |
| `UserConversationResolver` (179 lines) | Contact visibility/deduplication and one-to-one room creation. Give these different owners; groups reuse contact resolution, never direct-message creation. |
| `ReferenceLinker` (198 lines) | Deck already uses `CardReferenceReader`; Calendar still exposes four implementation collaborators plus schema/mapper constants. Replace that Calendar dependency with a public reference contract. |
| `TalkPlanRenderer` (377 lines), `Messages` (547 lines) | Six rendering branches and all module strings. Split rendering by message, attachment and group plans, and messages by their owning concern. |

`ConversationResolver`, `ConversationReader`, `FileSharer`, `AttachmentAccess`,
`AttachmentMessage` and `UserFileResolver` already have useful boundaries. Preserve
those responsibilities instead of replacing them with a broad gateway. The file
resolver's path/error semantics differ from other modules: moving it into a shared
Files abstraction is not necessary for this migration.

### Behavior that must not be silently “harmonized”

- `TalkModule` does not perform its own top-level availability check, even though
  its constructor currently receives `TalkServices`. The registry and native service
  accesses own those checks. Do not copy Deck's direct-call availability guard.
- A normal token-based conversation checks lobby state. Direct room creation checks
  federation, read-only/changelog status and the chat bit, but currently has no lobby
  check. Keep those paths distinct; policy changes require separate review.
- Reads never mark messages read or update notifications/activity. Native expired
  lobby validation can update lobby state, including during previews. “No preview
  writes” means no requested publication/share/creation; it must not remove this
  native access behavior. Preserve the Talk 24 timer check and older fallback.
- `sendBatchCall()` validates list shape before resolving the conversation;
  `sendBatchPreview()` resolves the conversation first. Preview normalizes every
  body and resolves every quoted parent before returning a plan. Confirmed execution
  sends sequentially, records `ConversationAccessException`/`InvalidArgumentException`
  per item and continues; unavailable Talk or another unexpected exception can abort.
  Do not make confirmation rerun the entire preview as a new precondition.
  The location of the failure matters: `quoteTarget()` catches every `Throwable`,
  including unavailable Talk, and maps it to a missing-parent refusal. Preserve
  that catch scope in `ReplyTargetReader`; unavailability at earlier service
  resolution still propagates and can abort the batch.
- Attach/quote/direct-message previews and calls have different argument/lookup
  ordering. Pin observable error precedence before extracting shared helpers.
  In particular, a direct confirmed call can open a room before message normalization.
- History recognizes JSON envelopes only for Talk's known envelope verbs. The preview
  excerpt currently recognizes a JSON `message` property without that verb check.
  Preserve both behaviors; do not reuse one parser for both without separate approval.
- Talk does not have Deck's verified-write recovery. Native calls can have effects
  before throwing; this plan preserves existing error/partial-result semantics and
  makes no new claim of idempotency, transactional publication or guaranteed rollback.
  Reusing a one-to-one room does not make repeating a message safe.

If characterization exposes a correctness/security issue that prevents safe
migration, record a separately reviewable prerequisite and stop the affected phase.
Do not hide a behavioral fix inside a mechanical extraction.

### Planning-time validation

On PHP 8.4.26 at the baseline, from `mcp/`:

```sh
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --do-not-cache-result tests/Unit/Tools/Talk tests/Unit/ApplicationRegistrationTest.php tests/Unit/Contract/VisibilityContractTest.php tests/Unit/Contract/ToolErrorsLanguagesTest.php tests/Unit/Tools/WritePlanContractTest.php tests/Architecture/DeckBoundaryTest.php
```

Result: **324 tests, 3,005 assertions, passing**. The project catalog's
`devbox run test --cwd "$TASK_REPO" --json` also passed:
**5,144 tests, 32,912 assertions**. These runs validate the unchanged baseline,
not the proposed architecture. No live Talk server or remote PHP matrix was run.
Composer setup reported existing PSR-4 skips for explicitly loaded test stubs;
these are not evidence of production autoload correctness.

## 2. Final ownership and structure

Paths below are relative to `mcp/`. Add files when their phase needs them; do not
scaffold empty directories or one interface/DTO per class. A small use case may
own both execution and preview when they share one operation. Extract an additional
plan class only if that operation actually needs it, not to satisfy a layer count.

```text
lib/Modules/Talk/
  TalkModule.php
  TalkToolCatalog.php
  Tools/
    ListConversations.php
    ReadMessages.php
    Reply.php
    AttachFile.php
    QuoteFile.php
    MessageUser.php
    SendBatch.php
    CreateGroup.php
  Access/
    Conversation.php
    ConversationResolver.php
    ConversationAccessException.php
    ConversationMessages.php
  Integration/
    TalkAvailability.php
    TalkConstants.php
    TalkServiceFactory.php
  Reading/
    ConversationReader.php
  Messaging/
    Reply.php
    BatchSending.php
    BatchSender.php
    ReplyPlan.php
    BatchPlan.php
    MessageSender.php
    ReplyTargetReader.php
    MessageText.php
    QuotedMessage.php
    MessageMessages.php
  Attachments/
    FileAttachment.php
    AttachPlan.php
    QuotePlan.php
    AttachmentQuote.php
    AttachmentPublisher.php
    FileSharer.php
    UserFileResolver.php
    AttachmentAccess.php
    AttachmentMessage.php
    FileAccessException.php
    AttachmentMessages.php
  DirectMessaging/
    ContactResolver.php
    DirectContact.php
    OneToOneConversation.php
    DirectMessage.php
    DirectMessagePlan.php
    ContactMessages.php
  Groups/
    GroupCreation.php
    GroupWorkflow.php
    GroupPlan.php
    GroupMessages.php
  References/
    ReferenceLinker.php
    ReferenceMessages.php
  Execution/
    ToolResult.php
    TalkErrors.php
    ResultBoundary.php
    PreviewBoundary.php
    TalkUnavailableException.php
    ErrorMessages.php
  Presentation/
    ActorNames.php
    ToolMessages.php
    ConversationPlan.php
    TalkPlanRenderer.php
    MessagePlanRenderer.php
    AttachmentPlanRenderer.php
    GroupPlanRenderer.php
    PlanFields.php
    MessageExcerpt.php
    PlanMessages.php

lib/Modules/Calendar/
  Contracts/EventReferenceReader.php
  Contracts/EventReferenceNotFound.php
  References/CalendarEventReferenceReader.php

tests/Unit/Modules/Talk/       # mirrors capability ownership, plus TalkModuleTest
  Tools/
  Access/
  Integration/
  Reading/
  Messaging/
  Attachments/
  DirectMessaging/
  Groups/
  References/
  Execution/
  Presentation/
tests/Unit/Modules/Calendar/References/CalendarEventReferenceReaderTest.php
tests/Support/Talk/           # only fixtures actually shared by tests
  TalkModuleFixtures.php
  TalkNativeFixtures.php
  ObjectProvider.php
  ConstantsProvider.php
  TalkMessageFixtures.php
tests/Architecture/TalkBoundaryTest.php
tests/Unit/Contract/TalkMigrationCompatibilityTest.php
tests/fixtures/talk-definitions.json
tests/fixtures/talk-definitions.json.license
```

### Public tool mapping, in the existing order

Every entry's module is `talk` and optional app is `spreed`. The registry continues
to publish annotations and add optional `confirm`; raw definitions do not add it.

| Tool | Entry point under `Tools/` | Capability | Operation/grant |
| --- | --- | --- | --- |
| `talk_list_conversations` | `ListConversations` | `Reading/ConversationReader::listConversations` | `read` |
| `talk_read_messages` | `ReadMessages` | `Reading/ConversationReader::readMessages` | `read` |
| `talk_reply` | `Reply` | `Messaging/Reply` | `reply` |
| `talk_attach_file` | `AttachFile` | `Attachments/FileAttachment` | `attach` |
| `talk_quote_file` | `QuoteFile` | `Attachments/AttachmentQuote` | `quote` |
| `talk_message_user` | `MessageUser` | `DirectMessaging/DirectMessage` | `reply` |
| `talk_send_batch` | `SendBatch` | `Messaging/BatchSending` | `reply` |
| `talk_create_group` | `CreateGroup` | `Groups/GroupWorkflow` and `GroupCreation` | `create` |

Each entry point implements `Tool` or `WriteTool`, owns its static definition,
and delegates execution/preview with `$context->userId` from `ToolContext`. Capability methods
accept the user ID explicitly and return the same payload arrays as today; the
result boundary wraps them once. A tool constructor exposes only its use case
and needed execution boundaries. It must be testable without constructing other
capabilities. Read tools may receive their exact typed query closure, as in Deck.

`TalkModule` implements the existing `ToolModule`, `PreviewsWrites` and `RendersPlans`.
It receives an explicit name-to-`Closure(): Tool` map, checks catalog coverage,
checks resolved type/name/write capability, and routes calls. Unknown calls and
unknown/read previews retain the localized argument error before factory resolution.
`TalkToolCatalog` is an explicit ordered list; no filesystem discovery. Static
metadata must not resolve native services or cache translations across requests.
Talk has no custom `ToolGuideNotes` today: preserve the registry-generated guide
instead of inventing a new guide abstraction.

`ResultBoundary` returns existing success/error envelopes; `PreviewBoundary` throws
`ToolFailure` for the corresponding failures. Both pass through `InvalidArgumentException`
without logging and share `TalkErrors` for safe message/log mapping. Keep operation
specific partial-write logs with the capability that produces the partial result.
Map factory-resolution failures at the module boundary as well, so construction
failures cannot bypass the established error contract. Limit that catch to resolution;
do not wrap tool execution/preview in a second boundary that logs a mapped failure twice.
Characterize newly lazy factory failures separately from operation failures; laziness
must not broaden the existing resource-error catch scopes. Catalog/factory type
mismatches are composition defects, not missing conversations.

The same rule applies to the intermediate old module: delegate migrated entries
**before** its old `try`/`catch` and return their envelopes/previews unchanged.
Otherwise a new preview's `ToolFailure` would be caught again, logged twice and
replaced by the old module's generic error. Remove each migrated entry's old
workflow branch and replace its inline definition with the new tool's definition
in the same phase. Keep one definition and one execution path per tool throughout.
Tests must exercise this intermediate registry route, not only the new tool alone.

Namespace changes necessarily change internal exception class names in diagnostic
logs. Retarget class-name assertions to the new types while preserving log level,
fields, count, original cause/code and message sanitization. Do not keep exception
aliases solely to make a historical class-name assertion pass.

### Native integration without another service locator

`TalkAvailability` keeps `AppEnablement`, minimum-version and per-user `IUser`
resolution behavior. Production composition supplies `IUserManager`; retain any
optional-user-manager compatibility only if an actual supported consumer needs it,
not merely to preserve an old test constructor.

`TalkServiceFactory` is bound at composition to **one** native class name and
`Closure(): mixed`; its `forUser(string $userId): object` checks availability and
`class_exists` before invoking that closure, and rejects non-object results as
unavailable. It has no container, no public class-name lookup and no map of services.
Bind four independent providers for Manager, ChatManager, ParticipantService and
RoomService. Inject only the required `Closure(string): object` into each consumer.
`TalkConstants` reads the existing constants from loaded native classes after the
same availability check. Do not copy upstream numeric constants.

Optional native class strings belong to Integration and framework composition;
capabilities continue to receive objects, so optional Talk classes are not required
to compile. `Conversation` contains room and participant objects internal to Talk.
Do not memoize availability, users, rooms or locale in shared factories. Verify
Basic/session and Bearer composition with the controller's current session lifecycle;
adding a new Talk session-equality policy is not part of this plan.

### Cohesive capability boundaries

- `ConversationResolver` owns token validation, membership, writable-room checks
  and lobby behavior. `ConversationReader` owns listing/history normalization.
- `MessageSender` sends one normalized text and fills one-to-one participation;
  `ReplyTargetReader` resolves a parent in the same room. Neither knows attachments,
  groups or batch policy. `MessageText` owns the 4,000-character normalization rule.
- `Messaging/Reply` resolves the conversation and optional reference for each call;
  `BatchSending` owns the 50-item shape, preview and ordered partial sending.
  Permanent `ReplyPlan` / `BatchPlan` builders and `BatchSender` keep planning
  and delivery separately testable. `QuotedMessage` builds the preview author/excerpt using `ReplyTargetReader` and
  `ActorNames`; it preserves the preview-specific JSON behavior.
- `FileAttachment` owns the attach workflow and partial-caption logging;
  `FileSharer` owns room-share creation/duplicate detection and sends the optional
  caption through `MessageSender`. `AttachmentQuote` owns preview/execution of an
  existing room share; `AttachmentPublisher` owns `addSystemMessage`, without
  creating a share. Preserve current lookup/envelope-building order.
- `ContactResolver` owns target validation, enumeration, ordered deduplication and
  account naming. `OneToOneConversation` owns native room creation/reuse and its
  existing writable check. `DirectMessage` composes those with text sending and
  its read-only target preview. `GroupWorkflow` owns argument parsing and the contact preview; `GroupCreation`
  owns name/participant limits, creation and per-guest partial outcomes, replacing
  `GroupCreator`. `GroupPlan` builds the group draft without creation.
- `ConversationPlan` only builds the common conversation/draft envelope. It does
  not resolve resources or dispatch six operations. The three plan renderers use
  `PlanFields`, `MessageExcerpt` and existing `Tools/PlanText` escaping as needed;
  size formatting stays with attachments. Rendering is pure, returns null on
  unsupported/malformed plans or thrown errors, and never queries services.
- Split `Messages` into the listed concern-specific classes. Keep strings literal
  and translation calls at the same runtime boundaries. Do not introduce one
  replacement “all messages” facade or change translations during this migration.

### Calendar reference contract and scope

Keep Deck's `Modules/Deck/Contracts/CardReferenceReader` unchanged. Talk may import
that contract, but no concrete Deck reader or entity. Add this small Calendar API:

```php
namespace OCA\Mcp\Modules\Calendar\Contracts;

interface EventReferenceReader {
    /**
     * @return array{title:string, url:string} Empty title is allowed.
     * @throws EventReferenceNotFound For an absent or inaccessible event.
     */
    public function read(string $userId, string $calendarPath, string $uid): array;
}
```

`CalendarEventReferenceReader` owns the existing visible-calendar lookup,
`objectByUid`, deleted-row handling, parse, classification, master-event summary
and canonical DAV deep link. It uses the current active `Tools/Calendar` access,
store, repository, classification and mapper collaborators; this dependency is
allowed **only inside this Calendar implementation**, not Talk. It is a permanent
capability containing the extracted logic, not an adapter forwarding to old Talk.
Calendar has no dependency back to Talk. The contract and capability remain useful
when Calendar's other infrastructure is subsequently migrated.

`EventReferenceNotFound` carries an optional original cause; its message is not
user-facing. The adapter catches `CalendarException` only around calendar resolution,
`objectByUid` and parsing, as the current linker does. Missing/deleted/unparseable
rows and hidden classification produce the contract exception with no cause.
Exceptions from classification, summary selection or URL construction, including
`CalendarException`, remain outside that catch and propagate to Talk's generic
boundary. Do not wrap the complete reader in one broad catch.

Talk catches only `EventReferenceNotFound` on this path and constructs its existing
localized reference refusal with `$exception->getPrevious()` as the cause, not
with the intermediate contract exception. This retains both the original cause
for sanitized logging and the no-cause case. Deck's existing catch-all around
`CardReferenceReader::read()` remains distinct; do not unify these two policies.

`ReferenceLinker` retains input-shape validation, app/read-grant checks **before**
calling either contract, optional Deck board matching, single-line titles and final
message length checks. Calendar's empty-title fallback remains Talk's localized
reference text; confidential events still use Calendar's current `BUSY_SUMMARY`
value, including its existing language. English source policy does not authorize
translating that output in this migration. Keep URL encoding, webroot and the
caller's shared-calendar path exactly as before.

The future implementation touches only these three Calendar files, their binding,
focused tests and affected boundary documentation. It does not move Calendar's
module/tools, change its API, extract shared DAV or start migrating Contacts/Tasks.

## 3. Consumer and test inventory

| Consumer | Required update and phase |
| --- | --- |
| `lib/AppInfo/Application.php` | Register native providers, the Calendar contract and eight explicit Talk factories; preserve `MODULES` position. Add bindings with their capabilities; replace module in Phase 6. |
| `tests/Unit/ApplicationRegistrationTest.php` | Execute real factories with strict doubles; prove lazy native construction and visibility injection (Phases 1–6). |
| `tests/Architecture/DeckBoundaryTest.php` | Its hardcoded `Tools/Talk/ReferenceLinker.php` path moves in Phase 2. Check all Talk source imports, not just one known file, so the Deck contract boundary cannot be bypassed. |
| `tests/Unit/Contract/VisibilityContractTest.php` | Retarget types/messages and recursive construction when their owners move; preserve the matrix covering both Talk file tools, hidden ancestors and preview non-disclosure (Phases 1, 4, 6). |
| `tests/Unit/Contract/ToolErrorsLanguagesTest.php` | Replace old module graph with final fixtures; preserve localized call/preview refusals (Phases 1–6). |
| `tests/Unit/Tools/WritePlanContractTest.php` | Retarget the module-to-renderer-test mapping in Phase 6. It checks `AssertsReadablePlans` and test methods, not a shared plan-data class. |
| `tests/Unit/Contract/NextcloudApi/NextcloudApiUsage.php` | Retarget the timer fallback owner and scanner explanations as files move; retain native optional-argument/default assumptions (Phases 1–6). |
| `tests/Unit/Contract/NextcloudApi/LibScanner.php` and contract tests | Check provider extraction remains visible to class-string/method scanning; adapt precision only if required, never delete API coverage to make the move pass. |
| `tests/bootstrap.php`, Composer files, scripts and CI | Audit for paths/loaders; change only if a real moved dependency requires it. No global native Talk fixture loader. |

Move existing tests with their owners; never leave a phase with broken mixed tests.
`TalkModuleTest` splits into entry-point/capability tests plus final composition tests.
`ConversationWriterTest` splits across text, parent lookup, batch and attachment
publication; `WritePreviewTest` follows its six operations. `TalkPlanRendererTest`
remains the public six-tool readability contract and gains focused renderer tests
only where they verify distinct behavior. `TalkTranslationTest` follows Presentation
and the concern-specific message classes.

Keep single-use room/participant doubles local. Extract only shared fixtures to
`tests/Support/Talk`. Replace `TalkServicesWithProbe` with fixed-provider tests;
never recreate the broad services facade as a test double. Native constant fixtures
may use isolated-process `OCA\Talk` aliases, as today; these are external API test
fixtures, not aliases for retired `OCA\Mcp\Tools\Talk` classes. Missing-app tests
must run before or independently of any native aliases. Move reference tests off
Deck entity stubs where only the public contract needs a double.

### Replacement and removal ledger

This table covers all 22 baseline Talk source files. “Remove” means delete the old
file and retarget every source/test consumer, including static schema constants and
relative fixture includes. Remove a file earlier if no consumer remains; never
extend its deadline to a follow-up migration.

| Old file(s) under `lib/Tools/Talk/` | Final owner(s) | Remove by |
| --- | --- | --- |
| `Conversation.php`, `ConversationResolver.php`, `ConversationAccessException.php` | `Access/` | Phase 1 |
| `ActorNames.php` | `Presentation/ActorNames` | Phase 1 |
| `TalkUnavailableException.php`, `ToolResult.php` | `Execution/` | Phase 1 |
| `FileAccessException.php` | `Attachments/FileAccessException` | Phase 1 |
| `Messages.php` | Concern-specific message classes | Phase 1 |
| `TalkServices.php` | `Integration/` providers, availability and constants | Phase 6; only an old-to-new adapter may remain after Phase 1 |
| `ConversationReader.php` | `Reading/ConversationReader` | Phase 2 |
| `ReferenceLinker.php` | `References/ReferenceLinker` and Calendar reference capability | Phase 2 |
| `ConversationWriter.php` | `MessageText`, `MessageSender`, `ReplyTargetReader`, `BatchSending`, `AttachmentPublisher` | Phase 5; extracted methods delegate only until their remaining callers migrate |
| `WritePreview.php` | Six capability previews, `QuotedMessage`, `ConversationPlan` | Phase 5; delete each old preview branch as its tool moves |
| `FileSharer.php`, `UserFileResolver.php`, `AttachmentAccess.php`, `AttachmentMessage.php` | `Attachments/` | Phase 4 |
| `UserConversationResolver.php`, `DirectContact.php` | `DirectMessaging/` | Phase 5 |
| `GroupCreator.php` | `Groups/GroupCreation` | Phase 5 |
| `TalkPlanRenderer.php` | `Presentation/` renderers and helpers | Phase 6 |
| `TalkModule.php` | Final module, catalog and eight tools | Phase 6 |

Retarget schema limit constants to their new owners as those owners appear; do not
retain `WritePreview` or `ConversationWriter` just for a constant. Keep any temporary
adapter inside the existing old Talk tree, recorded with its remaining callers and
removal phase. No new `Legacy`, `Compat`, forwarding namespace or transition folder
is permitted. New Talk classes must never import the old tree, even between phases.
No aliases for retired MCP classes are needed at any phase.

The test-support files in the final tree are permanent fixtures, not production
adapters; create them only when shared tests need them. Move or split each of the
15 baseline test files alongside its owner and remove the empty old test directory
in Phase 6. The test-method ledger and final removal scanner are complementary:
one guards behavioral coverage, the other guards architectural completion.

## 4. Execution phases and acceptance gates

All phases below are **complete locally**. The execution report records the focused
phase commits, verification and final ownership. Use one focused local commit per green phase.
No next phase starts until its focused tests and the full suite pass. Record
commands/results and unresolved concerns in the eventual execution report. Fix
regressions in the current phase; do not defer them to final cleanup.

### Phase 0 — Freeze externally observable behavior

**Files:** Add `tests/fixtures/talk-definitions.json` and its license sidecar,
`tests/Unit/Contract/TalkMigrationCompatibilityTest.php`; extend the current Talk
and consumer tests only where the coverage below is missing.

- [x] Capture raw definitions from baseline code, then registry-published metadata
  and generated guide content. Preserve list order, numeric types, empty objects,
  optional fields and source strings. Normalize associative key order only where
  the existing shared `ToolDefinition::toArray()` ordering requires it; do not
  normalize away schema or list differences. Never regenerate from migrated code.
- [x] Record all 271 original test method IDs and map split/moved cases to their
  destination throughout execution. Preserve assertions and behavioral cases,
  not merely an aggregate count.
- [x] Pin the six preview payloads and rendered bodies, JSON result envelopes,
  exception/log behavior, unknown/read-preview refusals and no new availability
  check. Include Spanish, Brazilian Portuguese and source-language fallback.
- [x] Add missing characterization for the ordering/asymmetries in section 1,
  two users in one module instance, app enablement changes, title/access changes
  between preview and execution, and confirmed calls with no prior preview.
- [x] Verify actual Basic/session and Bearer registry/controller paths. Pin that
  previews never send, share, create a room or invite; keep native lobby expiry.
- [x] Run focused tests and the full suite; record results, then commit the baseline.

**Accept:** The eight public definitions and six write flows have independent
baseline evidence. Existing behavior is distinguished from proposed architecture;
no production code changed and no snapshot comes from the replacement implementation.

### Phase 1 — Extract access, native integration and error foundations

**Files:** Add `Modules/Talk/{Access,Integration,Execution}` foundations,
`Presentation/ActorNames`, `Messaging/MessageText`, and the concern-specific message
classes; retarget old Talk consumers/tests and `Application` bindings. Add focused tests in the
corresponding new test directories.

- [x] First add tests for fixed-provider laziness and per-user rechecks: disabled,
  too-old/missing app, unknown user, absent class, non-object service and thrown
  resolution error. Retain the baseline distinction between unavailability and
  unexpected container errors.
- [x] Extract the three exceptions, `Conversation`, `ConversationResolver`, native
  factories/constants, actor names, message normalization and error/result boundaries.
  Extract `MessageText` now because the Phase 2 linker needs it; the old writer
  delegates normalization to it until removed. Move strings to their final owners
  without changing IDs. Retarget all implicit same-namespace uses as well as imports,
  and delete the old files due in this phase according to the removal ledger.
- [x] Keep permission bits, token pattern, unavailable-error propagation,
  lobby timer failure/absence fallback and read/write refusal distinctions.
- [x] Verify availability uses an `IUser`, no provider runs at construction, no
  user/locale leaks across calls, and result/preview failures map and log correctly.
- [x] Run the focused foundation/legacy/consumer tests and full suite, then commit.

**Accept:** New foundations import no old Talk code and have no service locator.
An old-to-new delegation may keep not-yet-migrated tools working, but every such
adapter is recorded for mandatory deletion in Phase 6. Do not retain a permissive
constructor just to keep an old fixture unchanged.

### Phase 2 — Migrate reading and close the reference boundaries

**Files:** Add the two reading tools, `Reading/ConversationReader`,
`References/ReferenceLinker`, the three Calendar reference files and binding;
move/split reader/reference tests and update `DeckBoundaryTest` and API usage owners.

- [x] Add a failing contract-boundary/composition test before replacing Calendar
  internals in Talk. Retain the existing card/event cases with real Calendar
  parsing/classification in Calendar's focused test, not only mocked references.
- [x] Move conversation reading; preserve activity sorting, missing participants,
  lobby-hidden unread counts, default 50/max 200 history, chronological order,
  timestamp/null/ID types, verb-restricted envelopes and literal user JSON text.
- [x] Extract the Calendar capability and contract; retain missing/deleted/private/
  confidential/untitled events, recurrence-master summary, shared calendars,
  subdirectory webroots and encoded URIs. Test expected versus unexpected errors.
- [x] Move the linker, retaining app/grant checks before reads, Deck board matching,
  title sanitization, canonical links and final message-length validation. Assert
  a reference-free reply never accesses either reference contract.
- [x] Route the old module's two read entries to the new tools, preserving list
  position and results. Delegate the complete tool result rather than wrapping its
  envelope in another success result. Use the intermediate routing rule above and
  replace the two inline definitions with their static tool definitions. Cover both
  success and refusal through the old module/registry. Native Talk is not resolved
  by definitions or guide output.
- [x] Run new reading/reference/Calendar tests, surviving Talk tests, Deck boundary,
  Calendar suite and shared contracts, then full suite; commit only when green.

**Accept:** Talk no longer imports Calendar internals or concrete Deck types.
Reading behavior is unchanged and neither the new Calendar capability nor any
new Talk class calls the old Talk implementation.

### Phase 3 — Migrate replies and batch sending

**Files:** Add `Messaging/*`, `Tools/{Reply,SendBatch}` and
`Presentation/ConversationPlan`; move/split writer, preview and module tests.

- [x] First pin text normalization, parent lookup/refusal scope, UTC timestamps,
  `ensureOneToOneRoomIsFilled`, native send defaults and final reference text.
- [x] Extract single-message sending and quote lookup. Implement reply and batch
  use cases with per-operation preview methods; route old dispatch to new tools
  outside its old error handler. Test one localized preview refusal through the
  intermediate registry with exactly one log entry, then remove the old branches.
- [x] Preserve the preview's all-item validation and execution's per-item results,
  zero-based indices, continuation after handled failures, abort on unavailable
  Talk, and exactly one partial-batch warning with the same fields.
- [x] Test blank/overlong bodies, missing/cross-room parents, malformed/oversized
  batches, all-failed and partially sent batches, title/access changes, and changed
  users. Prove no automatic retries and no publication from preview.
- [x] Keep success/error JSON envelopes and confirmation without prior preview.
  Run focused migrated/surviving tests, compatibility/language tests and full suite;
  commit only when green.

**Accept:** Reply and batch paths use final messaging capabilities. Shared text
sending has no attachment/group logic. Preview and execute keep their documented
ordering differences rather than obtaining accidental new common preconditions.

### Phase 4 — Migrate attachments and citations

**Files:** Add `Attachments/*` and `Tools/{AttachFile,QuoteFile}`; migrate the four
attachment/file test files and affected writer/preview/module cases. Update visibility
contract construction and application bindings in the same phase.

- [x] Pin traversal/invalid-path rejection before storage, user-folder resolution,
  read/share permissions, hidden nodes/ancestors and indistinguishable refusals.
- [x] Move sharing and attachment access. Verify production composition supplies
  `VisibilityGuard`; the new graph must not accidentally use a nullable fallback.
- [x] Preserve duplicate checks across both initiated and received room shares,
  read-only `TYPE_ROOM` share creation and the listener's single attachment card.
- [x] Extract system-message publication for quotes; verify provider-prefixed share
  IDs, same-room validation, unavailable/deleted/hidden nodes and metadata fallback.
  Quoting creates no share; attaching never manually publishes a second file card.
- [x] Keep optional caption null/normalization behavior, returned original caption,
  `captionSent` tri-state, optional `messageId`, partial-success guidance and error
  logging when the card exists but caption sending fails. No rollback or retry.
- [x] Run attachment, visibility, translation, compatibility and surviving Talk
  tests plus full suite; commit only when green.

**Accept:** Both tools preserve file confidentiality and publication counts.
Previews create neither shares nor messages. No attachment responsibility remains
in the final `MessageSender` and no old writer is required by migrated tools.

### Phase 5 — Migrate direct messages and group creation

**Files:** Add `DirectMessaging/*`, `Groups/GroupCreation`,
`Tools/{MessageUser,CreateGroup}`; split user-conversation/group/preview/module tests.

- [x] Characterize target validation, missing-versus-unreachable refusals, self
  refusal, enumeration using the real actor, ordered deduplication and guest naming.
- [x] Separate contact resolution from native one-to-one creation. Keep product
  room reuse, participant resolution and existing writable checks. Preview only
  reads the target; execution rechecks reachability and sends once.
- [x] Move group creation with name normalization, maximum 50 submitted participants
  before deduplication on execution, service resolution before room creation, and
  one `addUsers` call per resolved guest. Registry schema validation enforces the
  participant limit on previews; the current direct preview has no equivalent
  count guard. Preserve that boundary rather than adding a new capability check.
  Preview carries no room token and creates nothing.
- [x] Preserve owner/empty-list handling and the exact `participants` /
  `invitations_failed` arrays and per-guest warning logs. Failed invitations do not
  delete the group or cause retries; unexpected native behavior is not upgraded to
  Deck's recovery model. Include room creation/send failure and partial invitations.
- [x] Run contact/direct/group tests, all migrated and surviving Talk tests,
  compatibility/language/registration tests and full suite; commit only when green.
- [x] Remove `ConversationWriter`, `WritePreview`, the old direct/group classes
  and their migrated branches/constants. Only temporary composition/presentation
  and the optional old services adapter may remain for Phase 6.

**Accept:** All eight tools execute through final capability code. Contact checks
are shared without making groups depend on direct-message room creation. The old
module is now only temporary composition/presentation awaiting the final phase.

### Phase 6 — Final composition, presentation and complete removal

**Files:** Add `TalkModule`, `TalkToolCatalog`, final presentation classes and
`TalkBoundaryTest`; retarget the remaining shared consumers listed in section 3.
Delete `lib/Tools/Talk/` and `tests/Unit/Tools/Talk/` in full. Update the architecture
specification, adding-a-tool guide and documentation indexes; add an execution report.

- [x] Split rendering into message, attachment and group renderers. Retain one
  public six-tool readability contract using actual capability previews, hostile
  Markdown/newline cases, excerpt limits, size formatting, null fallback and a
  throwing translator. Rendering must resolve and publish nothing.
- [x] Compose the final ordered catalog and lazy factory module; register it in the
  same `Application::MODULES` position. Retarget the write-plan contract mapping.
  Remove all old module constants/references; derive write status from definitions.
- [x] Execute every production factory, instantiate all eight tools and the Calendar
  reference implementation, and cover real registry calls/previews. Assert correct
  user propagation, mandatory visibility injection and no eager native lookup.
- [x] Delete the old module/services/writer/preview/messages/rendering code, all
  temporary delegations, old-source includes, aliases for retired MCP classes, stale helpers and
  duplicate definitions. Regenerate the optimized autoloader before verification.
- [x] Add architecture tests that reject retired namespaces and paths in imports,
  FQCNs, class-name strings and config, plus production dependencies on test support.
  Scan source, tests, appinfo, scripts, CI, Composer and PHPUnit configuration.
  Include negative examples proving the scanner catches escaped string references.
- [x] Enforce explicit cross-feature boundaries: all Talk code may import only the
  Deck/Calendar public contracts; optional native strings stay at the integration
  boundary. Enforce the narrowly scoped Calendar implementation dependency from
  section 2, and reject a Calendar-to-Talk dependency. No allowlist may permit
  retired Talk code. Calendar's own active storage dependencies are the named
  intra-module exception described above, not a Talk compatibility exemption.
- [x] Reconcile the original 271 test-method ledger with final tests. Remove obsolete
  wiring-only assertions only with a recorded replacement; preserve all behavioral
  cases and consumer contracts. Verify focused tests discover their fixtures alone.
- [x] Run final focused tests, `devbox run check`, retired-reference audit and the
  production package probe below. Commit only after local checks pass.

**Accept:** All local gates are green, metadata/guide/results are compatible, and
no old Talk source/test directory or transition code exists. The only Calendar
addition is the permanent reference capability. The report distinguishes local
completion from outstanding remote CI and disposable-server integration checks.

## 5. Verification commands and completion evidence

Set `TASK_REPO` to the validated worktree returned by `devbox context`; do not use
the base checkout or the historical worktree path blindly. After adding the Phase 0
compatibility test, use this focused command from `mcp/` in Bash through Phase 5.
It includes every existing migrated/surviving Talk test directory and the shared
consumers; no future directory is passed to PHPUnit before it exists.

```sh
talk_tests=(
  tests/Unit/Contract/TalkMigrationCompatibilityTest.php
  tests/Unit/ApplicationRegistrationTest.php
  tests/Unit/Contract/VisibilityContractTest.php
  tests/Unit/Contract/ToolErrorsLanguagesTest.php
  tests/Unit/Tools/WritePlanContractTest.php
  tests/Architecture/DeckBoundaryTest.php
  tests/Unit/Contract/NextcloudApi
  tests/Unit/McpControllerTest.php
)
for suite in tests/Unit/Tools/Talk tests/Unit/Modules/Talk tests/Unit/Modules/Calendar/References; do
  if [ -d "$suite" ]; then
    talk_tests+=("$suite")
  fi
done
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --do-not-cache-result "${talk_tests[@]}"
```

Phase 2 additionally runs `tests/Unit/Tools/Calendar` with the same PHP flags.
Before advancing each phase, run the catalog's full test task:

```sh
devbox run test --cwd "$TASK_REPO" --json
```

Final focused command, from `mcp/` after Phase 6:

```sh
composer dump-autoload --optimize
php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit --do-not-cache-result tests/Unit/Modules/Talk tests/Unit/Modules/Calendar/References tests/Unit/Contract/TalkMigrationCompatibilityTest.php tests/Architecture/TalkBoundaryTest.php tests/Architecture/DeckBoundaryTest.php tests/Unit/ApplicationRegistrationTest.php tests/Unit/Tools/WritePlanContractTest.php tests/Unit/Contract/VisibilityContractTest.php tests/Unit/Contract/ToolErrorsLanguagesTest.php tests/Unit/Contract/NextcloudApi tests/Unit/McpControllerTest.php
```

Then run the catalog's full test/package validation:

```sh
devbox run check --cwd "$TASK_REPO" --json
git -C "$TASK_REPO" diff --check
```

From the repository root, this manual search supplements the executable boundary
scanner; expected output is empty outside deliberately constructed scanner test data:

```sh
test ! -d mcp/lib/Tools/Talk
test ! -d mcp/tests/Unit/Tools/Talk
rg -n 'Tools[\\/]+Talk|Unit[\\/]+Tools[\\/]+Talk' mcp/lib mcp/tests mcp/appinfo mcp/scripts .github mcp/composer.json mcp/composer.lock mcp/phpunit.xml
```

An empty `rg` result exits with status 1; status 2 is a search error and must not
be treated as a clean audit. Both directory assertions must succeed, even when the
remaining directories are empty.

Historical documentation may describe retired paths. It must never serve as an
executable compatibility loader or excuse to exclude active code from the scan.

For the actual archive produced by `check`, perform a fresh production-only probe
without altering the development `vendor/`: load the packaged autoloader with the
minimum host API fixtures and no native Talk classes. Inventory and load every
new Talk/Calendar reference class/interface; verify exact source bytes, eight raw
and published definitions, generated guide and pure renderer behavior. Inspect
archive membership and Composer classmaps for retired Talk paths, test support,
PHPUnit and development-only dependencies. Do not call a successful development
unit run a production-autoload test.

The report must record the commit baseline, phase commits, focused/full commands,
counts, package path and probe results, test-method reconciliation, source-removal
scan and any remaining integration gates. Avoid machine-specific paths in durable
commands except as clearly labeled local evidence.

## 6. Completion and subsequent work

Talk source migration is complete only when every phase and local acceptance gate
is satisfied. No planned follow-up may be needed to remove Talk adapters, aliases,
old folders, duplicate dispatch, imports or test loaders. The Calendar reference
contract is the final integration boundary, not a promise to clean up Talk later.

Remote CI must still cover PHP 8.2–8.5, and disposable Nextcloud/Talk environments
must exercise supported-version behavior: per-user app enablement, Basic/Bearer
identity, lobby expiry, history without unread changes, one-to-one room reuse,
preview non-publication, exactly one attachment card, quoted shares, partial batches,
caption failure, invitations and cross-feature references. Use test accounts/data;
this plan does not authorize sending real messages or publishing to production.
Keep any unavailable integration gate explicitly open instead of claiming release
readiness from unit tests alone.

After implementation, review the report before choosing the next migration.
Calendar/DAV and their Contacts/Tasks consumers remain a separate, larger scope;
Notes, People, Logs and administration remain separately scoped too. The existing
Files/Deck remote integration gates, Deck move-guide discrepancy, developer-command
aliases, formatting/static-analysis policy, README relocation and translated docs
remain independent work. Do not bundle those changes into Talk.
