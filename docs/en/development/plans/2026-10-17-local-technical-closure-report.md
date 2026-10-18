<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Consolidated local technical closure

This is the current closure ledger for the migration from upstream 1.0.8. Earlier
phase reports remain historical evidence; their recommendations for the next module
are not open migration tasks. Local implementation completion does not establish
release readiness, approval of translations or compatibility with upstream 1.1.0.

## Inputs and authorized scope

- Selected branch: `docs/talk-capability-plan`; starting revision:
  `f0c87d2` (`docs(i18n): add traceable operational translation drafts`).
- Original migration baseline and common ancestor with upstream:
  `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` (1.0.8).
- Authorized work: correct the Deck guide and architecture status, run available
  local checks, record results and remaining gates, and create a local commit.
- No merge, rebase, push, pull request, release, preview publication or deployment.
  The base checkout and original repository are not changed. A read-only upstream
  inspection fetched its current `main` into the local remote-tracking reference.

## Implementation reconciliation

| Scope | Local implementation status | Evidence |
| --- | --- | --- |
| Files, Deck, Talk, Calendar, Contacts, Tasks, Notes, People, Logs | Complete: 74 feature tools across nine capability modules | Per-module reports in this directory; architecture and contract suites |
| Shared MCP and DAV | Complete: explicit protocol, tool, access and platform owners | [Shared MCP report](2026-10-17-shared-mcp-infrastructure-migration-report.md), [DAV report](2026-10-16-calendar-dav-capability-migration-report.md) |
| Developer commands and analysis | Complete for the agreed scope: all production PHP at level 0, selected core at level 5 | [DevEx report](2026-10-17-developer-commands-static-analysis-report.md), [catalog/compatibility follow-up](2026-10-17-devbox-catalog-validation-report.md) |
| Administration JavaScript | Complete: authored responsibility modules and two generated entries | [JavaScript report](2026-10-17-admin-javascript-responsibilities-report.md) |
| README/docs structure, validation and packaging | Complete: canonical sources, manifest, package verification and no committed duplicate tree | [Documentation report](2026-10-17-documentation-i18n-report.md) |
| Spanish, Catalan, Brazilian and European Portuguese | 96 operational pages prepared and technically checked; human review pending | [Translation report](2026-10-17-operational-translations-report.md) |

No source or test transition tree is required by these completed scopes. Persistent
DB migrations, framework adapters, optional-app integration, native API compatibility
fixtures and current cohesive policy algorithms are intentional owners, not migration
scaffolding. Higher analysis levels, formatting automation and CSS/Settings/controller
redesign are separate possible improvements, not closure prerequisites.

## Corrections in this closure

`DeckGuide` incorrectly limited `deck_move_card` to reordering within one list. The
guide now describes both reordering and moving to another list, including another
board, identifies `stackId` as the destination and states the existing default for
omitted `order`. The handler, schema, grants, confirmation requirements and movement
implementation are unchanged. Existing operation tests cover those behaviors.

The public-contract expectations are deliberately updated by hand: one guide string
in `tests/fixtures/deck-definitions.json` and six identical strings in the shared
publication capture. No entire snapshot is regenerated, no comparison is weakened,
and no schema, protocol or result expectation changes. The capture README records
this authorized deviation from the original migration baseline.

The architecture specification now records implemented administration and docs/i18n,
the existing translation checks, five packaged languages and the distinction between
local completion and outstanding host/integration review. Historical reports are
preserved and this report supplies their consolidated current status.

## Original repository and integration boundary

On 2026-10-17, `git ls-remote --symref upstream HEAD` and
`git fetch --no-tags upstream main` identified the original repository as
[sintax-tech/mcp-for-nextcloud](https://github.com/sintax-tech/mcp-for-nextcloud),
with default branch `main` at `0911f9123a661968e707fc4c391adcf9d37c3e84` (1.1.0).
`origin` is the personal fork, `marcjoancr/mcp-for-nextcloud`; these are different
integration targets. At the starting local revision `f0c87d2`, the histories have
252 upstream-only and 99 local-only commits. Counts include merge commits and do
not measure effort. Upstream changes since the common ancestor affect 393 files
(56,250 insertions and 1,436 deletions).

The pinned [upstream changelog](https://github.com/sintax-tech/mcp-for-nextcloud/blob/0911f9123a661968e707fc4c391adcf9d37c3e84/CHANGELOG.md)
and source identify substantial changes to reconcile:

- Group grants, membership lifecycle, versioned policy, credential revocation and
  account cleanup: map to the new access/OAuth owners, preserving transaction and
  fail-closed behavior and the new upstream tests.
- Resource restrictions and excluded-file/reference filtering: map to access,
  Files, DAV, Tasks and Talk owners; do not retain old permissions by resolving
  namespace conflicts mechanically.
- Administrator-managed OAuth clients and redirect rules: reconcile the existing
  OAuth internals and new persistent client state with local composition.
- Redesigned administration, group editor, access inspector and resource drawers:
  port behaviors into the authored JavaScript modules and extend browser tests.
- Fixes for Nextcloud 32 DAV object reads, version restoration with files_lock and
  mount-boundary visibility: review individually before any release claim.
- German runtime localization, updated original attribution/credits and 1.1.0
  documentation: preserve upstream authorship and licenses, update English first,
  then reassess translation provenance and keep unreviewed translations pending.
  A German UI catalog is not automatically a reviewed German documentation tree.

The same incorrect Deck guide sentence is still present in the inspected upstream
`mcp/lib/Tools/Deck/DeckToolModule.php`. The local correction is a small independently
explainable upstream contribution, but no contribution has been submitted.

This branch still declares 1.0.8 and does not include the new upstream functionality
or certify its fixes. Existing English documentation and translation revisions
remain tied to this branch's behavior. Upstream's own integration evidence must be
reviewed for applicability; it does not automatically validate the relocated code.

## Validation results

All final local gates below exited 0. Checks apply to `f0c87d2` plus the bounded
changes described above; the local commit containing this report identifies the
resulting reviewed tree. Documentation-only edits to this report do not change the
production archive. Host tools: PHP 8.4.26, Composer 2.8.8, Node 24.21.0 and npm
11.19.0; the pinned Node directory was prepended to PATH for the catalog command.

| Check | Result |
| --- | --- |
| `devbox run check --cwd <selected-worktree> --json` | Exit 0; catalog delegates to `composer --working-dir=mcp check` |
| Composer manifest/lock and syntax | Valid; 904 PHP files parsed |
| PHPStan library and core scopes | Both pass, zero errors; unchanged level 0 / level 5 scopes |
| Complete PHPUnit suite on PHP 8.4 | 5,280 tests / 45,152 assertions, including architecture and native API contracts |
| Complete JavaScript suite | 165 passed (55 each in Chromium, Firefox and WebKit), 46.4 seconds |
| Documentation | 168 Markdown files and 110 package sources valid; 29 documentation tests pass |
| Translation provenance | 96 `review-required`, no missing operational pages; no review metadata advanced |
| Production package | Built and verified against the explicit documentation inventory and transformed source bytes; existing production-only checks pass |
| Offline PHP 8.2.34 cached image | Syntax: 904 files; Deck/shared MCP compatibility: 6 tests / 39 assertions, with deprecations, warnings and risky tests treated as failures |
| Offline `fsfe/reuse:5 lint` | 1,213 / 1,213 files have copyright and license metadata; no missing/bad/unused licenses or read errors |
| Independent full-history clone | Locked `npm ci` and Composer install pass; docs, translation states, 29 docs tests and actual package verification pass |
| Diff and capture review | Whitespace check passes; recursive JSON comparison confirms exactly seven guide-string changes and no changed keys, types, order, schemas or other values |

The initial red check deliberately changed expected guide output before production.
`DeckMigrationCompatibilityTest` failed (1 test / 1 assertion / 1 failure, exit 1)
on the old sentence. The initial combined red run was terminated with exit 143
after about 160 seconds while comparing the large shared-publication mismatch;
it is not counted as a successful check. After the guide correction, the two
existing compatibility classes pass together: 6 tests / 39 assertions, on both
PHP 8.4 and 8.2. No new permanent prose-matching test or suppression was added.

The independent clone used `git clone --no-local` from the selected branch, with
separate Git objects and full history, then `git apply --index` for the exact
reviewed patch. It lives under ignored `mcp/build/`; it starts without the working
tree's vendor, npm dependencies or generated bundles. This validates the staged
change rather than pretending it was already a separate committed revision.
Later report/provenance wording changes are checked again as documentation.

Composer installation emits 27 existing PSR-4 notices for explicitly loaded
foreign-namespace test doubles. They remain visible; no runtime autoload rule was
changed to hide them. `npm ci` reports zero vulnerabilities. The complete browser
run prints three expected esbuild errors for the deliberately missing-import
fixture, one per engine; those negative cases pass and the real build/package pass.
These fixture diagnostics are not unexplained failures in the final gate.

The available PHP 8.2 image lacks `intl`, `gd` and `zip`; no complete 8.2 suite is
claimed. Prepared PHP 8.3/8.5 runtimes are unavailable. Existing successful matrix
results in the compatibility report precede later changes and remain historical.

Local archive SHA-256:
`ccd021416422945e4e0799cd48fb849b2e0e1b14fbd604543c4ca2575be060d7`.
Independent-clone archive SHA-256:
`100d77f62b9bac9fe8c4f3e8d459a412e46aa75dc17d416780c1d4e2d2a91dd9`.
Both archives pass their source/inventory checks; archive-byte reproducibility is
not promised. These are local validation artifacts, not released 1.1.0 packages.

## Remaining gates and next action

| Gate | What is needed | Local closure implication |
| --- | --- | --- |
| Upstream 1.1.0 integration | A separately scoped plan mapping new behaviors, migrations and tests into the new owners; review licensing/credits, conflicts and documentation drift | Required before claiming this migration is ready against current upstream; no merge or conflict resolution was attempted here |
| Full PHP 8.2/8.3/8.5 matrix | Prepared isolated runtimes with all required extensions and locked dependencies; repeat on the integrated revision | Prior successful matrix runs are historical evidence, not a current full rerun |
| Human translations | Review all 96 pages separately by locale; record reviewer and exact English revision, including terminology and client UI labels | All remain `pending`; automated checks do not certify language quality |
| Real Nextcloud 32–35 | Authorized disposable hosts with synthetic accounts, files and logs; exercise native behavior and optional apps | Unit/API fixtures and browser harnesses cannot establish host integration |
| GitHub integration | Actions and actual GitHub rendering on the intended integrated revision, with full source history | No remote workflow result is claimed; no push authorized |

Real-host acceptance should cover Basic/Bearer identity, both MCP protocol eras,
eligibility/grant/connection revocation, optional-app enablement, hidden/shared
resources, locks, ETags, backups, partial batches, undo and trash recovery. DAV also
needs native scheduling, sync tokens, invitations, MOVE/transfer and selftest cleanup;
Talk needs actual attachment/reference publication and partial outcomes; Logs needs
roles, non-file backends, redaction and native audit delivery/failure. Administration
needs native assets/CSP/cache, request tokens, themes, narrow layouts, keyboard/focus,
no-JS readability and concurrent saves. Add the new upstream policy/resource/OAuth
cases when integration is planned. SMTP delivery is a separate explicit check.

Recommended continuation: first prepare a capability-by-capability integration plan
for the pinned upstream 1.1.0 revision, prioritizing security and compatibility fixes.
Keep the current migration history available and preserve source commits referenced
by translations. Only then implement the approved integration in bounded phases and
repeat the complete local/PHP matrix before real-host and remote integration gates.
