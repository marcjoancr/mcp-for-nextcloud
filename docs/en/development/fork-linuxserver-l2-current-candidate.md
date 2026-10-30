<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L2 and B0 with the renewed candidate profile

Subsequent status: [L3 for the renewed profile](fork-linuxserver-l3-current-candidate.md)
now passes upgrade/B1 qualification. L4 remains unstarted for that profile;
the phase-end statements below retain their original scope.

Date: 2026-10-30. **L2 passes; L3 has not started.** The unchanged runner
created a fresh isolated MCP 1.2.0 fixture, seeded synthetic records and captured
B0 under the [renewed profile](fork-linuxserver-l0-current-candidate.md), following
[the L2 plan](fork-linuxserver-upgrade-recovery-plan.md#l2--seed-120-and-capture-a-coherent-pre-upgrade-checkpoint).
This qualifies baseline data and checkpoint integrity, not installation of 2.0.0
or restoration. No application, runner or profile change was needed.

## Inputs and execution

| Input | Identity |
| --- | --- |
| Runner | `342bd444`, clean at native execution |
| Profile SHA-256 | `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9` |
| Candidate source | `efb27979b6a90122ce45a6a509e52390030f7ed9`, app 2.0.0 |
| Trusted source-kit manifest SHA-256 | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |
| Installed baseline | Upstream 1.2.0 at `02526119b845ccc1d8399be002da3e30b5527df6` |
| Baseline archive SHA-256 | `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801` |
| Native run | `mcp-l2-4e45298f-6bcf-4442-93c4-0f669b3c4541` |

Fresh checks at `2026-10-30T22:10:07.600245+00:00` verified all three pinned OCI
index/platform pairs, five official app archives and XML compatibility bounds,
and the retained 532-file baseline. The planner and source-kit verifier passed.
The locally cached pinned images required no layer download. Host preflight and
the runner enforced the existing resource/isolation limits; the fixture had no
published ports, production inputs or runtime egress.

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L2 --inputs ABSOLUTE_VERIFIED_INPUT_DIRECTORY \
  --kit ABSOLUTE_FROZEN_KIT_DIRECTORY \
  --manifest-sha256 c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289
```

The input directory for this run is `mcp/build/linuxserver-l2-current/`.
The frozen kit remains under `mcp/build/source/efb27979b6a90122ce45a6a509e52390030f7ed9/`.
Each L2 invocation internally repeats L1 in a new owned fixture. This run used
NC 35.0.2, PostgreSQL 16.15, PHP CLI/FPM 8.5.12 and the unchanged app/S3 pins.
Only MCP 1.2.0 was installed. The privately served 2.0.0 kit was checked for
integrity, not advertised by the baseline or installed as an update.

## Capture and independent verification

One native attempt exited **0**, with **30 named phase controls**, including
**120 HTTP assertions**. Basic/Bearer allow and deny behavior, Files visibility,
read-only and filename policies, DAV records, Notes editing, Deck labels,
dependencies/file links/deadlines and Talk records passed. The retained private
oracle includes the HTTP-created state and pending Notes/Deck confirmation plans.

The runner closed pre-PHP admission, enabled maintenance and stopped Nginx,
PHP-FPM and cron. It checked process/SQL-writer quiescence around the cross-store
capture. B0 contains a custom-format PostgreSQL dump, config/apps/themes and local
data archives, every primary S3 payload with metadata/tags, semantic inventory
and bootstrap requirements. No live PostgreSQL data directory was copied.

| Evidence | Value |
| --- | --- |
| B0 generation | `5cd2f8c7-f996-48b7-8220-84f8207daacf` |
| Trusted B0 manifest SHA-256 | `d53e11c76ee2da557074566e8a82936ccf65827b4fae5d35cbefc7d3538a24c7` |
| Native result SHA-256 | `33f88778d5dc405e2a4dc7832210a542284da51d6dde1e67a7a615b34c52cc4b` |
| Capture interval | `2026-10-30T22:13:47.170Z` to `2026-10-30T22:13:52.104Z` |
| Integrity verification completed | `2026-10-30T22:13:53.890Z` |
| Payloads, excluding manifest | 93 files; 1,083,726,448 bytes |
| S3 export | 87 objects; 85,037,764 payload bytes |
| Config archive | 28,081 entries; 34 legitimate internal links; owners `0:0`, `1000:1000` |
| Data archive | 6 entries; no links; owner `1000:1000` |
| Peak measured/reserved use | 4,381,511,680 bytes, below 30 GiB |

A separate read-only verification after cleanup passed all 93 payload hashes,
four private-input hashes, all eleven policy tables decoded from the actual dump,
and both archive validators. It confirmed the profile, image/app identities,
complete HTTP oracle and pending plans. Host `pg_restore` 17.11 read the PostgreSQL
16.15 dump without connecting to a database; this is not a restore test.
Empty objects, Unicode names, multipart payloads and primary S3 file IDs/hashes
were checked by the native capture. Controlled file change/deletion after B0 did
not alter the sealed backup, which passed its original trusted digest again.

Pending authorization codes were live when capture **and initial integrity verification** finished.
Stored codes subsequently expire normally; B0 does not keep them valid. Later
timed recovery cases must issue fresh credentials within their defined bounds.
No lifetime extension, clock change or database expiry rewrite was used.

## Cleanup and private evidence

Cleanup completed with admission closed. Fresh successful all-state Docker
queries independently confirmed absence of the exact **five containers, one
network and four volumes** and the fixture labels. No broad pruning occurred.
The native directory `mcp/build/linuxserver-l2/runs/mcp-l2-4e45298f-6bcf-4442-93c4-0f669b3c4541/`
retains only the result, resource journal, diagnostic log, `B0/`, `B0-trust.json`
and `private/`. Run, B0 and private directories remain mode `0700`.

B0 and the independent private oracle/bootstrap contain synthetic secrets. They
are ignored local test evidence, not source-kit contents or publication assets.
The separate `B0-trust.json` binds the generation/digest; the manifest binds all
93 payloads and four private inputs. Historical U5 checkpoints remain unchanged
and keep their original identities; this capture does not supersede their claims.

The aggregate `mcp/build/linuxserver-l2-current/evidence-index.json` seals
**48 records**, including the native result, B0 manifest/trust and
private-input records. Its SHA-256 is `1abf2379f06ba29fbea8796c4e5d4d1c3008387397b58ddf58e9f712b80b67a5`.
Independent closure proof: `verification.json`, SHA-256
`04bfc6b6310401e2ca18a71a7e20300d9eb155c8a4c9ecd7d484bfc2cdd0f576`. The payloads are bound transitively
by the trusted B0 manifest; the index does not make private evidence public.
Documentation/package and final review records are kept outside the sealed index.

## Validation and diagnostic limits

Existing controls passed: **42 Node tests**, **2 Python archive test methods**
with adversarial subcases, and **43 PHP tests / 224 assertions** selected by
`composer --working-dir=mcp test -- --filter LinuxServer`. The unit selection ran
on host PHP 8.4.27; the native fixture used PHP 8.5.12. Canonical planning and
`verify:source` passed against the frozen candidate. No production change or
new test exemption was needed.

Two command-selection errors are retained as failed preparation diagnostics:
combining `--plan` with `--phase/--inputs` was rejected before runtime, and the
nonexistent Composer `test:unit` alias was rejected before test execution.
Correct canonical commands then passed. Neither failure is counted as a passing
test or a native attempt. The single native L2 run had no failure or retry.

Documentation/package validation passed: **190 Markdown files and 115 package
sources**. All **100 translations remain `review-required`**. The package is a
dirty development build with its source offer explicitly unavailable; it does
not replace the frozen source kit. `git diff --check` passes. The full application
matrix, native signal experiments and upgrade/recovery phases were not rerun.

## Remaining gates and next step

L3 is the next separately authorized phase: install the frozen candidate in a
fresh isolated fixture, validate persistence/source discovery and capture B1.
Its existing runner creates fresh L2 state/B0 before upgrading; it must not treat
expired codes or historical checkpoints as live inputs. **L3 was not started.**
L4 must still qualify recovery for this candidate; B0 integrity alone establishes
neither restoration correctness nor intended-host readiness.

Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT and human security,
operations/recovery, license/attribution, accessibility and language review remain
pending. Intended-host S3/update/backup acceptance, source-history/publication
review and F3 also remain open. No intended-server access, push, deployment or
PR #14 modification occurred.

## Review before commit (2026-10-30)

The review confirmed all 48 sealed records unchanged and repeated retained-B0
verification: 93 payloads, four private-input hashes, eleven policy tables read
from the dump, both archive validators and the complete HTTP oracle. S3 payload
references and primary-file identities match the manifest and expected state.
B0 directory/file permissions remain `0700`/`0600`. Fresh successful all-state
Docker queries confirm the exact five containers, one network and four volumes,
and fixture labels, remain absent.

The new proof is `mcp/build/linuxserver-l2-current/review/verification.json`,
SHA-256 `e648a033684bce26bd02669565773f8cf4809120bd812aa63acfac30e504ae3d`. It is outside the original
sealed index. This review neither reran native L2 nor restored B0; recorded
code-expiry checks apply to the original capture/initial verification timestamps,
not continued credential validity. L3 remains unstarted.

The plan's pending-Inspector row now distinguishes the documented local
storage/provider correction from still-pending end-to-end acceptance. Public
HTTPS, hosted ChatGPT and human review remain pending. No application, runner,
profile or checkpoint correction was required.

The commit-review source-integrity and documentation/package logs are retained
outside the sealed index as `review/verify-source.log` and `review/package.log`
under the aggregate evidence directory. The development package does not replace
the immutable candidate kit or the retained B0.
