<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Notes capability migration execution report

Date: 2026-10-16. Reviewed implementation baseline: `d51e4f4` (Notes production
source matches planning baseline `c4f31fe`). Scope: all seven Notes tools and the
`nc://notes/{id}` resource. No dependency, data/schema, authorization or public API
changes. Work reused `docs/talk-capability-plan`; no branch rename, push or deployment.

## Phase evidence

Every full gate passed before the next phase's production changes began. Phase 0
has one commit-timing deviation described below; subsequent commits followed their
green gates. Commands ran on PHP 8.4.26 / PHPUnit 10.5.65.

| Phase | Focused tests / assertions | Full tests / assertions | Full runtime | Commit |
| --- | --- | --- | --- | --- |
| Baseline | Existing Notes/consumer suites | 5,175 / 37,605 | 38.640 s | `d51e4f4` |
| 0: immutable contracts | 148 / 3,437 | 5,179 / 37,710 | 41.068 s | `6e98107` |
| 1: storage and resource | 150 / 3,446 | 5,181 / 37,768 | 45.979 s | `ebadfdb` |
| 2: reads and search | 153 / 3,458 | 5,184 / 37,807 | 45.440 s | `0e58b13` |
| 3: writes and publication | 159 / 3,484 | 5,190 / 37,948 | 45.187 s | `bf5e102` |
| 4: final boundaries/package | 179 / 5,421 | **5,199 / 39,456** | 35.022 s | Closing migration commit |

Phases 0–3 used `devbox run test --cwd "$TASK_REPO" --json`. Phase 4 used
`devbox run check --cwd "$TASK_REPO" --json`, which passed the full suite and
production package validation. `composer dump-autoload --optimize` succeeded;
its PSR-4 warnings concern existing explicitly loaded external test stubs, not
Notes or production dependencies.

Final isolated runs:

| Suite | Tests | Assertions | Runtime |
| --- | ---: | ---: | ---: |
| Notes feature | 99 | 665 | 0.606 s |
| Notes, resources, global contracts, languages and Application | 179 | 5,421 | 1.459 s |
| Architecture | 26 | 7,330 | 0.744 s |
| Nextcloud API contracts | 2,129 | 4,126 | 40.213 s |

The full gate additionally exercises global publication and WriteGate fixtures.
The phase 3 explicit selection of those fixtures passed 15 tests / 3,182 assertions.

## Final ownership and preserved behavior

`lib/Modules/Notes` contains 34 production types:

```text
Notes/
  NotesModule.php                 # static publication and lazy dispatch
  NotesToolCatalog.php            # seven ordered Tool classes
  Tools/                          # list, search, read, create, edit, move, delete
  Storage/                        # folders, scoped lookup, metadata, names/messages
  Content/                        # limits, content read and messages
  Reading/                        # listing and reading
  Search/                         # search and its snippet logic
  Creation/                       # create preview and execution
  Editing/                        # edit, rename target and partial-edit failure
  Moving/                         # move preview and execution
  Deletion/                       # delete and trash recovery eligibility
  Execution/                      # write access, snapshots, notices/messages
  Presentation/                   # pure saved-plan rendering/messages
  Contracts/NoteResourceReader.php
  Resources/NextcloudNoteResourceReader.php
```

All seven names, order, metadata, schema shapes, results, guide text and saved plans
match the pre-extraction fixtures. Four writes retain direct-call execution and
registry-owned general confirmation; shared acknowledgement remains operation-level.
Workflows retain root-read timing, ETag/ACL/lock checks, category `0`, no-op move,
collision handling, trash eligibility and content-before-rename partial failures.
Previews have no mutation side effects. The 1 MiB content and 2,000-note candidate
bounds are unchanged; the latter does not bound directory traversal.

Tool lookup stays filtered. The resource contract retains separate unfiltered
candidate lookup followed by visibility assertion before bytes are read. Resource
registry authorization remains shared. Application factories inject required guards;
metadata, saved rendering and construction do not touch storage or optional app
services. Interleaved users/locales and app-disabled rejection have explicit coverage.

The old `lib/Tools/Notes` tree and all five dedicated flat Notes test paths are gone.
No aliases, forwarding facades, duplicate registration or transition dispatch remain.
Other features consume only the Notes resource contract; Application is the
composition exception. Shared `Tools/Common`, registry/confirmation contracts and
shared file/lock test doubles remain their permanent owners.

## Compatibility evidence and test reconciliation

Fixtures in `tests/Support/Notes/Compatibility` were captured at `d51e4f4`, before
any extraction, and were not regenerated afterward. They cover all seven raw and
published definitions and guide output; seven plan scenarios in English and
Brazilian Portuguese (four writes plus missing category, shared edit and locked
edit); seven results, errors, partial edit and the resource envelope. Only generated
`modified` timestamps are normalized to `1700000000`; IDs and ETags are deterministic.
Existing Spanish, privacy, lock and refusal cases remain active.

All **86 original Notes test methods** remain in their final owners:

| Original `tests/Unit/Tools` file | Final `tests/Unit/Modules/Notes` file |
| --- | --- |
| `NotesModuleTest.php` | `NotesModuleTest.php` |
| `NotesPreviewTest.php` | `NotesPreviewTest.php` |
| `NotesLockTest.php` | `NotesLockTest.php` |
| `NotesTranslationTest.php` | `NotesTranslationTest.php` |
| `NotesPlanRendererTest.php` | `Presentation/NotesPlanRendererTest.php` |

Expanded discovery was compared by test identity, normalizing only those namespace
moves: all 5,175 baseline cases remain, with **24 additions** and zero removals.
The additions are six compatibility cases, six catalog cases, four write-capability
cases, three read/search cases, two storage/resource cases, two architecture cases
and one production-registration case. Existing detailed tests supply equivalent
coverage for planned illustrative names on collision, resource privacy and edit
precedence; they were retained instead of duplicated under new names.

## Production archive probe

The actual `build/mcp-1.0.8.tar.gz` was extracted under the ignored
`build/notes-production-probe` directory. A fresh PHP process loaded only that
archive's production autoloader, with minimal host App/IBootstrap declarations.
The probe loaded all 34 Notes types; compared their bytes plus Application and
ResourceRegistry with the worktree; compared seven raw/published definitions,
complete guide output and all seven saved English plan renders; and verified the
nine-module order with Notes second. Tool factories throw if resolved during
metadata/rendering checks. Package membership and classmaps contain no retired
Notes types, test support, PHPUnit or references outside the extracted package.
No test bootstrap or development vendor was loaded. Golden JSON comparison retained
empty object shapes and key order. This complements, rather than replaces, tests
that execute the real registration closures with stateful host doubles.

## Execution deviations and review

- Harness scratch and raw logs stayed outside the repository, as server guidance
  requires. This report preserves evidence and decisions; scratch is temporary.
- The phase 0 commit occurred while the full process was finishing because its
  shell command did not stop on an empty log parse. The completed green result was
  checked before any extraction began. Later mutating/gate/commit scripts use
  fail-fast execution. Phase ordering and baseline capture were preserved.
- Full phase 3 tests exposed two indirect reflective fixture builders in
  ToolsListContractTest and WriteGateContractTest. They were retargeted to final
  factories, then the full gate passed before advancement; no tests were removed.
- Interleaved-user/locale coverage was completed in phase 4 rather than phase 0;
  phase 0 frozen multilingual results and the original privacy suites remained
  active throughout. Additional catalog rejection checks also landed in phase 4.
- Phase 4 formatting used the existing development parser, with before/after AST
  comparison. Unused extraction imports and stale parameter documentation were
  removed without adding a formatter dependency or reformatting unrelated code.
- Final independent review covered `d51e4f4` through the complete final tree,
  including boundary checks and this report. It found no actionable critical,
  important or minor issues, and declined to judge no behavior. The reviewer
  independently reran Notes (99 / 665), compatibility/catalog/boundary/registration
  checks (15 / 1,597), the production archive probe and the diff whitespace check;
  all passed. The verdict accepts the local migration scope, with external
  integration gates still required before release.

Recorded execution decisions: external scratch follows server guidance (raw logs
are temporary; committed fixtures and this report retain evidence); the additional
isolation/catalog cases landed in phase 4 (weaker pre-extraction characterization
for those new cases, offset by retained baseline coverage and final review); branch
and worktree are preserved without merge/push (integration is deferred, with no
loss of work). There are no deferred minor review findings.

## Remaining work

The Notes structural migration is locally complete. PHP 8.2–8.5 CI and disposable
Nextcloud 32–35 hosts remain external release evidence: enabled/disabled Notes per
user, configured/shared/team/external folders, Text/files_lock and own manual locks,
concurrent ETags/renames, trash wrappers, Basic/Bearer calls and resource privacy.
Unit/API fixtures and packaging do not prove native hooks or actual trash recovery.
No destructive diagnostics were run against real accounts.

With Notes, **71 tools** across Files, Deck, Talk, Calendar, Contacts, Tasks and
Notes now use capabilities. The next recommended scope is analysis and a reviewed
People plan, followed by Logs. Neither is implemented here. Administration scripts,
remaining shared MCP policy/registry relocation, stable developer commands, static
analysis and README/docs translation/navigation work remain separate scoped work.
Existing README language variants remain the operational reference; documentation
continues to use English source and explicit translated paths only when they exist.
