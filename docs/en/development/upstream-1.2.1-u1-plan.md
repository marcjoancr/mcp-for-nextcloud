<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U1 implementation plan

Use the executing-plans workflow for this batch and validate each gate before
advancing. This plan specializes the [approved intake](upstream-1.2.1-intake.md);
it does not authorize production deployment or release publication.

**Goal:** make backup visibility depend on the verified personal root, preserving
fail-closed privacy through shared and external mounts.

**Architecture:** `Access/Visibility` owns backup-origin classification and mirror
visibility. `Platform/FileAccess` retains the reserved backup name. Existing
`VisibilityGuard` delegates the mirror decision and invalidates its metadata.
No legacy `Service`/`Tools` tree, compatibility wrapper or second policy layer.

**Stack:** PHP 8.2–8.5, Nextcloud 33–35, existing PHPUnit/native harness.
**Starting revision:** `0ed330398386b484fccdefc0f153b924843fc24f`.
**Upstream input:** `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`.

## Decisions and boundaries

Adapt capability behavior rather than merge the upstream tree. Importing its
legacy owners would undo the fork architecture; a full-tree merge also bundles
unqualified Mail, hierarchy and configuration changes into a privacy fix.

U1 recognizes the existing reserved name only. U2 will add the complete settings
contract and its write sinks together. U1 does not introduce a settings reader,
unused constructor option or dormant switch in anticipation of U2. Mandatory
Files and Contacts copies and current write restrictions remain in force.
The spelling `MCP backups` is ASCII, so configurable Unicode-name handling belongs
to U2; do not add an otherwise unused normalization owner to this batch.

## Review focus

- A same-named nested folder is ordinary even if its sibling original is hidden.
- A real backup exposed through a local share keeps the owner's classification;
  unknown owner, cyclic ancestry and unverifiable roots refuse access.
- External/federated storage owner metadata is not proof of a local user/share.
- A nested namesake inside an actual backup does not truncate the mirrored path.
- Cached origins are invalidated after mutations/configuration changes, and an
  unknown original keeps a generic inspector reason without duplicate logging.

## Gate C0: reproducible public CI baseline

- [x] Diagnose the failed public cell, without skipping it or weakening checks.
- [x] Add failing regression tests for fork baseline ancestry and fixture selection.
- [x] Pin `upgrade-local` to the public 1.1.2 fork candidate and seed/verify it with
  `ci/replacement.php` through its exact capability owners. Keep 1.0.8 migration
  probes exclusive to `upgrade`; remove the retired local-layout branch.
- [x] Pass the focused harness suite and integration syntax checks.
- [x] Run the complete NC35/MariaDB/upgrade-local cell and record actual results.
- [x] Commit the correction separately before implementing U1.

## Gate U1: backup visibility

Files:
- Add `mcp/lib/Access/Visibility/BackupFolderMatcher.php`: resolve a candidate's
  personal root and local-share origin; cache only verified candidate metadata.
- Add `mcp/lib/Access/Visibility/BackupMirrorVisibility.php`: evaluate the existing
  original's visibility and preserve the inspector's outer explanation.
- Modify `mcp/lib/Access/Visibility/VisibilityGuard.php`: delegate and invalidate.
- Add `mcp/tests/Unit/Access/Visibility/BackupFolderMatcherTest.php` and native
  `mcp/tests/Integration/Exclusion/BackupFolderTest.php`, adapted from upstream.

Interfaces:
- `BackupFolderMatcher(?IRootFolder $rootFolder = null)` uses the existing
  `BackupLocation::FOLDER`; `matches(Node $node, callable $parentOf): array` returns
  verified `[Folder, list<string>]` pairs; `clearCache(): void` invalidates them.
- `BackupMirrorVisibility(VisibilityGuard $guard, BackupFolderMatcher $matcher,
  Closure $unavailable)` exposes `isHidden(Node $node, ?array &$explanation): bool`.
  The unavailable callback logs no paths, names or storage details.

Steps:
- [ ] Adapt the upstream regression suite to fork test doubles/owners; run it
  first and observe ordinary nested-folder failures before changing production.
- [ ] Implement the two focused owners and guard delegation; preserve original
  attribution. Run the new suite plus existing visibility, Files/Contacts privacy
  and architecture contracts; resolve discrepancies against real core behavior.
- [ ] Add explicit cycle/failing-origin and invalidation tests where not already
  covered. Do not retain tests that merely count helper calls without protecting
  origin freshness or a privacy boundary.
- [ ] Run `composer --working-dir=mcp check`, REUSE and the full native
  NC35/MariaDB/upgrade-local cell including new exclusion regressions.
- [ ] Obtain a separate code review, fix material findings, record evidence and
  pending phases, then commit U1. No complete 1.2.1 parity claim.

## Acceptance and next batches

Every ordinary namesake regression must pass without releasing a real hidden
mirror. All prior privacy contracts must remain green; unavailable ancestry and
original metadata refuse access. A native suite must prove personal, external,
groupfolder and received-share behavior, not only mocked node paths.

U2 backup configuration/copy policy, U3 subtasks, U4 optional Mail and U5 final
manifests/docs/qualification remain separate work under the intake. The full path
ledger records scope and ownership, not completion of these later behaviors.
LinuxServer L1-L5, intended-server qualification, HTTPS, Inspector, ChatGPT and
human review remain pending throughout this batch.
