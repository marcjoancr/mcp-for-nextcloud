<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Operator-owned MCP load paths on LinuxServer

**2026-10-31 decision.** The final layout passed the devbox security and
maintenance trial. The service account could neither replace MCP nor change
the configuration that selects it. The operator updated a signed app during a
closed maintenance window. Production qualification remains open.

The previous devbox trial mounted only the MCP directory read-only. The
Nextcloud service account could still rename its parent and inject an
`apps_paths` fragment. The alternate class even survived a container recreation.
This follow-up restores the same synthetic B1 and tests the complete lookup
path with the upstream LinuxServer entrypoint and a separate CLI maintenance
role. [Intended host and writer inventory](fork-minicolab-update-integrity-audit.md)

## Layout exercised

| Component | Serving container | Operator maintenance container |
| --- | --- | --- |
| LinuxServer application tree, `/app` | Read-only bind at the container root, including `/app/www/public`. Its config and app links resolve into the protected configuration volume. | Same read-only bind |
| `/config`, including all app roots, Nextcloud configuration, Nginx and PHP settings | Read-only volume, with a separate writable log area | Restored volume opened for maintenance |
| Business data | Writable `/data`, PostgreSQL and S3 on the internal fixture network | Same stores |
| HTTP admission | The operator controls the gate outside the container. The client must verify fixture TLS. | No web service. The operator removes the serving container before starting maintenance. |

This is a same-version 35.0.2 LinuxServer/Nginx trial for the frozen
fork 2.0.0 candidate `efb27979b6a90122ce45a6a509e52390030f7ed9`.
The operator role is the ability to create a container with the selected
mounts, not a Nextcloud administrator account. All mutation probes use
UID 1000 with zero effective Linux capabilities. The alternate catalog and
all credentials are synthetic or public test inputs. No public port is exposed.

Binding only the document root left an operator-path failure. After normal
LinuxServer initialization, UID 1000 owned `/app` and created a file there.
Read-only mounts at `/app` and `/config` put both active paths directly under
the container root. The fixture tests actual file creation through the selected
paths, including their aliases. It also checks ownership of the one remaining
ancestor, `/`. During serving, the Nextcloud `config_is_read_only` setting
accompanies the filesystem mount. The operator admits requests through a
separate read-only gate mount after startup checks.


## Measured outcomes

| Check | Final result |
| --- | --- |
| Writable control on restored B1 | The service account could append, create, rename and unlink files, rename MCP and its parent, open `config.php` for writing and create an `apps_paths` override. The probe reversed each change. |
| Serving container, after maintenance, and another recreation | Three distinct container IDs. The same five direct mutations, the parent rename and both writes to configuration were refused in all three. Creating a file in any of the seven tested anchor directories or aliases failed each time. |
| Code actually loaded | Fresh CLI and private PHP-FPM requests ran as UID 1000. Both selected `/config/www/nextcloud/apps/mcp/lib/AppInfo/Application.php` with SHA-256 `c8fe83004a8c67f39a5ba5adcaed58101c0894b0d5283d70ad0615155c4b867a`. The only effective app root resolved to `/config/www/nextcloud/apps`. |
| MCP availability and admission | Authenticated `initialize` returned version 2.0.0 on every admitted service. Closing the operator gate returned HTTP 503. TLS used the fixture CA. |
| Signed update | Maintenance installed Notes 6.0.0. The serving role's `occ app:update notes` exited 1 and the installed, signed 6.0.0 remained intact. With the web container removed, the CLI role ran the native update to 6.1.0. Nextcloud verified its signature in maintenance and again in both subsequent serving containers. |
| Fork integrity | The 975 MCP runtime files matched the trusted manifest on B1, during preparation, after the Notes update and in all three serving containers. |

**Startup compatibility.** In each final service generation the captured
LinuxServer startup tail contained 20 read-only filesystem messages. They
included attempts to refresh Nginx configuration and to rewrite
`config.php`. The prepared settings worked on the pinned same-version image.
An image upgrade or config migration that depends on these writes needs its
own offline preparation and verification before a production rollout.

The first two attempts stopped at the maintenance-process check. The image
reported the command as `/bin/sleep`. The final check compares the configured
entrypoint and permits only the sleep process and an optional container init.
Two intermediate document-root-only attempts stopped at the ancestor check.
The first rejected the aggregate permission check. An actual file creation
in the next run isolated the writable `/app` ancestor. All four attempts
remain in the evidence alongside the successful full-tree run.

**Evidence.** The successful run is
`mcp-operator-paths-3f256718-c296-4cd8-9e56-75df6205f7b2` under
`mcp/build/linuxserver-operator-paths/runs/`. Its private `result.json` has
SHA-256 `205eefbf876c01fb5cfc134da9c6e89bc7afc5b889fb46272e904a5ba4c3ac78`.
A separate inventory compared every recorded Docker ID and name across the
five attempts and checked the global fixture label. None of the 56 containers,
40 volumes or five networks remained. The five-run verification record is
`mcp/build/operator-campaign/verification.json`, SHA-256
`3412baa87bec33afb9290de59a6cecd2af96d84303f7319b4042c6b2d8c3c42d`.

## Production handoff

Before scheduling a change, define how the MinicoLab operator will prepare
and replace the protected application tree, control the Docker/Coolify
maintenance role, and restore the matching configuration, app, database and
object store. Validate startup for the intended image with its effective
settings and hooks. This trial covers the service UID and the measured app
selection paths on an isolated same-version restoration. Public HTTPS,
end-to-end Inspector, hosted ChatGPT and human review remain pending.
MinicoLab and upstream PR #14 were outside the trial.

## Reproduction

Run from the repository root on an isolated devbox using the pinned Node
runtime, source kit and original L3 checkpoint directory. The input directory
also contains the retained signed Notes 6.0.0 and 6.1.0 archives and their
catalog views. The fixture pins the control manifest to SHA-256
`7c8406804324fd3af69cd7a0c166c282ad0046d72b0fb762bbecb129b845ef17`.

```sh
composer --working-dir=mcp test:integration:linuxserver -- --check-operator-paths \
  --fixture-profile "$PWD/mcp/tests/Integration/ci/linuxserver/profile-121.json" \
  --kit "$kit" --inputs "$inputs" --checkpoints "$original_l3" \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289 \
  --b0-generation 09e16cbc-ef49-4c55-a73e-43d876f6daaf \
  --b0-sha256 3605cf279981f2ead319099dcfd1e1b7a117e58d0cca9870b9fcdeb248fc339b \
  --b1-generation e0f6df7f-dbe5-4798-ae61-b1ea1eae9123 \
  --b1-sha256 8cdeb7b703c489dfc6a2a0f706517dd254a85e7b9225d5cc14b2fa2415854adb
```
