<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L0: frozen inputs and offline planning

L0 was implemented on 2026-10-24 from `463cb8a` on the isolated `fork/main`
worktree. This record covers input selection, offline guardrails and local checks.
**No LinuxServer fixture was started. L1-L5 and F3 remain open.** The intended
server was neither contacted nor modified; no push, PR action or deployment was
performed. See the [reviewed test plan](fork-linuxserver-upgrade-recovery-plan.md).

## Frozen inputs and evidence

The canonical machine-readable lock is
[`profile.json`](../../../mcp/tests/Integration/ci/linuxserver/profile.json).
It records full image index/platform digests, app archive hashes and declared
compatibility, baseline package identity, candidate identity and resource ceilings.
Public registry/API queries and downloaded release archives were verified locally;
raw inputs and private command results are under `mcp/build/linuxserver-l0/` and
are not committed. These hashes bind the observed bytes, not publisher signatures
or a vulnerability assessment.

| Input | Selected and verified in L0 |
| --- | --- |
| Nextcloud | LinuxServer `version-35.0.2`, index `sha256:e7f790011bf023d33517b4be70231d7aa2e19d54c619664a8533e8bedd4716ac`, linux/amd64 manifest `sha256:27b2b4072c8de66b0d2909b98d9f8f18cd754880369325007aed6ee984b674d4` |
| PostgreSQL | Reference `postgres:16.15`, index `sha256:ca0bd484cb98bf4b24eb1010e73fb3fcbd6714d240fbc1a10eea5b7dbecb641d`, linux/amd64 manifest `sha256:75adc2a65806c96bc0e59030230bfe3f1c1b0d6d8f8181a8913e2b8b53bdc166` |
| SeaweedFS | Standard `chrislusf/seaweedfs:4.48`, index `sha256:4e61d15fd35994cb1e43e1e553dff106794841fd9a99ade2fc8c8bfce4d7872d`, linux/amd64 manifest `sha256:aba492e2a4e4c90bff795745e8e660affa1f09e7650f5981bd7bccd1a06cd931` |
| Calendar / Contacts | 6.6.3 / 8.9.2; official release archives declare NC32-35 / NC33-36 and PHP up to 8.5 |
| Deck / Talk | 1.19.0 / 25.0.6; official archives declare NC35; these manifests do not add PHP bounds |
| Notes | 6.1.0; official archive declares NC33-36 and PHP up to 8.5 |
| Baseline | Upstream commit `02526119b845ccc1d8399be002da3e30b5527df6`, built with its original `mcp/scripts/package.sh` and locked production dependencies; package check passed |
| Candidate | Existing audited kit for `0000d8c807cfae300c2009ce53f9efb4a5df8081`, trusted manifest `032b62b39bd9c56055bb3306b4a06c12efb77f876ea6da0087a550a89fc39e6f`; complete kit verified by the real planning command |

The baseline archive hash is
`dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801`.
Its extracted regular-file inventory has **532 files**; the retained JSON inventory
hash is `a120cbe11cf0cb838d1f8a0cc37799459a0c15c9124f7e48d5c353eb624940bf`.
Archive timestamps may change on rebuild: a replacement archive needs its own
reviewed digest and content comparison, not silent acceptance under this pin.
The retained candidate is unchanged; there is no new kit for the harness revision.

OCI index and selected platform manifest bytes were hashed against their digests.
No layers were pulled and no image was run: installed binaries, FPM configuration,
SQL connection and actual S3 behavior still belong to L1. The database reference
is not the host's observed Alpine variant or proof of the production connection.
App XML bounds are a static admission check, not native compatibility acceptance.

### SeaweedFS selection and storage contract

The official [4.49 release](https://github.com/seaweedfs/seaweedfs/releases/tag/4.49)
exists, but the standard Docker tag returned 404 during L0; the tag search exposed
only a FoundationDB large-disk variant. The standard
[4.48 release](https://github.com/seaweedfs/seaweedfs/releases/tag/4.48) image was
available and its immutable manifests were verified. This is an explicit fixture
selection, not a claim that 4.48 is the latest release, a silent fallback, or a
security approval. No development tag, `latest` image or additional FoundationDB
service was selected. Future pin changes require renewed review and evidence.

The reviewed 4.48 source defines the combined server and S3 configuration in
[`server.go`](https://github.com/seaweedfs/seaweedfs/blob/4.48/weed/command/server.go)
and [`s3.go`](https://github.com/seaweedfs/seaweedfs/blob/4.48/weed/command/s3.go).
Its [Docker filer configuration](https://github.com/seaweedfs/seaweedfs/blob/4.48/docker/filer.toml)
uses LevelDB2 under `/data/filerldb2`. The lock requires persistent master, volume
and filer state, one node, required authentication, no anonymous identity and an
unversioned exclusive bucket. Bound physical volume growth to one at a time,
512 MiB per volume and four volumes; keep total seeded objects at most 256 MiB.
The [S3 guide](https://github.com/seaweedfs/seaweedfs/wiki/Amazon-S3-API)
describes per-bucket volume growth configuration. L1 must apply and verify it;
the numeric profile fields alone do not enforce running-server limits.

L2 will export the quiescent bucket through authenticated S3 APIs: enumerate all
keys with pagination, capture object metadata, download every payload, record size
and SHA-256, and confirm there are no unfinished multipart writes. Restore into an
empty owned bucket, recreate payloads/metadata and compare the complete inventory
before admitting Nextcloud. Combine this with the same generation's database,
config/apps and local data. Do not treat raw SeaweedFS volume files or an S3 ETag
as a sufficient backup/hash. S3 version history, retention locks and production
key-management recovery are outside this unversioned fixture's qualification.

The source-kit endpoint will use a separate read-only static location in the same
pinned LinuxServer Nginx, with fixture-only TLS and access limited to the test
network. This avoids another unpinned serving image. Authentication remains on MCP
and settings routes; complete source artifacts themselves require no MCP grant.
No production Nginx route is configured by L0.

## Implemented interface and safety boundary

From the repository root, replace the two placeholders with the absolute kit
path and its independently trusted full manifest digest:

```sh
composer --working-dir=mcp test:integration:linuxserver -- --plan \
  --kit /absolute/path/to/reviewed-kit \
  --manifest-sha256 TRUSTED_MANIFEST_SHA256
```

`--profile ABSOLUTE_FILE` optionally supplies a reviewed profile. The default is
the committed profile. Relative paths, duplicate/unknown options and invocation
without `--plan` fail. **There is no execution mode yet.**

`run-local.sh` only delegates to `plan.mjs`. `profile.mjs` owns the bounded,
strict profile schema; it rejects unknown fields rather than allowing arbitrary
commands, existing container names, endpoints, credentials or mounts. Images need
versioned references with full digests and an explicit linux/amd64 manifest pin.
App identities, release URLs, versions and NC35 compatibility are checked. Public
ports, runtime egress, production inputs, excessive budgets and invalid types fail.
Unreadable, symlinked, oversized and special profile files are refused.

The planner reuses the existing release verifier for all supplied kit file hashes
and checks candidate version/commit against the profile. The command's trusted
digest must also match the profile. It requires the complete expected kit file set;
it does not rebuild bundles or execute any supplied source. Verification errors
remain nonzero and public diagnostics omit input values and private local paths.

Success emits JSON with `validationScope: "profile-and-source-kit"`, the profile
byte hash, resolved inputs and `executionAvailable: false`. The caller must trust
its selected profile and the independently supplied kit digest: syntactic digest
validation is not a trust authority. Image/app availability and downloaded archive
bytes are **not rechecked by this offline command**; L0 records those separate
checks above. The pending list retains execution preflight and native qualification.

The profile encodes the reviewed ceilings: 4 CPUs, 8 GiB memory, 30 GiB disk budget,
40 GiB minimum free disk, 60-minute run and 5-minute readiness deadlines. L0's local
disk check exceeded 40 GiB; actual enforcement, resource allocation and fresh host
checks remain mandatory before L1. No resource budget is a production capacity
claim. The app store, source endpoint, admission gate and container lifecycle have
not been implemented or changed by this batch.

## Persisted-state expectations for later phases

The selected baseline and candidate have identical `mcp/lib/Migration/` trees.
L3 must compare the real installed migration ledger, not infer success from a
version string or manufacture a new SQL migration. Expected intentional differences
are app version/packaged identity, fork implementation layout and configured source
offer. No selected migration authorizes loss or widening of existing MCP state.

Compare policy state/events, user and group grants, resource scopes, exclusions,
filename settings, OAuth client registrations, code/token/spent state and personal
preferences before any probe consumes or mutates them. Include checkout tokens,
file batches and log permissions as applicable. Separate intentional probe writes
and native version bookkeeping from the preserved-state comparison. Existing
capability record IDs/content and native permissions remain part of L2/L3 evidence.
Global recovery closure changes authentication generations intentionally; it must
not erase restrictions. The 300-second code deadline and stateless confirmation
contract in the reviewed plan remain mandatory for R0/R1.

## Validation results

The focused CLI boundary suite passes: **23 tests / 165 assertions**. The initial
run failed before implementation; the positive plan and specific negative-path
assertions then passed. Profile additions for fixed storage settings were likewise
rejected before support was added. Tests cover mutable/unsafe image references,
unknown fields and resource selectors, incompatible app metadata, invalid resource
limits, missing/duplicate options, trusted-digest and candidate-identity mismatch,
altered kit files and oversized/symlinked profiles. Tripwire executables prove the
exercised paths call no Docker, SSH or download tools; a successful run leaves no
new fixture files or resources.

The actual audited candidate kit also passes `--plan`. Its final profile SHA-256
is `0d450a741dfec6f40804d653cd8d237cf56d42ec2ce7e2c3679c0db91a50e527`.
The output explicitly reports offline scope and unavailable execution. Syntax
checks pass for the two Node modules and shell entry point. A final read-only
review independently checked the diff, real plan/refusals, three OCI pairs, five
app archives and baseline inventories; it found no actionable issue. It did not
execute native probes or replace the suite evidence.

The full catalog `devbox run check` passed with exit **0**: **8,451 PHP tests /
71,806 assertions**, **690 browser tests**, 29 documentation tests, 10 client-runner
unit tests and 11 release-tool tests. Syntax validation checked **1,291 PHP files**;
both static-analysis scopes passed. Documentation validated **155 Markdown files /
110 package sources**, and packaging passed. All **96 translation drafts remain
review-required**. The three expected `missing.js` diagnostics came from deliberate
browser build-failure tests; their errors were retained and the suite passed.
The resulting working-tree package is a dirty development build with source offer
unavailable; positive kit acceptance still uses the separate frozen candidate.
No native Docker suite or real client campaign was executed by this check.

## Remaining gates

- **L1-L5:** live LinuxServer/S3 bootstrap, real 1.2.0 → 2.0.0 upgrade, both full
  restores, negative cases, private Nginx TLS/admission and owned-resource cleanup
  are unimplemented and unexecuted. No native acceptance is claimed by `--plan`.
- **Execution prerequisites:** recheck pins/availability, permissions, tools, free
  resources and image behavior before any authorized run. The known 4.49 standard
  tag gap is recorded, not hidden by fallback at execution time.
- **Intended server:** installed 1.2.0 bytes/migrations, real DB target, FPM details,
  initialization hook, updater writers, S3 provider and backups remain unqualified.
- **F3/F4/F5:** final artifact/matrix, source-history/publication suitability,
  trusted distribution and production recovery approval remain separate gates.
- **HTTPS:** no public DNS/certificate/ingress or hosted-client acceptance.
- **Inspector:** prior **14/15** nonzero result and external encrypted-storage
  finding remain pending; L0 neither reruns it nor infers a new server disclosure.
- **ChatGPT:** real hosted consent, tools and revocation remain untested.
- **Human reviewers:** security, operations/recovery, license/attribution,
  accessibility and language review remain unassigned/pending. The owner's
  operational responsibility does not replace these reviews.

L0 does not require human steps to run its automated checks. Human sign-off is
retained as a separate gate, not presented as an automated result.
