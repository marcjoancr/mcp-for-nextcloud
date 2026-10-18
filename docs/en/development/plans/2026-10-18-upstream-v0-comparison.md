<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# V0: current upstream comparison and outgoing inventory

**Status: V0 reviewed on 2026-10-18; one complete PR selected by the user, no implementation.**
This executes V0 of the [PR checklist](2026-10-18-upstream-pr-validation-checklist.md).
The selected registered worktree was clean at start. No merge, rebase, cherry-pick,
fetch, branch/ref update, application edit, container operation, push or PR opening
was performed. Remote queries were read-only; all necessary Git objects already
existed locally. Human reviewers and every human verdict remain pending.

## 1. Exact references and freshness

| Reference | Observed identity |
| --- | --- |
| Upstream default branch / HEAD | `sintax-tech/mcp-for-nextcloud`, `main`, `0911f9123a661968e707fc4c391adcf9d37c3e84` |
| Previously integrated upstream target | Same `0911f9123a661968e707fc4c391adcf9d37c3e84`: **zero new commits on main since that target** |
| Release `v1.1.0` annotated tag | Tag object `d9e083a28cc5abe0be8a6a36355828532ee6f47a`; peeled commit `c36414a8091b308da2fa19b140dae608979c2b5c` |
| Published fork default branch / HEAD | `marcjoancr/mcp-for-nextcloud`, `main`, `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` |
| Local analysis head / proposed full-fork comparison head | `docs/talk-capability-plan`, `94431372f4030cfe2b345ad9c294ef9d29f9ae69` |
| Qualified local application code | `7d8873cafaaadedc479c2204c9ef75bb76062dcf` |
| Merge base: upstream main versus local analysis head | `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` (original 1.0.8) |

Git smart-HTTP `ls-remote --symref` and GitHub public repository metadata agree on
`main` for both repositories. Public `--heads` for the fork returned only `main`;
the local migration branch is not a published PR head. The upstream tag is **not**
the same commit as current main: main adds one `REUSE.toml` attribution commit after
the release commit. That commit was already the integration target. Do not describe
the tag-object hash as the released code commit or omit the post-tag attribution.

Sources: [upstream at the observed commit](https://github.com/sintax-tech/mcp-for-nextcloud/tree/0911f9123a661968e707fc4c391adcf9d37c3e84),
[release commit](https://github.com/sintax-tech/mcp-for-nextcloud/commit/c36414a8091b308da2fa19b140dae608979c2b5c),
[published fork](https://github.com/marcjoancr/mcp-for-nextcloud/tree/a1a7911cc72dcf2487d17e06e23edfa89b0ecf92).
These observations do not predict future upstream changes. Refresh references
before preparing any new code candidate or publishing.

## 2. History is different from content integration

`git rev-list --left-right --count UPSTREAM...HEAD` reports **252 upstream-only and
124 local-only commits**. Upstream is not an ancestor of the migration branch;
porting its behavior did not merge its history. There is no new upstream work to
integrate since the pinned main target, but the local branch is not Git-history
synchronized with main and the published fork still points at 1.0.8.

| Comparison | Exact changed paths, renames disabled | Meaning |
| --- | --- | --- |
| Upstream tip → local head | **1,911**: 1,251 added, 159 modified, 501 deleted | Actual tree difference from the current upstream implementation. |
| Merge base → local head (three-dot contribution) | **1,851**: 1,370 added, 95 modified, 386 deleted | What the current ancestry offers as the contribution; includes ported upstream work. |
| Merge base → upstream tip | **393**: 234 added, 159 modified | Incoming 1.1.0 inventory, already reconciled separately; not the outgoing PR inventory. |

With explicit `--find-renames=50%`, the direct comparison has 1,539 entries
(879 additions, 159 modifications, 129 deletions, 372 detected renames); the
three-dot comparison has 1,577 entries. Rename pairing is a Git heuristic, not proof
of behavior equivalence. Without rename pairing the direct diff has 289,562 inserted
and 115,930 deleted text lines; binary assets have no line count. Large counts partly
reflect decomposition, tests and documentation, not thousands of new product features.

A PR from the current branch would therefore contain imported upstream features
and an unnecessarily broad history. Recommend a new contribution based on the
observed upstream main, with the complete agreed architectural/DevEx change reapplied and tested in one PR.
The user selected the full scope; the earlier small-PR recommendation is superseded.
That is a future implementation action, not something done here. Do not force-push,
rewrite the working branch or use an `ours` merge to manufacture compatibility.

## 3. Complete path inventory

The [machine-readable inventory](../contracts/upstream-pr-v0-inventory.json) contains
**1,966 distinct path rows**, the union of both outgoing comparisons. Each row has
its structural category, status in each comparison, and exact mode/blob identity
(or absence) in merge base, upstream and fork. `null` comparison status means the
entries are identical, not that the path was overlooked. Deleted legacy paths and
new capability paths remain separate; no guessed rename hides a removal.

Inventory SHA-256: `2d4babf94165aff89931e4dbbfce2f8bad757aaee92919dc150f99c3bfeda133`.
The inventory freezes the analysis head above and deliberately excludes these new
V0 documents. Categories are inferred from paths; they assign review ownership,
not a verdict that every file was semantically audited. Broad category totals for
the direct comparison are:

| Structural category | Changed paths |
| --- | ---: |
| access-policy-scope | 101 |
| browser-ui-assets | 59 |
| capability-calendar | 58 |
| capability-contacts | 29 |
| capability-deck | 83 |
| capability-files | 114 |
| capability-logs | 17 |
| capability-notes | 34 |
| capability-people | 4 |
| capability-talk | 67 |
| capability-tasks | 31 |
| composition-http-settings | 21 |
| contributor-guides | 9 |
| devex-build-ci | 64 |
| legacy-shared-services | 20 |
| legacy-tool-calendar | 47 |
| legacy-tool-common | 10 |
| legacy-tool-contacts | 11 |
| legacy-tool-dav | 1 |
| legacy-tool-deck | 30 |
| legacy-tool-files | 36 |
| legacy-tool-logs | 9 |
| legacy-tool-notes | 4 |
| legacy-tool-people | 1 |
| legacy-tool-talk | 22 |
| legacy-tool-tasks | 5 |
| metadata-legal-root | 9 |
| native-integration | 46 |
| oauth-lifecycle | 21 |
| operational-docs-i18n | 124 |
| persistence-jobs-migrations | 10 |
| planning-and-evidence | 42 |
| platform-adapters | 40 |
| runtime-locales | 6 |
| screenshots | 2 |
| shared-mcp | 56 |
| tests-contracts-fixtures | 668 |

The nine final capability trees account for **437 added paths**; retired tool
categories account for **176 changed paths**. The [incoming source inventory](2026-10-17-upstream-1.1.0-inventory.md)
and [expanded-test reconciliation](2026-10-17-upstream-1.1.0-test-reconciliation.md)
remain useful ownership/contract evidence but do not replace this outgoing inventory.

## 4. Content checks that affect PR scope

- **Schema continuity:** all 11 files under `mcp/lib/Migration/` have identical blob
  identities on both tips (10 versioned migrations plus the seed file). Do not
  introduce new migration IDs merely to accompany structural moves. Changed DB
  mappers and jobs still require behavioral qualification; identical migrations
  alone do not establish upgrade safety.
- **Route continuity:** the 48 route array records are identical after removing
  whitespace and ignoring their order/comments. The route file diff mainly moves
  declarations; this check does not assert identical controller behavior.
- **Dependencies:** parsed Composer lock production packages are identical; existing
  development package records are also identical, with only `phpstan/phpstan` added.
  The new Node/browser/build toolchain is a real contributor dependency change.
- **Runtime language catalogs:** for each of `de`, `es`, `pt_BR`, JSON comparison
  finds two added keys, five removed keys, no changed values among shared keys and
  unchanged plural rules. Large textual diffs include formatting. Added/removed
  message coverage and rendering still need review; no human language approval.
- **Screenshots:** the two deleted `screenshots/*.png` files have exactly matching
  blobs under `docs/assets/`; they were moved, not silently discarded.
- **Manifest discrepancy:** the local `mcp/appinfo/info.xml` omits upstream 1.1.0's
  group/scope/inspector/client descriptions in its English, Spanish and Brazilian
  Portuguese metadata, including the group-permissions summary. Do not overwrite
  upstream metadata with this local file in a contribution. Reconcile the intended
  text and add focused parity checks before claiming full release-document parity.
  V0 recorded the discrepancy without changing the application manifest. The later
  authorized [manifest correction](2026-10-18-upstream-1.1.0-feature-parity-review.md#6-manifest-correction-after-the-review)
  closes the local source-text gap; this frozen inventory still describes the
  pre-correction analysis head. Human/rendered acceptance remains pending.
- **Application candidate unchanged:** `git diff --name-only QUALIFIED HEAD -- mcp`
  is empty. Existing candidate evidence has not become a fresh execution; any new
  contribution assembled on upstream has its own head and qualification needs.

Source inspection also confirms two candidate policy/OAuth corrections beyond the
upstream implementations: local event delivery isolates throwing log backends after
commit, and undated-cause reconciliation revalidates eligibility/generations before
a credential cut. A third candidate is owner-bound asynchronous scope UI state.
The [integration report's final review](2026-10-17-upstream-1.1.0-integration-report.md)
records local failing-before/passing-after regressions. The documentation review
reruns the existing local suites, as recorded in the
[feature-parity review](2026-10-18-upstream-1.1.0-feature-parity-review.md). These fixes
remain inside the complete contribution; there is no upstream-layout extraction
candidate, newly established vulnerability severity or separately proposed fix PR.

## 5. Contribution requirements and validation limits

The observed upstream tree has no repository-local `CONTRIBUTING`, `AGENTS`,
`CODEOWNERS`, security policy, code-of-conduct or PR template file. The public
`sintax-tech/.github` repository API returned 404; that establishes no publicly
retrievable inherited guidance, not that private organization rules cannot exist.
No maintainer was contacted. Recheck contribution guidance before publication.

The observed README's Development section uses Composer/PHPUnit and the legacy
Tools/Service layout. Its license section points to AGPL-3.0-or-later and the
attribution notice. Preserve existing attribution/legal files; adding the fork's
commands, layout and docs is a proposed contributor-facing change, not an upstream
convention already accepted by maintainers. The native upstream workflows exist;
the fork additionally changes their gates and adds quality/browser tooling.

Initial V0 verification compared Git objects, remote refs, normalized route records,
parsed lock/catalog data and inventory completeness. The subsequent documentation
review adds a 1.1.0 feature matrix and fresh local PHP/browser runs; see the linked
feature-parity review for results and limits. Neither task qualifies a new PR head
or closes human/client/staging gates. No native suite or deployment runs here.

Reproduction from a repository containing the pinned objects:

```sh
git ls-remote --symref https://github.com/sintax-tech/mcp-for-nextcloud.git HEAD refs/heads/main
git ls-remote https://github.com/sintax-tech/mcp-for-nextcloud.git 'refs/tags/v1.1.0*'
git ls-remote --symref https://github.com/marcjoancr/mcp-for-nextcloud.git HEAD refs/heads/main
git merge-base 0911f9123a661968e707fc4c391adcf9d37c3e84 94431372f4030cfe2b345ad9c294ef9d29f9ae69
git rev-list --left-right --count 0911f9123a661968e707fc4c391adcf9d37c3e84...94431372f4030cfe2b345ad9c294ef9d29f9ae69
git diff --no-renames --name-status 0911f9123a661968e707fc4c391adcf9d37c3e84 94431372f4030cfe2b345ad9c294ef9d29f9ae69
git diff --no-renames --name-status a1a7911cc72dcf2487d17e06e23edfa89b0ecf92 94431372f4030cfe2b345ad9c294ef9d29f9ae69
```

The [scope proposal](2026-10-18-upstream-pr-scope-proposal.md) turns these findings
into one complete PR with internal review blocks and preparation gates. No
contribution branch exists yet. The [feature-parity review](2026-10-18-upstream-1.1.0-feature-parity-review.md)
accounts for all 32 release items, including the inherited limitations.

## 6. Initial V0 validation record

Newly executed on 2026-10-18 UTC in the selected worktree, against the analysis
head plus these documentation edits. Tools: Git 2.47.3, Python 3.13.5, Node 24.21.0,
Composer 2.8.8 (host PHP 8.4.26). Node was selected explicitly from the installed
mise runtime; no dependencies were installed or application files changed.

| Check | Actual result | Limit |
| --- | --- | --- |
| Independent inventory reconciliation using Git `diff --no-renames --name-status -z` and `ls-tree -rz` | PASS: 1,966 unique rows; both outgoing comparison statuses/path sets, category/status totals, every mode/blob entry, merge base and report SHA-256 match | Structural completeness, not semantic equivalence. |
| `composer --working-dir=mcp docs:check` | PASS, exit 0: 184 Markdown files; 110 package sources | Documentation structure and source manifest; no package rebuild. |
| `composer --working-dir=mcp docs:translations` | PASS, exit 0: valid provenance, all 96 entries still `review-required` | No human language review or approval. |
| `composer --working-dir=mcp test:docs` | PASS, exit 0: 29 passed, 0 failed, 0 skipped | Documentation tooling tests, including negative controls. |
| `git diff --check` | PASS, exit 0 | Whitespace only. |

PHP/browser/native application suites, archive rebuilding and staging were **NOT
RUN** during the initial V0 preparation. The subsequent review's local PHP/browser
results and documentation validation are recorded in the feature-parity review.
No existing test result is promoted to a new PR-head result. S0, the recovery decisions and human reviewer gates are unchanged.
