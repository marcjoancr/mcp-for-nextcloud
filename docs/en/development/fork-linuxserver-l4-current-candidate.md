<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L4 recovery with the renewed candidate profile

Subsequent status: [L5 for the renewed profile](fork-linuxserver-l5-current-candidate.md)
now closes scoped local evidence, regression and retention acceptance. External
and human gates remain open; the phase-end statements below retain their scope.

Date: 2026-10-30. **L4 passes; L5 has not started.** The existing runner qualified
full 2.0.0 restoration and both full 1.2.0 rollback cases with the renewed profile,
including historical-credential rejection and fail-closed import negatives.
This follows the [L4 plan](fork-linuxserver-upgrade-recovery-plan.md#l4--restore-200-and-roll-back-a-failed-upgrade)
and consumes the [accepted renewed L3 checkpoints](fork-linuxserver-l3-current-candidate.md).
No application, runner, profile or original checkpoint change was required.
This is isolated fixture evidence, not intended-host recovery acceptance.

## Frozen inputs and execution

| Input | Identity |
| --- | --- |
| Runner | `93ac04da`, clean at native execution |
| Profile SHA-256 | `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9` |
| Candidate source | `efb27979b6a90122ce45a6a509e52390030f7ed9`, app 2.0.0 |
| Trusted kit manifest SHA-256 | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |
| Baseline | Upstream 1.2.0 at `02526119b845ccc1d8399be002da3e30b5527df6` |
| Native run | `mcp-l4-859a0a17-7efd-47a8-bfaf-9a2f47c64ea0` |

Original B0/B1 came from
`mcp/build/linuxserver-l3/runs/mcp-l3-dac97e4e-d494-40f2-957b-656cdafa4a28/`.
Their generation IDs and trusted hashes below were taken from the committed L3
record and explicitly supplied to the command. Independent retained verification
passed before execution. L4 copied and verified those inputs; it did not attach
an existing instance or import production data.

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L4 --inputs ABSOLUTE_VERIFIED_INPUT_DIRECTORY \
  --kit ABSOLUTE_FROZEN_KIT_DIRECTORY \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289 \
  --checkpoints ABSOLUTE_ACCEPTED_L3_RUN_DIRECTORY \
  --b0-generation 300324a7-3719-4d53-a43c-b04d452b0a73 \
  --b0-sha256 1db73d29e3f991f1f8c269a045a6766c5a985aba12211a61864289b8a01ca15f \
  --b1-generation c96caaf1-5ccc-4f7d-823a-e0bcbc67df83 \
  --b1-sha256 927a3806e4728dc08f549213ebfee8045d554dd74114d986ae09c3dabc54b0e0
```

Fresh pin checks at `2026-10-30T22:42:42.699154+00:00` verified the three OCI
index/platform pairs, five official app archives/XML bounds and retained 532-file
baseline. Canonical planning and resource preflight passed. The pinned images
were cached; no layers were pulled. NC 35.0.2, PostgreSQL 16.15, PHP 8.5.12,
SeaweedFS 4.48 and the five-app inventory stayed fixed. L4 does not use PHPUnit
PHAR/tokenizer inputs or rerun the L3 native capability suite. The earlier PHAR
HTTP 403 was not retried or silently reclassified as resolved.

Baseline and candidate migration trees both equal
`5213776af89952492bb1476cf3af05a5dd4cd3be`. The two R0 failure points therefore
remain orchestration boundaries, not simulated interruption inside a SQL migration.

## Restore and recovery results

The single native attempt exited **0**, with **72 recorded stages**.
Execution ran from `2026-10-30T22:43:08.608Z` to `2026-10-30T22:50:47.596Z`.

| Case | Restored MCP | Restore seconds | Checkpoint age seconds | Minimum live-credential margin | Reconnection HTTP checks |
| --- | --- | --- | --- | --- | --- |
| `B1-r1` | 2.0.0 | 33.752 | 9.771 | 231 s | 87 |
| `B0-r0-replaced` | 1.2.0 | 29.040 | 9.787 | 245 s | 87 |
| `B0-r0-upgraded` | 1.2.0 | 31.866 | 29.899 | 220 s | 87 |

Each case also passed two globally-closed and two disconnected HTTP assertions:
**273 HTTP checks** in total. All timed credential assertions retained
at least the required 30-second expiry margin. The measured restore interval
starts after trusted-input verification and ends at closed core readiness;
checkpoint age is measured from snapshot completion to that restore start.
These timings exclude case preparation and subsequent HTTP checks and are not
production RTO/RPO promises.

- **R1:** restore B1 with 2.0.0 into fresh owned storage, refresh synthetic
  credentials and capture `B1-r1`, then change/delete files, change/read back DAV
  data and revoke the owner's issued credentials. Restore the entire case
  generation into another fresh database, bucket and volumes; compare the
  pre-revocation state before applying the recovery safety cut.
- **R0 after replacement:** prepare a separate 1.2.0 case generation, apply the
  same mutations/revocation, replace the app while the database still records
  1.2.0, and prove HTTP remains closed. Restore the complete matching 1.2.0 set.
- **R0 after upgrade:** repeat independently, stop after `occ upgrade` completes
  but before reopening, then restore the entire 1.2.0 generation. No old app runs
  against a 2.0.0 database as a rollback shortcut.

Restore verified payloads before storage retirement. Imports checked numeric
owners/modes/links and archive readback, used PostgreSQL
`--exit-on-error --single-transaction`, recreated the original application login
without exposing its password, and compared all S3 bytes/metadata/tags and
installed app identity. Restored application role privileges remained bounded.
The new runtime's private CA and admitted-client IP were the only transport
overlay; application state and the Nextcloud instance secret were preserved.

Before maintenance release, each recovery advanced the restored global auth
epoch and disconnected affected synthetic accounts without changing restrictions.
Global closure and disconnected-state denial were proven before reconnecting only
the reviewed owner. Historical pre/post-snapshot access/refresh tokens and pending
codes were rejected with expiry guards; fresh OAuth still obeyed the original
permissions. The Nextcloud recovery data fingerprint changed in all three cases.
Basic denial is MCP policy enforcement, not global Nextcloud app-password revocation.

Fresh confirmation previews are required after recovery. Tests distinguish
unchanged-state stateless fingerprints from tampered/changed-state plans and
require denial without writes where appropriate; they do not claim blanket
invalidation of old fingerprints or rotate the instance secret. Each successful
case ended with MCP, maintenance and admission closed.

R1 also re-downloaded all five restored source-kit files over private trusted TLS
and matched their hashes. The unadmitted client remained denied. This does not
qualify public HTTPS, hosted clients or production update exclusion.

## Negative cases and checkpoint identities

Three runtime import faults were rejected at their intended validation boundary:
partial policy database, missing S3 payload and unavailable S3. None started the
normal Nginx/FPM/cron processes; the client observed connection refusal, not an
assumed successful closed response. Existing host controls separately cover
corrupt/missing payloads, foreign generation/profile/candidate identity, unsafe
archives, credential-window exhaustion and foreign resource IDs. Expected
negative-case failures do not represent failed native attempts.

| Retained checkpoint | Generation | Trusted manifest SHA-256 | Payloads / S3 objects | Policy tables checked |
| --- | --- | --- | --- | --- |
| `B0` | `300324a7-3719-4d53-a43c-b04d452b0a73` | `1db73d29e3f991f1f8c269a045a6766c5a985aba12211a61864289b8a01ca15f` | 93 / 87 | 11 |
| `B1` | `c96caaf1-5ccc-4f7d-823a-e0bcbc67df83` | `927a3806e4728dc08f549213ebfee8045d554dd74114d986ae09c3dabc54b0e0` | 291 / 280 | 17 |
| `B1-r1` | `c9cd68dd-67bc-4cef-a425-195f88e388bf` | `91363b8df3aef2f8211b08500e31f3d5ffc1b9e6fdf07a77091a0375f9df0c11` | 291 / 280 | 17 |
| `B0-r0-replaced` | `f371abd6-88fe-4885-a35e-6a1228fc486d` | `8a385812998e58899439ebd8bfb3c7f17f2e0009e9677fca46bb1607ecd4605f` | 93 / 87 | 17 |
| `B0-r0-upgraded` | `747cd3f9-bc8d-4aac-bf13-b297c4ae164d` | `eb95212116019ee0ab94fb8a1d2843eba4b9e00c31ab6f352b0861952f0517b5` | 93 / 87 | 17 |

The three case generations are distinct from original B0/B1. Each refreshes only
the authorized credential state before capture and has its own trust record.
Original B0/B1 hashes remained unchanged in both the L4 copies and the accepted
L3 directory. Old pending codes are not treated as live revocation evidence.

Post-cleanup read-only verification passed all five generations, **861 payloads**,
**20 private-input bindings**, every recorded policy table against its actual
dump, archive validators, primary-file/S3 references and both 2.0.0 source kits.
The **26 retained private files** are `0600`; private and checkpoint
directories are `0700`, and all checkpoint payloads are `0600`.
This audit checks the recorded liveness times, not continued credential validity.

## Cleanup and local validation

Fresh successful all-state Docker queries confirmed that every recorded ID/name
was absent: **38 containers, 1 internal network and
40 volumes**, accumulated across the sequential restore generations.
These resources were not all active together. Fixture labels were absent and
cleanup completed. No broad pruning, public listener, runtime egress, production
input or intended-server access occurred. Peak measured/reserved storage was
**9,568,153,600 bytes**, within 30 GiB; the existing CPU/memory/time
limits remained enforced.

Native evidence is retained privately under
`mcp/build/linuxserver-l4/runs/mcp-l4-859a0a17-7efd-47a8-bfaf-9a2f47c64ea0/`.
Only result/resource/diagnostic files, five checkpoints with separate trust files,
and private oracles remain. They contain synthetic secrets and are not release
assets. The original L3 evidence and earlier campaign records remain unchanged.

`mcp/build/linuxserver-l4-current/evidence-index.json` seals **76 records**,
SHA-256 `1b926f30fdb7e6c23b3c1912624f68f5be5921d7bf3799c5371a58a762813edc`. Payload bytes are bound
transitively by the trusted checkpoint manifests. Independent verification proof:
`verification.json`, SHA-256 `d3a6e44cd3a188fee24c3b0c8223fd2215c6a734e0d4b757a857fa2932feebd4`.
Native result SHA-256: `46b8beb1b84af4a771d0e803aea891276c4d7647129160dc0569b6e9ce6ec6c7`.
Documentation/package and final review logs stay outside the sealed index.

Before execution, **42 Node controls**, **2 Python archive test methods** with
adversarial cases and **43 PHP tests / 224 assertions** passed. Host PHP tests ran
on 8.4.27; native restoration used the fixed 8.5.12 image. No code correction or
native retry was needed. Automated review does not replace human acceptance.

Documentation/package validation passed: **192 Markdown files and 115 package
sources**. All **100 translations remain `review-required`**. The resulting
dirty development package has its source offer explicitly unavailable; it does
not replace the frozen candidate kit or any accepted checkpoint.
`git diff --check` passes. This is not the broader L5 regression/closure gate.

## Review before commit (2026-10-30)

A fresh read-only audit revalidated the 76 sealed records, all five checkpoint
generations (861 payloads and 20 private-input bindings), policy dumps, both
candidate source kits, private-file permissions and exact Docker cleanup.
The original evidence index and checkpoint identities remain unchanged.
The additional proof is retained outside the sealed index at
`mcp/build/linuxserver-l4-current/review/verification.json`, SHA-256
`67ff12d2bcf4bcb9dfd6096d7502cc42332824401394e9e343bb24c72eb5f81b`.

No code, profile or checkpoint correction was required. Credential liveness
remains a claim about the recorded execution times. This review did not rerun
native restoration or start L5. Fresh source-kit and documentation/package
validation logs are retained in `review/verify-source.log` and
`review/package.log`; the frozen candidate remains distinct from the development
package. All external and human gates below remain pending.

## Remaining gates

**L5 remains unstarted:** final reconciliation, retention review and relevant
regression closure require their separate phase. F3, intended-host app bytes,
startup/update controls, SQL/S3/backup qualification and source-history/publication
review remain open. Real desktop/mobile synchronization was not tested. Mail,
Groupfolders and external storage remain outside this profile.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT and human security,
operations/recovery, license/attribution, accessibility and language reviewers
remain pending. No push, deployment or PR #14 modification occurred.
