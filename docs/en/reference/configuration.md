<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Configuration

Use the administration and personal settings interfaces for policy changes. Direct `occ` writes to policy preferences bypass revision checks, group resolution and credential revocation; they are not a supported way to administer the 1.1.0 policy. Read-only diagnostics such as `occ config:app:get mcp service_enabled` remain useful.

| Storage | Key or owner | Meaning |
| --- | --- | --- |
| App `mcp` | `service_enabled` | `1` on; `0` or absent off. Change through the service command/UI. |
| User, app `mcp` | `eligible` | Individual `1`/`0` override of **Can connect**; absence allows group inheritance, then the off default. |
| User, app `mcp` | `connected` | `1` means the personal connection is active. This is distinct from a live OAuth credential. |
| User, app `mcp` | `grant_<module>_<operation>` | Individual `1`/`0` override. Absence means trusted group inheritance, then read allowed except logs; other operations denied. |
| Policy tables | `mcp_policy_state`, `mcp_policy_events` | Revision/global authentication epoch and durable audit, cleanup and receipt events. |
| Group tables | `mcp_group_grants`, `mcp_group_life` | Grant-only policy and observed group identity/lifecycle. |
| Authentication tables | `mcp_auth_users`, `mcp_auth_recheck` | Per-user credential generations/cuts and deferred lifecycle rechecks. |
| Resource tables | `mcp_scope_modes`, `mcp_scope_items` | Independent Files, Calendar and Tasks restrictions. |
| Client table | `mcp_oauth_clients` | Authoritative managed native/custom client state and generations after migration. |
| App `mcp` | `oauth_client_hosts` | Exact published-client hosts; absent or empty uses `claude.ai,chatgpt.com`. Edit in OAuth clients. |
| Legacy app inputs | `oauth_native_client_enabled`, `oauth_custom_clients` | Migration inputs for the client table, not the live managed-client authority. |
| Own table | `mcp_logs_groups` | Canonical JSON groups and version; empty means administrators only. Imports legacy appconfig on first read. |
| App `mcp` | `checkout_max_bytes` | Checkout upload limit in bytes; default 50 MiB, constrained by PHP's `post_max_size`. |
| App `mcp` | `file_write_policy` | Versioned optional filename allowlist; absent means off. Change in Data protection; invalid/unreadable state refuses affected writes. |
| App `mcp` | `hidden_system_tags` | JSON list of excluded system-tag IDs; `[]` disables tag exclusions. |
| App `mcp` | `logs_hide_file_names` | `1` by default: hide log-message text while exclusions are configured; `0` allows the complete log for diagnosis. |

## File type policy

In administration → **Data protection** → **File types writable through MCP**,
choose **No restriction (default)** or **Only listed extensions**, then review and
save. The setting applies globally to MCP Files and
Notes writes, independently of grants, native permissions, resource restrictions,
Guests, exclusions, locks and confirmations. It never makes an otherwise refused
write available. This is a filename/extension rule, not MIME/content inspection,
a malware scanner or protection against prompt injection; other clients are
outside its scope.

The allowlist accepts at most 128 unique extensions, separated by commas or lines.
A leading dot is optional; tokens are lowercased and sorted. Each contains 1–32
ASCII letters/digits, `_` or `-`, starting with a letter/digit. Evaluation uses the
resolved final basename's last extension, case-insensitively. Extensionless names
(including a leading-dot name with no later dot) need the separate explicit option,
which defaults off. Empty names, trailing dots/whitespace and malformed names are refused
while the policy is active; folder names themselves are not extension filtered.

It covers create/upload, editing and replacement, checkout upload, version restore,
copy/move, ordered batches and undo, and Notes create/edit/partial edit/move.
Mandatory internal Files and Contacts recovery backups remain exempt; a public
write using `.bak` or `.vcf` has no exemption. Preview and confirmation check the
policy; mutation boundaries read it afresh, so a newly blocked checkout upload is
refused and a blocked undo retains data and its receipt. An unavailable or malformed
configuration fails closed. An uncertain save is not replayed automatically; use
the interface's read/retry flow to inspect what is stored.

With policy active, folder operations preflight at most 2,000 inspected/projected
nodes per boundary; copies also retain the 1 GiB byte ceiling. Folder copy checks
the visible/readable tree and copies from that checked manifest, with fresh checks per entry. Later additions
are omitted; a failure can leave a reported partial copy, which is never deleted
automatically. Folder move also preflights descendants but remains one native
move: files another client inserts after the last check can move with the folder.
Policy updates and filesystem writes share no atomic transaction, and native
storage/content can change after a check. Visibility changes may withhold stale
receipt details while retaining already written data. Review uncertain results
before any new action; no automatic rollback or safe replay is promised.

These tables describe persistence, not a public SQL mutation API. Editing rows or restoring only some tables can break revision, lifecycle and revocation guarantees. See [permissions](../guides/permissions.md), [OAuth](oauth.md) and the [upgrade/recovery guidance](../installation.md).

Nextcloud owns `enabled`, `installed_version`, `types`, `levels` and `ocsid`; they are not MCP policy switches. In particular, Nextcloud's `enabled=yes` is distinct from `service_enabled=1`.

[Operational guides](../guides/README.md).

English · [Español](../../es/reference/configuration.md) · [Català](../../ca/reference/configuration.md) · [Português (Brasil)](../../pt-BR/reference/configuration.md) · [Português (Portugal)](../../pt-PT/reference/configuration.md)

## Source delivery

An operator configures the source destination separately, through Nextcloud's
system configuration (`config.php`), after staging the complete kit:

```php
'mcp_source_offer' => [
    'version' => '2.0.0',
    'sourceCommit' => 'FULL_40_CHARACTER_COMMIT_SHA',
    'manifestSha256' => 'SEPARATELY_TRUSTED_64_CHARACTER_MANIFEST_SHA256',
    'baseUrl' => 'https://sources.example.invalid/immutable-release-directory',
],
```

This system setting is release metadata, not a user or capability preference.
Replace the placeholder revision and digest with those of the approved source kit;
replace the example destination with a directory serving its five files:
`manifest.json`, `source.bundle`, `runtime-dependencies.tar.gz`,
`mcp-<version>.tar.gz` and `BUILDING.md`. Keep the expected digest in a separately
trusted operator record, outside the app files covered by that manifest.

The version and source commit must match the package's generated
`appinfo/source.json` and installed app version. Dirty development packages have
no source identity. Absent, invalid or mismatched configuration displays an
unavailable offer in personal settings and MCP discovery; it does not grant
access or change authentication. A configured offer still needs successful
retrieval and digest verification before release acceptance.

Use HTTPS with no credentials, query, fragment, percent encoding or traversal
segments. Literal loopback HTTP is reserved for isolated acceptance fixtures.
Do not publish a fixture URL as a release destination or send Nextcloud credentials
to a source host. The application only presents the links; it does not fetch them.
Public publication, supported integrity behavior and target-host update/recovery
qualification remain separate release gates.
