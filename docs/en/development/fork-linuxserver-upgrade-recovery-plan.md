<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer/Nginx isolated upgrade and recovery test plan

**Status:** reviewed on 2026-10-24; no runner implemented or rehearsal executed.
This is a local qualification plan, not a production runbook or deployment approval.

**Goal:** demonstrate a real MCP 1.2.0 → Nextcloud MCP 2.0.0 replacement and
coherent recovery on an isolated LinuxServer/Nginx, PostgreSQL and S3 fixture.

**Architecture:** add a separate, explicitly selected integration profile with
small lifecycle, checkpoint and probe owners. Keep the existing Apache matrix
unchanged. Run a real pinned baseline and candidate in sequence, with independent
restores into fresh run-owned storage and machine-verifiable evidence.

**Tech stack:** Linux x86-64, Docker, LinuxServer Nextcloud 35.0.2, Nginx/PHP-FPM,
PostgreSQL 16, a local S3-compatible fixture, PHP probes and the existing Composer
entry-point convention. Test code and documentation remain in English.

**Spec:** the [F3 delivery record](fork-f3-delivery.md), particularly the
[intended-host inventory](fork-f3-delivery.md#intended-host-read-only-inventory),
[source delivery contract](source-delivery.md),
[fork continuation gates](fork-continuation-plan.md) and
[1.2.0 integration record](upstream-1.2.0-integration.md).
Execute the reviewed plan phase by phase using the inline execution workflow;
implementation and fixture execution need their own subsequent task authorization.

## Boundaries and review focus

- No connection to or modification of the intended server. No production database,
  files, configuration, environment, initialization hook or bucket is copied.
- No push, PR action, release publication, public listener, preview exposure,
  DNS/ingress/firewall change or public HTTPS work. No Coolify/Traefik deployment.
- Each phase must pass before advancing. Missing dependencies, failed assertions,
  timeouts, interrupted runs and cleanup failures remain nonzero/incomplete results.
  A skipped mandatory check never qualifies the profile.
- No support-matrix expansion or F3 closure from this document or one successful
  rehearsal. Local evidence applies only to its exact candidate and profile.
- No application refactor or compatibility shim is planned. Baseline-only probe
  adapters belong to tests; production packages must contain neither probes nor
  baseline code. Preserve existing Apache checks without transition directories.

| Review risk | Required test owner |
| --- | --- |
| A restored database resurrects revoked credentials or pending operations | L4: revoke restored credentials before admitting clients; retry old access/refresh/code after reopening; distinguish changed-state confirmation rejection from unchanged-state fingerprints |
| App, database and S3 snapshots come from different generations | L2/L4: manifest generation and trusted hashes; reject incomplete or mixed sets before mutation |
| LinuxServer startup, an app path or cached FPM worker selects different bytes | L1/L3: resolve path and identity, restart/recreate at fixed image digest, compare installed files and HTTP identity |
| A successful CLI exit hides a failed native probe, TLS request or cleanup | L1/L5: explicit completion markers, assertions and exit codes; negative transport and ownership cases |
| The observed production version is mistaken for verified production compatibility | L0/L5: distinguish frozen upstream baseline from installed source, reference database from actual SQL target, local S3 from production provider |

## Inputs and unresolved pins

Resolve these before starting a fixture, and record full values in a reviewed
profile lock. Do not silently pull `latest`, rebuild a different candidate or
replace an unavailable digest. Capture image index/platform digest and actual
runtime versions separately; a tag does not establish them.

| Input | Selection and gate |
| --- | --- |
| Baseline | Upstream 1.2.0 commit `02526119b845ccc1d8399be002da3e30b5527df6`; use its reviewed packaging inputs and lockfiles, record archive SHA-256 and extracted inventory. This is not yet a byte comparison with the installed server app |
| Initial candidate | Reviewed source-audit commit `0000d8c807cfae300c2009ce53f9efb4a5df8081`; complete retained kit, trusted manifest SHA-256 `032b62b39bd9c56055bb3306b4a06c12efb77f876ea6da0087a550a89fc39e6f`. Recheck with `verify:source` before use. Later commits require their own kit and digest |
| LinuxServer image | Observed `lscr.io/linuxserver/nextcloud:version-35.0.2@sha256:e7f790011bf023d33517b4be70231d7aa2e19d54c619664a8533e8bedd4716ac`; verify availability and linux/amd64 selection in L0. Keep core version constant through app replacement and both restores |
| PostgreSQL | Reuse the existing reference pin `postgres:16.15@sha256:ca0bd484cb98bf4b24eb1010e73fb3fcbd6714d240fbc1a10eea5b7dbecb641d`; verify availability, SQL version and dump/restore tools. This is not the observed host's Alpine image or proof of its active SQL connection |
| S3 fixture | Recommend one persistent, single-node SeaweedFS S3 fixture. L0 must select a supported released image, freeze its linux/amd64 digest and configuration, and review its export/restore contract. Exercise authenticated object read/write/list/delete and recovery in L1/L4, after fixture creation. Release and digest are unresolved and block fixture execution |
| Other apps | Target observed Calendar 6.6.3, Contacts 8.9.2, Deck 1.19.0, Notes 6.1.0 and Talk 25.0.6; record immutable source/archive digests and NC35 compatibility before provisioning. Core OAuth2 2.0.0 is distinct from MCP's OAuth implementation. Do not substitute versions silently |
| Tools and harness | Record tool versions, harness commit and any harness diff, profile-lock hash, candidate manifest digest and baseline digest independently. Candidate evidence must never be attributed to the harness/documentation commit |

SeaweedFS is proposed only as a disposable S3-compatible test backend, not as a
production storage recommendation or proof of compatibility with the unknown
production provider. Its own [project documentation](https://github.com/seaweedfs/seaweedfs)
describes the S3 implementation; L0 must verify the selected release's commands
and persistence contract rather than relying on a mutable example.

Tasks and Groupfolders were absent from the selected host inventory, and external
storage was disabled. Record their absence explicitly. Core DAV/VTODO coverage
can exercise Tasks without claiming an installed Tasks UI; broader optional-app
coverage remains in the separate reference matrix.

## Proposed test ownership and interfaces

All paths below are **planned**, not available commands or files yet. Avoid
adding another mode full of LinuxServer conditions to the Apache `harness.sh`.

| Planned path | Responsibility |
| --- | --- |
| `mcp/tests/Integration/ci/linuxserver/profile.json` | Immutable profile inputs, limits and supported baseline/candidate pair; no secrets |
| `mcp/tests/Integration/ci/linuxserver/run-local.sh` | Phase ordering, run ownership, failure propagation and final cleanup; no application assertions |
| `mcp/tests/Integration/ci/linuxserver/runtime.sh` | Private network/volumes, LinuxServer readiness, s6/abc execution, TLS setup, writer quiescence and controlled restart |
| `mcp/tests/Integration/ci/linuxserver/checkpoint.sh` | PostgreSQL dump/restore and coherent config/app/data/object export/restore, manifest validation |
| `mcp/tests/Integration/ci/linuxserver/transport.php` | Profile base URL and private CA validation; never change the Apache `Support/Http.php` contract implicitly |
| `mcp/tests/Integration/ci/linuxserver/baseline.php`, `candidate.php` | Version-specific policy/OAuth adapters; load only their matching installed version |
| `mcp/tests/Integration/ci/linuxserver/upgrade.php` | Persisted-state and migration assertions across real app replacement |
| `mcp/tests/Integration/ci/linuxserver/recovery.php` | Credential timeline, recovery closure and reconnection assertions |
| `mcp/tests/Integration/ci/linuxserver/capabilities.php` | Select/adapt existing native capability cases through explicit path/transport inputs, without duplicating tool implementations |
| `mcp/tests/Integration/ci/linuxserver/source-offer.php` | Non-admin offer discovery and source download assertions; invoke existing release verification separately |
| `mcp/tests/Unit/Integration/LinuxServerHarnessTest.php` | Plan/argument validation, fail-closed orchestration, injected failure and ownership contracts |
| `mcp/composer.json`, `docs/en/development/testing.md` | One documented `test:integration:linuxserver` entry point, without changing default matrix selection |

The proposed entry point accepts `--plan`, `--profile FILE`, `--kit DIRECTORY`
and `--manifest-sha256 DIGEST`. `--plan` validates input structure and emits the
resolved run plan without Docker mutations. Full execution generates a unique
run ID and accepts no arbitrary existing container, database, volume or bucket.

The shared phase interface consumes a private run manifest (run ID, profile hash,
exact owned resource IDs, base URL, CA path and private credential-file paths).
A phase emits a sanitized result with its ID, start/end time, exit code,
assertion counts and explicit completion marker. Never serialize credentials,
OAuth codes or request Authorization headers into public results. Probes use
`Support/ProbeCompletion.php` so a host error handler cannot masquerade as success.
Checkpoint consumers additionally require a generation ID and manifest digest
retained outside the writable backup directory. No later phase infers success
from file existence or a version string alone.

Use three explicit runtime states: **closed** (normal HTTP blocked, maintenance
on, background writers paused), **probe-only** (only the run's test client admitted,
maintenance off, jobs still paused) and **cleanup** (admission closed, owned
resources stopped/removed). Keep the network unpublished in every state. The
admission gate must survive container recreation and act before PHP, independently
of Nextcloud maintenance. Permit only the narrowly required CLI upgrade/recovery
commands in closed state, then verify quiescence again before snapshots. Transition
to probe-only only after the phase's safety checks; every failure returns to closed.
Test a second, non-admitted run-owned client to prove the gate, not just its config.

## L0 — Freeze inputs and build the guardrails

**Deliverable:** reviewed profile lock and a tested, non-mutating plan mode.

- [ ] Review pinned baseline migrations and compare persisted schema/semantics
  against 2.0.0. Enumerate intentional changes before writing upgrade assertions;
  unchanged policy, grants, scopes and credentials must remain unchanged. The
  selected commits have identical `mcp/lib/Migration/` trees; record the actual
  installed migration ledger and native upgrade outcome rather than inventing a
  new SQL migration for the version change. The old 1.1.2 replacement probe is a reference, not proof of 1.2.0 compatibility.
- [ ] Reverify candidate kit integrity, baseline package provenance, all image/app
  digests and required tools. Resolve the S3 pin and export/restore method. Keep
  missing or unavailable inputs as hard preflight blockers.
- [ ] Implement plan validation with failing-then-passing tests for mutable images,
  wrong baseline/candidate version, mismatched trusted digest, outside-resource
  targets and missing required fields. No shell evaluation of manifest values.
- [ ] Bound one run to at most 4 CPUs, 8 GiB aggregate container memory and
  30 GiB planned artifacts/volume data; require at least 40 GiB free at preflight.
  Limit seeded object bytes to 256 MiB. Abort on storage pressure; do not prune
  other projects or change host quotas. Use a 60-minute total runtime limit,
  5-minute readiness limits and bounded subprocess timeouts, then owned cleanup.
- [ ] Run `composer --working-dir=mcp test -- tests/Unit/Integration/LinuxServerHarnessTest.php`
  and the proposed `--plan` entry point; verify no resources were created.
  L0 verifies pins and static contracts only; live S3 and SQL checks belong to L1.

**Acceptance:** all pins resolved and recorded, digest checks pass, guardrail
negative cases pass, no resources started. Resource ceilings are a test budget,
not a production capacity or performance claim. Any change of inputs reopens L0.

## L1 — Bootstrap the isolated LinuxServer profile

**Deliverable:** a synthetic NC35.0.2 instance with PostgreSQL and primary S3,
with verified execution context and private transport.

- [ ] Create a run-owned internal Docker network and separate `/config`, `/data`,
  PostgreSQL and S3 volumes. Publish no ports. Prepare pinned dependencies before
  removing runtime egress. Do not mount the host Docker socket inside containers.
- [ ] Generate private fixture credentials and a CA/certificate for a fixture-only
  hostname. Give clients that CA explicitly; verify hostname and certificate trust
  on real Nginx requests in probe-only state. Invalid trust/hostname must fail.
  No global verification bypass, public hostname or automatic HTTPS exposure.
- [ ] Prepare the exact candidate kit on a run-owned, read-only, fixture-only HTTPS
  endpoint trusted by the test client. Freeze its serving inputs in L0 too if it
  needs another image. Include it in ownership, resource, cleanup and recovery
  checks; advertise it only after the matching candidate is installed. No public
  source publication or new production route is involved.
- [ ] Configure primary S3 **before installing Nextcloud**, with an exclusive
  synthetic bucket and no anonymous access. Use an explicitly unversioned bucket
  for this first fixture; production version history, retention locks and external
  encryption-key recovery are outside its evidence. Verify persistence across an
  owned S3 restart and record backend limits. Keep local data too.
- [ ] Install the pinned apps and baseline MCP, disable the fixture app store and
  control background jobs explicitly. Inventory the image's startup and cron
  processes so they can be paused during checkpoints. Do not copy the unknown
  production initialization hook or claim its effects have been modeled.
- [ ] Resolve `/app/www/public/apps/mcp` to its actual persistent destination;
  verify ownership, permissions, mounts and symlink boundary. Run `occ` with the
  image's s6 service environment and `abc` identity. Verify SQL server version,
  Nextcloud status, CLI/FPM versions/extensions and HTTP readiness independently.
- [ ] Exercise failures for missing object-store environment, unavailable DB/S3,
  wrong app path and absent probe completion. Each must stop the phase. Protect
  readiness diagnostics from dumping environment/configuration secrets.

**Acceptance:** the baseline works through Nginx under private TLS; the real
DB/S3 data paths are exercised and persisted; wrong contexts fail visibly.
The current Apache `Http::BASE`, `www-data` and `/var/www/html` paths must not
leak into this profile. Private TLS does not close public HTTPS acceptance.

LinuxServer documents image-driven core updates and persistent volumes, and its
default endpoint uses a self-signed certificate. This plan keeps the core image
fixed and uses explicit private trust. Check the pinned image's actual behavior;
see the [LinuxServer image guide](https://docs.linuxserver.io/images/docker-nextcloud/).

## L2 — Seed 1.2.0 and capture a coherent pre-upgrade checkpoint

**Deliverable:** verified checkpoint **B0**, containing an actual running 1.2.0
installation and private expected-state/credential fixtures outside the backup.

- [ ] Seed administrator, allowed non-admin and denied users, group grants,
  exclusions, restricted scopes, filename rules, OAuth clients and personal
  connection preferences. Prove allow/deny behavior through MCP Basic and Bearer
  in probe-only state before snapshotting. Baseline adapters use upstream classes
  only, in their own process; never load upstream and fork class trees together.
- [ ] Seed Files content/shares, DAV events/contacts/VTODOs, Notes, Deck labels,
  dependencies/file links and Talk-visible data using the pinned native APIs.
  Record IDs and content hashes plus negative visibility assertions, including
  read-only recipients and excluded files. Cover the adopted 1.2.0 behavior from
  the integration record; classify baseline capability incompatibility as a
  blocker, not a skipped passing test.
- [ ] Issue test access/refresh tokens, a pending authorization code and pending
  write-confirmation plans; retain them privately with issuance/checkpoint times.
  Both pinned versions set `CODE_TTL = 300`, `ACCESS_TTL = 3600` and refresh
  lifetime to 30 days. Record actual expiry bounds; use the timed recovery cases
  below so expiry cannot explain a passing rejection. Do not extend lifetimes,
  change host time or edit token expiry rows to make a native check pass.
- [ ] Enter maintenance, block normal HTTP admission and pause every fixture
  writer/cron job. Verify quiescence, then create a PostgreSQL custom-format dump,
  capture synthetic role/bootstrap requirements, config/apps/themes and `/data`,
  and export all primary S3 object bytes and required metadata. Hash each object
  payload; do not use multipart ETags as content hashes.
- [ ] Record one generation manifest covering all components, app/core versions,
  profile/digests, ownership, capture interval and semantic inventory. Preserve
  modes, ownership and legitimate in-volume links; reject traversal, escaping
  links and special entries before archive extraction. Installed app content
  verification keeps its stricter no-symlink contract. Store the manifest's
  trusted digest separately. Check empty objects, Unicode names, multi-part-sized
  files and files changed/deleted after B0. Verify export completeness and reject
  missing or mixed components in unit tests before attempting a restore.

**Acceptance:** B0 passes integrity and inventory checks, with writers quiescent
for the entire cross-store capture. A SQL dump alone is not a cross-store
snapshot. PostgreSQL documents consistent single-database dumps and separate
global-role handling in its [pg_dump manual](https://www.postgresql.org/docs/16/app-pgdump.html).
Never copy a live PostgreSQL data directory as this plan's backup method.

## L3 — Replace 1.2.0 with 2.0.0 and verify persistence

**Deliverable:** successful real upgrade evidence and healthy checkpoint **B1**.

- [ ] With maintenance and writer/admission gates retained, verify and stage the
  candidate outside the scanned app path; replace the persistent MCP tree without
  leaving an old app or duplicate app ID in an apps directory. Run the native
  upgrade and capture its migration result. Keep the Nextcloud core image fixed.
- [ ] Restart PHP-FPM/container under the gate to clear old code caches; verify
  installed bytes against the trusted candidate manifest, native app integrity
  output and CLI version identity. Enter probe-only state only after those checks,
  then verify Nginx version identity and run the following HTTP assertions. Record
  unsigned-fork warnings explicitly; never disable global integrity checks or reuse an upstream
  signature over changed files.
- [ ] Compare the L0-approved schema/state expectations, migration IDs, grants,
  scopes, restrictions, client registrations and preferences. Test pre-upgrade
  unrevoked credentials still work within their original privileges and revoked
  credentials remain denied. Preserve confirmation safety: tampered fingerprints
  and plans whose relevant state changed must fail without side effects; do not
  equate age alone with invalidity. Classify intentional invalidation explicitly.
- [ ] Run positive and negative capability checks on the same seeded records.
  Include Files/Notes preview-confirm edits, DAV reads/writes and deletion backup,
  Deck dates/labels/dependencies/link/unlink/audience checks, Talk privacy,
  People/Logs permissions and filename enforcement. Read back allowed mutations
  through native APIs and verify denied operations changed nothing.
- [ ] Check real admin/personal settings routes and assets, plus complete source
  delivery through a non-admin personal session and MCP Basic/Bearer. Download
  all offered kit artifacts without maintainer credentials and compare their
  hashes. Anonymous MCP calls must be denied; disconnected users retain personal
  source access, and artifact downloads do not require MCP authorization. Test
  missing/mismatched offer identity is not advertised. Separately require
  `verify:installed` to reject tampered app snapshots and `verify:source` to reject
  changed downloads. `Release/SourceOffer` matches metadata to `source.json`; it
  does not hash every installed file or automatically block execution of tampered
  PHP. Reuse release verification without claiming that runtime enforcement.
- [ ] Recreate only the owned Nextcloud container with the same digest and volumes.
  Start recreation in closed state, verify bytes, then enter probe-only to check
  DB state, objects and permissions. Return to closed and capture coherent B1
  with the L2 protocol after successful checks, including source-kit availability.

**Acceptance:** 1.2.0 → 2.0.0 succeeds on the same persistent synthetic state;
no privilege expansion, lost data, duplicate app tree or unresolved required
check. Record expected warnings separately from failures. App-store disablement
and same-image recreation demonstrate fixture control/persistence only, not
production update exclusion or safety of a future image change.

## L4 — Restore 2.0.0 and roll back a failed upgrade

**Deliverable:** two independently verified full restores into fresh run-owned
volumes, plus fail-closed negative cases. Never run 1.2.0 code on the migrated
2.0.0 database as a rollback shortcut.

For each R0/R1 failure case, prepare a version-matched owned instance from B0/B1,
refresh the synthetic auth fixtures in probe-only state and immediately capture a
new coherent `B0-<case>`/`B1-<case>` generation. Record its distinct digest and
credential-only changes; never relabel the original B0/B1. Then mutate/revoke,
restore and assert rejection within the 300-second pending-code lifetime. Require
at least 30 seconds remaining at each relevant assertion. A missed window is
incomplete, not a revocation pass; repeat with a fresh case checkpoint if feasible.
This separates short-lived credential evidence from the longer capability suite.
B0/B1 in the cases below refer to those explicitly recorded case generations;
original checkpoint hashes and semantic inventories remain separate evidence.

- [ ] **R1, restore B1:** after snapshot, change/delete records and revoke an active
  credential. Restore the full 2.0.0 B1 generation into new volumes and an empty
  owned database/bucket. Match config, app, DB, local data and S3; compare file
  hashes and semantic state. The restored database must demonstrably contain the
  pre-revocation state before applying the recovery safety cut.
- [ ] **R0, return to B0:** start a separate copy of B0, attempt replacement and
  run two failure cases: immediately after app replacement, and after native
  `occ upgrade` returns but before reopening. The selected migration trees are
  identical: these are orchestration boundaries, not proof of interruption inside
  an SQL migration. A future candidate with new migrations needs its own failure
  point and expectations before execution.
  Assert the failed journey stays closed. Recover by restoring the entire B0
  set to fresh storage, including its 1.2.0 app and database. Verify the baseline
  through its version-specific probe. Preserve the failure evidence; do not claim
  the interrupted upgrade passed or that R0 leaves 2.0.0 installed.
- [ ] For both paths, validate manifests **before** destructive restore steps,
  keep Nginx admission/jobs blocked through startup and prevent automatic core/app
  upgrades. Require `pg_restore` to stop on errors; verify roles/ownership and no
  partial database import. Restore source-kit availability for the 2.0.0 source
  offer as part of its external configuration, not just the installed app files.
- [ ] Before releasing maintenance, disable MCP globally and disconnect every
  affected synthetic account with that restored version's supported policy API.
  Verify auth epoch/generation change, original restrictions and absence of new
  privileges. Reject pre-snapshot and post-snapshot access/refresh tokens and
  pending codes after an explicit controlled reconnection, with expiry controls
  above. Prove the global enabled → disabled transition actually advances the
  restored epoch; do not count a no-op setter as a credential cut.
  A rollback must not resurrect a revocation that happened after its checkpoint.
  Only after this cut, enter probe-only with MCP still globally closed to assert
  denial over HTTP; reopen MCP explicitly for the subsequent reconnection checks.
- [ ] Test Basic credentials are denied by MCP's disconnected/global policy; do
  not claim this revokes Nextcloud app passwords everywhere. Reconnect one reviewed
  synthetic account, issue fresh OAuth credentials and prove a permitted operation
  works while restricted operations still fail. Validate confirmations separately:
  old credentials cannot submit any operation; with fresh credentials, a tampered
  fingerprint or a plan whose relevant arguments/state changed must fail without
  side effects. `Mcp/Confirmation/PlanState.php` is a stateless HMAC of tool, user
  and shown state using the instance secret, with no expiry or recovery epoch.
  An unchanged-state fingerprint may still match after restore/reconnection; do
  not assert blanket invalidation or rotate the Nextcloud instance secret. Require
  fresh previews in the recovery procedure and record this contract explicitly.
  Leave MCP closed at the end.
- [ ] Apply and verify Nextcloud's recovery data-fingerprint procedure where state
  moves backwards. Measure checkpoint age and restore time; retain changed/deleted
  file expectations. Real desktop/mobile synchronization remains untested here.
- [ ] Inject missing S3 content, bad archive hash, foreign generation, partial
  restore, unavailable storage, wrong candidate digest and foreign resource IDs.
  Validate backup paths/types before extraction and exercise traversal/escaping
  symlink cases as well. Reject unsafe inputs before activation; do not resume
  jobs or clients after a failed restore. All resource destruction is limited to exact run-owned IDs.

**Acceptance:** R1 restores 2.0.0; R0 restores 1.2.0 with its matching state;
both preserve data/restrictions and reject historical credentials after recovery.
No mixed-generation activation, silent partial restore or unverified rollback.
The checkpoint age is the measured data-loss boundary, not a promised zero RPO;
restore duration is evidence, not a production RTO commitment.

The Nextcloud manuals explain coordinated backup components, primary S3's
DB/content split and data-fingerprint handling after recovery:
[backup](https://docs.nextcloud.com/server/latest/admin_manual/maintenance/backup.html),
[primary storage](https://docs.nextcloud.com/server/latest/admin_manual/configuration_files/primary_storage.html),
[restore](https://docs.nextcloud.com/server/latest/admin_manual/maintenance/restore.html).
Those retrieved pages identify themselves as the **36** manual; the version-35
URLs could not be retrieved during planning. Use them for the general rationale,
then verify exact NC35 commands/behavior against the pinned image in L0/L1.
Do not treat a mutable `latest` manual as NC35 acceptance evidence.

## L5 — Review evidence, validate and clean up

**Deliverable:** sanitized local report with reproducible inputs and honest limits.

- [ ] Store private run evidence under `mcp/build/integration/<run-id>/`, mode
  `0700`, secret-bearing files `0600`; nothing from that directory enters Git.
  Include each phase exit/completion status, immutable input hashes, migrations,
  assertion totals, checkpoint manifests, HTTP results, durations and warnings.
- [ ] Unit-test cleanup ownership mismatches and Docker inspection/removal errors.
  Inspect exact resource IDs and ownership before deleting; never use broad
  prune commands. A failed ownership query is a failure, not proof of absence.
  Cleanup runs on success, failure and ordinary interruption. A hard kill remains
  incomplete and needs an explicit owned-resource cleanup report on the next run.
- [ ] Remove run-owned containers/networks/volumes and private working copies of
  secrets after evidence capture. Retain only needed private evidence with access
  restrictions and documented retention/removal responsibility; redact public
  summaries. Do not remove any existing fixture or unrelated worktree.
- [ ] Run the new harness unit tests, `composer --working-dir=mcp check` and the
  relevant existing Apache upgrade/recovery regression cells after implementation.
  Keep their result sets separate from this new profile; a failed native mandatory
  assertion cannot be overridden by a green unit suite.
- [ ] Record L0-L5 outcomes and R0/R1 final versions in the F3 delivery record with
  links to the new local report. Rerun against a new frozen candidate if application
  changes become necessary; do not transfer old source-kit hashes or test results.

**Acceptance:** required assertions and cleanup pass, regression checks pass,
failures/blocked scenarios remain visible, and the report names exactly what was
executed. Production recovery readiness is not inferred from this fixture.

## Closure limits and next action

The next implementable batch is **L0**: resolve the S3/app pins, verify inputs,
and implement/test the plan-mode guardrails. No server access is needed for that
batch. This planning task does not start it or create containers.

| Still pending | Why local success does not close it |
| --- | --- |
| Intended-host source and state comparison | Installed 1.2.0 bytes/migrations, effective DB connection/server version, FPM settings and initialization-hook effects still need qualification |
| Intended-host updates, backup and recovery | Actual app/image update writers, S3 provider/versioning/retention, backup topology and restoration mechanism remain unverified; disabling a local app store is not an approved production control |
| Final release and support matrix | This is one additional target profile. The final frozen artifact, complete reference matrix, source-history/publication review and trusted distribution remain separate gates |
| HTTPS | Private fixture TLS does not validate public DNS, certificate chain, ingress or hosted-client access |
| Inspector | Prior result remains **14/15**, with a nonzero run and the external encrypted-storage finding unresolved; no new server disclosure is inferred and this plan does not rerun Inspector |
| ChatGPT | Real hosted consent, tool use and revocation have not passed acceptance |
| Human review | Security, operations/recovery, license/attribution, accessibility and language reviewers remain unassigned/pending. The owner retains operation/recovery responsibility, but that is not completed independent review |

Automated fixture tests should run without human actions once their inputs are
approved. Human review and real hosted-client validation are separate claims,
not silently waived or represented as automated passes. PR #14 remains a
historical reference; this work does not change it or restore an upstream remote.
