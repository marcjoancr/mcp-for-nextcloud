<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Complete PR review and formatting correction

Status: local automated/source review; human and staging acceptance remain pending.
No push, PR opening, deployment or human reviewer contact occurred. The complete update
remains one PR; the reviewed scope has not been divided into feature deliveries.

Follow-up: [committed-candidate qualification](2026-10-19-complete-pr-local-qualification.md)
records the fresh clean-clone, PHP 8.2–8.5 and full native matrix for `b968846`.
The working-tree/archive results below remain historical for their stated inputs.

## Reviewed inputs and exact correction

The branch is `refactor/complete-upstream-pr`, based on upstream
`0911f9123a661968e707fc4c391adcf9d37c3e84`. A fresh read-only HTTPS
`git ls-remote --symref` query on 2026-10-18 UTC confirmed that upstream main/HEAD
still points there. Review started at `c6f92e9accf5102d2188357a3598b53cf6bcc08f`.
The original branch remains at `bda34406018d8fffd2b30c52628c61b4dd4ad9bf`.

Exactly one final LF was removed from each of
`mcp/lib/Modules/Files/Reorganization/MovePlan.php` and
`mcp/src/admin/clients/oauth-changes.js`. Both retain the normal final newline;
there are no token/logic changes. No new permanent test was added for this
formatting-only correction. Existing behavioral, architecture and package gates
provide the meaningful regression checks.

This report identifies the tested working tree as the review-start commit plus
those two exact source changes, recorded with before/after SHA-256 in the
[review evidence](../contracts/complete-pr-review-evidence.json). Documentation
updates are non-distributed evidence. The earlier preparation report and outgoing
inventory remain frozen historical records; their two whitespace failures are
resolved by this review, not erased from history. Publication must use the actual
final commit and regenerate its outgoing inventory and package identity.

## Parity review and findings

No missing 1.1.0 feature family or actionable runtime regression was identified in
the reviewed scope. This is a bounded source/automated verdict, not a guarantee for
every host/backend or human acceptance. The following checks were performed:

- Reconciled all **32** release bullets with the existing feature matrix, including
  all eight known limitations. Verified all **1,918** frozen outgoing inventory
  entries against Git blobs, modes and path/status records.
- Compared the real manifest byte-for-byte with upstream, all **11** migration/seed
  files, all **48** normalized route records, and production Composer package
  records. They match; route ordering/comments and internal owners differ as
  documented. No legacy `lib/Tools` or `lib/Service` runtime directory remains.
- Re-enumerated **7,660** PHP cases. Every **12,663** directly retained source
  identity from the reconciliation ledger exists in the current target class with
  its exact expanded dataset suffix. The **50** supersessions retain reasons and
  target owners; all **209** upstream frontend allocations retain existing target
  suites. The ledger accounts for 7,433 upstream and 5,280 baseline identities,
  including overlap. Enumeration proves allocation, not execution or equivalence
  of assertions; the full gate below supplies current execution evidence.
- Compared runtime locales de/es/pt_BR: shared values and plural rules match
  upstream, with the same two added and five retired keys already documented.
  None of those five retired strings remains in authored PHP/JavaScript/template
  sources. This does not approve their language quality or rendered presentation.
- Inspected the capability contract tests for real publication/routing, confirmation
  before writes, and Talk attachment privacy. Inspected version restoration's
  bounded temporary stream and source closure before writes, and DAV's `fetch()`
  plus component-qualified UID queries. The scope intentionally preserves upstream
  privacy/folder-exposure and NC32 compatibility fixes.
- A separate automated source reviewer inspected policy transaction/cache/mutex
  boundaries, post-commit diagnostic isolation, generation-qualified deferred
  revocation, group trust/lifecycle, resource-scope fail-closed behavior and
  cross-user asynchronous scope UI ownership. It checked implementation and
  regression assertions, not merely the documentation matrix. No Critical or
  Important issue was found. This is not a human security verdict.

Representative source-review evidence: `PolicyTransaction`, `PolicyEventProcessor`
and `PolicyEventDeliveryTest` preserve durable outcomes when diagnostics fail;
`DeferredQueue`, `CauseProcessor`, `CredentialCommands` and `CauseProcessingTest`
retain fresh cause/generation validation before cuts; `GroupSourceClassifier`,
`GroupTrustObserver` and `GroupLifecycleTest` retain conservative backend/lifecycle
handling. `ScopeGuard`, `FilesAccessGuard` and `ResourceScopeWriter` preserve scope
refusals and bounded receipt-backed writes. The scope controller's ownership checks
and `scope-ownership.spec.js` cover late browse/reload/save/undo responses and detached
controls across account changes. The reviewer did not run another suite.

The [PR description](2026-10-18-complete-upstream-pr-description.md) now points to
this review and removes the resolved formatting debt. It retains original upstream
credit, all five review blocks, internal namespace/build compatibility disclosures,
release-version and provenance constraints, and explicit human/runtime limits.
Four historical source checkpoints remain necessary for the current translation
references; squash/rebase still requires an exact-source provenance reconstruction.

## Validation and artifact

Executed on 2026-10-18 UTC using PHP 8.4.26, Composer 2.8.8, Node 24.21.0 and
REUSE 5.1.1, in the existing isolated worktree. The full gate ran against the exact
two-line source correction. Non-distributed review documents were added while its
browser phase ran, then independently revalidated; no runtime source changed during
the gate. Thus its initial docs check counted 187 Markdown files, and its package
phase/final documentation check counted 188. Both used the same 110 package sources.

| Check | Fresh result |
| --- | --- |
| `devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/talk-capability-plan --json` | PASS, exit 0 / `ok: true`: valid Composer metadata, both PHPStan scopes, **1,144** PHP syntax files, **7,660 PHP tests / 65,227 assertions**, **609** browser cases with no retries/skips, documentation tooling and production package. |
| Source/inventory/release/expanded-test reconciliation | PASS; counts and exact source hashes in the linked machine-readable evidence. |
| `composer --working-dir=mcp docs:check` and `docs:translations` | PASS, exit 0; **188** Markdown files / **110** package sources; **96 review-required** translations with valid ancestry/provenance. |
| `composer --working-dir=mcp test:docs` | PASS, exit 0; **29/29** cases, zero failures/skips. |
| `uv tool run --from reuse==5.1.1 reuse lint` | PASS, exit 0; **1,522/1,522** files with licensing/copyright information, including new evidence; no read errors. |
| `git diff --check 0911f9123a661968e707fc4c391adcf9d37c3e84` | PASS, exit 0 across the complete tracked contribution plus working-tree changes. Both inherited EOF findings are resolved. |
| Per-member package comparison against frozen complete-PR archive | PASS; same **863** regular files and identical member paths/types/modes/link targets; exactly two changed regular files, explained below. |

The rebuilt archive SHA-256 is
`e37fbeb8903c693f0097173b213c1922515e3e29224c6c84d15e46392744e23c`.
It is preserved under ignored
`mcp/build/verification/complete-pr-review/mcp-1.1.0.tar.gz`. Its two member changes
relative to the earlier `c81cb09e…` archive are exactly the removed final LF in
`lib/Modules/Files/Reorganization/MovePlan.php` and the two Composer root-reference
substitutions from `ae551a2` to review-start `c6f92e9` in `vendor/composer/installed.php`.
That Composer reference names the base commit, not the working-tree formatting patch;
the source hashes plus archive hash identify the tested contents. This is not a
committed publication artifact. Both bundles, manifest, migrations, routes,
translations and all other regular-file bytes are identical to the prior package.
The authored JavaScript whitespace correction does not change esbuild output.
The earlier complete-PR and S0 archives were checked and remain unchanged.

Raw local logs remain under ignored `mcp/build/verification/complete-pr-review/`:
`check.log`, `docs-final.log`, `reuse-final.log`, `php-enumeration.log`,
`php-cases.xml` and `source-review.json`. The versionable evidence contains
sanitized counts and hashes, not test traces or credentials. Intentional missing
module diagnostics are asserted negative browser build fixtures, not hidden failures.
No application test failed or was filtered, and no suppression/baseline was added.

Two temporary audit assertions initially failed because the helper deduplicated
PHPUnit method names without dataset suffixes and parsed an unrelated S0 archive
label as a release item. It was corrected to use expanded XML IDs and only the
release-matrix tables; the complete audit then passed. No application or test was
changed to satisfy those assertions. The failed helper attempts are not evidence
of missing PHP cases or a 33rd upstream feature.

## Publication condition confirmed on 2026-10-19

The user requires completing all agreed validation that can run on devbox before
opening the complete PR. This includes the outstanding real Nextcloud/client and
recovery scenarios, with the exact candidate/package and their prerequisites.
Apply the documented candidate-change rules to determine which historical evidence
can be retained and which tests need a fresh run; never mark an unexecuted scenario
complete. Human review remains pending and is not waived. Local draft readiness
is not permission to open a PR with unfinished technical validation. The current
authorization covers this local commit only, with no push or deployment.

## Pending decisions and acceptance

- Human technical/security, accessibility, language and operational reviews remain
  pending. All **96** documentation translations still require review, including
  the same **60** whose referenced English source has changed. No reviewers were
  assigned or contacted by this task.
- Fresh PHP 8.2/8.3/8.5, native Nextcloud matrix and recovery runs were not performed.
  Their recorded results remain historical; the narrow source/package comparison
  bounds this correction but does not claim new host acceptance.
- Real Nextcloud theme/CSP and assistive-technology checks, OAuth/Basic client
  interoperability, S2 installed-byte checks, and outstanding S0–S7/recovery
  prerequisites remain open. The preserved S0 artifact/recipe was not retargeted.
- GitHub CI has not run on this unpublished branch. Publication, the final minor or
  major version, operational-document curation and the merge/provenance strategy
  remain decisions for the user/maintainers. A version change needs requalification.
- The two known formatting findings are resolved. This task adds no behavioral
  feature and does not introduce transition owners or compatibility aliases.

Local verdict: **READY FOR USER REVIEW OF PR DRAFT**, with the limits above.
This is neither release/merge approval nor S7 completion.
