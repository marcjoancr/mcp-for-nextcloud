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
