<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U5 reconciliation plan

Execute the approved consolidation from the [intake](upstream-1.2.1-intake.md)
against upstream `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`, compared with
`02526119b845ccc1d8399be002da3e30b5527df6`. Fork base:
`99df0211b9dc9444534b69118cd22dbf6d439ffc`. Retain capability ownership,
Nextcloud MCP / 2.0.0, Nextcloud 33–35 / PHP 8.4 and all original attribution.

## Ordered phases and acceptance

1. Verify every U4 CI workflow. Investigate failures before claiming acceptance.
   Repair the legacy upgrade contract for the explicit `mail.read` addition:
   every old operation/value must remain exact and the new permission must be
   denied before it can be excluded from comparisons with the old release.
   Reject removals, unknown additions and changed defaults; run regression and
   the failed native upgrade cells. Never drop failing cells or relax permissions.
2. Reconcile all 165 upstream paths against actual fork owners and evidence.
   Each path needs an adapted, equivalent or deliberately excluded disposition;
   identify any missing functionality instead of marking a group complete blindly.
   Compare public tool schemas, routes, guides, UI locales, manifest descriptions,
   fixture provenance and credits. Add a pinned Apache/MariaDB upgrade from
   upstream 1.2.0 using the existing state/credential probe; this complements,
   rather than replaces, the pending LinuxServer rehearsal. Correct discrepancies at their canonical owner.
   Preserve translation provenance and human review requirements.
3. Run the full catalog check, package, license and changed-source secret checks.
   Record exact local/native inputs and skipped cases, and distinguish CI of U4
   from validation of the U5 candidate. Keep the real-server 1.2.0 → 2.0.0 baseline;
   historical migration fixtures do not qualify that deployment path.
4. Obtain one independent whole-branch review. Fix material findings with regression
   evidence, then document outcomes, intentional differences and pending release,
   client, platform and human gates. Commit only after relevant checks pass.

## Review focus

The legacy comparison must not silently discard changed permissions or grant Mail
on upgrade. Source-ledger claims must cover all changed paths without restoring
legacy ownership or broadening platform support. Public text must reflect optional
copies, mandatory Contacts backups, Tasks hierarchy and cache-only Mail with
explicit permission. Generated fixtures and translated content retain their source
identity; automatic review is never described as human approval.

## Boundaries

No deployment, real-server changes or PR #14 update. Public HTTPS, Inspector's
14/15/encrypted-store issue, ChatGPT, LinuxServer L1–L5, trusted release artifacts
and human security, operations, license, accessibility and language reviews remain
separate gates. Reconciliation completion is not a release or blanket runtime
compatibility claim. Publication requires the existing fork/main authorization.

Executed with [recorded results, decisions and remaining gates](upstream-1.2.1-u5-integration.md).
