<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Fork development baseline

On 2026-10-27 the owner authorized publishing `fork/main` and making it the fork's
GitHub default branch, following the proposed consolidation of L0. This authorizes
development-branch publication, not a release, deployment or changes to upstream
PR #14. The original `main` and PR reference remain intact.

## Baseline and workflow

`fork/main` contains the capability architecture, selected upstream 1.2.0 ports,
Nextcloud MCP / 2.0.0 preparation and L0 at `80bd12ca`. The
[1.2.1 intake](upstream-1.2.1-intake.md) records the newer release and proposed
capability batches; **1.2.1 is not yet integrated**.

Create new topic branches from `fork/main` and contribute to this fork. Preserve
ancestry and attribution. Native integration CI now watches pushes to `fork/main`;
the other workflows already cover branch pushes. No production workflow is added.
Remote promotion and protection must be verified after publication. Prevent force
pushes and deletion; required-check and independent-review policies must be agreed
against actual CI contexts and available reviewers, not invented sign-offs.

## Local verification

The full catalog check on 2026-10-27 passed with exit 0 before the L0 commit:
**8,451 PHP tests / 71,806 assertions**, **690 browser tests**, documentation and
client-runner/release-tool unit suites, PHP syntax, both static-analysis scopes and
verified packaging. Documentation covered **155 Markdown files / 110 package
sources** at that point. Evidence is retained locally under
`mcp/build/fork-publication-review/check.json`; this ignored path is not a public
download. The [L0 record](fork-linuxserver-l0.md) describes reproducible commands,
immutable inputs and the separate frozen candidate. Subsequent workflow/docs
changes passed `docs:check` (**157 Markdown files / 110 package sources**),
Actionlint 1.7.7 and `git diff --check` before commit.

Publication screening compared objects reachable from the fork with the existing
public PR head `14a76df26817fb8a4540eb9c9d1d0043e3e87506`. Before the L0 commit,
556 new blobs were text. Gitleaks 8.30.1 with its explicit default configuration
returned one finding: the documented `alice:APP-PASSWORD` example in the client
guide. This was classified as a placeholder, not suppressed or treated as a clean
scanner exit. Separate L0 commit and staged workflow/documentation scans returned exit 0
with no findings. The raw reports remain local and redacted.

The 175 historical content-finding records and five binary fixture/screenshot
objects listed in the [F3 source audit](fork-f3-delivery.md) are already reachable
from that public PR head. This bounded comparison does not replace privacy,
license or visual review, and does not establish that all history is suitable for
a release source bundle. No history was rewritten and no generated evidence or
server credentials are included in this publication.

## Pending qualification

- LinuxServer L1-L5: runtime isolation, 1.2.0 → 2.0.0 upgrade, complete restores,
  negative cases and cleanup. L0 has planning only; it has not run a fixture.
- F3/F4/F5: final release source kit, supported matrix, trusted distribution,
  intended-server profile and recovery qualification/approval. A development
  branch is not a signed or accepted 2.0.0 release.
- HTTPS: real public certificate, ingress and hosted-client access remain pending.
- Inspector: retain the prior nonzero **14/15** result and encrypted-storage issue;
  do not infer acceptance from the server's local checks.
- ChatGPT: real hosted OAuth consent, tools and revocation remain untested.
- Human review: security, operations/recovery, licensing/attribution, accessibility
  and language review remain pending; translation drafts are not human-approved.

These release/operational gates do not prevent independently tested feature work
on the fork. No server changes, deployment or upstream PR update are performed.
