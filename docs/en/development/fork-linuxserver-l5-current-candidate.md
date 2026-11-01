<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L5 closure for the renewed candidate profile

Date: 2026-10-31 (Europe/Madrid; evidence timestamps use UTC).
**L5 passes within the isolated local scope.** This closes evidence reconciliation,
regression checks and retention for the [renewed LinuxServer campaign](fork-linuxserver-upgrade-recovery-plan.md).
It does not close F3, qualify recovery for the intended deployment or approve publication.
HTTPS, Inspector end-to-end, hosted ChatGPT and human reviewers remain pending.

## Exact candidate and scope

- Candidate source: `efb27979b6a90122ce45a6a509e52390030f7ed9`, app `mcp` 2.0.0.
- Trusted kit manifest SHA-256: `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.
- Runtime archive SHA-256: `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297`.
- LinuxServer profile SHA-256: `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9`.
- L5 runner revision: `0baf6c6b21898de9dba686a038c0d674fce95253`, clean at native staging.
- Baseline: upstream 1.2.0 at `02526119b845ccc1d8399be002da3e30b5527df6`;
  archive SHA-256 `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801`.

No application, runner, test-selection or profile change was needed. L5 is an
operational audit, not a runtime phase; the existing CLI still rejects `--phase L5`.
Fresh planning and source-kit verification passed without starting LinuxServer.
The earlier native runs remain historical executions and were not repeated or
relabelled. The former U5 campaign and its checkpoints remain separate.

## Reconciled LinuxServer evidence

| Phase | Accepted record | Scoped result |
| --- | --- | --- |
| L0 | [Input renewal](fork-linuxserver-l0-current-candidate.md) | Frozen candidate, pins, profile and planning controls |
| L1 | [Baseline qualification](fork-linuxserver-l1-current-candidate.md) | 26 stages; 1.2.0 baseline and owned cleanup |
| L2 | [Fixture and B0](fork-linuxserver-l2-current-candidate.md) | 30 stages, 120 HTTP assertions; coherent pre-upgrade capture |
| L3 | [Upgrade and B1](fork-linuxserver-l3-current-candidate.md) | 64 stages, 55 native tests / 1,144 assertions; preserved data and source offer |
| L4 | [Recovery](fork-linuxserver-l4-current-candidate.md) | 72 stages, 273 HTTP checks; R1 → 2.0.0 and both R0 boundaries → 1.2.0 |
| L5 | This record | Retained-evidence audit, two new Apache regressions and final owned cleanup |

All **267 indexed records** across the five sealed indices match their original
byte counts and SHA-256 values. Read-only validation also checked **eight retained
checkpoint copies**, covering **six distinct generations**, **1,338 payloads** and
**32 private-input bindings**: L2 B0, L3 B0/B1 and the five L4 copies/cases.
Their trusted generation/digest pairs, policy dumps, archives, S3 references and
source-kit bindings agree with the accepted phase reports. Original manifests,
payloads and result bytes remain unchanged. Credential liveness is verified at
recorded capture/assertion times; expired credentials are not a fresh experiment.

Successful all-state Docker queries found all **54 recorded containers, four
networks and 52 volumes** absent, as well as LinuxServer fixture labels. Counts
are cumulative across sequential phases and restores, not simultaneous use.
The audit retains **1,410 files and 31 directories** for these four accepted runs.

The first retention audit found L3's `native-junit.xml` at `0644` inside its private
`0700` run directory. After recording the exact preview, L5 changed that file to
`0600` and verified the same SHA-256. The initial failed audit is retained; the
complete repeat passed. All retained run files/directories now have `0600`/`0700`
permissions. No checkpoint sealer, test result or application behavior changed.

There are no failed or superseded LinuxServer runs in this worktree to delete.
Keep accepted synthetic checkpoints and their bound private inputs for F3 and
independent review. The repository owner remains responsible for retention and
removal; revisit disposal at the next F3 release or intended-host acceptance
review. No unattended deletion schedule or 30-day retention is introduced.
Historical evidence in the other campaign's worktree was not modified.

## New Apache regressions

The two complete suites ran sequentially; the second started only after the first
passed its assertion, candidate-integrity, expected-skip and cleanup gates.
Both used the same immutable kit with the existing `--candidate-kit` option.
The upgrade also supplied the verified baseline using `--baseline-package`.
No checkout rebuild, test filter, version override or replacement baseline was used.
Exact commands, pinned image references and runner inventories are in the private
observations/plans. Representative command suffixes are:

```sh
composer --working-dir=mcp test:integration -- \
  --candidate-kit ABSOLUTE_KIT TRUSTED_MANIFEST_SHA256 \
  --baseline-package ABSOLUTE_BASELINE BASELINE_ARCHIVE_SHA256 \
  35 mariadb upgrade-upstream-120
composer --working-dir=mcp test:integration -- \
  --candidate-kit ABSOLUTE_KIT TRUSTED_MANIFEST_SHA256 \
  35 sqlite fresh
```

| Cell | Run ID | Cases / assertions / skips | JUnit seconds |
| --- | --- | --- | --- |
| `35-mariadb-upgrade-upstream-120` | `mcp-it-20261030230246-f982ae3c0178` | 300 / 4,436 / 11 | 383.674 |
| `35-sqlite-fresh` | `mcp-it-20261030231037-227118302c88` | 300 / 4,396 / 14 | 301.209 |

These are Nextcloud **35.0.2 Apache / PHP 8.5.12** cells; MariaDB is **10.11.19**.
They remain distinct from LinuxServer/Nginx/PostgreSQL/SeaweedFS acceptance.
Both installed inventories match all **975 runtime files** of the frozen kit.
The upgrade additionally matched the installed 532-file baseline and preserved
migrations, restrictions, OAuth clients/generations/scopes and existing bearer
access across the 1.2.0 → 2.0.0 transition. SQLite passed coordinated recovery:
old access/refresh/code denial, explicit reconnection and service left closed.

The MariaDB cell has 11 expected skips: six older-baseline `upgrade` cases, two
opt-in Mail cases and three PostgreSQL query-log cases. SQLite has those plus
three database-locking/concurrency cases, for 14. Skips remain skips, not passes.
These two regressions do not repeat the complete platform matrix or Mail coverage;
[the M4 reconciliation](fork-platform-m4.md) retains that separate evidence.

The pinned PHPUnit 10.5.65 download succeeded and matched its locked SHA-256
`ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835`.
This is a fresh availability observation, not a rewrite of L3's earlier HTTP 403
or a promise about future downloads. No cached-artifact substitution was required.

## Validation and cleanup

The full catalog `devbox run check --cwd ABSOLUTE_WORKTREE --json` exited 0:
Composer validation, documentation/provenance, 29 documentation-tool tests,
10 client-runner controls, 23 source/integrity controls, PHP syntax, both static
analysis scopes, **8,707 PHP tests / 74,493 assertions**, **717 browser tests**
and packaging passed. Host PHP was 8.4.27 and Node was 24.22.1.
The separate LinuxServer controls passed **42 Node tests** and **two Python
archive test methods**. Expected negative build-test diagnostics for `missing.js`
remain in the full log; all three browser projects passed those failure assertions.
No failed mandatory assertion was suppressed.

Exact resource IDs were observed during both Apache runs, including anonymous
volumes, and checked absent after completion. No ports were published. Before
removing local working copies, L5 recorded their exact paths, file inventories and
artifact hashes. Only those owned runs' temporary credentials, working copies,
raw logs and the redundant captured baseline tree were removed. Retained artifacts
kept identical bytes and private modes. The preview removed **45 paths / 291,741,214 logical bytes**;
this is not a physical disk-reclamation measurement. Both runs' total
**3 containers, 2 networks and 3 anonymous volumes** are absent.
No broad prune, unrelated fixture removal or worktree cleanup occurred.

Local check/package output is development-build evidence; it neither replaces
the frozen kit nor publishes a release. The final documentation package explicitly
marks its source offer unavailable when the checkout is dirty. Final documentation
and package validation passes **193 Markdown files / 115 package sources**;
all **100 translations** remain `review-required`.

A fresh-context automated review found no actionable findings in the documentation
or retained evidence. It independently rehashed the 96-record L5 index and all
267 prior phase records, checked JUnit totals/skips, private modes, preserved
artifacts and the absence of every previewed removal path. Its own live Docker
query was sandbox-denied; it relied on retained cleanup proofs and the executor's
successful fresh queries for resource absence. This does not replace human review.

## Closure review before commit (2026-10-31)

Fresh read-only verification matched the 96 sealed L5 records, all 267 earlier
phase records, eight checkpoint copies and 32 bound private inputs. The retained
Apache JUnit results still report 600 cases / 8,832 assertions / 25 expected skips,
with no failures or errors. All 53 retained Apache artifact files keep their hashes
and private modes; all 45 removed paths and the exact recorded resources remain
absent. The frozen source-kit verification passed again.

No application, harness, profile or checkpoint correction was required. The full
check and native suites above remain evidence for unchanged code; this review
reran no fixture, restoration or destructive cleanup. New proofs and final
source/documentation/package logs are retained in the candidate review output, outside the sealed index. Historical evidence references and the next decision were clarified. External and human gates remain open.

## Evidence and next gate

Private L5 proofs remain in ignored build output. Accepted Apache artifacts remain in their run-specific ignored integration output.
The index `evidence-index.json` seals **96 records**, SHA-256
`678d20d5bc2441de05444c1925cec9dd149b0fcc1c262830dd4bb2972d3b2e1c`.
The index binds phase reconciliation, fresh checkpoint audits, commands, plans,
regression summaries/artifacts, permission/retention previews and results, source
verification and validator logs. Documentation validation after index sealing
stays outside it. Raw evidence includes synthetic credentials/backups; it must
remain private and is not a release asset. Sanitized reports and hashes belong in Git.

The current candidate now has scoped local LinuxServer upgrade/recovery closure
in addition to the reference-platform evidence. After this technical closure
review, the next decision concerns final release/source publication and
intended-host qualification. Independent human acceptance remains pending;
local success does not authorize production changes.

Still pending:

- F3/intended host: installed app bytes and migrations, effective database/FPM,
  startup/update writers, real S3 configuration, backup topology and restoration.
- Final source-history/license/attribution review, trusted release distribution
  and publication decisions; a locally verified kit is not a published release.
- Public HTTPS: DNS, trusted certificate chain, ingress and hosted-client access.
- Inspector: the local storage/provider correction has separate passing evidence;
  refreshed end-to-end acceptance remains pending. L5 does not turn the historical
  14/15 nonzero result into a pass.
- ChatGPT: hosted consent, tool use and revocation have not passed acceptance.
- Human security, operations/recovery, license/attribution, accessibility and
  language review; the owner's operational responsibility is not independent approval.

No production-server access, push, deployment or PR #14 modification occurred.
No production RPO/RTO, desktop/mobile synchronization, Groupfolders or external
storage qualification is inferred from these fixtures.
