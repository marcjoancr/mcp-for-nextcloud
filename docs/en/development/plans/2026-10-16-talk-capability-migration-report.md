<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Talk capability migration execution report

Date: 2026-10-16. Reviewed-plan baseline: `d457fe3`.
Scope: all eight Talk tools, their direct consumers, and the permanent Calendar
reference capability. No other module's tools were migrated.

## Phase evidence

Each phase's focused and full suites passed before the next phase's implementation.
Phase 0's commit was issued while the full task was still running; that result was
verified before Phase 1 began. Subsequent commits followed completed gates.

| Phase | Focused tests / assertions | Full tests / assertions | Commit |
| --- | --- | --- | --- |
| 0: frozen behavior | 328 / 1,563 | 5,149 / 32,928 | `8943ece` |
| 1: access, integration, errors | 321 / 2,208 | 5,150 / 32,983 | `98276ab` |
| 2: reading and references | 314 / 2,332; Calendar 388 / 1,832 | 5,151 / 33,008 | `df5de4f` |
| 3: replies and batches | 309 / 1,533 | 5,152 / 33,052 | `59afcdf` |
| 4: attachments and citations | 313 / 2,037 | 5,152 / 33,078 | `d8b335f` |
| 5: direct messages and groups | 313 / 2,037 | 5,152 / 33,095 | `822a632` |
| 6: final composition and removal | 2,513 / 9,864; registry fixtures 15 / 3,182; final metadata/dispatch 45 / 291 | **5,168 / 35,666; production package valid** | Closing migration commit |

Local runtime: PHP 8.4.26 and PHPUnit 10.5.65. Commands ran in the validated isolated
worktree, using `devbox run test --cwd "$TASK_REPO" --json` after each phase and
`devbox run check --cwd "$TASK_REPO" --json` for final test/package validation.
The final focused command is recorded in the [plan](2026-10-16-talk-capability-migration.md#5-verification-commands-and-completion-evidence).
The two generic registry fixture builders needed the final factory-map constructor;
the full gate exposed this and both were corrected before completion.

The actual `mcp/build/mcp-1.0.8.tar.gz` archive was extracted into the ignored
worktree build directory and loaded in a fresh PHP process using only its packaged
production autoloader. No development autoloader, test bootstrap, host API fixture
or native Talk class was required. All **70 new types** (67 Talk and 3 Calendar)
loaded. All eight raw/published definitions and the generated guide matched the
independent frozen baseline; all six write-plan renderers and null fallbacks worked.
All 70 source files matched the worktree byte for byte. Archive membership and
Composer classmaps contained no retired Talk path, test support or PHPUnit.
The catalog's package check also excludes development-only dependencies.

## Final ownership

```text
lib/Modules/Talk/
  TalkModule.php                 # lazy factory dispatch and presentation entry point
  TalkToolCatalog.php            # explicit ordered list of eight tools
  Tools/                         # definition and entry points per tool
  Access/                        # conversation, membership and writable/lobby checks
  Integration/                   # availability, fixed native providers, native constants
  Reading/                       # conversation listing and history
  Messaging/                     # text, parent reads, replies, batch delivery and plans
  Attachments/                   # file/share access, sharing, system cards and plans
  DirectMessaging/               # contacts, one-to-one rooms, messages and plans
  Groups/                        # creation, invitations, workflow and plans
  References/                    # accessible Deck/Calendar links
  Execution/                     # exceptions, safe error mapping and result boundaries
  Presentation/                  # actors, plan envelope, pure renderers and messages
lib/Modules/Calendar/
  Contracts/                     # EventReferenceReader and EventReferenceNotFound
  References/                    # CalendarEventReferenceReader
```

All 22 baseline Talk source files are replaced by final owners. The **67 Talk PHP
sources** have cohesive responsibilities; the three Calendar files form the public
event-reference boundary. `lib/Tools/Talk/` and `tests/Unit/Tools/Talk/` are absent.
No compatibility alias, transitional wrapper, duplicate dispatcher or old loader
remains. Native optional-service strings stay in Integration and application
composition. Architectural tests inspect imports, FQCNs and escaped class/path
strings across active source, tests, configuration, scripts and CI.

Talk depends only on Deck's card-reference contract and Calendar's event-reference
contracts. Only `CalendarEventReferenceReader` uses Calendar's active access,
store, repository, classification and mapper collaborators. Calendar never depends
on Talk. Future Calendar migration can change those internal collaborators without
changing Talk's API.

## Behavior and decisions

- The baseline fixture freezes all eight raw definitions, registry-published
  metadata and generated guide before production changes. Final schemas reuse
  capability-owned limits without changing values, order, empty objects or strings.
- Read and write tools receive the acting user on every call. Construction,
  definitions and rendering resolve no native Talk service. Providers recheck
  availability and never cache authorization. Production availability requires an
  actual user manager; file/share access receives the visibility guard explicitly.
- The registry retains grants and confirmation. Calls confirmed without a prior
  preview still work; no approval state or new module-level app guard was added.
  Factory errors and operation errors are mapped/logged once, with existing locale
  behavior. Unknown/read-preview names are rejected before factory resolution.
- Reply, batch and attachment preview/execution ordering remains distinct. Parent
  lookup still maps even unavailable Talk to a missing-parent refusal in its
  original catch scope. Reading and preview JSON excerpt rules remain different.
- Attaching creates one read-only room share and lets Talk publish its card.
  Quoting creates no share. Captions preserve original returned text, normalized
  sending and partial outcomes. Batches and invitations preserve ordered results,
  continuation rules and logs; no automatic retry or rollback was introduced.
- Contact resolution is independent of one-to-one creation, so previews leave no
  empty room. Group limits retain submitted-count-before-deduplication behavior
  and the existing registry/direct-preview asymmetry.
- Calendar references retain real parsing/classification, private/confidential
  handling, master-event summaries, missing/deleted events, empty titles, encoded
  URIs and subdirectory canonical links. The existing localized busy title stays
  unchanged. Original error causes and unexpected-error propagation are preserved.
- **Implementation ruling:** execution scratch remained external to the repository
  under the devbox policy. Durable evidence is recorded here. If misplaced, only
  workflow traceability would be affected; production has no dependency on it.
- **Implementation ruling:** permanent per-operation plan builders, `BatchSender`
  and `GroupWorkflow` supplement the illustrative tree. They keep independently
  tested preview, delivery and orchestration responsibilities separate. Their cost
  is a few extra files; they are not forwarding facades and require no later cleanup.
- Formatting was applied only to migrated capabilities/tests using an external
  tool, without adding a repository dependency or formatting policy. English
  comments/documentation and existing localized user-facing strings are preserved.

## Test-method reconciliation

All **271 original test methods** from 15 files remain, with the same behavioral
assertions and final subjects. The table maps original files to final paths under
`tests/Unit/Modules/Talk/`; additional tests cover new boundaries and composition.
The broad writer/preview fixtures now hold separate real capabilities. Obsolete
never-call expectations referenced methods that no longer exist on the
narrow sender; publication/caption/preview assertions remain. The old static
write-status assertion now checks the real tools' write interfaces and catalog.

| Original test file | Original methods | Final test owner |
| --- | --- | --- |
| `ReferenceLinkerTest.php` | 16 | `References/ReferenceLinkerTest.php` |
| `ConversationReaderTest.php` | 20 | `Reading/ConversationReaderTest.php` |
| `ConversationWriterTest.php` | 15 | `Messaging/MessageDeliveryTest.php` |
| `UserFileResolverTest.php` | 10 | `Attachments/UserFileResolverTest.php` |
| `UserConversationResolverTest.php` | 15 | `DirectMessaging/ContactAndConversationTest.php` |
| `FileSharerTest.php` | 20 | `Attachments/FileSharerTest.php` |
| `WritePreviewTest.php` | 28 | `Presentation/CapabilityPlansTest.php` |
| `AttachmentMessageTest.php` | 8 | `Attachments/AttachmentMessageTest.php` |
| `GroupCreatorTest.php` | 13 | `Groups/GroupCreationTest.php` |
| `TalkModuleTest.php` | 41 | `TalkModuleTest.php` |
| `TalkTranslationTest.php` | 5 | `Presentation/TalkTranslationTest.php` |
| `AttachmentAccessTest.php` | 15 | `Attachments/AttachmentAccessTest.php` |
| `ConversationResolverTest.php` | 26 | `Access/ConversationResolverTest.php` |
| `TalkPlanRendererTest.php` | 27 | `Presentation/TalkPlanRendererTest.php` |
| `TalkServicesTest.php` | 12 | `Integration/NativeIntegrationTest.php` |

Shared fixtures expose independent native-provider doubles and explicit tool
composition. They are test-only and absent from production autoload/package output.
Calendar's focused tests exercise the real parser and classification; Talk's link
contract also retains its original end-to-end reference cases.

## Independent review

A fresh reviewer examined baseline `d457fe3` through the final worktree and found
one actionable issue: an inherited one-to-one-room comment implied retrying a
failed send was safe. The comment now states that room reuse does not prevent
message duplication. No runtime retry was introduced. The reviewer reported no
other actionable runtime or architecture mismatch and did not execute tests;
the phase/final tests above were run by the implementer. No review finding is deferred.

## Pending work and recommended continuation

1. Run the PHP 8.2–8.5 remote CI matrix when publication is authorized. Exercise
   supported Nextcloud/Talk versions on a disposable instance: app absent/disabled,
   per-user enablement, Basic/Bearer identity, lobby expiry, history, preview
   non-publication, room reuse, exactly one attachment card, quoted/hidden shares,
   partial batches/captions/invitations and cross-feature references. Unit/API
   contracts do not prove those live integration outcomes. Files/Deck retain their
   corresponding remote integration gates too.
2. Analyze **Calendar/DAV and its Contacts/Tasks consumers** next and write a
   reviewed capability plan before implementation. The reference API is already
   permanent; shared DAV transport and feature-specific access/confirmation rules
   need a broader inventory than Talk. Notes, People, Logs and administration
   remain separately scoped.
3. Correct the existing Deck move-guide discrepancy in a separate behavior/docs
   change: the frozen guide says same-list while supported behavior crosses lists
   and boards. This migration deliberately preserves published metadata.
4. Broader DevEx remains: developer command aliases, static-analysis/formatting
   policy, operational README relocation and actual translated docs. English
   sources and GitHub-friendly language directories are already the agreed policy.

No push, pull request, deployment or real message publication was performed.
