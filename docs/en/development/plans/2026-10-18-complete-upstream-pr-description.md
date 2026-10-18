<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Complete upstream PR — local description draft

Status: local draft; no PR has been opened or published. Replace the evidence
references with accessible artifacts when publication is authorized. This draft
keeps human and staging limitations visible.

## Proposed title

Reorganize MCP by capabilities and unify development workflows while preserving 1.1.0 behavior

## Problem and outcome

Extending broad service/tool classes and global administration scripts makes it
harder to identify ownership, reason about shared access rules and verify changes
across capabilities. This contribution reorganizes the existing solution around
explicit responsibility boundaries and provides a consistent contributor workflow.
It delivers the complete migration in one PR, with permanent tests and documentation.
It targets development scalability and maintainability; it claims no measured runtime
throughput improvement.

The base is upstream `main` at `0911f9123a661968e707fc4c391adcf9d37c3e84`.
The [branch preparation report](2026-10-18-complete-upstream-pr-preparation.md)
identifies the qualified code candidate, archive and exact validation scope.

## Review map

1. Nine capability modules: Files, Notes, Calendar, Contacts, Tasks, Deck, Talk,
   People and Logs, with explicit composition and shared Access/Platform/Mcp/OAuth
   owners. Old Tools/Service runtime trees and transition adapters are removed.
2. Policy/OAuth and asynchronous UI correctness: retain committed outcomes if a
   diagnostic logger fails; revalidate deferred credential cuts; bind scope-editor
   continuations to the correct user/session. Their regression tests remain included.
3. Administration and personal JavaScript: authored modules with two production
   bundles, explicit lifecycle/ownership and browser coverage across three engines.
4. Contributor workflow: unified Composer entry points, syntax/static analysis,
   PHP/browser/native tests, package inspection, CI and fixture attribution.
5. Documentation: canonical English, offline operational guides, es/ca/pt-BR/pt-PT
   drafts, translation provenance and developer architecture/testing guidance.

## Compatibility and attribution

The [1.1.0 feature matrix](2026-10-18-upstream-1.1.0-feature-parity-review.md) covers
all 32 release items and inherited limitations. Group grants, lifecycle/unknown
handling, inheritance/reset, inspector, resource restrictions, managed clients,
German support and upstream security fixes remain upstream contributions, with their
original credits. The restored manifest exactly matches the pinned upstream source.
Migration IDs, routes, production dependency records, native ACLs, receipt/confirmation
semantics, credential generations and conservative cleanup are preserved.

Internal PHP namespaces and source/frontend build requirements change; downstream
consumers of internal classes must be reviewed before choosing the release version.
The current unreleased qualification metadata remains 1.1.0. A future minor or major
version and its upgrade guide require an explicit compatibility decision. Code-only
downgrade to 1.0.8 remains unsupported; follow the canonical
[upgrade and recovery guide](../../installation.md#upgrade-and-recovery) for coordinated state restoration
and credential invalidation.

Four small English-source checkpoints preserve the existing translation references
on the new ancestry. They are internal provenance commits, not separate feature PRs.
Do not squash/rewrite them without rebuilding valid provenance against identical
historical source bytes. All translations remain marked for human review; this PR
does not certify them as reviewed.

## Validation and open gates

Use the preparation report's command/result and artifact tables as the authority.
It distinguishes fresh local checks from byte-equivalent historical native and
multi-PHP evidence; neither is presented as a successful GitHub CI run.

Known minor formatting debt: two inherited extra blank EOF lines remain in
`MovePlan.php` and `oauth-changes.js`; the upstream-to-candidate whitespace check
returns exit 2. They are recorded separately from the passing functional gates.

Pending: human security/technical, language and accessibility reviews; real Nextcloud
UI/theme/CSP and client acceptance; installed artifact/recovery gates; final version
and merge strategy. The local branch and package have not been pushed or deployed.
This is a reviewable complete contribution, not a claim of release or S7 acceptance.
