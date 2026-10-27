<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U1 integration record

This batch adapts backup-location visibility from upstream
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`, following the
[U1 plan](upstream-1.2.1-u1-plan.md). The [path ledger](upstream-1.2.1-source-ledger.md)
accounts for the release's 165 changed paths; it is not a completion claim for
U2-U5 or full 1.2.1 parity.

## Result and ownership

Ordinary nested directories named `MCP backups` no longer inherit an unrelated
sibling original's exclusion. The reserved location is the verified personal-root
folder. A received local share resolves the owner's original location before
classification; missing origins, cycles and unverifiable roots refuse access.
External/federated storage owner metadata is not used as a local-share identity.
A nested namesake inside a real backup retains the entire mirrored relative path.

`Access/Visibility/BackupFolderMatcher` owns root/origin recognition and cached
metadata. `BackupMirrorVisibility` evaluates the original's visibility, retaining
generic failure explanations. `VisibilityGuard` delegates and invalidates metadata
when its existing mutation/configuration hooks clear caches. No compatibility
wrappers or legacy ownership directories were introduced. Original upstream
attribution is retained, with fork attribution on adaptations.

U1 intentionally uses the existing ASCII `BackupLocation::FOLDER`; configurable
names and normalization remain part of U2's full settings/write-policy contract.
No unused settings dependency, dormant option or speculative normalizer was added.
Files pre-write copies and verified Contacts backups remain mandatory.

## Prerequisite CI repair

The published baseline's Package, Quality and Tests workflows passed, but one of
14 native cells depended on an unpublished local-only commit. Commit `1e9fdd51`
replaces that baseline with public fork candidate `14a76df` (1.1.2) and uses the
correct generation-qualified state-preservation probe. Released 1.0.8 schema and
OAuth migration coverage remains in the separate `upgrade` cells. The historical
local-only 1.0.8 preparation is no longer a current input; no old private history
was published and no failing cell was dropped.

The repaired cell passed locally before U1: **276 tests / 4,237 assertions / 9
inapplicable skips**. The [baseline record](fork-development-baseline.md#native-ci-baseline-correction-2026-10-27)
contains the original CI failure, archive identities and precise scope.

## Verification

The meaningful U1 regression first ran against the old guard: three ordinary
namesake cases failed and the real-root backup case passed (4 tests / 5 assertions).
After the change, the focused visibility, Files/Contacts privacy and architecture
suite passed **167 tests / 17,093 assertions**. Coverage includes local-share and
external origin handling, missing roots, cyclic ancestry, nested mirror paths,
cache invalidation and privacy-safe inspector explanations. Native regressions
exercise personal, external, groupfolder and received-share locations.

A fresh independent agent reviewer examined the initial C0/U1 diff and plan,
finding no actionable issue. During final inspection, three additional tests then
exposed a privacy gap in the initial adaptation: a renamed backup mount, shared
backup subtree or individually shared backup could bypass the original's exclusion.
All three failed before correction (3 tests / 6 assertions). The guard now passes
its already-verified canonical share origins to the matcher, which preserves the
owner's mirror path regardless of the mounted name. It also retains mounted-path
checks; neither alias may weaken a restriction. The focused suite now passes
**170 tests / 17,102 assertions**, including unchanged owner-lookup cost assertions.
The cost fixture gained realistic paths; its bounds were not relaxed. Two native
cases were added for actual folder/file shares, tagging the original only after
copying/sharing so copied tags cannot make the proof pass accidentally.
The agent review preceded this follow-up; its verification uses these regressions,
not a claimed independent re-review or human approval.

The final full local catalog check passed with exit 0 after the alias correction:
**8,477 PHP tests / 71,953 assertions**, **690 browser tests**, both static-analysis
scopes, syntax, docs and client/release unit suites, and verified packaging.
Documentation covered **160 Markdown files / 110 package sources**. All 96
translation drafts remain review-required. The three `missing.js` diagnostics
are deliberate browser build-failure tests; their failures were retained and the
suite passed. Evidence is local at
`mcp/build/upstream-1.2.1/check-u1-final.json`; this ignored path is not a public
artifact.

The first U1 native cell passed **281 tests / 4,253 assertions / 9 skips** but did
not cover the later alias regression. The follow-up run
`mcp-it-20261027103940-9af5f6f2b6bd` completed with **283 tests / 4,255 assertions /
2 errors / 9 skips**: its two new cases attempted to resolve a newly shared node
through a long-lived CLI recipient mount cache, which raised `NotFoundException`
before the visibility assertions. The tests now use fresh authenticated MCP HTTP
requests, first proving the share is listed, then excluding the original and
checking both listing and generic read refusal. The failed run remains recorded
in `mcp/build/upstream-1.2.1/u1-alias-native.log`; it is not a passing acceptance.
The corrected full native cell passed with exit 0: **283 tests / 4,270 assertions /
9 skips**, run `mcp-it-20261027105037-545748406419`, on NC35.0.1 / PHP8.5.11 /
MariaDB10.11.19. Six skips are pre-1.1 migration cases (covered by the separate
`upgrade` cells); three are PostgreSQL-specific probes. The generation-qualified
baseline transition passed, including unchanged policy, clients, generations,
scopes and HTTP bearer access. The two HTTP regressions passed. The runner removed
its owned containers/network and published no ports. Coordinated recovery remains
covered by its separate NC35/SQLite/fresh cell; this run does not certify it.

Local sanitized artifacts:
`mcp/build/integration/mcp-it-20261027105037-545748406419/mcp-it/artifacts/`.
The run packages are identified by SHA-256:

| Input | SHA-256 |
| --- | --- |
| Public 1.1.2 fork baseline archive | `a243104c16b3efaa0e0457a0802eeb2aba402501b774844aba33b4dd8ba0b3a3` |
| U1 candidate archive | `2c1bd725b6190e92de9dc781cee08a594546ddcf703395c24ebb5e92a5983bed` |
| Native test source archive | `a5f6fcc4d4e204f087e90a581b2accc7a0ad386ce1a074a4446a8c2a1f044f76` |

The candidate was packaged from the working tree before the evidence-only closure;
it is not a signed release artifact. The final native fixture change affected tests
only; syntax checks passed for **1,295 PHP files**. REUSE 6.2.0 passed **1,682 /
1,682** files, and the committed C0/ledger delta and staged U1 delta each passed
Gitleaks 8.30.1 with exit 0 and no findings using the pinned default rules.

Publication advances only `fork/main`. Its public workflows must be evaluated
against the resulting commit; earlier green runs are not proof of this revision.
See [fork Actions](https://github.com/marcjoancr/mcp-for-nextcloud/actions) for the
exact head and sanitized native artifacts. The local result above qualifies one
native cell, not the entire 14-cell CI matrix.

## Remaining work

- U2: backup configuration, preserved reserved names, copy preference and all
  mutation/confirmation boundaries, with admin UI/API and compatibility evidence.
- U3: Tasks hierarchy, filtering, serialization and confirmation-time checks.
- U4: optional Mail account/mailbox reads, explicit grants, host/API fixtures.
- U5: final tool/manifest parity, canonical guides, localization and full native
  qualification. Retain fork identity/version and translation provenance.
- LinuxServer L1-L5, actual-server profile and recovery qualification, trusted
  release source/artifact distribution and F3/F4/F5 approval.
- Public HTTPS; Inspector's prior nonzero 14/15 result and encrypted-store issue;
  real ChatGPT consent/tools/revocation; human security, operations/recovery,
  licensing, accessibility and language reviewers.

This work makes no production deployment or release-readiness claim and does not
modify the intended server or upstream PR #14.

## Published CI follow-up (2026-10-27)

At `19aff24650742aa03baf3ba8e81884ea0a19c37f`,
[Package](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40492702540),
[Quality](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40492702290)
and [Native integration](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40492702283)
passed. [Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40492702382)
failed in PHP 8.2, 8.3, 8.4 and 8.5 at the same ancestry contract; its browser job
passed. PHPUnit's checkout retained only one commit, so the public baseline was
unavailable even though it is an ancestor. The native workflow already fetched
full history and passed all 14 cells.

A local depth-one clone reproduced exit 128 for the exact baseline ancestry check;
fetching its full history changed the same command to exit 0. The PHPUnit checkout
now uses `fetch-depth: 0` and disables credential persistence. No test was skipped
or weakened. The full local PHPUnit suite then passed **8,477 tests / 71,953
assertions**, and actionlint passed. These local results do not retroactively make
the failed remote run green; the corrected revision needs its own CI run.
