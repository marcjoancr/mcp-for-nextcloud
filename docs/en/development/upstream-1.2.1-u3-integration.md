<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U3: Tasks hierarchy

This record covers the [U3 plan](upstream-1.2.1-u3-plan.md) and the Tasks batch of
[the intake](upstream-1.2.1-intake.md). Starting fork revision:
`de60781641f9b344d7e7453dc40528a0948c3316`; upstream input:
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`. No full 1.2.1 or 2.0.0 release
qualification is implied.

## U2 prerequisite

The four workflows for the exact starting revision completed successfully:
[Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40513979098),
[Quality](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40513978984),
[Package](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40513979272),
[Native integration](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40513978961).
The unit matrix covered PHP 8.2/8.3/8.4/8.5 and the native workflow covered its
pinned Nextcloud/database/install-and-upgrade cells. These runs qualify U2, not U3.

## Implemented behavior

The existing seven Tasks tools now expose parent UIDs. Create/edit accept a UID,
null or omission; list can filter direct children before pagination. Native
RELATED-TO parent links coexist with preserved CHILD/SIBLING/custom relations.
Plans show escaped readable parent titles and UIDs. A private, confidential,
missing, trashed or cross-calendar parent is refused without revealing its title.

The hierarchy checks ancestry and moved descendants against 64 levels, before
writing and again at confirmation. Required server locking serializes MCP
parent-setting writes per calendar; contention or ineffective locking refuses
before DAV. Clearing a parent remains available without hierarchy locking.
Completion and deletion remain non-cascading. ETags, scoped collections, shared
approval, recurrence restrictions and Files-reference output filtering remain.

The final owners are `Content/TaskRelations`, `Reading/ParentTitles`,
`Hierarchy/{TaskHierarchy,TaskHierarchyLock,TaskParentStep,TaskHierarchyMessages}`
and `Presentation/ParentPlanText`. Creation and Editing compose them through
mandatory dependencies. `TaskWriter` returns the saved item; the capability
boundary decorates warnings and encodes the MCP response once. Completion uses
the same writer and preserves its envelope. No legacy Tasks handlers or
compatibility owner is introduced.

An external CalDAV writer does not acquire the MCP hierarchy lock. A detected
post-write loop through the saved task produces a successful-save warning with a
repair instruction. The bounded check is not a cross-client transaction or a
claim that every external race can be detected. Native interleaving tests run
both calls within one PHP process against the real locking provider; they are
not a two-worker HTTP stress test.

## Provenance and public contract

Adapted hierarchy/relations, parent presentation, unit and native cases from the
pinned release. Existing Sintax copyright/license headers and the attribution to
Marc-Olivier Turgeon-Ferland's subtask contribution remain. No new dependency.
The frozen DAV/MCP captures receive only explicit Tasks parent schemas, listing
description, guide notes, null parent output and empty parent-title maps for their
existing top-level cases. They were not regenerated to hide unrelated changes.

The [English Tasks reference](../reference/tools/tasks.md) and live guide describe
limits and repair. Eight new runtime messages are taken from pinned de/es/pt_BR
catalogs. Existing catalog entries remain unchanged. Documentation translation
provenance is retained; changed English pages and human language/accessibility
review remain outstanding. No Tasks-specific MCP prompt previously existed.

## Validation

Initial regression evidence: the 15 subtask cases failed on missing parent output,
ignored filtering and rejected parent-only edits. The first invocation stopped
at the required Node version check; rerunning used Node 24.21.0. Early adapter
errors (canonical collection/import and fixture constructor) were fixed before
the focused suite passed.

- New hierarchy/subtask/write/lock tests: **40 tests / 129 assertions**.
- Expanded Tasks/DAV/architecture tests: **237 / 2,582**.
- PHPStan production and core scopes: **pass**.
- First full PHP attempt: **8,560 tests / 72,981 assertions, four failures** in
  changed DAV definitions, DAV plans, shared publication and missing translations.
  Explicit additive patches resolved them; focused contracts/l10n pass **21 /
  3,002**. No suppressed errors or new baseline exclusions.
- `devbox run check --cwd <worktree> --json`: **exit 0 / ok true**. PHP **8,560 /
  73,039**; Chromium/Firefox/WebKit **717 passed**; both PHPStan scopes pass;
  syntax **1,328 PHP files**; docs tooling **29**, client-runner safety **10** and
  release tooling **11** tests pass; production package builds and verifies.
- `composer --working-dir=mcp test:integration -- 35 mariadb upgrade-local`:
  **exit 0**, **298 tests / 4,435 assertions / 9 skipped** on Nextcloud 35.0.1,
  PHP 8.5.11 and MariaDB 10.11.19. All five new native Tasks cases pass (**51
  assertions**, no skips), covering both endpoint credentials, persisted CalDAV
  links, ETags, protected shared parents, interleaved writes and disabled locking.
  Six pre-existing baseline snapshot/credential cases have no local-upgrade
  fixture; three statement-log cases require PostgreSQL. They were not disabled
  for U3. Coordinated recovery belongs to the separate NC35/SQLite/fresh cell;
  this local run does not claim it.
- Final `docs:check`: **164 Markdown files / 110 package sources**; translation
  report retains **96 review-required** entries with valid provenance.
- REUSE: **1,723 / 1,723** files attributed/licensed; changed/new source secret
  scan: **zero findings**. `git diff --check` passes.

The browser build tests deliberately exercise a missing import and log three
`Could not resolve "./missing.js"` diagnostics; all three assertions pass. Native
policy tests deliberately reproduce MariaDB deadlocks and assert safe handling.
These expected diagnostics are retained. The dirty development package reports
source offer unavailable, as designed; it is not a trusted release/source kit.

Local evidence lives under `mcp/build/upstream-1.2.1-u3/` and
`mcp/build/integration/mcp-it-20261027135538-3b9fc249cafb/mcp-it/artifacts/`.
The latter includes `junit.xml`, `result.json` (exitCode 0), runtime/image pins,
package/test hashes and redacted logs. Its source-commit field names the pre-commit
base `de607816`; the package/test hashes identify the tested uncommitted U3 delta.
The unchanged `upgrade-local` baseline is `14a76df26817fb8a4540eb9c9d1d0043e3e87506`. Raw credentials/logs are not committed;
share only redacted harness artifacts. Public CI for the resulting U3 commit is
separate from the U2 prerequisite above.

## Final technical review

One independent read-only whole-branch review covered tracked and new files and
found no critical or important issue. It identified a deferred presentation
minor: PHP converts numeric-string UID keys to integers, which `ParentPlanText`
currently ignores. A parent with UID `123` therefore appears as `"123"` without
its readable title. The relation, UID disambiguation and authorization remain
correct. This was corrected in [U4](upstream-1.2.1-u4-integration.md) by accepting/normalizing
integer keys, with a create/edit regression for UID `123` and `0`. No second review or cosmetic fix pass was performed.

Review boundaries: single-process native interleaving does not establish
multi-worker/cross-user contention behavior; exclusion across workers relies on
the native provider's shared storage. Human approval and U4/U5/release/client
qualification remain open. The reviewer did not certify pending full/native runs;
the final test results above were subsequently observed separately and passed.

Implementation decisions: use an external execution ledger because repository
harness metadata is forbidden; require locking dependencies rather than silently
substitute production defaults; encode saved items at the capability boundary
so post-save warnings do not require decoding/re-encoding. Missing a writer
caller could break an envelope, so the full contract/native suite checks that
boundary. Losing the temporary ledger would weaken the execution trace, not
change runtime behavior. A production dependency-resolution error fails closed.

## Remaining work

U4 Mail and U5 complete 1.2.1 parity/qualification remain open. LinuxServer L1-L5
and intended-server upgrade/recovery qualification, trusted source/artifact
preparation and distribution, and F3/F4/F5 approval remain open. The rehearsal
still targets the observed **1.2.0 → 2.0.0** installation baseline; no actual
1.2.1 upgrade is claimed here.

Public HTTPS; Inspector's prior nonzero **14/15** result and encrypted-store
issue; real ChatGPT consent/tools/revocation; and human security, operations,
recovery, licensing, accessibility and language review remain pending. PHP 8.2/8.3
unit coverage does not extend native support beyond the declared PHP 8.4 minimum.
No deployment, MinicoLab server modification or upstream PR #14 update occurred.

Publication advances only the already-authorized `fork/main`. U3 remote CI is
pending at this documentation closure and must be checked for the resulting
commit; U2 success is not substituted for it. Original `main` and upstream PR #14
remain intact.

U3 follow-up: all four workflows for `9b580041` passed; exact links and the
numeric-title correction are recorded in [U4](upstream-1.2.1-u4-integration.md).
