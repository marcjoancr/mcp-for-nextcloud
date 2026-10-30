<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L1 with the renewed candidate profile

Subsequent status: [L2 for the renewed profile](fork-linuxserver-l2-current-candidate.md)
now passes fixture/B0 qualification. L3 remains unstarted for that profile;
the phase-end statements below retain their original scope.

Date: 2026-10-30. **L1 passes; L2 has not started.** The existing runner executed
one new isolated baseline qualification under the [renewed L0 profile](fork-linuxserver-l0-current-candidate.md),
following the [L1 plan](fork-linuxserver-upgrade-recovery-plan.md#l1--bootstrap-the-isolated-linuxserver-profile).
No application, runner or profile changes were required. No push, deployment,
production access or PR #14 operation occurred. Public HTTPS, Inspector,
hosted ChatGPT and human reviewers remain pending.

## Exact inputs and execution

| Input | Identity |
| --- | --- |
| Runner revision | `0a22e5133b0e4d1a7364050924e3cba188efd1bb`, clean at execution |
| Profile SHA-256 | `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9` |
| Frozen candidate source | `efb27979b6a90122ce45a6a509e52390030f7ed9` / app 2.0.0 |
| Trusted kit manifest SHA-256 | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |
| Installed baseline | Upstream 1.2.0 at `02526119b845ccc1d8399be002da3e30b5527df6` |
| Baseline archive SHA-256 | `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801` |
| Native run | `mcp-l1-eb09fd94-5385-433c-a3d2-3dbb6d584a2a` |

Immediately before execution, all nine input checks passed: fresh registry
metadata for the three locked OCI index/platform pairs, fresh official archives
for five apps with their XML bounds, and the retained baseline's 532-file inventory.
`pin-verification.json` records the observation at
`2026-10-30T21:54:46.235154+00:00`.
The local pinned images were already present with matching linux/amd64 identity;
no image layers were pulled. Resource preflight found no LinuxServer-labelled
resources and sufficient host CPU/memory capacity and the required free disk.
These observations are not a production capacity assessment.

The canonical `--plan` passed before explicit execution. The same kit and trusted
digest were then supplied to:

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L1 --inputs ABSOLUTE_VERIFIED_INPUT_DIRECTORY \
  --kit ABSOLUTE_FROZEN_KIT_DIRECTORY \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289
```

The fixture installed **MCP 1.2.0**, not 2.0.0. It served the exact 2.0.0 kit only
through a private integrity endpoint. The baseline did not advertise it as its
own source. `upgradePerformed: false` and `checkpointsCreated: false` remain
explicit in the result. The candidate was not rebuilt or replaced by a development
archive. Old U5 evidence/checkpoints retain their original profile and identity.

## Native acceptance

The command exited **0**, the runner reports **passed**, and all **26 named
acceptance stages** completed in order. These stages include compound native and
HTTP assertions; they are not 26 PHPUnit cases or the full application matrix.
Elapsed execution was **159.101 seconds**, including cleanup.
No failed attempt or diagnostic rerun occurred in this renewal.

| Area | Observed result |
| --- | --- |
| Nextcloud and database | NC 35.0.2, installed and no pending DB upgrade; PostgreSQL 16.15 |
| CLI and PHP-FPM | Both PHP 8.5.12; FPM SAPI `fpm-fcgi`, UID 1000 (`abc`); required extensions present |
| Persistent app location | `/app/www/public/apps/mcp` resolves to `/config/www/nextcloud/apps/mcp`, owned by `abc` with safe permissions |
| Enabled apps | MCP 1.2.0, Calendar 6.6.3, Contacts 8.9.2, Deck 1.19.0, Notes 6.1.0 and Talk 25.0.6 |
| Isolation | Five containers, four volumes and one internal network; no published ports, extra runtime network or mounted Docker socket |
| S3 | Pinned SeaweedFS 4.48; authenticated unversioned bucket; write/list/read/delete and persistence across owned restart pass |
| Primary storage | Nextcloud reads its synthetic file through primary S3; separate local data remains intact across service restarts |
| Source endpoint | Five kit files downloaded and hashes matched; read-only source mount and rejected PUT; no baseline source advertisement |
| Writer/admission control | App store disabled, effective cron tables empty and cron paused; server-level gate remains closed before PHP after restart |

The private CA was explicitly supplied to clients for `nc.fixture.test`. The
admitted client reached status/login; anonymous MCP initialization was denied.
An untrusted certificate and wrong hostname were rejected as TLS trust failures.
The second client was denied, including with a spoofed forwarded-IP header.
This proves private-fixture transport/admission, not public HTTPS or a hosted
client journey.

S3 rejected anonymous and invalid-signature access. Effective storage limits
matched the profile: at most four 512 MiB volumes, growth of one. The runner proved
expected failures for missing object-store environment, wrong app path, missing
probe completion, unavailable database and unavailable S3. Database/S3 recovery
and subsequent app restart restored the permitted health reads while preserving
the admission gate. These are service-restart checks, not checkpoint restoration.

Aggregate allocations remained four CPU cores and 7168 MiB of memory under the
8192 MiB ceiling. Peak measured/reserved disk use was **2,211,606,528 bytes**,
under 30 GiB with the free-space reserve monitored. This is measured admission,
not a filesystem quota or production performance commitment.

## Cleanup and retained evidence

The runner closed admission before cleanup and returned `cleanup: complete`.
Independent successful all-state Docker queries then confirmed that the exact
**five container IDs, one network ID and four volume names**, and this run's
labels, were absent. No broad pruning or unrelated-resource deletion occurred.

The native evidence directory is
`mcp/build/linuxserver-l1/runs/mcp-l1-eb09fd94-5385-433c-a3d2-3dbb6d584a2a/`.
Only `result.json`, `resources.json` and `diagnostics.log` remain there; generated
credentials, certificates and private working inputs were removed by owned-run
cleanup. The directory remains mode `0700`. Result SHA-256:
`7de1c182e5b48a724298f016ae828dae45fea74725274d7fee6ebcb643388c5c`.

The separate ignored `mcp/build/linuxserver-l1-current/` directory contains public
input copies, preflight/plan, exact command, exit status and independent closure
proof. Its sealed `evidence-index.json` binds **30 records**, including
the three native files, with SHA-256
`7d0b66ca89c1a35f5ac58dfe6f54cb9464eae52a5153aeba3eb64d9ace34dead`.
Old L0/M4/U5 artifacts are unchanged. Documentation/package and final review logs
are retained outside this index. Private evidence is not an approved publication
bundle; only the bounded results in this report are intended for documentation.

## Documentation and package validation

Fresh `composer --working-dir=mcp package` passes: **189 Markdown files and
115 package sources**. All **100 translations remain `review-required`**.
`git diff --check` passes. The working-tree package is a dirty development build
with source offer disabled; it does not replace the immutable kit accepted by
planning and retrieved inside L1. No application or runner changes were made.

A fresh-context automated review found no actionable findings in the six
changed documents. It rechecked all 30 indexed artifacts, profile/baseline/kit
identities, the native result and 26 stages, retained cleanup proof, permissions
and the runner assertions behind this report. It did not repeat live Docker or
external availability queries, builds or native execution, inspect historical
checkpoint payloads, or qualify upgrade/restoration, production or hosted-client
behavior. This does not replace the primary session's live cleanup checks or
human acceptance. Final package validation is retained outside the sealed index.

## Remaining gates and next step

L1 closes baseline/bootstrap qualification for the renewed profile only. **L2 is
next and remains unstarted:** seed the prescribed synthetic business records and
capture a fresh coherent B0 under this profile. L3 must then install the current
candidate, verify persistence and non-admin source discovery, and capture B1;
L4 must qualify full restoration/rollback with the matching checkpoints. None of
those claims follows from the private download or service restarts in L1.

The unchanged runner's prior interruption/cleanup unit and historical native
signal tests were not repeated as new signal experiments. No human UI inspection,
full application matrix rerun or production validation is claimed by these 26 stages.
Current-candidate upgrade/recovery, intended-host S3/update/backup acceptance,
source-history/publication review and F3 remain open.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT and human security,
operations/recovery, license/attribution, accessibility and language reviews remain
pending. The local encrypted-provider correction is not Inspector journey
acceptance. No fixture is left running; the intended server was not accessed.

## Review before commit (2026-10-30)

The retained-input review reverified all 30 sealed records, the three OCI
index/platform pairs, five app archives and XML bounds, and all 532 baseline
files. It confirmed the exact profile/candidate identities and the ordered
26 native acceptance stages. This rechecks retained evidence; it does not claim
a new registry-availability observation or repeat native execution.

Fresh successful Docker queries confirmed that the exact five containers, one
network and four volumes, and all run labels, remain absent. Private working
inputs remain removed. The additional proof is retained outside the original
sealed index at `mcp/build/linuxserver-l1-current/review/verification.json`,
SHA-256 `d6fbe3c3cca3006c1eb002c09db6ad67e7169c4f5cb0d0d397bace5bd76a8a01`. Original evidence and its index
are unchanged. Historical status pointers now explicitly scope pending L2 and
upgrade/recovery to the renewed profile. No application or runner correction was
needed; L2 remains unstarted and all external and human gates above remain open.

Review validation also passed the canonical `verify:source` command against the
frozen kit and trusted manifest, and `composer --working-dir=mcp package`
(189 Markdown files, 115 package sources). All 100 translation records remain
`review-required`. Logs are retained as `review/verify-source.log` and
`review/package.log` under the aggregate evidence directory; neither changes
the sealed execution index nor replaces the immutable candidate kit.
