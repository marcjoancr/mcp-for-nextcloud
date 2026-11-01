<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Read-only MCP mount on LinuxServer

A read-only bind mount protects the mounted directory from writes through that
mount. It does not establish that Nextcloud loads the files at that path: a
writable parent can be renamed or replaced, and writable configuration can add
another app root. Both changes can redirect app discovery while leaving the
original mount read-only.

## Qualification approach

Use an isolated LinuxServer fixture with synthetic accounts and data. Compare a
writable control with the proposed mount, and perform substitution attempts as
the real serving identity. Check the selected app root and the class loaded by a
fresh PHP process. Recreate the container with the same persistent configuration
to determine whether a prior redirect survives restart.

Test the entire lookup path: mounted directory, parent directories, aliases,
configured app roots and the configuration that selects them. Verify app files
against a reviewed manifest at the source and at the effective install path.
Repeat the checks after restart and after updates to companion apps.

The test must also verify cleanup and closed admission when a fixture fails. A
single failed run is not evidence of a substitution unless the relevant probe
completed; inspect individual results and cleanup status separately.

## What closes the boundary

Give the operator exclusive control of both the path hierarchy used to find MCP
and the effective configuration that chooses app roots. Keep the serving
identity unable to modify either. Test ordinary signed app updates under this
layout and maintain a distinct, approved maintenance path.

A fixture result is not production certification. Production acceptance requires
an identified maintenance writer and a coordinated, restorable backup of the
application, configuration, database and object storage. Use the [operator-owned
path guide](linuxserver-operator-paths.md) for the broader qualification checklist.
