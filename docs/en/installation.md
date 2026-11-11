<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Installation

Nextcloud MCP runs as a native Nextcloud application. It uses the existing server's PHP runtime and storage; it needs no separate MCP daemon or Node.js runtime.

## Requirements

| Component | Requirement |
| --- | --- |
| Nextcloud | **33–35**, as declared in the app manifest |
| PHP | **8.4 or newer**, also supported by your selected Nextcloud version |
| PHP extensions | `zip`, `mbstring` and `dom`, in addition to Nextcloud's own requirements |
| Client access | An HTTPS URL reachable by the chosen MCP client |
| Optional apps | Enable the corresponding apps for Notes, Tasks, Calendar, Contacts, Deck, Talk, Mail and Forms capabilities |

Tools also depend on individual tool assignments, the connection's selection and native resource permissions. Mail requires a known version of at least 5.0.0; Forms requires a known version of at least 5.3.0. Their tools need explicit assignments and selection, and these API floors do not certify every app/server combination. Deck dependency tools require compatible APIs on NC34/35. See the [tool catalog](reference/tools.md).

The contributor tooling also tests PHP 8.2/8.3; those test runtimes do not lower the app's PHP 8.4 installation requirement. The native fixture matrix covers Linux x86-64/Apache with NC33/34/35 and MariaDB 10.11, plus NC35 with PostgreSQL 16 or SQLite. A separate LinuxServer/Nginx fixture exercises NC35/PostgreSQL/S3. These are test profiles, not an exhaustive deployment support guarantee.

## Choose the fork package

The fork retains app ID **`mcp`** and the manifest declares **2.7.0**. It replaces an existing installation of MCP for Cloud with that same app ID. The Nextcloud App Store distributes the original project: **`occ app:install mcp` installs the store edition**.

Use `mcp-2.7.0.tar.gz` built from the reviewed fork revision, with its matching source kit and separately trusted manifest digest. A GitHub source archive or a copy of the unbuilt `mcp/` directory is not an installation package. The complete source checkout contains the packaging instructions in `docs/en/development/packaging.md` and `docs/en/development/source-delivery.md`.

## New installation

For an instance without `mcp` installed:

1. Verify the package and matching source kit against the trusted manifest digest.
2. Extract the archive's single `mcp/` directory into an application root configured in your Nextcloud `apps_paths`. Set ownership and read access according to your deployment's serving and maintenance roles.
3. As the Nextcloud maintenance user, run `php /absolute/path/to/nextcloud/occ app:enable mcp` using the PHP binary selected for that instance. Container-based deployments run the equivalent command inside their maintenance environment.
4. Open **Administration → Nextcloud MCP**. Enable the service, allow the intended users to connect and assign the individual tools they may use.
5. Each user enables their connection in **Personal settings → Nextcloud MCP**, selects the tools for the client and follows [client setup](guides/clients.md).

Use the endpoint shown in the administration Overview, normally:

```text
https://cloud.example.com/apps/mcp/
```

Include your Nextcloud installation subdirectory if it has one. The service, user eligibility and personal connection start disabled, including for administrators. Writes start denied; Mail, Forms and server-log access also need explicit grants. Newly added tools start unassigned and unselected. See [permissions and administration](guides/permissions.md).

Tool responses reach the connected AI client. Choose grants and resource restrictions for the data that client should receive; self-hosting the connector does not determine the client's own data processing or retention policy.

## Upgrade and recovery

Before updating, arrange a consistent backup of the application, database and required Nextcloud configuration/data, following the operator's backup procedure. Rehearse replacement and recovery on a matching isolated profile and ensure automated App Store updates cannot replace the fork.

Maintenance mode prevents traffic during replacement without changing MCP's service policy or revoking credentials merely to perform a routine upgrade. Replace the app directory as a whole, run the native `occ upgrade`, clear/restart PHP caches where necessary, then verify migration history, assets, permissions and existing connections before reopening traffic. Keep source-delivery metadata aligned with the installed kit.

Protect the effective app root, its writable ancestors and the configuration that selects `apps_paths`. A read-only mount on `mcp/` alone cannot prevent a process from selecting replacement code when its parent or configuration remains writable. Separate serving access from operator maintenance where your deployment requires that protection.

Prefer forward repair after a failed update. Rolling only application files back to 1.0.8 is unsafe: that code does not enforce the new epoch/generation revocations or resource restrictions. A database backup can also contain credentials revoked after it was taken.

For coordinated recovery, keep Nextcloud in maintenance while restoring matching application and database state and any required configuration/data. Before reopening MCP, invalidate restored OAuth codes/tokens through the supported policy commands, explicitly disconnect accounts where reconnection is required, and review effective permissions and resource restrictions. Review/revoke Nextcloud app passwords separately in the native Security controls. Re-enabling service access alone is not this review. An old version that cannot enforce required restrictions cannot be reopened for those users.

The disposable native suite rehearses a same-version application/SQLite restore, old-credential rejection and explicit reconnection. It does not certify an operator's backup tooling, a file-only downgrade or every production storage arrangement.

[Operational guides](guides/README.md).

English · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · [Português (Portugal)](../pt-PT/installation.md)
