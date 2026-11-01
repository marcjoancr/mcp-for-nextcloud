<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# F3: local delivery, update control and integrity

This guide describes source delivery and update-integrity controls for a self-hosted
Nextcloud app. It contains reusable engineering guidance; it does not certify any
particular deployment or release.

## Historical local source kit

A source kit should bind one reviewed revision to its installable app archive,
source bundle, build instructions, dependency lock data and a manifest of runtime
files. Keep these inputs together so an operator can verify the offered source and
the installed tree against the same revision.

Development packages and release kits have different provenance. Do not substitute
a package rebuilt from an arbitrary working tree for a previously reviewed kit.

## Recommended update-control design

Treat every mechanism that can replace app files as a writer: app-store updates,
installation or reinstallation, command-line maintenance, container recreation,
mounted volumes, deployment automation and privileged filesystem access. Choose a
single reviewed update path and test that competing paths cannot replace the
selected app while normal signed updates for unrelated apps continue to work.

A catalog filter alone does not protect writable files from another writer. A
read-only mount protects only the paths and ancestors that are actually sealed.
Verify the effective app root, aliases and parent directories, including after
startup and container recreation. Keep maintenance access separate from the web
service identity where the platform supports it.

## Integrity procedure and its limits

Before installation, verify the source-kit signature or independently recorded
manifest digest. After installation and each approved update, compare the runtime
file inventory to that manifest and fail closed on missing, changed or unexpected
files. Verify the file tree actually selected by the application, not only the
staging directory.

Integrity checks establish file identity at the time of comparison. They do not
prove that the database migrations are correct, that all writers are controlled,
or that the application will start successfully. Re-run the checks after restart,
redeployment and recovery.

## Technical source-distribution audit

Record the source revision, runtime inventory, dependency provenance and package
reconstruction steps needed for an independent reviewer to reproduce a delivery.
Keep local logs, private fixture state, credentials and host-specific inventories
out of public documentation and release archives. Public release hashes may be
published when they identify downloadable artifacts and are independently
reproducible.

## Proposed isolated LinuxServer upgrade and recovery qualification

For a LinuxServer deployment, first qualify the selected image and its startup
hooks in an isolated fixture. Exercise the normal serving identity, an isolated
maintenance identity, the configured app path, and all supported update paths.
Repeat the checks after container recreation and after updating a signed companion
app.

Seed a disposable baseline, upgrade it, then restore the coordinated app,
configuration, database and object-store state in isolation. Check migrations,
application availability, access policy and client reconnection before treating a
recovery procedure as qualified. A synthetic fixture demonstrates only the tested
fixture behavior; it does not establish production backup or restore readiness.

## Platform/source plan and Inspector storage resolution

Client acceptance tooling and runtime support matrices should be qualified
independently. Pin the client version and lockfile, use disposable encrypted
storage, and test persistence, rotation, deletion and cross-resource isolation.
A client test result is evidence about that client configuration only; it does
not certify a hosted service or a production endpoint.
