<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream contribution validation and evidence checklist

**Status: V0 reviewed; complete-PR scope confirmed; V1–V3 for the future contribution remain open.** This is the short-campaign evidence checklist
requested after the [owner decisions](2026-10-18-upstream-1.1.0-staging-acceptance-report.md#confirmed-decisions--short-validation-campaign)
at `1d197f3`. No deployment, image pull, push, PR opening or reviewer contact is
authorized. The user owns devbox and accepts operation/recovery with agent assistance;
all human review verdicts and the remaining reviewer assignments stay pending.

The purpose is a reviewable contribution to `sintax-tech/mcp-for-nextcloud`, based
on an explicit upstream revision and honest validation limits. This is not a release
checklist or a substitute for [full S0–S7 acceptance](2026-10-18-upstream-1.1.0-staging-acceptance.md).
The companion [short-campaign recovery proposal](2026-10-18-short-campaign-recovery.md)
is unaccepted design; it does not change the current recipe or authorize recovery.
V0/V1 and local V3 draft preparation can proceed independently of blocked V2 work
when their own actions are authorized. V0 read-only analysis was authorized and
completed; staging execution remains unauthorized. A draft must preserve V2 and
human-review gaps explicitly.

## Evidence states and scope

V0 checkboxes are supported by the linked source-comparison report; V1–V3 remain
open. Existing qualification reports are evidence sources to verify,
not new executions. Record `NOT RUN`, `BLOCKED`, `PASS` or `FAIL`, plus whether a
result was newly executed or carried forward from an exact qualified candidate.
Do not turn missing reviewers/clients into `NOT APPLICABLE` or make a historical
pass look current. Use the acceptance plan's narrower, evidence-backed exceptions
only for deployment-specific subcases.

| Evidence source | What it can support | What it cannot establish |
| --- | --- | --- |
| [Integration report](2026-10-17-upstream-1.1.0-integration-report.md) | P0–P9 results for its pinned candidate, PHP/browser/static/native evidence and documented limitations | Today's upstream HEAD, a new PR head or human/runtime acceptance. |
| [393-path inventory](2026-10-17-upstream-1.1.0-inventory.md) and [test reconciliation](2026-10-17-upstream-1.1.0-test-reconciliation.md) | Incoming upstream 1.1.0 paths and preserved test semantics mapped to capability owners | The outgoing contribution diff against the eventual PR base; these are different comparisons. |
| [S0 report](2026-10-18-upstream-1.1.0-staging-acceptance-report.md) | Candidate/hash preservation, local inventory and explicit owner decisions | Approved execution, fresh resource availability, complete S0 or staging success. |
| [Recipe record](2026-10-18-upstream-1.1.0-staging-provisioning-recipe.md) | r3 inputs, static checks and mocked negative controls | Active configuration, image vulnerability acceptance or real recovery. |
| [Human checklist](2026-10-18-upstream-1.1.0-human-review-checklist.md) | Complete review scope and how to record verdicts | Any approval: all 96 documentation reviews and other human verdicts are pending. |

For every result record: evidence ID; UTC date; exact base/head commit and package
SHA-256 if relevant; tool/runtime versions; complete command or canonical scenario
ID; expected/actual outcome and exit status; failures/skips; sanitized artifact
reference; scope/limits; actor and any actual human review. Keep raw traces, secrets,
backup endpoints and credentials private. Retest a corrected failure explicitly;
do not overwrite its history or use a successful retry to conceal it.

Use E-SOURCE for the V0 reference/diff inventory, E-LOCAL for local command/runtime
qualification and E-PACKAGE for artifact/content verification. E-ENV through
E-RECOVER below cover runtime results; E-HUMAN points to actual checklist verdicts
and stays pending while reviews are missing. These IDs group records; they do not
replace individual test/scenario identifiers or create evidence by themselves.

## V0 — Freeze the outgoing contribution scope

Completed as analysis on 2026-10-18: [references and inventory](2026-10-18-upstream-v0-comparison.md),
[scope recommendation](2026-10-18-upstream-pr-scope-proposal.md). The analyzed full-fork
head is frozen; no new contribution head exists. The user selected one complete
architecture/DevEx PR, retaining all 1.1.0 functionality, on 2026-10-18. The
[feature-parity review](2026-10-18-upstream-1.1.0-feature-parity-review.md) records
local checks and open findings; these do not qualify a future assembled head.
Checked analysis items do not approve publication.

- [x] Resolve the selected worktree identity/status and preserve unrelated changes.
- [x] Record exact source references: original 1.1.0 target
  `0911f9123a661968e707fc4c391adcf9d37c3e84`, qualified local code
  `7d8873cafaaadedc479c2204c9ef75bb76062dcf`, full-fork analysis head, observed
  upstream base and their merge base. A future contribution head does not yet exist.
  Record both the upstream-tip-to-head tree comparison
  and the merge-base-to-head contribution diff, accounting for any divergence that
  affects the platform's displayed PR. Upstream `main` is the recommended target;
  refresh its exact base before assembling a contribution.
- [x] Verify current upstream branch/revision and contribution requirements through
  read-only discovery before proposing integration. Do not merge/rebase or claim
  the fork is up to date merely from the pinned 1.1.0 comparison. Record new upstream
  deltas and their impact separately; the V0 report records live read-only queries
  without a fetch, merge or implementation.
- [x] Produce the outgoing base-to-head inventory by capability, with additions,
  behavior changes, migration/compatibility impact and rationale. Distinguish code
  imported from upstream from the fork's actual contribution; avoid proposing
  upstream's own changes back as novel work.
- [x] Record one complete PR: architecture, frontend, correctness, DevEx and
  documentation/i18n, with 1.1.0 behavior preserved. Internal review blocks and
  preparation phases do not become separate feature PRs. The assembled head must
  build without transition aliases, incomplete migrations or deferred features;
  record dependencies and the major/minor compatibility decision explicitly.

Acceptance: exact comparison and scope are recorded; known upstream changes are
accounted for. Complete-PR scope is the user's decision; branch assembly and
publication remain later actions requiring their own authorization.

## V1 — Assemble local qualification

- [ ] Preserve the frozen archive before builds. Record package/source/asset identity
  and compare changed paths with the qualified candidate. Never overwrite the S0
  preserved copy when producing a PR-specific artifact.
- [ ] Apply the acceptance plan's candidate-change rules. Development-only docs need
  docs/provenance/tooling checks. Runtime/shared-policy changes need broader gates;
  do not rerun every historical suite merely because a report date changed.
- [ ] For a new code-bearing PR candidate retaining the fork's tooling, run the
  registered full gate from the selected worktree:
  `devbox run check --cwd PATH --json` with the resolved absolute
  worktree path. The portable equivalent is `composer --working-dir=mcp check`.
  Use one entry point, inspect every failure, and record counts without filters.
  The complete contribution includes this tooling. Verify its catalog binding and
  scripts before execution; do not run the newer catalog against an unmodified
  upstream tree and mistake a missing command for a completed gate.
- [ ] Qualify PHP 8.2–8.5 and REUSE as required by the candidate delta, using the
  existing [testing contract](../testing.md) and qualified procedures. Host PHP and
  a passing `check` alone do not establish all PHP versions or REUSE compliance.
- [ ] For behavior/shared-boundary changes, map regression cases to actual defects,
  affected capabilities and retained upstream expectations. Both PHPStan scopes,
  complete PHP/browser gates and package inspection must pass as applicable; no
  baselines, suppressed errors, excluded paths or skipped mandatory tests.
- [ ] Identify the new package separately even if only Composer root metadata changes.
  Check package contents, generated assets and offline documents using
  [packaging](../packaging.md). Carry behavioral evidence forward only under the
  acceptance plan's exact-byte comparison/review rules.

`check` does not start Nextcloud containers. Native integration does: all required
14 cells and PHP container qualifications, when needed, require their authorized
local execution scope. Follow [native testing](../../../../mcp/tests/Integration/README.md),
never point its disposable runner at persistent staging, and do not execute those
commands merely because they appear in this checklist.

Acceptance: each claim names the actual tested candidate and its limits. Unchanged
historical results are explicitly carried forward, not described as freshly run.

## V2 — Authorized runtime evidence, after prerequisites

S0 is still partial. None of these rows may start under the current request. Use
S1–S6 in order, with the user as operational/recovery owner and named reviewers
where required. A separately proposed narrower technical exercise needs its own
scope and safety prerequisites; it cannot be used to claim full staging acceptance.

| Evidence ID | Canonical coverage | Required record before claiming success |
| --- | --- | --- |
| E-ENV | S1 | Exact topology/versions, native fixture baselines, isolated listeners, logs, cron, resource limits and private administration; accepted recovery arrangement. |
| E-INSTALL | S2 | Pre-install checkpoint; installed artifact/migration/asset identities; fresh-origin results distinguished from historical upgrade/replacement evidence. |
| E-CLIENT | S3, C1a–C1e/C2–C4 | Each real client/build/account, positive and refused OAuth/Basic controls, discovery/callback/withdrawal behavior; missing clients stay blocked. |
| E-BOUNDARY | S4, F1–F7 | Native ACL/group/scope/reference/federation and concurrency controls, including visible positive controls; retain exact scenario IDs and all required rows. |
| E-UI | S5, H-UI | Real Nextcloud theme/CSP, keyboard/screen-reader/zoom and four runtime languages; mocked browser tests do not replace these human observations. |
| E-RECOVER | S6, H-OPS | Coordinated independent checkpoint, restoration and credential-cut evidence, fresh controls and measured RPO/RTO; follow the companion proposal and canonical procedure. |

- [ ] Record owner authorization for exact operations and time windows, then satisfy
  dependency gates before each scenario. No blanket public exposure or destructive
  fixture operation follows from the resource allocation.
- [ ] Close ingress between sessions; stop dependent work on failure. Preserve
  sanitized failures and exact retests, with no unscheduled cleanup or data deletion.
- [ ] Keep every missing human verdict pending. S7 can close only under its existing
  complete checklist, not because technical evidence is sufficient to draft a PR.

## V3 — Prepare the upstream review package locally

- [ ] Draft an English title/body for the selected contribution, leading with the
  concrete problem and resulting behavior, then scope, capability owners and why
  the change belongs upstream. Follow the verified upstream template if one exists.
- [ ] Include the exact base/head, migration identities and compatibility contract,
  intentional behavior differences and known limitations. Separate user-facing fixes
  from structural moves and tooling so reviewers can assess each independently.
- [ ] Attach an evidence table using the IDs above: passed commands/cases, carried
  historical results, failures/retests and unexecuted host/client/human gates. Do not
  present local execution as GitHub CI or a draft translation as human approved.
- [ ] Explain recovery/rollback constraints using the canonical guide; no file-only
  downgrade, hidden transition code, credential material or private devbox details.
- [ ] Verify licenses/fixture attribution, no accidental generated files or secrets,
  and that the proposed PR contains the intended contribution. Private campaign
  approvals and operational artifacts are not automatically upstream deliverables.
- [ ] List unresolved defects and reviews with scope/impact and disposition needed.
  Security, credential revival, data loss or broken mandatory behavior must remain
  visible blockers; a draft discussion is not acceptance or release readiness.
- [ ] Provide a local handoff verdict: `READY FOR USER REVIEW OF PR DRAFT` or
  `BLOCKED FOR THE PROPOSED CLAIMS`, with explicit remaining work. This verdict does
  not assert S7 success. User authorization is still required to push/open a PR.

A locally reviewable draft can disclose unexecuted V2 scenarios and pending human
reviews; it is not a recommendation to merge or publish despite a known blocker.
Do not require a completed S7 verdict merely to write an honest draft, and do not
use draft readiness to bypass S0 prerequisites for actual staging execution.

The campaign has no minimum duration. Stop execution after its authorized evidence
is collected or a blocking issue prevents progress; retain state/evidence according
to the approved recovery/disposition decision. Reviewers may remain pending while
local draft preparation proceeds, but no missing review is marked complete.
