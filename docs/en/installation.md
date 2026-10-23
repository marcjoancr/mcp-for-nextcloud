<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Installation

## Requirements

Nextcloud MCP **2.0.0** is an independent development candidate. It is not yet a
qualified release and must not be deployed based on this document alone. It keeps
app ID `mcp`, so it replaces the original application rather than installing beside
it. The Nextcloud App Store package is the original MCP for Cloud, not this fork.

The approved qualification targets are:

| Nextcloud | PHP | Database | Reference platform |
| --- | --- | --- | --- |
| 35 | 8.5 | MariaDB 10.11 or PostgreSQL 16 | Linux x86-64 / Apache |
| 34 | 8.5 | MariaDB 10.11 | Linux x86-64 / Apache |
| 33 | 8.4 | MariaDB 10.11 | Linux x86-64 / Apache |

Use security-maintained patches. The final candidate and exact patches still need
qualification; manifest bounds do not certify every combination. SQLite remains a
local test/recovery target, not an initial production profile. NC 32 is excluded
from this release's deployment scope; its historical tests do not prove an upgrade
sequence from an NC 32 host. PHP needs `zip`, `mbstring` and `dom`.

Optional apps provide only their own tools: Notes, Calendar, Contacts, Deck, Talk
(`spreed`), Versions (`files_versions`, required for edits) and Deleted files
(`files_trashbin`, required for deletes). Disabled or unsupported apps have hidden
tools. Deck's three dependency tools additionally require the compatible NC34/35
APIs described in [Deck](reference/tools/deck.md#conditional-native-dependencies).

### Install via SSH

The source build produces `mcp-2.0.0.tar.gz`. No production installation command is
approved yet. Before installation, record the exact current server/app versions,
qualify their in-place upgrade and coordinated restore, and prove that each
routine update path preserves the fork. A higher version number does not stop a
future App Store package replacing it. Do not use `occ app:install mcp` to install
this fork or force-enable it on a platform outside its manifest bounds.

The controlled procedure must verify the candidate digest and corresponding
source, enable maintenance mode, replace the app directory as a whole, run the
native upgrade and verify migrations, policy, assets and existing connections
before reopening traffic. It must also establish custom-app integrity behavior
without disabling Nextcloud integrity checks globally. Those checks remain release
gates; the actual host, public HTTPS, Inspector, ChatGPT and human review are still
pending. Legacy migration IDs are retained. An installed upstream 1.2.0 to 2.0.0
upgrade is not established merely by integrating its source changes.

## Upgrade and recovery

Before updating, arrange a consistent backup of the application, database and required Nextcloud configuration/data, following the operator's backup procedure. Maintenance mode prevents traffic during replacement without changing MCP's service policy or revoking credentials merely to perform a routine upgrade. Replace the app directory as a whole, run the required native upgrade, clear/restart PHP caches where necessary, then verify migration history, assets, permissions and existing connections before reopening traffic.

Prefer forward repair after a failed update. Rolling only application files back to 1.0.8 is unsafe: that code does not enforce the new epoch/generation revocations or resource restrictions. A database backup can also contain credentials revoked after it was taken.

For coordinated recovery, keep Nextcloud in maintenance while restoring matching application and database state and any required configuration/data. Before reopening MCP, invalidate restored OAuth codes/tokens through the supported policy commands, explicitly disconnect accounts where reconnection is required, and review effective permissions and resource restrictions. Review/revoke Nextcloud app passwords separately in the native Security controls. Re-enabling service access alone is not this review. An old version that cannot enforce required restrictions cannot be reopened for those users.

The disposable native suite rehearses a same-version application/SQLite restore, old-credential rejection and explicit reconnection. It does not certify an operator's backup tooling, a file-only downgrade or every production storage arrangement.

[Operational guides](guides/README.md).

English · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
