<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Complete upstream contribution scope after V0

**Decision: one complete PR, confirmed by the user on 2026-10-18.** This replaces
the earlier draft recommendation to extract fixes into separate contributions.
The objective is to make the solution easier to extend and maintain as development
grows, while retaining **all upstream 1.1.0 functionality and safety boundaries**.
Architecture, frontend, DevEx, documentation and the local correctness fixes belong
to the same contribution. Review sections and preparation phases are not separate
PRs, partial feature deliveries or permission to leave transition code.

The [V0 comparison](2026-10-18-upstream-v0-comparison.md) fixes upstream main at
`0911f9123a661968e707fc4c391adcf9d37c3e84` and the full-fork analysis head at
`94431372f4030cfe2b345ad9c294ef9d29f9ae69`. At V0, the application tree matched
qualified code `7d8873cafaaadedc479c2204c9ef75bb76062dcf`. These are comparison inputs,
not an approved publication head. The initial review changed documentation only;
the later authorized manifest correction is recorded under C1. Human
reviewers/verdicts remain pending; no deployment, push or PR opening is authorized.

Current execution: C2 is complete locally on `refactor/complete-upstream-pr`;
C3 available local checks and a C4 description draft are recorded in the
[branch preparation report](2026-10-18-complete-upstream-pr-preparation.md).
Historical text below describes the original proposal; remaining human, staging,
publication and release decisions are still open.

## 1. Purpose and complete scope

Proposed English PR title: **Reorganize MCP by capabilities and unify development workflows while preserving 1.1.0 behavior**.

The problem is the cost of extending broad service/tool owners and global frontend
scripts safely. The contribution introduces explicit responsibility boundaries,
shared infrastructure with controlled consumers, and reproducible contributor
commands and checks. It aims to improve development scalability, discoverability
and regression isolation. It does not claim greater runtime throughput, faster
large-group operations or measured capacity gains without separate benchmarks.

| Review block within the single PR | Included outcome | Compatibility obligation |
| --- | --- | --- |
| Capability architecture | Complete Files, Deck, Talk, Calendar/DAV, Contacts, Tasks, Notes, People and Logs owners under `Modules/`; shared `Access`, `Platform`, `Mcp` and OAuth infrastructure; explicit composition | Preserve all tools, grants, schemas, resources, prompts, native ACLs and 1.1.0 behavior. No legacy Tools/Service runtime tree, aliases or duplicate authority. |
| Policy/OAuth correctness | Post-commit diagnostic isolation, generation-aware deferred revocation, preserved transaction/retry/receipt boundaries | Include `PolicyEventDeliveryTest` and `CauseProcessingTest`; diagnostics must not invalidate committed commands, and stale causes must not cut newly valid credentials. Never suppress policy/storage failures. |
| Administration and personal frontend | Responsibility-based ES modules, two packaged bundles and asynchronous scope-owner/sequence protection | Retain groups, inheritance/reset, levels/operations, inspector, resources, clients, revision conflicts and undo. The `scope-ownership.spec.js` regressions remain part of this block. |
| DevEx, tests and packaging | Unified Composer commands, both PHPStan scopes, PHP/browser/native tests, build graph checks, package verification and CI | Document Node/build requirements; retain runtime assets in the archive, production dependency identities and upstream regression value. No hidden errors, broad suppression or temporary runner. |
| Documentation and i18n | Canonical English README/docs, es/ca/pt-BR/pt-PT counterparts, provenance, contributor/architecture/upgrade guides, offline package docs | English code/comments; original attribution and German runtime support retained; all 96 translation reviews remain pending. Include auditable migration evidence, with private operational details excluded or sanitized. |

Group grants, resource restrictions, managed OAuth clients, German support and the
other 1.1.0 features are **upstream work preserved by this PR**, not features newly
invented by the fork. Credit the original authors and the post-tag attribution
commit. The [feature-parity review](2026-10-18-upstream-1.1.0-feature-parity-review.md)
is the acceptance map for the complete contribution.

## 2. Base and history strategy

The migration branch and upstream share the 1.0.8 merge base. Its current three-dot
diff includes upstream's own work. Prepare a new isolated contribution branch from
a freshly observed upstream main and apply the complete intended final delta there.
This changes presentation/history, not the agreed functional scope. Keep the current
branch intact; no force-push, artificial `ours` merge or patch extraction into a
series of feature PRs. No new branch is created during this documentation review.

The inventory contains 1,911 changed paths against upstream before rename pairing,
or 1,539 entries with explicit 50% rename detection. Its 1,966-row union also covers
the old merge-base comparison. It is a frozen analysis artifact, not the eventual
PR diff: new review docs and authorized corrections will change the final counts.
Record the assembled base/head and regenerate the outgoing inventory before publication.

Preserve permanent tests, source provenance and engineering evidence. Publicly useful
plans can remain in `docs/en/development/`; review devbox-specific reports for relevance
and privacy before publication, retaining a local archive and explaining any omission.
Documentation curation must not omit behavior, tests, dependencies or required guides
from the complete PR. No files are removed by this proposal.

## 3. Update size and release version

Treat this as a substantial architectural and developer-experience update, not a
small patch. If the maintained public contracts and upgrade paths remain compatible,
a **minor release candidate such as 1.2.0** is a reasonable proposal. If review finds
incompatible supported APIs, extension points, configuration or upgrade requirements,
propose a **major release such as 2.0.0** with an explicit migration guide. These are
examples, not assigned versions or a claim that upstream has adopted SemVer.

Under [Semantic Versioning](https://semver.org/spec/v2.0.0.html), public-contract
compatibility determines major/minor classification; the number of changed files
alone does not. The standard term is *minor*, rather than *medium*. Removing internal
PHP namespaces and changing source-build tooling must be disclosed and checked for
supported external consumers before concluding that the update is non-breaking.
Maintainer agreement and the final compatibility review decide the release number.
No version, tag or release metadata is changed here. A future version change must
update manifest/protocol/package documentation together and requalify that artifact.

## 4. Ordered preparation and acceptance

These phases prepare **one final PR**. Preparation remains open. The user later
authorized the C1 manifest correction; its [local evidence](2026-10-18-upstream-1.1.0-feature-parity-review.md#6-manifest-correction-after-the-review)
does not approve the remaining implementation, publication or human gates.

### C1 — Close known parity and presentation gaps

- [x] Restore the missing upstream 1.1.0 descriptions in `mcp/appinfo/info.xml` for
  English, Spanish and Brazilian Portuguese; retain group summaries, administration,
  inheritance, inspector, resource/privacy boundaries and managed-client information.
  Source-text parity and its regression contract are verified locally; human text
  approval and installed/rendered verification remain pending.
- Close every discrepancy in the feature matrix, or record a deliberate, justified
  compatibility decision. Missing 1.1.0 functionality cannot be silently deferred.
- Review the two added/five removed runtime keys per locale and attribution. Keep
  human language/accessibility/security verdicts pending until actually reviewed.

Acceptance: no unexplained functional or metadata regression; every upstream release
item has final owners and tests/evidence. The manifest omission is corrected locally;
further application edits and remaining human gates require their own follow-up.

### C2 — Assemble the complete contribution on current upstream

- Refresh refs and contribution guidance, account for any new upstream delta, then
  create an isolated branch with all five review blocks and no transition code.
- Review the final two-dot/three-dot diff, migration IDs, route records, dependencies,
  public contracts, original credits and documentation inclusion. Disclose internal
  API/build changes; agree the version strategy before preparing release metadata.

Acceptance: one coherent, buildable head, based on the actual upstream target,
without reintroducing its existing features as novel changes or altering the source
branch. Every omission from the full-fork inventory is accounted for.

### C3 — Qualify that exact head and package

- Run the unified complete gate, both static-analysis scopes, PHP 8.2–8.5, browser
  coverage, REUSE and archive inspection as required by the candidate-change rules.
- Run or carry forward native evidence only under the acceptance plan's exact-input
  rules. The shared-boundary changes require the 14-cell matrix, upgrade, replacement
  and recovery coverage; historical results are not fresh results for a new artifact.
- Keep S0/runtime prerequisites and human checks separate. Missing real-client,
  theme/CSP, accessibility, linguistic or operational evidence stays pending. No
  staging execution or public exposure follows from accepting the PR's scope.

Acceptance: evidence names the new head/package, failures and skips are explicit,
and no required test is silently dropped. Local technical success does not close S7.

### C4 — Prepare and review the single PR locally

Use V3 to draft one English body: problem and architectural outcome; review map;
preserved 1.1.0 features; intentional compatibility/build changes; complete test and
package evidence; upgrade/recovery notes; attribution and human/runtime limitations.
Use reviewable internal commits if helpful, but submit one complete contribution.

Acceptance: the entire update is explainable and reviewable in one PR, with all known
gaps named. Publication requires a later explicit request. Release acceptance and
human approval are not inferred from the PR draft or automated tests.

## 5. Next action

The manifest correction and complete branch assembly are complete locally. Next,
review the [prepared branch and PR draft](2026-10-18-complete-upstream-pr-preparation.md),
including its new inventory, artifact identity, provenance/merge constraint, minor
formatting debt and outstanding human, rendered-language and candidate-artifact gates.
Do not return to the superseded small-PR sequence. The user continues to own devbox
operation and recovery; the recovery proposal, outstanding S0 gates, reviewer
assignments and every human verdict remain pending. No reviewer was contacted.
