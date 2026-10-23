<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Source delivery and file integrity

Use this workflow to prepare a **local, unpublished candidate** from a reviewed,
committed revision. It complements [production packaging](packaging.md); it does
not publish a release, install an app, sign a Nextcloud package or configure an
updater. See [F3 delivery evidence and deployment gates](fork-f3-delivery.md).

## Build a frozen kit

Use the [development toolchain](setup.md), including Git and tar. From `mcp/`:

```sh
composer package:source -- FULL_40_CHARACTER_COMMIT_SHA
```

Replace the placeholder with a full lowercase commit SHA from this repository.
The command rejects branch names and abbreviated revisions. It executes build
scripts from that commit, so only select a reviewed, trusted revision. Uncommitted
changes do not enter the kit. It fetches that commit into a private local Git
repository, bundles only its reachable history, installs locked npm dependencies
with lifecycle scripts disabled, and runs the commit's canonical `composer package`.
The frozen revision must include source-identity packaging support. Older kits
remain historical evidence; they cannot enable the new runtime source offer.
Packaging may download locked production Composer dependencies. Caches use their
normal per-user locations. No registry update or lock rewrite is performed.

Output: `mcp/build/source/<full-commit>/`, containing:

| File | Purpose |
| --- | --- |
| `source.bundle` | Application preferred source, original JavaScript, build/install scripts, dependency locks, attribution and reachable history needed for documentation provenance |
| `runtime-dependencies.tar.gz` | Complete production vendor tree from the validated package, including PHP source, Composer loader and dependency licenses |
| `mcp-<version>.tar.gz` | Canonically validated installable app for that same commit |
| `BUILDING.md` | Reconstruction steps and limits |
| `manifest.json` | Source commit/version, kit file hashes/sizes and the exact installed app file inventory |

The command prints the manifest SHA-256 after verifying the complete kit. It
refuses an existing destination and cleans its private staging tree on ordinary
success/failure. A process kill may leave a `.prepare-*` staging directory; it is
not a completed kit and must never be distributed. The kit contains no original
repository-local Git config, hooks, unrelated branch history or untracked files.
**Reachable committed history is retained**, including deleted tracked files:
review it for private content and release suitability before publication. No
automatic historical-content clearance is claimed.

Preserve the entire kit together. Runtime dependency sources accompany the bundle;
the bundle alone is not the complete delivery. A clean clone from the bundle can
rebuild the app using the documented toolchain and locked registries or trusted
caches. This is not an offline development-toolchain distribution. Rebuild from
the same detached commit when comparing Composer's Git-derived root metadata.
Tar/gzip timestamps can differ; compare extracted files, not just archive hashes.

## Verify the kit and an installed tree

Obtain the manifest digest through a separately trusted release record or channel.
Do not calculate a new digest from a suspect kit and treat that as approval. Run
these commands from a trusted source checkout on the verification workstation:

```sh
composer verify:source -- /absolute/path/to/kit TRUSTED_MANIFEST_SHA256
composer verify:installed -- /absolute/path/to/kit TRUSTED_MANIFEST_SHA256 /absolute/path/to/mcp
composer test:release
```

The first command checks the trusted manifest digest and every kit file. The second
also compares every installed regular file's SHA-256 and size. Missing/extra files,
changed bytes, symlinks and special filesystem entries fail. Empty directories,
permissions, ownership, ACLs, mount flags and OPcache state are not certified by
this file-content check. Both commands are read-only: no repair, replacement,
permission change, database access or Nextcloud invocation occurs. Test fixtures
exercise snapshot isolation, manifest tampering and installed-tree tampering;
`composer check` includes the complete release-tool test scope.

Use a quiescent, trusted snapshot of the app directory. This verifier is not a
filesystem lock or a defense against an attacker replacing paths concurrently.
It does not certify database/configuration state or protect bytes after the check.
Node/Composer are verification/build tools, not new runtime requirements for the
Nextcloud app. An operator may copy a read-only installed-tree snapshot to the
verification workstation, preserving links/types so unexpected entries fail.

Hashes bind content to a trusted record; they are **not a publisher signature**.
Keep the expected manifest digest outside the writable app directory. Signature
key ownership, trusted publication, OS write controls and native Nextcloud
integrity behavior need separate qualification. Never disable Nextcloud integrity
checks globally or attach an upstream signature to modified files.

## Delivery acceptance before serving users

1. Freeze the final release commit, prepare its kit and record the trusted manifest
   digest outside that kit. Run the complete candidate checks and platform matrix.
2. Review retained history and notices, verify preferred source/dependency
   completeness, clone the bundle and rebuild. Record exact file comparisons and
   any differences without normalizing them away.
3. After publication is authorized, publish the complete immutable kit and its
   trusted digest, with release notes describing independent fork maintenance.
   Verify retrieval without maintainer credentials. Retain each served version's
   source and applicable source-delivery records.
4. Bind the deployed version to that usable destination. Make the source offer
   visible to ordinary personal-settings users and MCP users; test both journeys
   without administrator privileges. A moving repository link or this local kit
   alone does not satisfy this acceptance gate.
5. Qualify update exclusion, native/custom-app integrity behavior and coordinated
   recovery on the intended host before deployment. Human review remains explicit.

No public URL is fabricated and no partially functioning source endpoint is
introduced by the local preparation tooling.

## Runtime source offer

A clean committed package includes generated `appinfo/source.json` with its
source commit and version. The packaging command deliberately omits this identity
for a dirty development checkout. A staged identity supplied by the source tree
is rejected. Do not edit the generated file or a release package after validation.
The kit builder checks that its package identity matches the selected commit.

Configure the operator-controlled destination using the [source-delivery system
setting](../reference/configuration.md#source-delivery).

Use the actual frozen release record. The directory
must serve all five kit files at their original names. The application performs
no server-side URL fetch; it checks the configured identity against its packaged
identity and installed version, then supplies the same links and expected digest
to personal settings and MCP `initialize` / `server/discover` instructions.
A syntactically valid configuration does not prove remote availability or bytes:
retrieval acceptance must independently verify the expected digest and contents.

URLs require HTTPS, with no credentials, query, fragment, encoded characters or
traversal segments. Literal loopback HTTP (`127.0.0.1` or `[::1]`) is allowed only
as a local fixture destination; its configuration stays outside the package.
No test URL may become a release destination. Missing, malformed or mismatched
configuration, or missing package identity, yields an explicit unavailable offer
without links. This state fails source-offer acceptance, not the existing MCP
authentication or connection-policy checks.

Source downloads are static release artifacts and must require no maintainer
credentials. Do not forward Nextcloud credentials to their host. Personal source
access does not require enabling the user's MCP connection. MCP discovery still
requires its existing authentication and connection admission, and neither
adapter adds a Files, Notes or other capability grant. The shared metadata lives
in `lib/Release`; protocol and template adapters only present it.

## Disposable native source acceptance

From `mcp/`, using Docker and the current pinned native reference image:

```sh
composer test:source -- /absolute/path/to/kit TRUSTED_MANIFEST_SHA256
```

This explicit command verifies the kit and inspects the runtime archive before
starting an owned NC35/SQLite fixture. It reuses the native harness, publishes no
ports, leaves production configuration alone and removes only its labeled
container/network on exit. A cleanup inspection error, ownership mismatch or
removal error fails acceptance; success is reported only after cleanup completes.
The run directory is created with mode `0700`, independently of the caller's
umask. Logs and downloaded source evidence remain under
ignored `mcp/build/source-acceptance/`; raw failure logs are private and must not
be published. An interrupted process may leave owned resources: inspect the exact
run labels before any cleanup. Never clean unrelated containers or networks.

The check uses real Nextcloud personal settings and both MCP discovery paths as a
non-admin with Basic and Bearer credentials, varies Files grants, downloads every
kit file without credentials, preserves anonymous/connection denial and checks
unavailable/mismatched metadata. The host verifier checks retrieved bytes and an
intentional tampering probe. Source reconstruction from that retrieved kit is a
separate required acceptance step; a successful download alone does not close F3.
This focused fixture does not run the full native platform matrix, qualify HTTPS,
exercise ChatGPT or replace human review and target-host acceptance.
