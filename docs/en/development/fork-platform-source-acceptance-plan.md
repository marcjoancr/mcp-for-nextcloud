<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Isolated platform and source-offer acceptance plan

> Execute the batches in order, validating each gate before advancing. This
> document records M0 completion and specifies the remaining work; it does not
> start the native matrix or authorize publication, deployment, production access
> or changes to PR #14.

Date: 2026-10-29; M0 closure: 2026-10-30. **Next bounded batch: M1.**
[M0 results and trusted candidate identity](fork-platform-m0.md) close preparation
and control checks; no native cell has run for the successor. M2-M4 define
completion criteria so the first batch cannot be mistaken for the entire matrix.
HTTPS, Inspector end-to-end acceptance, hosted ChatGPT and human reviews stay open.

**Goal:** qualify one immutable runtime/source kit against the approved reference
profiles and ordinary-user source retrieval, with explicit failures and cleanup.
**Architecture:** reuse the existing disposable native harness and source-offer
runner; give the native harness a verified kit input instead of rebuilding the
moving checkout. Record runner revision separately from candidate identity.
**Stack:** Linux amd64, Nextcloud Apache 33/34/35, MariaDB 10.11, PostgreSQL 16,
SQLite for local tests, PHP 8.4/8.5, Composer, Node, Docker and the existing suites.
**Specification:** [approved platform scope](fork-continuation-plan.md#proposed-platform-scope),
[source delivery](source-delivery.md), [candidate record](fork-candidate-re2js.md)
and [LinuxServer boundaries](fork-linuxserver-l5.md).

## Inputs and decisions

The current candidate is the [M0 successor](fork-platform-m0.md#frozen-candidate-and-independent-reconstruction)
at `efb27979b6a90122ce45a6a509e52390030f7ed9`. All remaining batches must use
its externally recorded manifest digest. The paragraphs below retain the original
planning baseline and the prerequisites that M0 has now completed.

The previous reviewed kit was 2.0.0 at
`cfeb26d4fdc7d4417e1e0d604e65cedf42040c4d`, with trusted manifest SHA-256
`b643a1596eab2612ec7881a8ebc76bc4a8e27611a8bf445c6daea679626004d9`.
Its exact location and runtime/source hashes are in the candidate record. Keep it
immutable. No result for a later checkout may silently inherit that kit identity.

Two prerequisites were found while preparing this plan (historical state):

1. `run-local.sh` / `harness.sh packages` rebuilt the checkout without accepting
   the frozen kit. `source-commit.txt` described the harness checkout,
   not necessarily the actual installed source identity.
2. The repository build pin was Node 24.21.0. The official
   [October security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases)
   identifies 24.22.1 as a patched runtime. Inspector storage already used 24.22.1,
   but the app's build pin/guards and previous candidate reconstruction had not
   been migrated. A clear npm audit does not assess Node runtime vulnerabilities.

Approved choice, completed in M0: update the build toolchain and freeze a reviewed successor
kit, then every native cell and source-offer check consumes that same kit. The
previous kit remains the comparison baseline. An archive from a dirty checkout or
rebuilding each cell independently is not an acceptable substitute. If the
successor changes runtime logic or served docs, review those differences and
repeat affected qualification; metadata-only differences still need recording.

## Reviewed image pins

The [official maintenance schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule)
listed these maintained patches on 2026-10-29. Registry metadata was read without
pulling layers or starting containers. Use the **linux/amd64 manifest digests**
below, not a floating tag. M0 has applied and verified these pins in the matrix;
the database/Dovecot leaf digests and full metadata results are in its report.

| Profile | Reviewed image |
| --- | --- |
| NC33 / PHP8.4 / Apache | `nextcloud:33.0.10-apache@sha256:9ef9786fec0274ff88d7467e246034f8ad4e5742e344de6e049e05a3ee2bb74d` |
| NC34 / PHP8.5 / Apache | `nextcloud:34.0.5-apache@sha256:e881320833451d8320727d7e20386c39021d51ba03bbb1e153ddfeb114ce6145` |
| NC35 / PHP8.5 / Apache | `nextcloud:35.0.2-apache@sha256:c73d6b4ada5126dc56bbd6357f9c73c9caf99f4a692bd2da4187ea58b9089eec` |

The corresponding image-index digests, for provenance only, are
`5ded1127972ea84c29792215f0621c71929ed315cc47be946b489c7a1c1ad305`,
`515c072ea1cf82ece3503f301fdf75dd5a7148abcc8fa10aaefd5ec56643b703` and
`e0b1a2af52c82c07b7a0e8e59878bd8769e704447734d5d238a836982f51b9c8`.
Registry annotations name PHP 8.4 for NC33 and 8.5 for NC34/35. M0 verified
linux/amd64 metadata, expected PHP patch versions and version-specific core
requirements. M1/M2 must still record the actual running versions.
M0 retained the database releases in
[`matrix.json`](../../../mcp/tests/Integration/ci/matrix.json): MariaDB 10.11.19 and
PostgreSQL 16.15, resolving their amd64 manifests and maintenance status. SQLite
is a test profile, not a production support claim. M0 also verified
Mail 5.12.5/Dovecot 2.4.5 inputs in
[`mail-pins.json`](../../../mcp/tests/Integration/ci/mail-pins.json), without
expanding Mail to other core versions by inference.

## M0 — Freeze executable inputs and prevent candidate substitution

**Files:** `mcp/.node-version`, `mcp/package.json`, `mcp/package-lock.json`,
`mcp/scripts/build-assets.mjs`, matching build tests/toolchain documentation and
workflow pins; native `ci/matrix.json`, `matrix.php`, `run-local.sh`, `harness.sh`;
new `mcp/tests/Integration/ci/candidate-kit.mjs`,
`mcp/scripts/release/accept.mjs` for the matching installed-tree gate, and tests
under `mcp/tests/release/native-candidate.test.mjs`.

- [x] Move the app build to Node 24.22.1 / npm 11.19.1 coherently, including guards,
  engine declarations and CI setup; keep the historical P0/P1 fixture contract
  explicit rather than changing its pins under old evidence. Validate locked
  installs, audit, assets, documentation, package and the complete check.
- [x] Refresh native image pins and prove the expected core/PHP/DB versions and
  linux/amd64 architecture. Recheck optional-app availability on each core.
- [x] Add an explicit native CLI input:
  `--candidate-kit ABSOLUTE_KIT TRUSTED_DIGEST`, before NC/DB/mode arguments.
  The helper verifies the kit and package paths/types, source identity and full
  installed-file manifest, then copies the exact archive into the owned run.
  Never rebuild or silently fall back when this input is invalid.
- [x] Record candidate SHA, manifest/archive digests and runner SHA separately in
  plans and artifacts. `--plan` validates inputs without Docker effects. Reject
  relative paths, bad digests, extra/altered files and identity mismatches before
  any resource creation. Add meaningful failure tests first; valid kit copies
  must retain their archive hash.
- [x] After fresh installation and after candidate replacement/upgrade, compare
  the **actual installed application tree** with the kit's runtime-file inventory
  and source identity, using the existing read-only integrity verifier. Version
  and enabled-state checks alone are insufficient. Require this gate in both the
  native harness and source-offer runner before their acceptance tests; retain
  the measured inventory/verdict and fail on missing, changed or extra files.
  Test that substituted installed bytes block advancement even when the archive
  and app version are valid. Do not repair or normalize the measured tree.
- [x] Keep baseline commits in `matrix.json` unchanged. Before execution, inventory
  each baseline package and its source/build provenance. Legacy builds requiring
  an old runtime must use a verified retained package or isolated offline locked
  reconstruction; do not weaken their guards. Unavailable baseline inputs block
  the affected cell rather than skipping or replacing it.
- [x] Only after all M0 tooling, pin changes and prerequisite tests pass, freeze
  the reviewed changes in a commit. Build and reconstruct the successor source
  kit from that commit and record its trusted digest outside it. Compare every
  extracted runtime file with `cfeb26d4`; do not normalize differences away.
  Recheck the final runner's plan/input gates against this kit before M1. Any
  later change to candidate inputs requires a newly reviewed kit and rerun of
  affected acceptance; record runner-only revisions separately.

**Acceptance:** exact kit selection and installed-tree enforcement are proven by
focused tests, no version/lock bypass exists,
all prerequisite checks pass, baseline packages are identified, and the chosen
successor kit has a separate reviewed identity. No matrix cell is accepted from
unreviewed new runtime bytes. M0 is not native platform acceptance.

## M1 — Source offer and primary profile first

**Files:** existing `mcp/scripts/release/accept.mjs`, source-offer fixture and native
runner, plus the new evidence report under `docs/en/development/`.

- [ ] Run `composer --working-dir=mcp test:source -- ABSOLUTE_KIT TRUSTED_DIGEST`
  with the patched verification runtime and refreshed NC35/SQLite pin. It already
  consumes the archive and publishes no host port. Require M0's new installed-tree
  gate before the source-offer checks; M0 implements it, but its real-container
  acceptance remains part of this batch.
- [ ] Require a non-admin's personal-settings offer, both MCP discovery paths,
  Basic/Bearer access boundaries, disabled-connection behavior, mismatched metadata
  denial, anonymous retrieval of all five files and rejection of a tampered copy.
- [ ] From the **downloaded** kit, verify the trusted digest, clone the bundle,
  detach at the candidate SHA, rebuild and compare every extracted runtime and
  vendor-source file. A successful download alone is insufficient.
- [ ] Run full native NC35/MariaDB `fresh --mail`, then `upgrade-upstream-120`,
  both with the verified candidate-kit option implemented by M0. Preserve the
  exact installed 1.2.0 baseline before replacement and native upgrade evidence.
- [ ] Independently verify removal of exact-owned containers, networks and
  anonymous volumes. Any uncertain ownership/cleanup is a failed gate.

**Acceptance:** source offer and fetched-source reconstruction pass; both native
cells pass unfiltered with expected conditional skips enumerated and no hidden
error. This is **2/15 matrix cells**, plus a distinct source-offer fixture. The
source fixture does not count as the full NC35/SQLite suite. No HTTPS/public
retrieval/ChatGPT or intended-server acceptance follows from this result.

## M2 — Remaining core and database profiles

Run sequentially (no shared packaging races), stopping advancement on an
unexplained failure:

| Profile | Modes | Cells |
| --- | --- | --- |
| NC35 / PostgreSQL16 | `fresh`, `upgrade` | 2 |
| NC35 / SQLite | `fresh`, `upgrade` | 2 |
| NC34 / MariaDB10.11 | `fresh`, `upgrade` | 2 |
| NC33 / MariaDB10.11 | `fresh`, `upgrade` | 2 |

**Acceptance:** 8 more cells pass, for **10/15 total**. PostgreSQL concurrency/log
assertions must actually run; do not generalize SQLite skips into database
acceptance. Require SQLite's coordinated recovery result where selected by the
runner. Each cell records actual versions, optional apps, candidate inventory,
JUnit totals/skips, exit code and exact cleanup. Unsupported optional features
remain explicit; a silently missing app does not establish capability parity.

## M3 — Remaining upgrade origins

On NC35/MariaDB, execute `upgrade`, `upgrade-local --mail`, `upgrade-upstream`,
`upgrade-upstream-111` and `upgrade-upstream-112` using their existing exact
baseline SHAs. These are five distinct origins, not repeated 1.2.0 tests.

**Acceptance:** **15/15 declared native cells** pass. Mail is explicitly enabled
in `fresh` (M1) and `upgrade-local` (M3); it is not an extra matrix cell or a claim
that every cell tests Mail. The current `matrix.php --matrix` returns 15 cells;
older 14-cell reports stay historical. Do not drop a failed upgrade origin from
the denominator or use filtered diagnostics as final acceptance.

## M4 — Reconcile evidence and close only the accepted scope

- [ ] Confirm all cells and source-offer results bind the same immutable kit;
  preserve failed attempts and distinguish diagnostic repetitions.
- [ ] Reconcile LinuxServer L0-L5 separately: its tested kit was U5, not this final
  successor. If claiming LinuxServer support for the final artifact, refresh its
  profile/kit pins and repeat the necessary candidate upgrade/source-offer/recovery
  qualification under that plan. Do not overwrite the retained checkpoints or
  infer LinuxServer/S3 acceptance from the Apache matrix.
- [ ] Record release-history/attribution review and ordinary-user source
  publication as separate gates. A local loopback source host is not the final
  immutable public destination.
- [ ] Verify owned-resource absence, redact artifacts and retain private inputs
  only under the recorded owner/retention policy. No broad Docker pruning.

**Acceptance:** the report lists exact coverage, remaining gaps and release
readiness without turning local tests into intended-host or human acceptance.
No source-kit publication, PR mutation, push or deployment is part of this plan.

## Review focus and persistent blockers

Candidate substitution, dirty builds, false-clean cleanup, baseline provenance,
and skipped optional/security checks are the principal failure modes; M0-M4 give
an explicit acceptance condition for each. Node runtime advisories are assessed
separately from npm audit. New upstream patches after these observations require
pin review before execution, not silent drift during a campaign.

[Inspector storage](inspector-storage-resolution.md) is locally corrected and
qualified only at the encrypted provider boundary. Its refreshed end-to-end
private/public OAuth journey, HTTPS, hosted ChatGPT and all human reviewers remain
pending. The MinicoLab production server is neither touched nor certified here.
