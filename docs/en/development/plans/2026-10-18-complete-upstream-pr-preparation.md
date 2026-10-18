<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Complete upstream PR branch preparation

Status: local branch preparation and validation; no push, PR opening, deployment or
human approval. The user selected one complete architecture/DevEx contribution with
all upstream 1.1.0 features. This executes C2 and the locally available C3 gates of
the [agreed scope](2026-10-18-upstream-pr-scope-proposal.md); the sections below name
what was run, carried forward and left pending.

## Inputs and resulting branch

- Live upstream `main`: `0911f9123a661968e707fc4c391adcf9d37c3e84`, confirmed by
  read-only HTTPS `git ls-remote --symref` on 2026-10-18 UTC. No new upstream delta
  from the previously integrated main target. The initial sandbox DNS lookup failed;
  the permitted network lookup succeeded. No remote ref was modified.
- Preserved source branch: `docs/talk-capability-plan` at
  `bda34406018d8fffd2b30c52628c61b4dd4ad9bf`, including the corrected manifest.
- Complete contribution branch: `refactor/complete-upstream-pr`, in the existing
  isolated worktree `/home/dev/worktrees/mcp-for-nextcloud/talk-capability-plan`.
  The base checkout and original branch remain unchanged. No branch was renamed.
- Frozen contribution/code candidate: `ae551a2ab4ea75cd45a3dbac3a93416aca38498d`.
- Upstream is an ancestor and the exact merge base. The three-dot contribution is
  now the same tree delta as the upstream-tip comparison; upstream's existing 1.1.0
  commits are not represented as newly authored fork work.

## Assembly and provenance decisions

Ruling: use a linear upstream-based branch with four small canonical-source
checkpoints, then the complete final tree. The four original source revisions used
by translations are outside upstream's ancestry. Merely copying the final files
would violate the existing provenance validator. Advancing every translation to
current English would also lose the fact that 60 translations reference older
English blobs. The checkpoints preserve the exact source path/blob pairs; they are
history for provenance, not separately releasable feature slices or transition code.

The [provenance map](../contracts/complete-pr-translation-provenance.json) records
all 96 original/replacement revision pairs, canonical paths and source blob IDs.
Only the `translation-source-revision` comments change in translated files; visible
text, pending notices and `translation-review: pending` remain byte-identical.
Every old source blob is available at its replacement ancestor. All 96 remain
`review-required`, including the same 60 source-changed cases. No validator was
weakened and no old runtime owner, compatibility alias or second policy authority
was restored.

Integration consequence: preserve these commits when merging the eventual PR.
A squash/rebase that replaces the source checkpoints requires another explicit
provenance remap with exact source-byte comparisons; do not merge with stale commit
references. This is an integration constraint, not authorization to publish.

The complete original tracked tree is retained. Relative to the preserved source
head, application/runtime/test/build files are identical; the only pre-report changes
are the 96 provenance comments. Development evidence added after the frozen candidate
is excluded from the production package. Existing historical plans remain clearly
historical; raw credentials, backups, test traces and devbox harness state are not
added to Git. Maintainers may still request curation of operational planning documents
before publication, without dropping feature code, tests or public documentation.

The frozen [outgoing inventory](../contracts/complete-pr-outgoing-inventory.json)
records **1,918 paths: 1,259 additions, 501 deletions and 158 modifications**, with
rename detection disabled and one category/blob/mode record per path. Direct and
three-dot sets are identical. All **1,532** tracked source files are preserved;
none was omitted. The subsequent evidence-only commit adds this report, draft,
three JSON artifacts and their license sidecars, and updates the plan/index/checklist.
It is outside the frozen candidate inventory; regenerate against the actual final
head before any eventual publication. The original V0 inventory stays frozen.

## Scope and 1.1.0 continuity

All five agreed review blocks are included together: nine capability modules and
shared infrastructure; policy/OAuth correctness; modular admin/personal frontend;
unified DevEx/tests/packaging; English documentation and es/ca/pt-BR/pt-PT drafts.
The [feature matrix](2026-10-18-upstream-1.1.0-feature-parity-review.md) retains all
32 upstream release items and their limitations. The manifest is byte-identical to
upstream. All 11 migration/seed files, the 48 route records and production Composer
dependency records retain their verified identities. Full local source equivalence
provides continuity with the reviewed feature implementation; it is not human or
live-host acceptance.

App/protocol version remains 1.1.0 for this unreleased qualification candidate. The
future major/minor decision remains open: substantial architectural change does not
by itself establish a breaking public API. No release tag or store publication is
prepared here. Any chosen version must be applied consistently and requalified.

## Validation and artifact evidence

Commands ran on 2026-10-18 UTC, in the selected worktree, using PHP 8.4.26,
Composer 2.8.8 and Node 24.21.0. The actor is the coding agent; no human verdict is
implied. The registered gate ran against the exact final source tree immediately
before its commit; post-commit tree equivalence was verified. A separate package
build then ran at the committed candidate so its Composer root identity is correct.

| Evidence | Command/check | Result and limits |
| --- | --- | --- |
| E-SOURCE | Live upstream lookup, Git tree/blob/ancestor checks | PASS; exact upstream base, all source paths preserved, 96 metadata-only translation changes, no legacy runtime trees. |
| E-LOCAL | `devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/talk-capability-plan --json` | PASS, exit 0 and `ok: true`: Composer validation; 185 Markdown files / 110 package sources; 29 documentation tests; 1,144 PHP syntax checks; both PHPStan scopes; **7,660 PHP tests / 65,227 assertions**; **609 browser cases**, no retries/skips; package inspection. |
| E-LOCAL | REUSE 5.1.1 via `uv tool run --from reuse==5.1.1 reuse lint` | PASS, exit 0; 1,515/1,515 checked files before the evidence-only additions; no copyright/license/read errors. |
| E-PACKAGE | `composer --working-dir=mcp package`, after candidate commit | PASS, exit 0; 110 documentation sources staged and verified. New artifact identity recorded below. |
| E-PACKAGE | Exact member comparison with preserved S0 archive | PASS; 863 regular files; member names, types, modes and link targets identical; 90 precisely explained byte changes. |
| E-LOCAL | `git diff --check 0911f9123a661968e707fc4c391adcf9d37c3e84 ae551a2ab4ea75cd45a3dbac3a93416aca38498d` | **FAIL, exit 2**: inherited extra blank EOF lines in `mcp/lib/Modules/Files/Reorganization/MovePlan.php:29` and `mcp/src/admin/clients/oauth-changes.js:38`. Minor formatting findings deferred to preserve exact runtime source; the PR is not whitespace-clean. |
| E-HUMAN / V2 | Human and real staging acceptance | NOT RUN / BLOCKED by outstanding prerequisites and assignments; no reviewer contacted. |

Raw local logs remain under ignored `mcp/build/verification/complete-pr/`:
`check.log`, `package-committed.log` and `reuse-corrected.log`. The full-gate stderr
includes asserted missing-module diagnostics from negative browser build fixtures;
they are not ignored test failures. The first REUSE invocation used a nonexistent
`bin/uv` path and exited 127 (`reuse.log`); rerunning with the installed mise uv binary
succeeded. Neither that command error nor the whitespace failure is hidden.
The staging wrapper detected the whitespace failure but did not stop the subsequent
complete-tree commit; an independent post-commit source comparison confirmed exactly
the intended 96 provenance changes. The full functional gate had already passed.

The [package comparison](../contracts/complete-pr-package-comparison.json) records
every changed member with old/new SHA-256. The candidate archive was copied to
ignored `mcp/build/verification/complete-pr/mcp-1.1.0.tar.gz` for preservation.

| Artifact | SHA-256 |
| --- | --- |
| Preserved S0 archive (unchanged) | `5eaf563d5d26f52d60f1bc81fb6a220043811838ab36d98403d02e05613fa01e` |
| Complete PR candidate archive | `c81cb09eb6d0b3a95d5625ca9861c98c472957f3db60d3976533d9227d7a3a7e` |
| Corrected `appinfo/info.xml` | `213213bbf282532f90fc8a1e08583c0286aa564d4b691c4d8b0883969344d639` |
| Unchanged `js/admin.js` | `0ad2a4bf78896b3d3d7da5affdc871c03f5219dcecdb8b58277b6c997614a4eb` |
| Unchanged `js/personal.js` | `e20bd1aae36265abe96e6ccb4a5079532ecd9e8c1c53c4168d81bb29076eb5ec` |

Of the 90 changed members, **88** contain only the mapped translation revision
comments, **one** is the manifest restored exactly to upstream, and **one** is
`vendor/composer/installed.php` with exactly two root-reference substitutions from
`7d8873c` to this candidate. All other regular files are byte-identical, including
runtime classes, migration/seed files, route declarations, production dependencies
and both bundles. No development evidence or transitional runtime tree is packaged.

Historical evidence carried forward under the
[candidate-change rules](2026-10-18-upstream-1.1.0-staging-acceptance.md), with
byte-equivalent runtime/assets rather than a fresh execution claim:

- The [integration report](2026-10-17-upstream-1.1.0-integration-report.md#strict-php-runtime-checks)
  records strict PHP 8.2.34, 8.3.35, 8.4.26 and 8.5.11 qualification of `7d8873c`:
  7,657 tests / 65,175 assertions on each. The three later manifest-description
  regression cases are included in the fresh host gate; they were not rerun on the
  three other PHP versions during this assembly.
- The [final native matrix](2026-10-17-upstream-1.1.0-integration-report.md#final-native-artifact-matrix)
  records all 14 cells, 2,800 tests / 34,686 assertions, with 84 explicitly
  inapplicable skips and no missing required applications/tests skipped. Its pinned
  artifacts and upgrade/replacement/recovery results remain historical. No container,
  installed-byte, real-client, rendered-language or new recovery run was performed here.
- The independent full-history clean-clone gate belongs to `7d8873c`, not this branch.
  New source-revision ancestry/blob availability was verified locally; no fresh
  clean-clone installation is claimed. GitHub CI has not run for this branch.

A fresh automated read-only assembly review found no Critical or Important findings.
It independently checked source preservation, upstream ancestry, provenance,
manifest identity and the two Minor whitespace findings. A follow-up independently
recomputed all 1,918 inventory rows, both archive hashes, all 863 regular-member
comparisons and the 90 changed-member records; it confirmed the saved gate output
and checklist scope. It did not rerun the suites and is not a human or release approval.

After adding the evidence-only files, `composer --working-dir=mcp docs:check`,
`docs:translations` and `test:docs` passed again (exit 0): **187 Markdown files /
110 package sources**, **96 review-required** translations and **29/29** tooling
tests, no failures/skips. REUSE 5.1.1 passed again for **1,520/1,520** checked files.
`git diff --cached --check` passed for this documentation commit; it does not erase
the two failures in the complete upstream-to-candidate diff. Final logs are
`docs-final.log` and `reuse-final.log` in the same ignored evidence directory.
These development-only changes do not alter the frozen candidate package; no
unnecessary runtime/container rerun or new artifact identity is claimed.

Local handoff: **READY FOR USER REVIEW OF PR DRAFT**. The
[English PR description](2026-10-18-complete-upstream-pr-description.md) is a local
artifact only. This verdict does not mean ready to merge, release or close S7.

## Remaining gates and next step

- Human security/technical, language, accessibility and operational verdicts remain
  pending. All 96 documentation reviews and German/rendered interface review remain
  pending. Local automated review is not a human sign-off.
- Real Nextcloud theme/CSP, actual OAuth/Basic clients, S2 installed-byte verification
  and remaining S0–S7/recovery decisions are not executed or closed here.
- The preserved S0 archive/recipe is unchanged. The new contribution artifact has its
  own identity; selecting it for staging requires the existing candidate-change gates.
- GitHub CI has not run on this unpublished branch. No push, PR opening or deployment
  is authorized by local preparation. The PR is one complete contribution.
- Choose the eventual release version and review the integration/provenance strategy
  before publication. Preserve attribution and the source-history checkpoints.

Next: review the complete local diff, evidence and PR description; resolve human and
runtime gates for the acceptance level being claimed. A local PR draft may state
these limits honestly, but must not label the release or S7 accepted.
