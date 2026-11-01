<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Operator-owned MCP load paths on LinuxServer

A read-only mount of the MCP directory protects that directory's contents only.
It does not protect a writable parent directory, another configured app root,
or the configuration that determines which app tree Nextcloud loads.

## Layout to qualify

| Component | Serving container | Maintenance container |
| --- | --- | --- |
| LinuxServer application tree | Read-only to the serving identity | Opened only for an approved maintenance operation |
| Nextcloud configuration and app roots | Read-only to the serving identity | Writable only through the maintenance role |
| Business data and logs | Writable only where required by the service | Same stores, with writers quiesced during a coordinated backup |
| HTTP admission | Controlled outside the application container | No serving process while maintenance is active |

The maintenance role is an infrastructure privilege, not a Nextcloud administrator
account. Keep the service identity unable to replace the selected MCP app, rename
its parent, add an alternate app root or rewrite the effective configuration.
Where supported, separate the maintenance container from the serving container.

## Checks

Test the effective app root and every alias or parent that could change app
selection. Attempt file creation, rename and configuration changes as the actual
serving identity, then start a fresh PHP process and verify which application
class Nextcloud loads. Repeat after container recreation and after an approved
update to a signed companion app.

Treat startup hooks separately. Prepare required configuration offline, then
qualify the exact image and hook behavior in an isolated fixture. Keep logs and
business data writable only where the service requires them. An HTTP health check
alone does not prove that the expected app tree or startup hooks are active.

A fixture demonstrates only the paths and image that it exercised. Production
readiness also requires an identified maintenance owner, a coordinated backup
of app/configuration/database/object storage, and a restore rehearsal in an
approved isolated destination.

## Reproduction

Use the project's [LinuxServer integration guide](../../../mcp/tests/Integration/README.md)
with disposable fixture state and a reviewed source kit. Keep private fixture
inputs and run evidence outside public documentation.
