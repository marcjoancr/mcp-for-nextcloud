<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U3 implementation plan

Execute the authorized Tasks batch from the [intake](upstream-1.2.1-intake.md).
Baseline: `de60781641f9b344d7e7453dc40528a0948c3316` (U2).
Pinned upstream: `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`.

## Scope and ownership

Preserve the seven Tasks tools and their capability owners. `Content/TaskRelations`
reads and changes RELATED-TO parent links; `Reading/ParentTitles` resolves only
readable parent titles. `Hierarchy/TaskHierarchy` checks same-calendar ancestry and
subtree depth, `TaskHierarchyLock` serializes MCP parent-setting writes, and
`TaskParentStep` composes these checks for Creation and Editing. Presentation owns
escaped title/UID rendering. Shared DAV boundaries and the native writer remain
canonical; no legacy handler tree, second Tasks module or compatibility aliases.
All production collaborators are mandatory dependencies, including the locking
provider. Disabled locking must fail closed for parent-setting writes.

A parent UID sets the link, null clears parent links, omission preserves them.
Other relation types and unknown properties survive. Listing by parent selects
direct children before pagination; it does not traverse descendants. Readable
recurring tasks can be parents, but editing a recurring task remains refused.
Private/confidential shared tasks and their titles remain hidden. Parent changes
are checked in preview and again under the calendar lock at confirmation, counting
the moved subtree toward the 64-level limit. Parent removal remains available to
repair existing cycles. Completion/deletion never cascade.

Locks serialize MCP parent-setting operations only. Other CalDAV writers can
change the hierarchy concurrently. Detect a loop through the written task on
read-back and report that the save succeeded with a repair warning; do not claim
cross-client atomicity or roll back unrelated writes.

## Ordered phases and acceptance

1. **Prerequisite and regressions.** Observe all four U2 workflows for the exact
   baseline. Adapt pinned unit cases to canonical dependencies and prove missing
   parent output/filtering/writes with failing tests. Preserve fixture attribution.
2. **Content, reading and safe writes.** Implement relations, parent filtering,
   same-calendar readable parent checks, depth/cycle limits, effective locking,
   confirmation recheck and post-write warning. Pass focused Tasks, DAV and
   architecture tests before broadening validation. Keep ETag, shared approval,
   recurrence, Files-reference filtering and native trash behavior unchanged.
3. **Public contract and guidance.** Add nullable parent to create/edit and a UID
   filter to listing, truthful guide text (there is no Tasks prompt), title plus UID in plans and
   required runtime translations. Update only intentional frozen contract fields.
   Keep human translation/accessibility review pending.
4. **Native and closure.** Exercise persisted RELATED-TO through real CalDAV,
   visibility, direct-child pagination, concurrent interleaving and disabled locks.
   Run the full catalog check, native NC35/MariaDB/upgrade-local, REUSE and secret
   scan. Obtain one final whole-branch review; fix material findings with regression
   tests. Record exact successes, failed attempts and limitations, commit and
   publish the authorized fork branch without changing upstream PR #14.

## Review focus

- No hidden parent title leaks through plans or read/list responses.
- Cycles and subtree depth are checked before DAV, including at confirmation.
- Disabled/contended locks fail before mutation and are released on exceptions.
- External writes receive an honest saved-with-warning result when a loop is seen.
- Optional parent fields preserve existing unrelated Task behavior and contracts.

## Execution status

Phases 1-4 completed with local validation; see the [results and remaining
presentation minor](upstream-1.2.1-u3-integration.md). All four U2 workflows passed.
Remote U3 CI must be observed after publication for its own commit. Human review
and whole-release qualification are not completed by this plan.

## Pending beyond U3

U4 Mail and U5 complete 1.2.1 qualification; LinuxServer/Nginx L1-L5 and intended
server upgrade/recovery; trusted source/release distribution; public HTTPS;
Inspector's prior nonzero/encrypted-store issue; ChatGPT; human security, language
and accessibility reviews. Unit PHP 8.2/8.3 coverage does not expand native support
beyond Nextcloud 33-35 with PHP 8.4 minimum. No deployment or release is authorized.
