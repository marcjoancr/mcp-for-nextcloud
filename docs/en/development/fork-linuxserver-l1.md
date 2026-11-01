<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L1 baseline qualification

Subsequent status: [L5 for the renewed profile](fork-linuxserver-l5-current-candidate.md)
now closes scoped local evidence, regression and retention acceptance. External
and human gates remain open; the phase-end statements below retain their scope.

This record covers the authorized L1 bootstrap on devbox on 2026-10-27.
It does not qualify an upgrade, checkpoint, rollback, production deployment or
hosted client. L2 had not started at L1 acceptance; its later authorized work
is recorded separately in [L2 fixtures and B0](fork-linuxserver-l2.md).
PR #14 and remote branches are unchanged.

## Inputs and execution boundary

The [reviewed plan](fork-linuxserver-upgrade-recovery-plan.md) and
[L0-U5 record](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27) remain authoritative
for immutable inputs. The application candidate remains published `940c3da3`,
separate from the L1 harness changes based on `1a5e8e5f`.

| Input | Exact value |
| --- | --- |
| Profile SHA-256 | `743f2704669f259cc97810771a5bea58722871f9a13962bb5beae33b54d4cc63` |
| U5 candidate | `940c3da36f0d9c5310a98d7c3f355d2d99c86d33` |
| Trusted kit manifest SHA-256 | `2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63` |
| Baseline archive SHA-256 | `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801` |
| Runtime | LinuxServer Nextcloud 35.0.2, PostgreSQL 16.15, SeaweedFS 4.48; exact image digests from the unchanged profile |
| Apps | MCP 1.2.0, Calendar 6.6.3, Contacts 8.9.2, Deck 1.19.0, Notes 6.1.0, Talk 25.0.6 |

The offline planner still uses `--plan` and never starts Docker. Explicit
execution uses the unified command below. `--inputs` is an absolute directory
containing the retained baseline and five app archives named
`<app-id>-<version>.tar.gz`. Every archive is rehashed before extraction. The
source kit is independently verified before any resource is created.

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L1 --inputs ABSOLUTE_INPUT_DIRECTORY \
  --kit ABSOLUTE_KIT_DIRECTORY \
  --manifest-sha256 2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63
```

At the L1 revision, `--phase L2` through `L5` were rejected. The later L2
implementation adds explicit B0 capture; L3-L5, mixed planning/execution
arguments and incomplete execution arguments remain rejected. L1 results explicitly carry
`scope: baseline-bootstrap`, `upgradePerformed: false` and
`checkpointsCreated: false`. The 2.0.0 archive is served as a private integrity
fixture only; it is neither installed nor advertised by the baseline app.

## Isolation, owners and controls

`runtime.sh` is a thin execution boundary. Node modules separate argument/profile
validation, verified inputs, resource ownership, fixture configuration, bounded
commands, network selection and native validation. PHP probes reuse the existing
completion guard; a zero process exit alone cannot certify a native assertion.
Python extraction rejects links, traversal, special entries and excess size.
No application capability code or existing Apache runner is changed.

Each invocation owns a labelled internal network, five containers and four
persistent volumes. No ports are published, no Docker socket is mounted and
runtime services have no external network. The runner audits Docker networks and
host routes before selecting an unused private `/24`; it never edits daemon
address pools, deletes foreign networks or reuses another fixture.

CPU limits total four cores; memory limits total 7168 MiB under the 8192 MiB
profile ceiling. Container swap is bounded, process counts and Docker logs are
limited. The runner measures work files, container writable layers and persistent
storage against 30 GiB, checks the 40 GiB free-space reserve and enforces a one-hour
execution deadline with bounded commands/readiness checks. Cleanup uses separate
bounded commands so expiry does not skip removal. This is monitored disk
admission, not a host filesystem quota. S3 permits at most four 512 MiB volumes and growth of
one; its master, volume and LevelDB2 filer state share the owned persistent S3
volume. Only tiny health objects/files are written; L2 business fixtures are absent.

A private CA authenticates `nc.fixture.test`. Server-level Nginx admission is
closed before PHP regardless of Nextcloud maintenance state. Probe-only mode
admits one owned client by its actual network address; forwarded-IP headers are
not trusted. The second client must remain denied. The exact source kit is mounted
read-only under the same pinned Nginx endpoint without MCP credentials.

Cron inputs are empty before LinuxServer starts and the cron service is explicitly
paused. The runner uses `s6-envdir`, `s6-setuidgid abc` and the actual persistent
app path. It does not import the intended server's unknown initialization hook.

Cleanup first closes admission, then inspects exact resource identities and run
labels before removal. Removal/query errors fail the run. SIGINT/SIGTERM enter
cleanup; a hard kill remains incomplete. Existing labelled resources block a new
run and require an explicit ownership audit, never an automatic broad prune.
Private evidence stays in a run-specific ignored output directory (mode 0700);
credential files are 0600 and are removed after successful cleanup. Only sanitized
results, resource identities and diagnostic logs are retained. The owner is
responsible for retaining or deleting these local evidence files after review.

## Findings from native execution

Failed development attempts remain failures in their local result records:

- The host's automatic Docker address pools were exhausted. Explicit selection
  now excludes all existing Docker subnets and non-default host routes; no host
  infrastructure configuration was changed.
- Docker's local log driver rejects compression with a single log file. Every
  fixture container now uses bounded two-file rotation.
- This image imports `/etc/crontabs` and has no `/defaults/crontabs`; seeding only
  `/config/crontabs` did not pause jobs. The runner now prepares the effective
  inputs before startup and checks the live service state.
- S3 authentication can respond before the volume map becomes readable after a
  restart. Readiness now requires reading the exact persisted object; bounded
  retries retain diagnostics and do not turn a lost object into a pass.
- The fresh image creates `config.php` after its recursive ownership update.
  The runner assigns this synthetic file to `abc` before installation and checks
  writability. Installer output must explicitly acknowledge successful completion;
  a zero exit with a Nextcloud error page is rejected.

Seaweed configuration follows the pinned release's
[master growth settings](https://github.com/seaweedfs/seaweedfs/blob/4.48/weed/command/scaffold/master.toml)
and [LevelDB2 filer settings](https://github.com/seaweedfs/seaweedfs/blob/4.48/weed/command/scaffold/filer.toml).
The fixture's live checks, rather than those references alone, determine acceptance.

## Results and remaining gates

The 2026-10-27 native command exited zero with **26 recorded checks**,
`status: passed`, `cleanup: complete` and admission closed. Its run ID is
`mcp-l1-c34da5a6-5c88-41bf-9300-714a05d9b4d0`, completed at
`2026-10-27T20:16:12.764Z`. The retained `result.json` SHA-256 is
`ca0c9af24a9359f1f33ca89b20cb6aa0289fc9415c809db0afd59083776f789a`.
Peak measured/reserved disk usage was 2,211,110,912 bytes (about 2.06 GiB).

The native result verifies SQL 16.15, Nextcloud 35.0.2, CLI/FPM PHP 8.5.12,
`abc` UID 1000, the persistent app path and all six pinned apps. It includes
private-TLS login/status, anonymous MCP rejection, FPM extensions, five source-kit
file digests, read-only delivery, invalid trust/hostname/signature/forwarded-IP
rejection, primary-S3/local persistence, missing environment/wrong context/missing
completion/unavailable DB/S3 rejection, restart admission and final cleanup.

One independent automated review identified three Important runner defects.
All have reproducing tests that failed before correction and now pass: reject
command timeouts/Docker errors instead of treating them as expected native
rejections; make interruption during cleanup invalidate qualification; reject
FIFO/special inputs without a blocking open. At that review, the Node control
suite passed 14 tests, including the local-Docker boundary and no daemon contact after
rejected preflight with no attempted resource creation. No Critical or Minor
findings were reported. Native and human acceptance were explicitly outside that
review's judgment; the actual native evidence above is a separate result.

Two separate live interruption checks passed their expected-failure assertions:

| Injection | Run ID | Required outcome |
| --- | --- | --- |
| SIGTERM after closed admission and paused cron | `mcp-l1-7ec7b3e1-cb6b-4d05-8714-d9bb6b51eb20` | Exit 1, failed qualification, admission closed, cleanup complete |
| SIGINT after the aggregate-budget check, during cleanup | `mcp-l1-78c5d94d-f79e-48b0-b60d-3d79de95bfde` | Exit 1, `L1 interrupted`, failed qualification despite completed native checks, cleanup complete |

Their `result.json` hashes are respectively
`2ef7b3cf719a972e53e0a78a511316684271ac21f2c32130f25a4e430b8b356a` and
`6de50c440578731cdff7bd1aedafe2f326a49f89b202352720c541d9b762b909`.
The local automated injection driver and assertions are retained as `check-interruptions.py` and `interruptions.json`.
To reproduce, launch the documented L1 command as a child process, send SIGTERM
after `closed-admission-and-paused-cron`, then in a fresh invocation send SIGINT
after `aggregate-budget`; require nonzero exit and the outcomes above. Signals
must target only that invocation's recorded child PID. No hard-kill recovery
claim is made.

`devbox run check` exited zero: 174 Markdown files/115 package sources,
29 documentation tests, 10 client-runner controls, 11 release controls,
1,365 PHP syntax checks, both PHPStan scopes, **8,688 PHP tests/74,201 assertions**,
**717 browser tests**, and production packaging. The retained `check-final.log` has SHA-256
`c71bcff029dcf70ab04328966ee16c101b08d1a56285f6255e981b08b71c379c`.
The final empty-preflight cleanup guard was subsequently covered by the focused
harness suite and Node controls; it does not change the populated-resource native
path tested above. Documentation and package checks were repeated after the report
updates. The dirty development package correctly reports source offer unavailable;
it is not a substitute for the unchanged frozen U5 source kit.

All 100 translation records retain valid provenance and remain review-required.
No existing Apache native upgrade/recovery cell was rerun in this L1-only batch;
that implementation is unchanged and the broader L5 regression gate stays open.
The first development attempt reported cleanup incomplete because network creation
failed before a resource existed. A subsequent exact-label audit found no resources
for that run; its original failed report is retained, not rewritten as a pass.
All later completed development runs cleaned their owned resources. No L1-labelled
container, network or volume remains after the final audit.

L2-L5 remain pending: seeded B0, U5 replacement, B1, forward recovery and rollback
have not run in this profile. Intended-host startup, update writers, backup/S3
semantics and production compatibility are not inferred from this fixture.
Public HTTPS remains pending. Inspector remains at the prior 14/15 nonzero result
with its encrypted-storage finding unresolved. ChatGPT acceptance has not run.
Security, operations/recovery, license/attribution, accessibility and language
reviewers remain pending; an automated code review does not replace them.


## Review and local closure (2026-10-29)

The closure review verified the four retained historical evidence hashes and
corrected two additional runner failure paths. Diagnostic capture exceptions can
no longer bypass owned-resource cleanup. Disk measurement now retries a failed
scan at most three times while the service remains running, discards every
partial result and requires a complete successful scan. Timeouts fail immediately;
persistent scan errors remain failures. A stopped service retains its previous
complete measurement. Both corrections have reproducing failing-then-passing tests;
the final Node suite passes **16 controls**.

The first repeat, `mcp-l1-87f25c37-2d63-4037-b25f-ad454bef60df`, remains a failed
run with complete cleanup. Its diagnostic shows `du` racing with removal of the
Nginx admission file. It is not counted as successful qualification.
The subsequent native run `mcp-l1-a82b94f4-b6ae-490d-938f-3bfa69c7f62c`
exited zero with 26 checks, closed admission and complete cleanup. Its result
SHA-256 is `857a8319e9a7626506986011e2738aa7bc3b8a6f7f8b8675e349b8a4c4f2558e`.
Peak measured/reserved disk usage was 2,210,938,880 bytes.
The fresh exact-label audit found no remaining containers, networks or volumes.

The registered full check exited zero again: 8,688 PHP tests/74,201 assertions,
717 browser tests, both PHPStan scopes, documentation and production packaging.
Its `closure-check-20261029.log` has SHA-256
`6dec3711b53b2aefff01da5892c52a1adf5ab653076db4a2e8f3ba38dfdcbf3d`.
The subsequent budget refinement was validated by the focused harness suite,
the final Node controls and the native repeat above. Documentation and package
checks were repeated after these report updates. Only the local L1 implementation,
its tests and documentation are included in the closure commit; private artifacts
remain untracked. The frozen U5 kit/profile and all existing phase/client/human
acceptance limits remain unchanged. L2 was not started.
