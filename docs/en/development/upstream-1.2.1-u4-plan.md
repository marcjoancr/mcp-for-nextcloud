<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Upstream 1.2.1 U4 implementation plan

Execute inline using the executing-plans workflow. The owner authorized U4 in the
[reviewed intake](upstream-1.2.1-intake.md), plus the numeric parent-title fix.
Base: `9b58004193939c9da0bf6b2d3843ff2184487991`; upstream:
`3c61e928f3abdb37ad60b80ed207a5e243bf0ec4`. Preserve Nextcloud MCP / 2.0.0 and native
Nextcloud 33-35 / PHP 8.4 minimum. Historical API/unit fixtures are not support promises.

## Scope and architecture

Add only `mail_list_accounts` and `mail_list_mailboxes`: the actor's own accounts
and cached folders/counters. No messages, send, flags, synchronization or IMAP
connection from either tool. New `mail.read` starts denied until explicitly granted;
existing grants/scopes are preserved. This deliberately strengthens upstream's
ordinary-read default, following the intake's fail-closed migration requirement.

Use `Modules/Mail/Accounts/AccountLister`, `Mailboxes/MailboxLister`,
`Presentation/{MailRows,MailMessages}`, `Tools/{ListAccounts,ListMailboxes}` and
static `MailToolCatalog`/lazy `MailModule`. `Contracts/MailReader` returns arrays,
not Mail app objects. `Integration/{MailGateway,MailServices}` owns the optional
app calls, resolves fixed class names via injected closures and checks per-user
app availability and ownership. Only Application holds the container. Missing,
disabled, unknown-version or unsupported Mail is unavailable; foreign/delegated/missing accounts
have the same refusal. The minimum API version is 5.0.0, with per-major pinned
surfaces and native Mail 5.12.5 acceptance. No compatibility handlers or aliases.

## Ordered phases

- [x] Observe exact U3 CI; preserve failed or pending checks honestly.
- [x] Reproduce numeric parent UID title loss through create/edit rendered plans;
  accept PHP integer array keys without changing UID identity/escaping; pass Tasks.
- [x] Adapt pinned Mail boundary/handler tests to canonical owners; prove missing
  behavior, then implement owned account projection, cache-only folders, nullable
  counters, deterministic sorting/pagination, bounded/control-cleaned untrusted
  names and generic refusals. Keep production dependencies mandatory.
- [x] Wire optional discovery, administrator matrix, explicit read grant, OAuth
  scope authentication and grant refusal and public schema/title/guide. Check existing-install defaults
  and that grants for every other capability retain their meaning.
- [x] Add attributed Mail API snapshots/pins and reproducible generator support;
  modify only Mail surfaces, preserving unrelated API fixtures. Check signature,
  version floor and absent-app boundaries without requiring installed Mail.
- [x] Add opt-in pinned Mail/Dovecot fixtures to the existing disposable harness.
  No published ports, fixed shared resource names, global Docker control or image
  pruning. Cleanup must match this run's labels. Validate planning and fixture
  controls before executing the native cell. Observe accounts/cached folders,
  other-user denial, actual endpoint credentials/scopes and no cache/IMAP mutation.
- [x] Update canonical English guides, runtime strings, targeted frozen contracts
  and provenance; keep human translation/accessibility review pending.
- [x] Pass full catalog checks, native acceptance, license and secret checks;
  obtain one independent whole-branch review, fix material findings with tests,
  document exact evidence/limitations, commit and publish only authorized fork/main.

## Review focus

- Existing installations never gain Mail access merely by upgrading the catalog.
- Mail objects, credentials/server configuration and foreign/delegated accounts
  cannot escape the integration boundary.
- Optional service resolution fails safely before account/cache access; no Mail
  manager/synchronization path or IMAP connection is reachable from a tool.
- Hostile names remain bounded JSON values, null counters are not invented zeros,
  and pagination is stable across equal case-folded paths.
- Opt-in native fixtures cannot expose ports, clean unrelated resources or hide
  missing prerequisites; snapshots/generator attribution remain traceable.

## Still pending

U5 complete 1.2.1 parity/qualification; LinuxServer L1-L5, intended-server upgrade
and recovery, trusted sources/artifacts and release approval; HTTPS; Inspector's
prior nonzero 14/15/encrypted-store issue; ChatGPT; human security, operation,
license, accessibility and language reviews. The observed server baseline remains
1.2.0 → 2.0.0. No deployment or PR #14 operation is included.

Completed with [recorded results and limits](upstream-1.2.1-u4-integration.md).
