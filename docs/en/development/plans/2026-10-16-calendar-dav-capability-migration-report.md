<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Calendar, DAV, Contacts and Tasks migration execution report

Date: 2026-10-16. Reviewed implementation baseline: `ddfa06c`.
Scope: all 20 tools (Calendar 7, Contacts 6, Tasks 7), shared DAV, Calendar references,
CLI diagnostics and their direct consumers. No data/schema, dependency, permission,
public tool or authentication changes. Work stayed in the validated existing
`docs/talk-capability-plan` worktree branch; no branch rename, push or deployment.

## Phase evidence

Every phase's full gate passed before the next phase's implementation began.
Failures found during a phase were corrected before its commit and advancement.

| Phase | Focused tests / assertions | Full tests / assertions | Commit |
| --- | --- | --- | --- |
| Baseline | Existing focused/consumer suites | 5,168 / 35,666 | `ddfa06c` |
| 0: publication contracts | 566 / 3,548 | 5,169 / 35,673 | `72d8308` |
| 1: shared values, dates and notices | 566 / 3,548 | 5,169 / 35,709 | `731699c` |
| 2: native transport and read ports | 587 / 7,552 | 5,159 / 35,521 | `a69ca08` |
| 3: Calendar capabilities | 594 / 7,877; shared contract fixtures 19 / 3,684 | 5,159 / 35,888 | `91cd15b` |
| 4: diagnostic consumer | Diagnostic/command 13 / 42; native scanner 11 / 104 | 5,159 / 36,356 | `e4511f6` |
| 5: Contacts | 90 / 4,724 | 5,159 / 36,444 | `961d108` |
| 6: Tasks | Tasks/native 153 / 858; shared contracts 19 / 3,684 | 5,159 / 36,544 | `a54c1cf` |
| 7: composition, architecture and package | Focused suites plus isolated suites below | **5,175 / 37,605; package valid** | Closing migration commit |

Runtime: PHP 8.4.26, PHPUnit 10.5.65. Full phase command:
`devbox run test --cwd "$TASK_REPO" --json`. Final command:
`devbox run check --cwd "$TASK_REPO" --json`, after `composer dump-autoload --optimize`
from `mcp/`. `git diff --check` passed. Composer emitted existing PSR-4 notices for
explicitly loaded external test fixtures; the production archive contains none.

Final suites also ran in separate PHP processes:

| Suite | Tests | Assertions |
| --- | ---: | ---: |
| `tests/Unit/Modules/Calendar` | 350 | 1,664 |
| `tests/Unit/Modules/Contacts` | 70 | 1,039 |
| `tests/Unit/Modules/Tasks` | 71 | 562 |
| `tests/Unit/Platform/Dav` | 82 | 300 |

The final focused selection includes all three feature suites, Platform/Dav,
Mcp/Tools, Mcp/Confirmation, DAV compatibility/catalog, ApplicationRegistration,
licensing and all architecture tests. Native/API, protocol/controller,
Basic/Bearer, grants, reference, language and remaining consumers run in the full
catalog suite. Strict application construction covers all 20 tools plus reference
and diagnostic consumers without resolving native DAV. Actual production module
factories route all 12 captured previews; actual registry approval and confirmed
mutation are exercised for Calendar editing, contact editing and task completion.
Detailed feature tests retain confirmed execution coverage for all 12 writes.

## Independent review and final rulings

A fresh-context reviewer inspected the complete migration from `ddfa06c` through
Phase 7, including normalized method comparisons against the baseline. No actionable
regression or important architectural defect was identified; there are no deferred
minor findings. Its independent focused run passed **204 tests / 2,650 assertions**
on PHP 8.4.26, covering compatibility, architecture, composition, approval, privacy,
transport and diagnostics.

Execution rulings, including the reviewer's explicitly unjudged areas:

1. Keep scratch and the execution ledger outside the managed repository, following
   server guidance. Evidence is preserved in this report before temporary scratch
   removal; the tradeoff is that temporary raw logs are not permanent artifacts.
2. Translate neutral date-validation failures at Calendar's existing boundary while
   Tasks uses the neutral exception. This preserves client field/rule/message data;
   an incorrect translation would affect direct internal exception consumers.
3. Accept the late expanded golden capture only because it was independently produced
   from `ddfa06c` and matched the migrated implementation byte for byte. Existing
   behavior tests stayed active; the tradeoff is weaker early-phase snapshot evidence.
4. Complete new boundary rejection cases with their final owners in Phase 7, keeping
   existing architecture checks enabled throughout. The tradeoff is that intermediate
   commits had fewer negative architecture checks than the final tree.
5. Leave PHP 8.2–8.5 and live Nextcloud 32–35 compatibility as pending external gates.
   Local results establish neither matrix compatibility nor the native plugin
   lifecycle; skipping these gates could miss version-specific integration failures.
6. Leave actual scheduling-email delivery and production recovery unverified. The
   refactor preserves tested native dispatch and failure behavior; treating these
   tests as operational proof could miss delivery or recovery failures.
7. Preserve baseline retry, rollback and retention behavior instead of adding new
   semantics. This follows the compatibility scope; existing partial-write and
   recovery limitations remain and must not be described as retry-safe.


## Compatibility and sequencing notes

The committed [compatibility fixtures](../../../../mcp/tests/Support/Dav/Compatibility/README.md)
freeze all 20 raw/published definitions and generated guides, all 12 write plans and
rendered texts, and representative success/error envelopes. Raw key order, object
schema shapes, annotations, approval suffixes and translated message sources remain
unchanged. Contact creation's random UID is the only normalized value.

Phase 0 froze publication and retained the existing detailed preview/result tests.
The expanded plan/render golden file was added in Phase 7, using an isolated archive
of **the original `ddfa06c` source**. Independent baseline and migrated captures
matched byte for byte before committing expectations. This is a sequencing deviation
from capturing every golden file before extraction, not a regenerated expected
result from the new code. Negative boundary cases were completed with their final
owners in Phase 7; existing Files/Deck/Talk boundary checks stayed enabled throughout.

Calendar keeps its internal CalendarArgumentException boundary by translating the
neutral ISO parser's validation exception. Tasks consumes the neutral exception;
client field/rule/message data stays compatible. This avoids leaking one feature's
exception type into another. Transport failures have a neutral CalDavFailure owner.

Execution scratch and the ledger stayed outside the repository as required by the
server's guidance. Formatting used the already installed PHP parser and verified
identical ASTs before/after; no formatter dependency or new developer command was
introduced. Temporary baseline extraction was used only for independent comparison and removed
after verification; it is not a runtime fallback or package input.

Missing imports, old fixture constructor assumptions and a native-test loader were
caught and corrected by phase gates. The final suite also caught a new snapshot
fixture leaving Portuguese localization active for later Files tests; its teardown
now resets the translator. The combined regression test and final full check passed.

## Final ownership

```text
mcp/lib/
  Modules/Calendar/
    CalendarModule.php, CalendarToolCatalog.php, Tools/
    Reading/, Creation/, Editing/, Relocation/, Deletion/
    Events/, Scheduling/, Execution/, Presentation/
    Contracts/, References/, Selftest/, Integration/
  Modules/Contacts/
    ContactsModule.php, ContactsToolCatalog.php, Tools/
    AddressBooks/, Directory/, Search/, Reading/
    Creation/, Editing/, Deletion/, Cards/, Execution/, Presentation/, Integration/
  Modules/Tasks/
    TasksModule.php, TasksToolCatalog.php, Tools/
    Reading/, Creation/, Editing/, Completion/, Deletion/
    Content/, Execution/, Presentation/, Integration/
  Platform/Dav/
    Collections/, Transport/, Icalendar/, CalDav/, CardDav/
  Mcp/Confirmation/                # plain shared-collection notice/messages
  Mcp/Tools/DavCollectionSchema.php # field fragments, no definition builder
```

Collection/object reads, object writes, administration and ACL probing have separate
ports. Event-range and VTODO queries remain feature-owned. Native providers are
explicit lazy closures at composition; production capabilities receive no container.
Calendar's event-only preparation supports MCP approval and diagnostics. CLI cleanup
owns reserved-resource identity checks, invitation cleanup and independent failure
reporting; the runner restores the previous session in `finally`. Public tools never
request administration or probe ports.

Contacts separates directory lookup, enumeration policy and public-field mapping.
Its deleter retains verified backup, fresh access/owner/ETag checks and native DELETE.
Tasks separates parsing/mapping, visibility/complexity, patching, dates/alarms and
Unicode categories. Native writes, read-back, retention, scheduling suppression,
privacy and existing failure precedence remain covered by the original scenarios.

All five old production trees (`Tools/Calendar`, `Tools/Contacts`, `Tools/Tasks`,
`Tools/Dav`, `Service/Calendar`) and their four old test trees are absent. No forwarding
adapter, compatibility class alias, alternate dispatch or transitional directory was
introduced. Shared Tools/Common, registry, confirmation, policy and localization
facilities remain active shared owners explicitly excluded from this migration.

## Test reconciliation and production archive

All **462 original test methods** in the 38-file inventory remain, with their data
providers. Full test-discovery XML was compared against an independent baseline
archive: the only ten removed generated cases are class/method API checks for
`OCP\Server` across fixture majors 31–35, because the global locator is no longer
used. Seventeen new cases were added, giving 5,168 - 10 + 17 = **5,175**. No behavior
case was dropped to preserve a superficially similar count.

The actual `mcp/build/mcp-1.0.8.tar.gz` from the final check was extracted into the
ignored worktree build directory and loaded in a fresh PHP process with **only its
packaged production autoloader**. All **143 affected production files/types** loaded
and matched working source bytes. Minimal explicit host fixtures supplied the Sabre
SAPI parent and constructor-only OCP/PSR interfaces; no PHPUnit, application test
support, development vendor or native DAV implementation was loaded.

All 20 raw/published definitions, generated guides and all 12 saved-plan renderings
matched the baseline. Module order, archive membership and Composer classmaps contain
no retired owner or test dependency. Nine native adapter/factory constructors were
also exercised with providers that throw if resolved; construction remained lazy.
This proves production autoload/composition prerequisites, not the real native plugin
lifecycle. The catalog package checker separately verifies production-only contents.

Original method owners are reconciled below. Paths are relative to `mcp/tests/`;
zero-method helper files are accounted for through permanent support ownership.

| Original file | Final file(s) | Retained methods |
| --- | --- | ---: |
| `Unit/Tools/Calendar/AttendeeResolverTest.php` | `Unit/Modules/Calendar/AttendeeResolverTest.php` | 9 |
| `Unit/Tools/Calendar/DateInputTest.php` | `Unit/Modules/Calendar/DateInputTest.php` | 3 |
| `Unit/Tools/Calendar/CalendarPlanRendererTest.php` | `Unit/Modules/Calendar/CalendarPlanRendererTest.php` | 29 |
| `Unit/Tools/Calendar/ListEventsTest.php` | `Unit/Modules/Calendar/ListEventsTest.php` | 27 |
| `Unit/Tools/Calendar/CalendarModuleTest.php` | `Unit/Modules/Calendar/CalendarModuleTest.php` | 7 |
| `Unit/Tools/Calendar/CalendarApprovalTest.php` | `Unit/Modules/Calendar/CalendarApprovalTest.php` | 19 |
| `Unit/Tools/Calendar/EmbeddedDavDispatcherTest.php` | `Unit/Platform/Dav/EmbeddedDavDispatcherTest.php` | 27 |
| `Unit/Tools/Calendar/ClassificationTest.php` | `Unit/Modules/Calendar/ClassificationTest.php` | 4 |
| `Unit/Tools/Calendar/CreateEventTest.php` | `Unit/Modules/Calendar/CreateEventTest.php` | 12 |
| `Unit/Tools/Calendar/CalDavBackendContractTest.php` | `Unit/Platform/Dav/CalDavBackendContractTest.php` | 3 |
| `Unit/Tools/Calendar/EmbeddedCalDavServerFactoryTest.php` | `Unit/Platform/Dav/EmbeddedCalDavServerFactoryTest.php` | 6 |
| `Unit/Tools/Calendar/TransferEventTest.php` | `Unit/Modules/Calendar/TransferEventTest.php` | 9 |
| `Unit/Tools/Calendar/ListCalendarsTest.php` | `Unit/Modules/Calendar/ListCalendarsTest.php` | 5 |
| `Unit/Tools/Calendar/MoveEventTest.php` | `Unit/Modules/Calendar/MoveEventTest.php` | 9 |
| `Unit/Tools/Calendar/DeleteEventTest.php` | `Unit/Modules/Calendar/DeleteEventTest.php` | 10 |
| `Unit/Tools/Calendar/UpdateEventTest.php` | `Unit/Modules/Calendar/UpdateEventTest.php` | 22 |
| `Unit/Tools/Calendar/DavCalendarStoreTest.php` | `Unit/Platform/Dav/DavCalendarStoreTest.php` | 13 |
| `Unit/Tools/Calendar/PlanWarningsTest.php` | `Unit/Modules/Calendar/PlanWarningsTest.php` | 45 |
| `Unit/Tools/Calendar/Scheduling/CollisionCheckTest.php` | `Unit/Modules/Calendar/Scheduling/CollisionCheckTest.php` | 26 |
| `Unit/Tools/Calendar/Scheduling/SharedCalendarFinderTest.php` | `Unit/Modules/Calendar/Scheduling/SharedCalendarFinderTest.php` | 15 |
| `Unit/Tools/Calendar/Scheduling/AvailabilityCheckTest.php` | `Unit/Modules/Calendar/Scheduling/AvailabilityCheckTest.php` | 9 |
| `Unit/Tools/Contacts/ContactsPlanRendererTest.php` | `Unit/Modules/Contacts/ContactsPlanRendererTest.php` | 8 |
| `Unit/Tools/Contacts/ContactsModuleTest.php` | `Unit/Modules/Contacts/ContactsModuleTest.php` | 31 |
| `Unit/Tools/Contacts/ContactsPolicyTest.php` | `Unit/Modules/Contacts/ContactsPolicyTest.php` | 2 |
| `Unit/Tools/Contacts/EmbeddedCardDavDispatcherTest.php` | `Unit/Platform/Dav/EmbeddedCardDavDispatcherTest.php` | 14 |
| `Unit/Tools/Contacts/ContactBackupTest.php` | `Unit/Modules/Contacts/ContactBackupTest.php` | 5 |
| `Unit/Tools/Contacts/SystemContactsTest.php` | `Unit/Modules/Contacts/SystemContactsTest.php` | 22 |
| `Unit/Tools/Tasks/TaskCategoriesTest.php` | `Unit/Modules/Tasks/TaskCategoriesTest.php` | 14 |
| `Unit/Tools/Tasks/TasksModuleTest.php` | `Unit/Modules/Tasks/TasksModuleTest.php` | 24 |
| `Unit/Tools/Tasks/TasksPlanRendererTest.php` | `Unit/Modules/Tasks/TasksPlanRendererTest.php` | 21 |
| `Unit/Service/Calendar/CalendarSelftestServiceTest.php` | `Unit/Modules/Calendar/Selftest/CalendarSelftestServiceTest.php` | 10 |
| `Unit/Service/Calendar/DavCalendarSelftestReaderTest.php` | `Unit/Modules/Calendar/Integration/DavCalendarSelftestReaderTest.php` | 2 |

Shared storage/native doubles now live in `tests/Support/Dav`; Calendar, Contacts and
Tasks fixtures live in `tests/Support/<feature>`. No surviving fixture requires an
old feature test path. Boundary tests inspect imports, qualified calls, serialized
class strings and executable/autoload paths, with negative scanner cases and an
allowed `DavCollectionSchema` prefix case.

## Pending evidence and next scope

Local migration and package checks are complete. Remote CI on PHP 8.2–8.5 and
execution in disposable supported Nextcloud 32–35 servers remain pending. Fixtures
also retain older-major API checks where already present; they do not extend the
declared support range or prove actual native scheduling and delivery.

Before release, exercise Basic/Bearer session pinning, native plugin construction,
precondition failures, sync tokens, suppression/outcomes, MOVE/transfer/trash,
contact directory privacy and backup, Tasks without its optional UI app, reference
URLs under a webroot, and selftest cleanup/restoration on disposable accounts.
No real-user diagnostic or mutation was performed for this refactor.

Remaining source migration candidates are Notes, People and Logs. A focused Notes
analysis/plan is the next suggested scope; People and Logs should have their own
reviewed boundaries. Broader shared MCP registry/policy relocation, administration,
developer command standardization, README relocation and translated documentation
remain separate authorized work. None is required to remove transition code from
this completed DAV scope.
