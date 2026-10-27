<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 intake

Observed on 2026-10-27. This is a release intake and proposed ordering, not an
exhaustive source audit, an implementation record or a claim of 1.2.1 parity.
The fork retains its capability architecture and 2.0.0 development version.

## Immutable references and scope

The [1.2.1 release](https://github.com/sintax-tech/mcp-for-nextcloud/releases/tag/v1.2.1)
was published on 2026-10-24. Annotated tag object
`3758df7bc532bccfbbd4073c18873bb89b387c8f` resolves to
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`.
The comparison baseline is released 1.2.0 at
`02526119b845ccc1d8399be002da3e30b5527df6`, already evaluated in the
[1.2.0 inventory](upstream-1.2.0-inventory.md).

The full Git comparison contains **165 changed files, 9,418 insertions and 639
deletions**. The release includes backup policy changes, optional Mail queries,
Tasks hierarchy and associated administration, localization and tests. These
are functional changes despite the upstream patch version. No merge or cherry-pick
was applied. Research used explicit repository URLs; no upstream remote was added.

## Proposed integration order

| Batch | Behavior to evaluate and port | Capability ownership and acceptance |
| --- | --- | --- |
| U1: Backup visibility | Recognize backup locations only at verified personal roots; handle shared/external origins and normalized names; preserve hidden-original mirror restrictions without hiding ordinary nested names | Shared `Platform/FileAccess`, with Files/Contacts regression coverage. Fail closed for unresolved origins; test owner/share aliases, exclusions, Unicode normalization and cache invalidation. |
| U2: Backup settings | Configurable direct-child backup folder, retained historical reserved names, optional Files pre-write copies with native Versions admission/fallback | Shared backup policy owner plus Files editing, Contacts deletion, admin API/UI and localization. Copies remain on by default; unavailable Versions restores copies; Contacts deletion always requires verified backup. Exercise authorization, CSRF, revision conflicts, renamed locations and all write sinks. |
| U3: Task hierarchy | Parent references via `RELATED-TO`, direct-child filtering and safe parent changes | Tasks Content/Reading/Creation/Editing plus existing DAV boundaries. Preserve non-parent relations; same-calendar readable parent; reject cycles and depth beyond 64 including descendants; recheck at confirmation. Test concurrency, disabled locking and externally written CalDAV cycles; do not imply cascading completion/deletion. |
| U4: Optional Mail | `mail_list_accounts` and `mail_list_mailboxes` for the acting user's accounts and cached folders/counters | New `Modules/Mail` owners, explicit read grant and optional-app discovery. No message reading, IMAP refresh or mutations. Test disabled/missing/incompatible Mail, cross-user denial, empty cache, scopes and optional native Mail/Dovecot fixture attribution. |
| U5: Consolidation | Tool manifests, guides, translations, attribution and native evidence | Inventory every changed upstream path and map it to a port, existing equivalent or justified exclusion. Run focused checks after each batch and the complete suite/package before declaring selected integration complete. |

U1 and U2 share upstream changes and must be audited together before choosing a
safe implementation split. Do not copy partial backup semantics that temporarily
weaken a boundary. Preserve current architecture and security contracts rather
than reintroducing legacy `Service`/`Tools` owners or transitional wrappers.
Upstream release notes and test counts are not independent proof of safety.

## Before implementation

Prepare a complete path ledger against the pinned release and compare each behavior
with the current fork. Inspect authorization, data migrations, compatibility and
new dependency/fixture licenses; record deliberate exclusions. Freeze the fork
starting revision and derive focused and native acceptance cases. Keep grant
migration deny-by-default and verify persisted settings across an upgrade.
Only then implement the reviewed batches, validating before advancing.

The [LinuxServer rehearsal](fork-linuxserver-upgrade-recovery-plan.md) keeps its
**1.2.0 → 2.0.0** baseline: 1.2.0 is the last observed installation, and a new
upstream release does not change that observation. If a 1.2.1 upgrade path is
needed, add a separately pinned case and evidence; do not replace the existing
baseline or silently reuse its acceptance. L0 still verifies the frozen candidate
kit, not future upstream ports.

## Remaining acceptance

Publishing `fork/main` enables independent development; it does not include these
ports or qualify the 2.0.0 release. LinuxServer L1-L5, production profile facts,
public HTTPS, the unresolved Inspector result, real ChatGPT flows and human
security, operations, license, accessibility and language reviews remain pending.
No server changes, deployment, release publication or upstream PR update are part
of this intake.
