<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L0 renewal for the M4 candidate

Subsequent status: [L2 with the renewed profile](fork-linuxserver-l2-current-candidate.md)
has passed fixture/B0 qualification after L1. For this renewed profile, candidate
upgrade/recovery remain pending; the phase-end statements below retain their
original scope.

Date: 2026-10-30. **L0 input renewal passes; no new L1-L4 execution occurred.**
The owner authorized profile/kit binding, pin verification and offline controls
following [M4 reconciliation](fork-platform-m4.md). This change starts from runner
revision `2a728a9bc708a934cd058633f6f4dce9e2ee4a40` on the existing worktree.
No application implementation, image layer, container, intended server, public
endpoint, push, deployment or PR #14 change is part of this renewal.

## Candidate and profile identities

Only `candidate.sourceCommit` and `candidate.manifestSha256` changed in the
[profile](../../../mcp/tests/Integration/ci/linuxserver/profile.json). App version,
image/app/baseline pins, strict schema, resources and isolation are unchanged.

| Input | Accepted identity |
| --- | --- |
| Application | `mcp` / `2.0.0` |
| Frozen source commit | `efb27979b6a90122ce45a6a509e52390030f7ed9` |
| Trusted kit manifest SHA-256 | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |
| Runtime archive SHA-256 | `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297` |
| Renewed profile SHA-256 | `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9` |
| Prior U5 profile SHA-256 | `743f2704669f259cc97810771a5bea58722871f9a13962bb5beae33b54d4cc63` |

The existing five-file kit at `mcp/build/source/efb27979b6a90122ce45a6a509e52390030f7ed9/`
passes the canonical verifier. It was neither rebuilt nor relabelled. Its 975-file
runtime identity, downloaded-source reconstruction and Apache acceptance remain
those recorded in M0-M4. The runner/profile revision is distinct from the frozen
application source; this test-profile change does not replace the accepted kit.
Historical U5 kits, indexes and checkpoints retain their own identity.

## Fresh external inputs and retained baseline

At **2026-10-30T13:32:09.631115+00:00**, all nine input checks passed:

- LinuxServer Nextcloud 35.0.2, PostgreSQL 16.15 and SeaweedFS 4.48: fresh OCI index
  and platform-manifest bytes matched both locked digests. Each index contains
  the selected unique linux/amd64 member. No image layers were fetched or run.
- Fresh official Calendar 6.6.3, Contacts 8.9.2, Deck 1.19.0, Notes 6.1.0 and Talk 25.0.6
  archives matched pinned sizes and hashes. XML IDs, versions and declared
  Nextcloud/PHP bounds matched the profile. This is static admission evidence.
- The retained upstream 1.2.0 archive matched
  `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801`,
  1,280,730 bytes and all **532 files** in its trusted inventory. Inventory SHA-256
  remains `a120cbe11cf0cb838d1f8a0cc37799459a0c15c9124f7e48d5c353eb624940bf`.
  No baseline rebuild or substitution with the differently packaged Apache input
  occurred. A verified copy is retained with this renewal's evidence.

Full references and platform digests remain in the profile; timestamped public
retrieval results are in `pin-verification.json`. Availability is an observation,
not a guarantee for a later run, publisher-signature verification or a scan of
vulnerabilities in image layers.

During the 2026-10-30 renewal review, the [Nextcloud schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule)
listed 35.0.2 as the current 35 patch, and the
[PostgreSQL policy](https://www.postgresql.org/support/versioning/) listed 16.15 as
current in supported major 16. SeaweedFS had later releases
[4.49](https://github.com/seaweedfs/seaweedfs/releases/tag/4.49),
[4.50](https://github.com/seaweedfs/seaweedfs/releases/tag/4.50) and
[4.51](https://github.com/seaweedfs/seaweedfs/releases/tag/4.51). Their notes include
OIDC replay handling, S3 authorization/metadata behavior and storage fixes; they
are not silently ignored or described as irrelevant to production.

**Selection decision:** retain exact 4.48 for this candidate-only, synthetic,
isolated comparison. The fixture has an internal network, no published ports or
runtime egress and one full-privilege S3 identity, with no configured anonymous
identity, OIDC provider or multi-tenant IAM claim. Its object inventories and
restore checks must still catch data loss; historical success cannot waive them.
The later releases do not establish that 4.48 is safe for other deployments.
Changing SeaweedFS would require a separately reviewed profile/runtime delta;
this renewal is not a latest-version selection or production security approval.

## Offline planning and negative controls

Before the profile edit, the real current kit was refused with
`Trusted manifest digest does not match profile` and exit 1. After the two-field
change, the same kit passes the canonical Composer plan with exit 0:

```sh
composer --working-dir=mcp test:integration:linuxserver -- --plan \
  --kit /absolute/path/to/source/efb27979b6a90122ce45a6a509e52390030f7ed9 \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289
```

Output binds the profile hash and exact candidate above, with
`validationScope: "profile-and-source-kit"`. `executionAvailable: true` and
`availablePhases: ["L1", "L2", "L3", "L4"]` describe the existing runner's
capabilities; they do not mean a phase ran or was authorized. Bare invocation
without `--plan` or an explicit phase remains invalid. The planner's historical
`inspector-14-of-15` pending token refers to the earlier incomplete journey;
[encrypted-provider repair](inspector-storage-resolution.md) does not close
end-to-end Inspector acceptance.

A separate real-kit control run passed one valid plan and six expected refusals:
wrong trusted digest, wrong candidate commit, published-port profile, mutable
image, altered kit member and invocation without `--plan`. Each refusal exited 1
with the expected diagnostic and no success output. Docker/SSH/download tripwire
executables were never called. The deliberately altered kit is a separate local
negative-test copy, never the accepted candidate.

Existing automated checks also pass with Node 24.22.1 / npm 11.19.1 and PHP 8.4.27:

| Check | Result |
| --- | --- |
| PHP planner boundary suite | 24 tests / 194 assertions |
| LinuxServer Node unit controls | 42 passed, zero skips/failures |
| Source delivery/integrity controls | 23 passed, zero skips/failures |
| Canonical frozen-source verification | Exit 0 |

No test expectation was relaxed. The full application/native matrix was not
repeated for this profile-only change. New runtime resource/preflight checks and
installed-image assertions remain required before native qualification.

Fresh documentation/package validation passes: **188 Markdown files and
115 package sources**, with all **100 translations still `review-required`**.
The generated working-tree archive is a dirty development build with source offer
disabled; it does not replace the frozen kit used by the plan. `git diff --check`
also passes. No application or runtime-runner implementation changed.

A fresh-context read-only review found no actionable findings in the seven-file
renewal diff. It checked the exact two-field profile change, kit identity,
55 indexed records, retained image/app/baseline inputs, control logs and evidence
permissions. It did not repeat external queries, tests or builds, rehash historical
checkpoint payloads or qualify native restoration/runtime behavior. The review
supports L0 input renewal only and does not supply human acceptance.

## Evidence, continuity and stop point

Ignored private evidence is under `mcp/build/linuxserver-l0-m4/`. The sealed
`evidence-index.json` binds **55 files**, including before/after profiles,
registry manifests, app archives/XML, baseline/inventory, the expected pre-edit
refusal, positive/negative controls, test logs and source verification.
Index SHA-256: `9076f318713d94f5ed03f257b8d8c0ecaabc45a19c3c665152df7bf37e6d4241`.
Documentation/package validation and final review records are retained separately.
The directory is mode `0700` and non-executable evidence files are `0600`. No previous
evidence was overwritten or deleted; no production inputs or credentials were
used. This evidence is not an approved public release bundle.

**No L0 input blocker remains.** The next separately authorized phase is renewed
L1 baseline qualification with this exact profile and kit. Its accepted run must
precede fresh B0 capture and the necessary candidate upgrade/source/recovery
checks. Reuse the existing implementation, not the old U5 acceptance labels.
Old B0/B1 manifests bind the U5 profile hash and cannot be relabelled as checkpoints
for this candidate. Verify retained payload/private-input contracts before any
historical reuse; this renewal neither restores nor rehashes those payloads.

Current-candidate LinuxServer runtime acceptance, intended-host/S3/update/recovery
qualification, final source publication/history review and F3 remain open.
**Public HTTPS, Inspector end-to-end, hosted ChatGPT and human security,
operations/recovery, license/attribution, accessibility and language reviewers
remain pending.** Private-fixture planning cannot close those gates.

## Review before commit (2026-10-30)

The review reverified all 55 sealed records, the exact two-field profile delta,
three retained OCI index/platform pairs, five app archives/XML declarations,
the 532-file baseline and the complete kit with its 975-file runtime inventory.
No profile correction or kit regeneration was needed. Wording now dates the
maintenance observations explicitly and clarifies version/permission notation.

The real-kit positive plan and six expected refusals passed again without
triggering Docker/SSH/download tools. Planner, LinuxServer unit, release-integrity,
source verification and documentation/package checks were repeated; logs are
retained under `mcp/build/linuxserver-l0-m4/review/`. The additional
`verification.json` has SHA-256
`289ce26df0ac82a65688641281604dcfe68a4da3b74abd9723b71282444b93a0`.
This review checked retained external inputs rather than making another registry
or maintenance-status query; the original timestamp remains the availability
observation, subject to revalidation before execution. Original indexes and
artifacts remain unchanged. No checkpoint restoration or native phase ran.

L1, current-candidate native qualification, HTTPS, Inspector end-to-end, ChatGPT,
intended-host acceptance, publication/history review and human reviewers remain
pending. The local review and commit do not close those gates.
