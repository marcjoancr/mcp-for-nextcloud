<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L3 upgrade and B1 with the renewed candidate profile

Subsequent status: [L5 for the renewed profile](fork-linuxserver-l5-current-candidate.md)
now closes scoped local evidence, regression and retention acceptance. External
and human gates remain open; the phase-end statements below retain their scope.

Date: 2026-10-30. **L3 passes; L4 has not started.** The existing runner upgraded
a fresh isolated MCP 1.2.0 installation to the frozen 2.0.0 candidate, verified
preservation and capability/source checks, recreated the owned app container and
captured B1. This follows the [L3 plan](fork-linuxserver-upgrade-recovery-plan.md#l3--replace-120-with-200-and-verify-persistence)
and [renewed L2 qualification](fork-linuxserver-l2-current-candidate.md).
No application, runner or profile changes were needed. Recovery remains unqualified.

## Frozen inputs and preparation

| Input | Identity |
| --- | --- |
| Runner | `06f56661`, clean at native execution |
| Profile SHA-256 | `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9` |
| Candidate source | `efb27979b6a90122ce45a6a509e52390030f7ed9`, app 2.0.0 |
| Trusted kit manifest SHA-256 | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |
| Baseline | Upstream 1.2.0 at `02526119b845ccc1d8399be002da3e30b5527df6` |
| Native run | `mcp-l3-dac97e4e-d494-40f2-957b-656cdafa4a28` |

Fresh verification at `2026-10-30T22:21:13.783019+00:00` checked all three pinned OCI
index/platform pairs, five official app archives/XML bounds and the retained
532-file baseline. The cached images matched their locked identities; no image
layers were downloaded. Canonical planning and `verify:source` passed. The fixture
kept NC 35.0.2, PostgreSQL 16.15, PHP CLI/FPM 8.5.12, SeaweedFS 4.48 and the same
five app versions. Mail, Groupfolders and external storage remain absent.

The official PHPUnit 10.5.65 PHAR URL returned **HTTP 403** before native execution.
The retained local PHAR was independently checked against the exact locked SHA-256
`ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835`
and used unchanged. Fresh PHAR-download availability remains unqualified; replay
requires these retained bytes or restored access to the same pinned artifact.
This is not a passing download check or a floating dependency substitution.
The official `php85-tokenizer-8.5.12-r0.apk` download matched
`5020af709d77e877db0bd5ed4b3fa3fa8eed037bfbcc4e5852aa5a72390accd1`.
Native Alpine signature verification passed. Tokenizer was loaded only for test
CLI actors; PHP-FPM and the installed APK database were not modified.

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L3 --inputs ABSOLUTE_VERIFIED_INPUT_DIRECTORY \
  --kit ABSOLUTE_FROZEN_KIT_DIRECTORY \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289
```

The exact arguments and extra-input provenance are retained under
`mcp/build/linuxserver-l3-current/`. This invocation created a new L1/L2/B0 fixture;
it did not restore the earlier standalone B0 or attach existing containers.
The pre-upgrade state includes L2's intentional post-B0 file change/deletion.

## Upgrade and acceptance results

The single native attempt exited **0**, with **64 phase controls**.
Baseline HTTP checks passed **120 assertions**;
L3's nine HTTP modes passed **210 assertions**.
The native candidate suite passed **55 tests /
1,144 assertions**, with zero failures, errors or skips.
Execution ran from `2026-10-30T22:22:05.257Z` to `2026-10-30T22:32:37.178Z`.

- Native upgrade completed on the same database and persistent app path with the
  core image fixed. Installed bytes and CLI identity matched the trusted kit
  after replacement and after same-image container recreation. No duplicate or
  old MCP tree remained in the scanned apps directories.
- Pre-probe schema, migrations, policy/authentication rows, preferences, DAV,
  Files, Deck and Talk state matched the pre-upgrade snapshot. The new `mail.read`
  permission stayed denied. Allowed retained credentials kept their grants;
  revoked/denied requests remained denied. Intentional test changes are recorded
  separately from migration preservation.
- Native capability checks covered Tasks hierarchy and numeric parent titles,
  Notes preview/confirmation, Deck labels/dependencies/files/dates, DAV mutation
  and mandatory contact backup, backup configuration/privacy and Talk references.
  Positive Mail behavior is outside this fixture's scope.
- Real admin/personal routes and assets, non-admin MCP source discovery,
  disconnected-user personal access, anonymous MCP denial and missing/mismatched
  offer negatives passed. All five source-kit artifacts were retrieved without
  credentials through the privately admitted Nginx endpoint and hash-verified,
  before and after recreation. This does not qualify public HTTPS or hosted clients.
- Tampered downloaded-kit copies and copied installed snapshots were rejected by
  release verifiers. These controlled negatives did not modify executing PHP or
  claim automatic runtime tamper enforcement by `SourceOffer`.

Native app integrity returned the expected unsigned-fork warning:
`mcp: App signature not found, skipping app integrity check`. Exact-byte checks
passed separately. No global integrity bypass or copied upstream signature was
used. Recreation reused the same owned volumes and image digest, initially with
admission closed and effective cron tables empty; it is not a restoration test.

## Checkpoints and retained verification

| Evidence | B0 | B1 |
| --- | --- | --- |
| Installed MCP | 1.2.0 | 2.0.0 |
| Generation | `300324a7-3719-4d53-a43c-b04d452b0a73` | `c96caaf1-5ccc-4f7d-823a-e0bcbc67df83` |
| Trusted manifest SHA-256 | `1db73d29e3f991f1f8c269a045a6766c5a985aba12211a61864289b8a01ca15f` | `927a3806e4728dc08f549213ebfee8045d554dd74114d986ae09c3dabc54b0e0` |
| Payloads / S3 objects | 93 / 87 | 291 / 280 |
| Payload bytes, excluding manifest | 1,083,726,451 | 1,162,770,597 |
| S3 bytes | 85,037,764 | 150,836,295 |
| Policy tables compared with dump | 11 | 17 |
| Private inputs / primary files / pending plans | 4 / 7 / 2 | 4 / 11 / 2 |
| Initial integrity verification finished | `2026-10-30T22:26:02.722Z` | `2026-10-30T22:32:33.326Z` |
| Minimum pending-code margin at that time | 292 seconds | 289 seconds |

Both captures ran with admission closed, maintenance enabled and Nginx/FPM/cron
stopped; process and SQL-writer checks bracketed capture. Each generation binds
the custom-format SQL dump, config/data archives, all S3 bytes and metadata,
semantic inventory and private bootstrap/oracle inputs. B1 also includes the
complete matching source kit. The wider B1 oracle does not imply six new schema
migrations; it records more policy tables and deliberate test mutations.

Post-cleanup read-only verification rehashed all payloads and private inputs,
matched the policy oracles against each actual dump, checked both volume archives
per checkpoint and every primary-file/S3 reference, and reverified B1's source
kit. B0 retained its original trusted digest after upgrade. Pending codes were
live at initial capture verification; they expire normally and must not be
assumed live during later recovery. No SQL restore or L4 case was executed.

## Cleanup and evidence handling

All **six container IDs** (including the retired/recreated app), **one network**
and **four volumes** were absent in fresh successful all-state Docker queries;
fixture labels were also absent. Cleanup completed with admission closed. No
published ports, production inputs, runtime egress or broad pruning were used.
Peak measured/reserved use was **5,714,993,152 bytes**, within the
30 GiB fixture limit. This is not production capacity or update-control acceptance.

The private native directory is
`mcp/build/linuxserver-l3/runs/mcp-l3-dac97e4e-d494-40f2-957b-656cdafa4a28/`.
Only result/resource/diagnostic/native-suite files, B0/B1 with separate trust files,
and private oracles remain. Checkpoint directories/files have `0700`/`0600` modes.
Synthetic secrets stay in ignored private evidence and must not be published.
Historical U5 and standalone renewed L1/L2 evidence remains unchanged.

The aggregate `mcp/build/linuxserver-l3-current/evidence-index.json` binds
**58 records**, including manifests, trust, oracles and native evidence.
Its SHA-256 is `4aeeb5977600805701d6750502629072b8b6234a021f543cad0b9802f3aed2bf`. Checkpoint payloads
are transitively bound by their trusted manifests. Independent verification proof:
`verification.json`, SHA-256 `b36eee7e5b6f5ad22c3c32437cdc23b5e64412feb8b0c541fee83854d8ab4eb4`.
Native result SHA-256: `11ae4214129f92087048ec8a06e26d3a666fc1dce07d8ab41ed621966fb7d7e5`.
Documentation/package and final review records are outside this sealed index.

## Local validation and remaining gates

Before execution, **42 Node controls**, **2 Python archive methods** with
adversarial subcases and **43 PHP tests / 224 assertions** passed. Host unit tests
used PHP 8.4.27; the native suite used PHP 8.5.12. No skip, failed native attempt
or application/test correction was needed. The PHAR download failure remains the
explicit preparation limitation above.

Documentation and package validation passed: **191 Markdown files and 115 package
sources**. All **100 translations remain `review-required`**. The generated
package is a dirty development build with source offer unavailable, not a new
candidate kit. `git diff --check` passes. Validation logs are retained outside
the sealed execution index.

L4 recovery is the next separately authorized phase and remains unstarted. It
must prove B1 restoration, rollback to B0 and credential cuts using its defined
fresh timed case generations. Checkpoint integrity and same-volume recreation do
not establish those claims. L5 regression closure for this renewed campaign,
intended-host storage/update/backup acceptance, source-history/publication and F3
remain open. The full platform matrix was not rerun by this phase.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT and human security,
operations/recovery, license/attribution, accessibility and language reviews remain
pending. No intended-server access, push, deployment or PR #14 modification occurred.

## Review before commit (2026-10-30)

The commit review confirmed all 58 sealed records unchanged and repeated the
read-only retained-checkpoint audit: **384 payloads**, eight private-input
bindings, eleven B0 and seventeen B1 policy tables read from the actual dumps,
all four volume archives, primary-file/S3 references and the complete B1 source
kit. Checkpoint permissions and recorded JUnit/HTTP results match this report.
Fresh successful all-state Docker queries also confirm the six container IDs,
one network, four volumes and fixture labels remain absent.

The additional proof is
`mcp/build/linuxserver-l3-current/review/verification.json`, SHA-256
`d608479ddd5c6b2fb032071bfbe713fdb556426736d7f9cbfaae741daca11c6e`. Original evidence and its sealed
index remain untouched. Source-integrity and documentation/package review logs
are retained separately as `review/verify-source.log` and `review/package.log`.
No application, runner, profile or checkpoint correction was required.

This audit did not repeat native L3, restore either checkpoint or start L4.
Recorded credential-liveness checks apply to initial capture verification, not
continued validity. The PHAR download limitation and external/human gates above
remain open; retained pinned bytes do not establish fresh download availability.
