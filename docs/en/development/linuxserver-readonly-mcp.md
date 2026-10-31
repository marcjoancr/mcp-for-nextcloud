<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Read-only MCP mount on LinuxServer

**Production decision, 2026-10-31.** A read-only bind mount at
`/config/www/nextcloud/apps/mcp` protected the contents of that directory.
The Nextcloud service account could still make Nextcloud load a different MCP
application from writable storage. That substitution survived another full container
recreation with the read-only mount still active. This layout fails the release criterion
for MinicoLab. [Host update and integrity audit](fork-minicolab-update-integrity-audit.md)

## Test and evidence

The devbox runner restored a private copy of B1 from the previous 1.2.1 to
2.0.0 rehearsal. It used Nextcloud 35.0.2 (LinuxServer/Nginx), PostgreSQL and
SeaweedFS on an internal Docker network with no published ports. It extracted
the verified candidate into a separate host directory and bound that directory
over the active MCP path. Each new container used the restored configuration
and data volumes. Docker inspection confirmed distinct container IDs, the
expected bind source and target, and `RW=false`.

| Check as `abc` (UID 1000, zero effective capabilities) | Outcome |
| --- | --- |
| Unmounted positive control | Append, create, rename a file, unlink and rename the entire MCP directory all succeeded. The probe reversed each change. |
| Same five operations with the mount | All five were refused in both the first mounted container and its replacement. An authenticated MCP initialization over private TLS returned 2.0.0 in each. |
| Replace the parent directory | The service account moved the writable `apps` directory aside and put another directory at that path. A fresh Nextcloud PHP process loaded the alternate MCP class. |
| Redirect app discovery | The service account wrote a configuration fragment changing `apps_paths`. A fresh Nextcloud PHP process again loaded the alternate class. |
| Leave the redirect in place and recreate again | With the alternate tree and fragment in the persistent configuration volume, the third mounted container loaded the same alternate class without another write by the service account. All three mounted container IDs were distinct. |

The alternate application came from the same candidate with one inert comment
added to its application class. It kept the same app ID and version. The probe
checked the path selected by Nextcloud and the file actually loaded for
`OCA\Mcp\AppInfo\Application` using PHP reflection. Its SHA-256 changed
from `c8fe83004a8c67f39a5ba5adcaed58101c0894b0d5283d70ad0615155c4b867a` to
`f5a5bd62204757e96a472baf38f85d8cae777b240b288e944379959e15b2eba3`.
Reversing each redirection restored the original path and class hash. The
runner also compared all 975 runtime files at the mount source and the visible
installation with the trusted manifest.

## Evidence retained on devbox

The final fixture ran from the frozen fork 2.0.0 kit at
`efb27979b6a90122ce45a6a509e52390030f7ed9`. The private result is
`mcp/build/linuxserver-mount/runs/mcp-mount-62215eb8-4253-431c-8c6c-a036c84cbecf/result.json`,
SHA-256 `c44213f2eb1871105168e170a89b0939c0992fe19aa99eeab26a46ed93a2b67c`.
It records both generations and the additional recreation with the redirect
left active. The final container loaded the class from
`/config/www/nextcloud/mount-trial-shadow/mcp`. The authenticated HTTP checks
were made against the intact candidate. Alternate-code selection was proved
by bootstrapping Nextcloud in fresh service-account PHP processes.

An initial run independently reproduced both redirections before and after
recreation. The first follow-up stopped during restore when Docker's size scan
encountered a disappearing temporary image file. It gave no substitution
result. The final run repeated the follow-up with the same budget checks.
Admission was closed at teardown in all three runs. A separate Docker inventory
check found none of the 32 recorded containers, 24 volumes or three networks.
The run and cleanup index is `mcp/build/mount-campaign/verification.json`,
SHA-256 `3e3ae13d2b8c0f21fc1086185c12cf76ab3f54e07e3d5a5aaf01bae6bdf0b3f0`.

The 44 LinuxServer Node controls, 70 PHP integration-harness tests (496
assertions) and 29 documentation tests passed. PHP lint checked 1,391 files.
The security trial itself returned exit 1 because the alternate app loaded.
Signed updates of other apps under this particular mount were outside the trial.

## Reproduce on an isolated devbox

Use the private fixture inputs, source kit, and original L3 checkpoint
directory. B0 and B1 are admitted as a matching pair. This probe restores B1.
Use the published 1.2.1 inputs described in the [intake](upstream-1.2.2-intake.md).
Run from the repository root with the project's pinned Node runtime.

```sh
composer --working-dir=mcp test:integration:linuxserver -- --check-mount \
  --fixture-profile "$PWD/mcp/tests/Integration/ci/linuxserver/profile-121.json" \
  --kit "$kit" --inputs "$inputs" --checkpoints "$original_l3" \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289 \
  --b0-generation 09e16cbc-ef49-4c55-a73e-43d876f6daaf \
  --b0-sha256 3605cf279981f2ead319099dcfd1e1b7a117e58d0cca9870b9fcdeb248fc339b \
  --b1-generation e0f6df7f-dbe5-4798-ae61-b1ea1eae9123 \
  --b1-sha256 8cdeb7b703c489dfc6a2a0f706517dd254a85e7b9225d5cc14b2fa2415854adb
```

An exit of 1 with `mount.protected=false` records the observed substitution.
An incomplete run can also return 1. Check both container generations,
the individual probe results, the cleanup status and the recorded failure.
The case uses synthetic credentials. Keep the native evidence and checkpoints
private.

## What would close the blocker

The [operator-owned path rehearsal](linuxserver-operator-paths.md) subsequently tested this boundary. The [production window handoff](minicolab-production-window.md) records its MinicoLab-specific replay.

The next isolated design must give the operator exclusive control of both the
path hierarchy used to find MCP and the effective configuration that chooses
app roots. Repeat both substitution attempts and test ordinary signed app
updates under that layout. Production also requires an identified maintenance
writer and a coordinated, restorable backup of the intended host.
Public HTTPS, end-to-end Inspector, hosted ChatGPT and human review remain
pending. This trial ran entirely on devbox.
