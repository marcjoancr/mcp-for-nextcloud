<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L0: frozen inputs and offline planning

L0 was implemented on 2026-10-24 from `463cb8a` on the isolated `fork/main`
worktree. This record covers input selection, offline guardrails and local checks.
**No LinuxServer fixture was started. L1-L5 and F3 remain open.** The intended
server was neither contacted nor modified; no push, PR action or deployment was
performed. See the [reviewed test plan](fork-linuxserver-upgrade-recovery-plan.md).

## U5 follow-up scope (2026-10-27)

This is historical L0 evidence for `0000d8c8`, not LinuxServer qualification of U5.
The [updated plan](fork-linuxserver-upgrade-recovery-plan.md#u5-evidence-and-immediate-next-batch)
reopens candidate-kit/profile qualification for published `940c3da3` before L1.
That review left the profile unchanged. The authorized
[U5 renewal below](#l0-u5-renewal-2026-10-27) now supersedes its candidate pin
and offline planning result; it does not certify a LinuxServer runtime. HTTPS,
Inspector, ChatGPT and human reviews remain pending.

## Frozen inputs and evidence

The canonical machine-readable lock is
[`profile.json`](../../../mcp/tests/Integration/ci/linuxserver/profile.json).
It records full image index/platform digests, app archive hashes and declared
compatibility, baseline package identity, candidate identity and resource ceilings.
The following table and original validation describe historical L0; the current
candidate identity is recorded in the U5 renewal below.
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

## L0-U5 renewal (2026-10-27)

The owner authorized the reviewed input refresh only. **L0-U5 passes; L1 has not
started.** The execution checkout is based on `59d602a1` with only the two
candidate fields in the profile and the related documentation changed. The frozen
application source is **`940c3da36f0d9c5310a98d7c3f355d2d99c86d33`**, separately from this
harness/documentation revision. The initial refresh left these changes uncommitted;
the subsequent owner-authorized review records only the local profile/documentation
commit. No push, production access, deployment, PR #14 action, image-layer pull or
container operation is part of either task.

### Frozen U5 kit and profile

The canonical `composer --working-dir=mcp package:source -- 940c3da36f0d9c5310a98d7c3f355d2d99c86d33`
built the complete kit at `mcp/build/source/940c3da36f0d9c5310a98d7c3f355d2d99c86d33/`.
The build used Node **24.21.0**, npm **11.19.0**, PHP **8.4.27**, Composer **2.8.8**,
Git **2.47.3** and GNU tar **1.35**, with the selected commit's build scripts and
locked dependencies. Production packaging passed with **115 documentation sources**.
The metadata/archive verification scripts used Python **3.13.5**.
No dependency locks, publisher signatures or app version were changed.

The trusted manifest digest was captured from that build and then independently
supplied to `verify:source` and the planner; it was not replaced after tampering.
Keep this digest outside the writable kit directory:

- Source-kit manifest SHA-256: `2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63`.
- Refreshed profile SHA-256: `743f2704669f259cc97810771a5bea58722871f9a13962bb5beae33b54d4cc63`.
- Runtime identity: `mcp` / **2.0.0**, source commit `940c3da36f0d9c5310a98d7c3f355d2d99c86d33`.
- All **975** runtime regular files match the manifest's sizes and hashes;
  `appinfo/source.json` identifies that exact commit/version. No links, duplicate
  paths or special runtime entries were accepted.

| Kit member (in addition to `manifest.json`) | Bytes | SHA-256 |
| --- | --- | --- |
| `BUILDING.md` | 1,549 | `f996079aca59a66d86f9e39c04dfa3e33734e469a1c740e75f9441bd7237ca76` |
| `mcp-2.0.0.tar.gz` | 1,415,359 | `b48ba9054beffe923ccd7019aef79bca8fd863304bebae7b635b229d5f5aeaa9` |
| `runtime-dependencies.tar.gz` | 133,164 | `2f6f748dfccaeeff0a35170b0b662e13596a6b68e5b189bb3d1ab4c1822fe35e` |
| `source.bundle` | 9,210,398 | `1acd88d3f6ad580d1c35cd7441731328f0240aa6db696654464d1ff1bdbc9815` |

The complete five-file kit passes the existing verifier. It remains a local,
unpublished candidate: the Git bundle retains reachable source history and has
not acquired human historical-content or release-publication approval. This batch
checks the kit/package inventory, not independent reconstruction or a non-admin
runtime download on LinuxServer; those acceptance gates remain separate.

### Baseline and external pins

Fresh verification at **2026-10-27T19:23:14.759755+00:00** passed all nine inputs:

- The retained 1.2.0 archive has SHA-256
  `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801`,
  **1,280,730 bytes**, and **532** runtime files. Every archive file matched the
  retained inventory, whose SHA-256 remains
  `a120cbe11cf0cb838d1f8a0cc37799459a0c15c9124f7e48d5c353eb624940bf`.
  Both were copied into this worktree's ignored evidence directory after checking.
  No baseline rebuild, substituted Apache archive or baseline-pin change occurred.
- Freshly fetched OCI index and linux/amd64 manifest bytes for **LinuxServer
  Nextcloud 35.0.2**, **PostgreSQL 16.15** and **SeaweedFS 4.48** matched both locked
  digests. Each index selected the expected unique linux/amd64 entry. Only registry
  metadata was downloaded; no layers were pulled and no image was started.
- Fresh official archives for **Calendar 6.6.3**, **Contacts 8.9.2**, **Deck 1.19.0**,
  **Notes 6.1.0** and **Talk 25.0.6** matched their pinned sizes and SHA-256 hashes.
  Their XML IDs, versions and declared Nextcloud/PHP bounds matched the lock.
  This does not certify installed binaries or native app compatibility.

All image/app/baseline values, resource limits and isolation settings remain
unchanged. Only `candidate.sourceCommit` and `candidate.manifestSha256` changed.
Image availability is an observation at the stated time, not a guarantee for L1;
L1 must verify downloaded layers and live runtime prerequisites before starting.
No new security approval is inferred from digest equality.

### Offline checks and evidence

Before editing the profile, the real U5 kit was correctly refused with
`Trusted manifest digest does not match profile` (exit **1**). After the two-field
refresh, the same canonical planning command passes (exit **0**) with the correct
U5 identity, `validationScope: "profile-and-source-kit"` and
`executionAvailable: false`.

The existing boundary suite passes **23 tests / 165 assertions**. A separate
seven-case check used the real kit and controlled local copies: one valid plan
passed; wrong trusted digest, wrong candidate commit, a published-port profile,
a mutable image, an altered kit member and invocation without `--plan` each
returned exit **1** with its expected error. Tripwire Docker/SSH/download
executables were never called. Expected refusals are retained as negative evidence,
not hidden failures or successful native runs. No existing test was weakened.

Evidence is under ignored `mcp/build/linuxserver-l0-u5/`: `kit-build.log`,
`kit-verify.log`, `kit-inventory-verification.json`, `pin-verification.json`,
registry metadata and downloaded app archives, the baseline archive/inventory,
`profile-before.log`, `plan.json`, `plan-command.log`, `planner-tests.log` and
`actual-kit-controls.json` with per-case output under `controls/`. The altered kit
there is a deliberately invalid test copy, never the selected kit. These local
paths are not public downloads or durable release publication.

### Final validation and independent review

Documentation validation passes **173 Markdown files / 115 package sources**;
all **29 documentation-tool tests** and **11 release-tool controls** pass.
Translation provenance is valid, with **100 drafts still review-required**.
`git diff --check` passes. The frozen kit build already validated its production
package; this profile/docs-only refresh did not rerun the full application or
native matrix and does not claim a new runtime result.

One independent read-only review reported **zero Critical, Important or Minor
findings**, accepting L0-U5 only. It recomputed kit/profile/member hashes, checked
all 975 runtime files and the packaged identity, all three retained OCI pairs,
five app archives/XML metadata and the baseline's 532-file inventory. It checked
the recorded planner/control results; it did not make another live registry
availability query or independently reconstruct the source bundle.

Live resource enforcement, private TLS/admission/cleanup, snapshot coherence,
credential resurrection, startup/FPM behavior and intended-host compatibility
were outside that review: their assertions belong to L1-L5 and cannot be inferred
from static input hashes. Independent source reconstruction/history/publication
acceptance and public HTTPS/Inspector/ChatGPT/human approval also remain separate
gates. Treating any of those as passed would falsely qualify a deployment.
No review finding was deferred; the above limits do not waive later acceptance.

### Pre-commit verification of the renewal

The subsequent review reverified the retained kit against the recorded trusted
digest, its 975 runtime files and packaged identity, the baseline's 532 files,
all three retained OCI index/platform pairs and five app archives/XML metadata.
Only the two candidate fields differ from the previous profile; the recorded
profile hash remains exact. No pin correction or kit rebuild was needed.

Fresh checks pass: 23 planner tests/165 assertions, 11 release controls, the real
U5 plan and all six expected negative controls, 29 documentation tests, document
links/provenance and `git diff --check`. The 100 translation drafts still require
human review. Evidence is retained separately under
`mcp/build/linuxserver-l0-u5/review/`, including `retained-evidence.log`,
`verify-source.log`, `planner-tests.log`, `release-tests.log`, `controls.log` and
per-case output. These checks read the previously downloaded inputs; they are
not another registry-availability observation. The timestamped external checks
above remain the availability evidence, subject to L1 revalidation.

### Remaining gates and next action

No L0-U5 input blocker remains after the above checks. The next bounded task is
**implement and validate L1** from the reviewed plan; execution is still rejected
by the current runner. L1 must establish resource availability/enforcement, pinned
image runtime behavior, Nginx/FPM identity and private TLS, PostgreSQL/S3 persistence,
closed/probe-only admission, source serving and owned cleanup. L2-L5 must still
prove coherent checkpoints, actual upgrade and both complete recovery paths.

The intended host's exact app bytes, active database target, FPM/startup hooks,
update writers and real S3/backup/recovery behavior remain unqualified. The local
S3 fixture does not reproduce an unknown production provider. Trusted release
publication/source delivery and final F3/F4/F5 acceptance remain open.

**Public HTTPS, Inspector's 14/15/encrypted-storage result, real ChatGPT flows and
human security, operations/recovery, license/attribution, accessibility and
language reviews remain pending.** Automated input checks do not replace them,
and private fixture trust would not certify public HTTPS. No real fixture or
server change was made by this input refresh.
