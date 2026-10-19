<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Final checklist for opening the complete upstream PR

Status: **NOT READY TO OPEN**. HTTPS and the real ChatGPT journey remain blocked
or unexecuted. This checklist records the opening scope accepted by the owner on
2026-10-19: one complete architecture, functionality, frontend, DevEx and
documentation contribution, preserving upstream 1.1.0 functionality. It does not
authorize infrastructure changes, publication or deployment.

This is the current opening gate. It narrows the broader runtime checklist in
[V0–V3](2026-10-18-upstream-pr-validation-checklist.md) to the agreed contribution
claims; it does not mark its unexecuted rows passed or not applicable. Human
security, accessibility and language reviews may take place during PR review and
must remain visibly pending. Full [S0–S7 acceptance](2026-10-18-upstream-1.1.0-staging-acceptance.md),
other hosted clients and standing-target recovery remain separate, incomplete
acceptance work. Opening a PR does not establish merge, release or production readiness.

## 1. Freeze the complete contribution

- [ ] Refresh upstream `main` through read-only discovery and record its exact
  revision, the final committed PR head and merge base. The last observed base was
  `0911f9123a661968e707fc4c391adcf9d37c3e84`; this document makes no fresh upstream
  query claim. If upstream moves, inventory its delta and resolve compatibility
  before declaring the contribution ready.
- [ ] Review both upstream-tip-to-head and merge-base-to-head diffs. Update the
  [outgoing inventory](2026-10-18-upstream-v0-comparison.md) and
  [complete scope](2026-10-18-upstream-pr-scope-proposal.md) for the final candidate.
  Preserve upstream attribution and the English source checkpoints used by
  translation provenance; do not silently squash away those references.
- [ ] Reconcile all 32 upstream 1.1.0 release items with the
  [feature matrix](2026-10-18-upstream-1.1.0-feature-parity-review.md), capability
  ownership, contracts and regression evidence. Account for every incoming item;
  counts alone do not establish functional parity.
- [ ] Confirm the final architecture has no transition Tools/Service trees,
  forwarding adapters or incomplete capability migrations. Check shared access,
  OAuth, platform and MCP boundaries as well as all nine capabilities.
- [ ] Review the final compatibility statement: migrations, routes, manifest,
  authorization and tool contracts, production dependencies and build requirements.
  Recommend a minor or major release based on public contract changes, with the
  maintainer's release decision explicitly pending where appropriate. The current
  1.1.0 qualification metadata is not a release/version approval.

Acceptance: one complete, reviewable diff and an exact candidate identity, with
no missing 1.1.0 capability or unexplained breaking change.

## 2. Qualify the final candidate locally

Existing evidence is substantial but belongs to its recorded inputs:

| Evidence | Qualified scope and limit |
| --- | --- |
| [Committed candidate](2026-10-19-complete-pr-local-qualification.md) | `b96884631bf9bda245ce9895facfa8380ab08998`: full clone gate, strict PHP 8.2–8.5, 14 native cells and bounded SQLite recovery. Standing-target recovery is not established. |
| [Closed UI](2026-10-19-closed-devbox-ui-acceptance.md) | Actual Nextcloud settings/consent and locale/theme technical checks. Human accessibility and language approval remain pending. |
| [Private TLS / Inspector](2026-10-19-private-tls-inspector-report.md) | 12/12 required cases on the unchanged application; real CLI OAuth/Basic journeys and negative controls. Private trust does not prove public ingress. |
| [Encrypted storage](2026-10-19-inspector-encrypted-storage-report.md) | 14/15 required cases passed; static-secret issuer isolation failed, command exit 1. Component storage qualification, not two real authorization-server exchanges. |

- [ ] Commit reviewed pending changes and verify a clean worktree. Record the final
  head externally or in the subsequent qualification record; avoid a self-referential
  commit hash in this checklist.
- [ ] Run the unified gate for the final candidate using the selected worktree:
  `devbox run check --cwd PATH --json` (portable equivalent:
  `composer --working-dir=mcp check`). Record all exits, counts, failures and skips.
- [ ] Validate licenses, fixture attribution, documentation links/provenance,
  generated assets and the package. Record archive SHA-256 and compare installed
  application bytes with the frozen qualified artifact, whose SHA-256 is
  `de71b3cbf223764033e97fc59c3122e4c11944730dabe8b79a7f44fe9eb5d751`.
  A changed package is a new artifact even when behavior is unchanged.
- [ ] Apply candidate-change rules explicitly. For unchanged application bytes,
  carry PHP/native/browser results with exact identities and a delta review.
  Runtime, shared-policy or dependency changes require relevant renewed tests;
  documentation/test-only edits do not alone justify repeating all 14 native cells.
- [ ] Confirm the final contribution contains no credentials, private fixture
  contents, raw traces, instrumented third-party bundles or accidental build files.
  Curate sanitized evidence and preserve the provenance needed to reproduce claims.

Acceptance: final checks pass within their declared scope, carried evidence is
traceable, and any failed external qualification remains separately visible. A
passing ordinary `check` cannot convert the Inspector storage failure into a pass.

## 3. Close agreed client compatibility before opening

- [ ] **HTTPS:** qualify a supported, explicitly authorized public HTTPS route
  with machine-client-compatible authentication and external-vantage checks. The
  GitHub sign-in gateway currently blocks this. Preserve application authentication,
  loopback origin binding, bounded exposure and revocation. Private TLS is already
  exercised but does not close this row. Shared devbox changes require separate
  authorized infrastructure work; this checklist does not authorize them.
- [ ] **Inspector over HTTPS:** exercise discovery, OAuth, tool access, refused
  controls and cleanup through that route, using recorded client/runtime pins and
  a precisely identified application artifact. Preserve issuer warnings and failures.
- [ ] **ChatGPT:** run the actual account/workspace connection, CIMD/OAuth consent,
  representative tool calls, withdrawal and cleanup. Record the actual account
  capability and results without credentials. Account login, MFA and permission
  decisions belong to the owner when required; no simulated flow closes this row.
- [ ] **Inspector finding disposition:** carry the failed static-registration test,
  its impact and external follow-up in the PR description. The exercised Nextcloud
  flow uses public-client `none` and no client secret; the storage finding does not
  by itself establish a fork defect or require patching Inspector to submit this
  contribution. Do not claim universal credential isolation. If real-flow testing
  reveals credential disclosure or a server defect, resolve and retest it before
  opening. The external static-secret contract/security review remains pending.

Acceptance: public Inspector and ChatGPT journeys actually pass with cleanup
verified, and the external static-secret limitation is disclosed with bounded
claims. An unresolved third-party claim is not silently waived or counted as a pass.

## 4. Prepare the review package and opening decision

- [ ] Update the [English PR draft](2026-10-18-complete-upstream-pr-description.md)
  for the final base/head, complete change, compatibility rationale, validation,
  carried evidence, known failures and remaining reviews. Provide accessible,
  sanitized evidence references rather than private devbox paths.
- [ ] Curate the upstream documentation while retaining required source provenance,
  architecture decisions and reproducible tests. Revalidate links, translation
  provenance and packaging after any curation.
- [ ] Explicitly list **pending human security/technical, accessibility and language
  reviews**, including all 96 review-required documentation translations and German
  runtime UI review. Do not assign reviewers or approval without their agreement.
  These reviews may occur during PR review; they remain required for the relevant
  acceptance claims and are not replaced by automated review.
- [ ] State that other client matrices, standing staging/recovery and S7 are pending
  and outside this PR-opening claim. Retain the bounded native recovery evidence;
  do not advertise general deployment/recovery readiness.
- [ ] Produce a final `READY TO REQUEST PR OPENING` verdict only after sections 1–3
  and the preparation rows above are satisfied. Obtain explicit authorization for
  push/opening; the current instruction forbids both. No PR exists yet.

## Execution ownership and immediate next step

The agent can review/commit the current diagnostic and checklist, run available
local checks, refresh the upstream comparison and prepare the final candidate and
PR text. Public-client execution depends on resolving the documented HTTPS blocker
in separately authorized infrastructure work. The owner supplies any account access,
MFA or workspace consent needed for ChatGPT. Human reviewer assignments and verdicts
remain pending; the owner already owns operation/recovery with agent assistance.

The immediate step after this commit is final local candidate/diff qualification
and a concrete handoff for the HTTPS prerequisite, without changing infrastructure.
Do not open a PR to work around those remaining client gates.

## Checklist and pending-change review validation

Review validation on 2026-10-19 passed: Composer manifest/lock; documentation
(197 Markdown files / 110 package sources); 29 documentation tests; 10 runner
safety tests; 5 developer-command tests / 40 assertions; production package build
and verification; REUSE 5.1.1 (1,551/1,551 files); source/evidence identity and
whitespace checks. All 96 translations remain review-required.

The unchanged storage runner was rerun as `storage-1d450D`: **14 passed, 1 failed,
0 blocked; exit 1**, reproducing only the static-client issuer boundary failure.
Its private result SHA-256 is
`48caf5adf4238dd90cbf3637eb6ba9995b98a39474b56f6c46bd9b2cadfae2ac`;
runner source hashes match the [curated original evidence](../contracts/inspector-encrypted-storage-2.11.0.json).
The installed Inspector bundle remained unchanged. No test failure was suppressed.

This review changes documentation and adds an opt-in external-client diagnostic
and command. It does not change application implementation. The full PHP/browser/
native suites were not rerun for this delta: their recorded qualification is
carried forward, while final candidate qualification above remains an opening gate.
No infrastructure, listeners, containers or public exposure were changed.
