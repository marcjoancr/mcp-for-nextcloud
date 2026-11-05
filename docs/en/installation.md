<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Installation

## Requirements

Nextcloud MCP 2.6.2 uses app ID `mcp`. It replaces an existing installation of MCP for Cloud. The Nextcloud App Store distributes the original app. For the fork, use the package built from the reviewed source revision.

The reference environment is Linux x86-64 with Apache. Use maintained patch versions and the PHP extensions `zip`, `mbstring` and `dom`. SQLite is used for local tests and recovery rehearsals.

| Nextcloud | PHP | Database |
| --- | --- | --- |
| 35 | 8.5 | MariaDB 10.11 or PostgreSQL 16 |
| 34 | 8.5 | MariaDB 10.11 |
| 33 | 8.4 | MariaDB 10.11 |

Tools depend on the corresponding Nextcloud apps and the account’s grants. Deck dependencies need compatible APIs on NC34/35. See the [tool catalog](reference/tools.md).

### Install via SSH

Verify `mcp-2.6.2.tar.gz` and its matching source kit against a trusted manifest digest. Rehearse the upgrade and recovery on your server profile and make sure automated updates preserve the fork. The command `occ app:install mcp` selects the original store package.

Before deployment, use MCP Inspector to verify HTTPS access, client authorization and revocation, then confirm that a client can read the tool guide in a disposable instance.

## Upgrade and recovery

Before updating, arrange a consistent backup of the application, database and required Nextcloud configuration/data, following the operator's backup procedure. Maintenance mode prevents traffic during replacement without changing MCP's service policy or revoking credentials merely to perform a routine upgrade. Replace the app directory as a whole, run the required native upgrade, clear/restart PHP caches where necessary, then verify migration history, assets, permissions and existing connections before reopening traffic.

Prefer forward repair after a failed update. Rolling only application files back to 1.0.8 is unsafe: that code does not enforce the new epoch/generation revocations or resource restrictions. A database backup can also contain credentials revoked after it was taken.

For coordinated recovery, keep Nextcloud in maintenance while restoring matching application and database state and any required configuration/data. Before reopening MCP, invalidate restored OAuth codes/tokens through the supported policy commands, explicitly disconnect accounts where reconnection is required, and review effective permissions and resource restrictions. Review/revoke Nextcloud app passwords separately in the native Security controls. Re-enabling service access alone is not this review. An old version that cannot enforce required restrictions cannot be reopened for those users.

The disposable native suite rehearses a same-version application/SQLite restore, old-credential rejection and explicit reconnection. It does not certify an operator's backup tooling, a file-only downgrade or every production storage arrangement.

[Operational guides](guides/README.md).

English · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
