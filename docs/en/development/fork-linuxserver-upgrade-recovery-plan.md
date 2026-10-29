<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer/Nginx isolated upgrade and recovery test plan

**Status:** L0 inputs were renewed for U5 on 2026-10-27; the profile selects
`940c3da3` and its separately verified source kit. L1 baseline bootstrap is now
implemented and has passed native acceptance with cleanup. See the
[L1 results and limits](fork-linuxserver-l1.md) and preserve the historical
[L0-U5 evidence](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27).
L2-L5 remain unimplemented and have not started in this profile.
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

## Frozen inputs and qualification gates

Resolve these before starting a fixture, and record full values in a reviewed
profile lock. Do not silently pull `latest`, rebuild a different candidate or
replace an unavailable digest. Capture image index/platform digest and actual
runtime versions separately; a tag does not establish them.

| Input | Selection and gate |
| --- | --- |
| Baseline | Upstream 1.2.0 commit `02526119b845ccc1d8399be002da3e30b5527df6`; use its reviewed packaging inputs and lockfiles, record archive SHA-256 and extracted inventory. This is not yet a byte comparison with the installed server app |
| Historical L0 candidate | Reviewed source-audit commit `0000d8c807cfae300c2009ce53f9efb4a5df8081`; complete retained kit, trusted manifest SHA-256 `032b62b39bd9c56055bb3306b4a06c12efb77f876ea6da0087a550a89fc39e6f`. Recheck with `verify:source` before use. Later commits require their own kit and digest |
| Current U5 candidate | Published `fork/main` commit `940c3da36f0d9c5310a98d7c3f355d2d99c86d33`, including U5 and its publication record. Complete local kit verified; manifest SHA-256 `2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63`. Refreshed profile and evidence are in the L0-U5 record; no LinuxServer runtime qualification is claimed |
| LinuxServer image | Observed `lscr.io/linuxserver/nextcloud:version-35.0.2@sha256:e7f790011bf023d33517b4be70231d7aa2e19d54c619664a8533e8bedd4716ac`; verify availability and linux/amd64 selection in L0. Keep core version constant through app replacement and both restores |
| PostgreSQL | Reuse the existing reference pin `postgres:16.15@sha256:ca0bd484cb98bf4b24eb1010e73fb3fcbd6714d240fbc1a10eea5b7dbecb641d`; verify availability, SQL version and dump/restore tools. This is not the observed host's Alpine image or proof of its active SQL connection |
| S3 fixture | Recommend one persistent, single-node SeaweedFS S3 fixture. L0 must select a supported released image, freeze its linux/amd64 digest and configuration, and review its export/restore contract. Exercise authenticated object read/write/list/delete and recovery in L1/L4, after fixture creation. L0 selects standard 4.48 with full index/platform digests in the profile lock; see the L0 record for the unavailable standard 4.49 tag |
| Other apps | Target observed Calendar 6.6.3, Contacts 8.9.2, Deck 1.19.0, Notes 6.1.0 and Talk 25.0.6; record immutable source/archive digests and NC35 compatibility before provisioning. Core OAuth2 2.0.0 is distinct from MCP's OAuth implementation. Do not substitute versions silently |
| Tools and harness | Record tool versions, harness commit and any harness diff, profile-lock hash, candidate manifest digest and baseline digest independently. Candidate evidence must never be attributed to the harness/documentation commit |

SeaweedFS is proposed only as a disposable S3-compatible test backend, not as a
production storage recommendation or proof of compatibility with the unknown
production provider. Its own [project documentation](https://github.com/seaweedfs/seaweedfs)
describes the S3 implementation; L0 must verify the selected release's commands
and persistence contract rather than relying on a mutable example.

Tasks and Groupfolders were absent from the selected host inventory, and external
storage was disabled. Keep the five-app L0 fixture unchanged for this first
LinuxServer journey; Mail is not installed in that fixture. Test missing-app
non-advertisement and effective `mail.read` denial without inferring the host's
Mail installation state. Positive Mail coverage remains in U5's separate Apache
opt-in cell. Adding Mail/Dovecot to LinuxServer requires an explicit profile
variant with reviewed pins, resources and tests; it is not an implicit dependency
of this rehearsal. Record the selected app inventory explicitly. Core DAV/VTODO
coverage can exercise Tasks without claiming an installed Tasks UI; broader optional-app
coverage remains in the separate reference matrix.

## Proposed test ownership and interfaces

The profile, planning entry point and harness unit tests below exist for L0.
Runtime, checkpoint and native probe paths remain **planned**, not executable. Avoid
adding another mode full of LinuxServer conditions to the Apache `harness.sh`.

| Planned path | Responsibility |
| --- | --- |
| `mcp/tests/Integration/ci/linuxserver/profile.json` | Immutable profile inputs, limits and supported baseline/candidate pair; no secrets |
| `mcp/tests/Integration/ci/linuxserver/run-local.sh`, `plan.mjs`, `profile.mjs` | L0 offline planning and strict profile/kit validation; future phase ordering must retain these checks |
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

The L0 entry point accepts `--plan`, `--profile FILE`, `--kit DIRECTORY`
and `--manifest-sha256 DIGEST`. `--plan` validates input structure and emits the
resolved plan without Docker calls or networking. Execution is currently rejected.
Future full execution must generate a unique run ID and accept no arbitrary
existing container, database, volume or bucket.

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

## U5 evidence and immediate next batch

The [U5 reconciliation record](upstream-1.2.1-u5-integration.md) covers all selected
1.2.1 capabilities. Published `940c3da3` has passing
[Quality](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40563539950),
[Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40563540195),
[Package](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40563540304)
and [Native integration](https://github.com/marcjoancr/mcp-for-nextcloud/actions/runs/40563540166).
All 15 Apache cells passed, including the upstream 1.2.0 replacement and opt-in
Mail. These checks cover the candidate source revision, not a new source-kit
archive or LinuxServer/Nginx/PostgreSQL/S3 recovery. Native artifact retention is
14 days; workflow links are not a permanent backup or trusted release record.

### Completed batch: refresh L0 inputs for U5, without starting a fixture

Use the existing source-delivery and planning commands. Do not add a second kit
builder or change the planner to accept a mismatched candidate.

- [x] Freeze candidate `940c3da36f0d9c5310a98d7c3f355d2d99c86d33`; record the
  execution worktree/harness revision separately. Later harness-only commits may
  test that candidate, but any application fix needs a new frozen kit and evidence.
- [x] Prepare its complete kit with the reviewed toolchain:
  `composer --working-dir=mcp package:source -- 940c3da36f0d9c5310a98d7c3f355d2d99c86d33`.
  The builder writes `mcp/build/source/<full-commit>/` and rejects an existing
  destination. Inspect and verify any existing kit; never overwrite it silently.
  Retain the emitted manifest digest in an independent reviewed record, then run
  `composer --working-dir=mcp verify:source -- ABSOLUTE_KIT TRUSTED_DIGEST`.
  Check all five kit files, runtime `appinfo/source.json` identity and extracted
  inventory. The dirty development archive used during U5 is not this kit.
- [x] Reverify the retained 1.2.0 baseline archive against the L0 hash/inventory.
  If unavailable, rebuild from its pinned source and compare extracted content,
  record the reason and new archive/inventory hashes before updating the lock.
  If the retained inventory cannot be recovered or content differences cannot be
  explained against the pinned build inputs, stop: a freshly calculated hash is
  not evidence that the baseline content is equivalent.
  U5's Apache baseline archive has a different hash: a shared source commit does
  not authorize substituting one archive under another archive's digest.
- [x] Recheck the three pinned OCI index/platform manifests and five app archives
  without starting containers; keep the exact core/app versions. An unavailable
  pin or unexpected byte difference blocks this batch, not a fallback to latest.
  Leave live SQL/S3/FPM behavior, runtime tool availability and resource enforcement
  to L1 preflight. No production SSH connection or data is needed.
- [x] Update only the reviewed candidate fields (and any explicitly justified
  rebuilt-baseline fields) in
  `mcp/tests/Integration/ci/linuxserver/profile.json`; preserve strict validation
  in `profile.mjs`. Record the resulting profile SHA-256 separately from the kit
  manifest digest and candidate commit. Historical L0 evidence remains historical.
- [x] Run the existing `LinuxServerHarnessTest.php` suite and the real
  `composer --working-dir=mcp test:integration:linuxserver -- --plan --kit ABSOLUTE_KIT --manifest-sha256 TRUSTED_DIGEST`
  entry point. Require the selected U5 identity, matching hashes, zero exit,
  `validationScope: "profile-and-source-kit"` and `executionAvailable: false`
  at the L0-only revision. After L1 implementation this capability flag is true
  with `availablePhases: ["L1"]`; `--plan` still performs no runtime operation.
  Wrong digest/commit, changed kit, unsafe profile and execution-without-plan
  cases must remain nonzero. Validate documentation and record all results.

**Acceptance and stop point:** complete verified U5 kit, refreshed profile and
baseline provenance, freshly verified external pins, passing offline guardrails,
no container/server operation. A missing kit/digest or failed pin verification
keeps L0-U5 open. This batch does not implement L1 or qualify upgrade/recovery.
The authorized refresh completed these steps; see the
[exact kit, pins and offline results](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27).
That L0-only batch did not execute L1. The separately authorized L1 batch is
recorded below and in the [L1 results](fork-linuxserver-l1.md).

### L1 execution boundary

The L1 implementation uses a thin `runtime.sh` boundary, focused Node
lifecycle/transport owners and `LinuxServerHarnessTest.php` failure tests.
`run-local.sh`, `plan.mjs` and `profile.mjs` remain the guarded entry boundary. The
entry point must identify a baseline-bootstrap-only result and reject requests
for unimplemented L2-L5; a healthy baseline cannot be reported as an upgrade pass.
Use `--phase L1 --inputs ABSOLUTE_DIRECTORY` with the existing kit arguments.
The [L1 record](fork-linuxserver-l1.md#inputs-and-execution-boundary) documents
its exact CLI, prerequisites and baseline-only result.

Enforce aggregate CPU/memory/disk/time limits across every owned service/client;
verify the source endpoint using the pinned Nextcloud Nginx (no extra unpinned
server image). Prove admission closure survives restart, denied clients and bad
TLS fail, and successful/error/ordinary-interruption runs clean only their exact
owned resources. A hard kill remains incomplete and requires an explicit owned
resource audit/cleanup on the next run, as specified in L5. Run the isolated L1
acceptance with synthetic data only after its implementation checks pass. Its exit gate is a working, privately trusted
1.2.0 baseline on LinuxServer/PostgreSQL/S3 plus verified cleanup. L2 then seeds
and captures B0; L3 upgrades U5; L4 restores B1 and rolls back B0; L5 closes evidence.
Human review, public HTTPS and hosted clients are separate acceptance gates, not
manual actions required to run this automated fixture.

## L0 — Freeze inputs and build the guardrails

**Deliverable:** reviewed profile lock and a tested, non-mutating plan mode.
The completed boxes below record historical L0. The separate U5 refresh above
records renewed input qualification; neither result certifies L1-L5 execution.

- [x] Review pinned baseline migrations and compare persisted schema/semantics
  against 2.0.0. Enumerate intentional changes before writing upgrade assertions;
  unchanged policy, grants, scopes and credentials must remain unchanged. The
  selected commits have identical `mcp/lib/Migration/` trees; record the actual
  installed migration ledger and native upgrade outcome rather than inventing a
  new SQL migration for the version change. The old 1.1.2 replacement probe is a reference, not proof of 1.2.0 compatibility.
- [x] Reverify candidate kit integrity, baseline package provenance, all image/app
  digests and local build/planning tools. Live image tools remain an L1 check. Resolve the S3 pin and export/restore method. Keep
  missing or unavailable inputs as hard preflight blockers.
- [x] Implement plan validation with failing-then-passing tests for mutable images,
  wrong baseline/candidate version, mismatched trusted digest, outside-resource
  targets and missing required fields. No shell evaluation of manifest values.
- [x] Encode the runtime limits in the profile: bound one run to at most 4 CPUs, 8 GiB aggregate container memory and
  30 GiB planned artifacts/volume data; require at least 40 GiB free at preflight.
  Limit seeded object bytes to 256 MiB. Abort on storage pressure; do not prune
  other projects or change host quotas. Use a 60-minute total runtime limit,
  5-minute readiness limits and bounded subprocess timeouts, then owned cleanup.
  Runtime enforcement and cleanup belong to L1-L5; L0 checks their configuration.
- [x] Run `composer --working-dir=mcp test -- tests/Unit/Integration/LinuxServerHarnessTest.php`
  and the implemented `--plan` entry point; verify no resources were created.
  L0 verifies pins and static contracts only; live S3 and SQL checks belong to L1.

**Acceptance:** all pins resolved and recorded, digest checks pass, guardrail
negative cases pass, no resources started. Resource ceilings are a test budget,
not a production capacity or performance claim. Any change of inputs reopens L0.

## L1 — Bootstrap the isolated LinuxServer profile

**Deliverable:** a synthetic NC35.0.2 instance with PostgreSQL and primary S3,
with verified execution context and private transport.

- [x] Create a run-owned internal Docker network and separate `/config`, `/data`,
  PostgreSQL and S3 volumes. Publish no ports. Prepare pinned dependencies before
  removing runtime egress. Do not mount the host Docker socket inside containers.
- [x] Generate private fixture credentials and a CA/certificate for a fixture-only
  hostname. Give clients that CA explicitly; verify hostname and certificate trust
  on real Nginx requests in probe-only state. Invalid trust/hostname must fail.
  No global verification bypass, public hostname or automatic HTTPS exposure.
- [x] Prepare the exact candidate kit on a run-owned, read-only, fixture-only HTTPS
  endpoint trusted by the test client. Freeze its serving inputs in L0 too if it
  needs another image. Include it in ownership, resource, cleanup and recovery
  checks; advertise it only after the matching candidate is installed. No public
  source publication or new production route is involved.
- [x] Configure primary S3 **before installing Nextcloud**, with an exclusive
  synthetic bucket and no anonymous access. Use an explicitly unversioned bucket
  for this first fixture; production version history, retention locks and external
  encryption-key recovery are outside its evidence. Verify persistence across an
  owned S3 restart and record backend limits. Keep local data too.
- [x] Install the pinned apps and baseline MCP, disable the fixture app store and
  control background jobs explicitly. Inventory the image's startup and cron
  processes so they can be paused during checkpoints. Do not copy the unknown
  production initialization hook or claim its effects have been modeled.
- [x] Resolve `/app/www/public/apps/mcp` to its actual persistent destination;
  verify ownership, permissions, mounts and symlink boundary. Run `occ` with the
  image's s6 service environment and `abc` identity. Verify SQL server version,
  Nextcloud status, CLI/FPM versions/extensions and HTTP readiness independently.
- [x] Exercise failures for missing object-store environment, unavailable DB/S3,
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
- [ ] Apply the U5-specific expectations below before checkpoint B1; retain the
  pre-probe state snapshot separately from intentional policy and content changes.
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

### U5-specific upgrade and recovery expectations

The 1.2.0 and U5 `mcp/lib/Migration/` Git trees are identical
(`5213776af89952492bb1476cf3af05a5dd4cd3be`). That does not imply identical policy
or application behavior: check the installed migration ledger and actual state.
Do not import the 1.0.8-specific `LegacyCatalogContract` as the complete 1.2.0
comparison. Reuse assertions from `ci/replacement.php` through explicit profile
adapters, without its Apache path/transport assumptions or post-verification
cleanup: that probe deletes its seeded user, OAuth clients and private token
file. LinuxServer must retain its synthetic records and private expected-state
fixtures through B1 and both recovery paths; cleanup belongs to the run lifecycle.

| Concern | L2/L3 assertion | R1 and R0 assertion |
| --- | --- | --- |
| Preserved state / new operation | Compare old grants, scopes, policy bytes/events, OAuth state and generation before any probe writes. Allow only the explicit new `mail.read`, effectively denied for every seeded user/group combination; existing credentials must not gain it. | R1 preserves B1 grants/restrictions before the deliberate recovery credential cut. R0 restores the baseline catalog and complete matching B0 state; no candidate-only settings are spliced into the old DB. |
| Backup configuration and visibility | With no stored `backup_settings`, observe `MCP backups`, historical reservation and copies enabled without inventing a migration write. Then use the real revisioned admin API to rename the destination and test current/old backup privacy, shared aliases and ordinary nested names. Exercise optional Files copies only under native Versions admission and mandatory Contacts deletion copies. Disabling Versions must make copies effectively required while the existing `files_edit` refusal still applies; verify unchanged content rather than expecting the fallback to authorize a write. | B1 includes the deliberately configured backup document, reserved names, actual backup payloads and effective policy. R1 must restore all of them across DB and S3/local data; R0 returns to B0's original configuration and content. |
| Tasks hierarchy | Native-seed parent/child VTODOs and numeric UIDs; verify readable parent titles, direct-child listing, reparenting and preserved non-parent relations. Reject cycles, cross-calendar parents and depth beyond 64 without writes; include the allowed depth-64 boundary. Unavailable locking must reject confirmed writes that assign a parent, while removing a parent remains allowed under ordinary grants/ACLs; MCP locking does not serialize external CalDAV writers. | Compare UIDs, relations, readable titles and ordinary permissions after each version-matched restore. Do not require new U5 tool fields from the 1.2.0 probe or claim cascading completion/deletion. |
| Optional Mail | Keep Mail absent in this five-app fixture; verify no advertised Mail tools and denied effective read. Cite positive cache-only/ownership evidence from the separate U5 Mail cell without claiming LinuxServer Mail qualification. | R1 restores the same app inventory and denied Mail grant. R0 restores the 1.2.0 catalog without a Mail entry; do not require the baseline policy API to resolve an unknown operation. Neither path installs Mail or introduces a new permission. |
| Source identity | Download the complete frozen U5 kit through the private Nginx source endpoint, exercise non-admin personal/MCP discovery and verify downloaded/installed bytes against the separately trusted manifest. | R1 restores the matching offer configuration and complete kit. R0 uses its own baseline behavior and must not advertise a U5 kit as the source of 1.2.0. |

The existing credential expiry, epoch-cut, stateless confirmation, cross-store
checkpoint and fail-closed admission requirements below remain mandatory for U5.

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
  The completed L1 bootstrap uses its dedicated
  `mcp/build/linuxserver-l1/runs/<run-id>/` evidence directory.
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

L1 bootstrap has passed for the frozen U5 inputs; see the
[execution report](fork-linuxserver-l1.md). The next separate batch is **L2**:
seed the reviewed 1.2.0 capability fixtures and capture coherent checkpoint B0
with admission closed and writers paused. L2 requires its own authorization and
implementation. The current command rejects L2-L5 and cannot perform the
LinuxServer upgrade/recovery journey; no checkpoint or U5 replacement is claimed.

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
