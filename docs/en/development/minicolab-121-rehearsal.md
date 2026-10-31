<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# MinicoLab 1.2.1 upgrade and recovery rehearsal

**2026-10-31 decision. The candidate passed upgrade and recovery. Production
rollout remains blocked on control of filesystem writers.** The filtered
Nextcloud store protected the fork and continued to update a signed app. The
LinuxServer service account could still write both the active MCP directory
and its parent. The final runner returned exit 1 for this outstanding check.

One isolated devbox campaign exercises the exact published MCP 1.2.1 files seen
in the read-only host audit and the frozen 2.0.0 source kit. It uses synthetic
users, grants, credentials, tasks and files. Docker runs Nextcloud 35.0.2
(LinuxServer/Nginx), PostgreSQL 16.15 and SeaweedFS on an internal network.
Only the fixture client can enter during an admitted test. [Host audit](fork-minicolab-update-integrity-audit.md) · [Pinned inputs](upstream-1.2.2-intake.md#pinned-inputs)

| Input | SHA-256 |
| --- | --- |
| Published 1.2.1 archive, 574 files | `a3531836a0373df526908b5969370aac272e3561a07ae7c62e329cfc1358ef79` |
| LinuxServer 1.2.1 profile | `3b4f842e5d47a2a149068d9983bb29f5dcda3266d75c3c8c704e4f3e890a615f` |
| Candidate manifest at `efb27979b6a90122ce45a6a509e52390030f7ed9` | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |

The three image index and platform pins, five app inputs and the 1.2.1 archive
were checked on devbox. The harness verifies the extracted baseline inventory,
source manifest and private checkpoint generations before it can accept a restore.
B0 contains the initial 1.2.1 state. B1 captures the upgraded 2.0.0 state
before attempting any destructive update-control experiment. Both cover the
app and configuration, database, local data and S3 objects with their metadata.

## Results

The 1.2.1 baseline passed 130 HTTP assertions. On 2.0.0, 106 HTTP assertions
checked the same accounts and application state after the native upgrade.
The checks cover authorized and denied access, file shares and exclusions,
Notes, Deck, Talk, and Basic and Bearer credentials. The seeded backup policy
and parent/child VTODO relationship survived the upgrade and app recreation.
The source offer served the pinned kit over fixture TLS. All 975 installed
runtime files (6,014,647 bytes) matched the candidate at installation and
after recreation. Independent modified, missing and extra file checks each
failed verification until the copied snapshot was restored. Nextcloud returned
zero while explicitly skipping the unsigned MCP app in its native signature
check. The trusted kit comparison supplies the file-integrity evidence.

The first upgrade attempt stopped in the frozen admin regression suite. The
suite expected a fresh `MCP backups` policy and received the migrated
`Case Archive` value. Its teardown deletes that setting. The runner now
checks and saves the exact migrated value, gives the default-policy suite its
own starting state, and restores and checks the original policy before any
further migration assertions. The repeat passed all 55 native tests (1,144
assertions, zero failures, errors or skips). Both attempts are retained.

The first protection attempt ran after B1. Named and bulk updates were refused
because the fixture's configuration fragment still disabled its store. The
unprivileged client also lacked permission to read its public CA. These
refusals provide no evidence of filtering. The corrected runner reads back the
effective store setting and supplies the public CA to the client. It tests the
store exclusively on an isolated restore of the original B1 and leaves the
original checkpoints intact.

### Coordinated recovery

| Restored state | Injected condition | Verified client reconnection checks |
| --- | --- | --- |
| B1, fork 2.0.0 | Changes to data and credentials after the candidate backup | 87 |
| B0, upstream 1.2.1 | Candidate files replaced the app, database still on 1.2.1 | 87 |
| B0, upstream 1.2.1 | Native upgrade completed, admission still closed | 87 |

Each case restored into fresh volumes and checked the corresponding MCP version,
policy, task hierarchy and business state. The checks included the credential
cut, historical credential rejection, explicit reconnection, and refusal of a
client outside the fixture ACL. R1 also retrieved all five expected source-kit
files over private TLS and checked their hashes. HTTP stayed closed at both
injected upgrade stops. The fixture measures local restore behavior only.

The partial database, missing S3 object and unavailable storage imports all
failed closed. Nginx never served the partial state. The original B0 and B1
manifest digests were verified again after the negative cases.

### Update protection on the restored B1

| Operation | Measured outcome |
| --- | --- |
| Admin app page and its native OCS list | The control offered synthetic MCP 2.1.0 on stable and 2.2.0-beta.1 on beta. The filtered views kept the installed MCP 2.0.0 row and omitted the update and store metadata. For all four views a cold request fetched the catalog and a warm request reused the AppData cache. The higher MCP releases were visibility controls. |
| Named, repeated and bulk OCC updates | On both channels Nextcloud fetched the filtered catalog. MCP's selected path, kit inventory and stored state, including policy and applied migrations, stayed the same. |
| Reinstallation with the app directory absent | The native installer explicitly reported that MCP was missing from the store. The target path stayed absent until the runner restored it. Browser coverage was the authenticated app page and list. Reinstallation was exercised through OCC. |
| Signed positive control | Nextcloud installed Notes 6.0.0 and updated it through the filtered store to 6.1.0. Both native signature checks passed. |
| Alternate root and app recreation | Adding a second configured root containing a synthetic MCP tree triggered duplicate detection. After removing the shadow and recreating the app, the filtered catalog remained effective, the candidate bytes and B1 state matched, and the gate stayed closed. |
| Direct writes | The application user could write both the MCP tree and its parent. A filtered catalog cannot prevent that user, another filesystem writer, or a privileged operator from replacing those files. |

Of 37 recorded protection checks, 36 passed. The failed check and the
`write-protection-incomplete` blocker describe the same writable-tree finding.
The fixture restored its original store configuration and cache, closed HTTP
admission and turned maintenance on. The final cleanup checked 55 containers,
52 volumes and three networks across both upgrade attempts and the recovery
run. No recorded name, ID or ownership label remained in Docker.

## Production decision

The subsequent [single-directory mount trial](linuxserver-readonly-mcp.md) reproduced two bypasses. The [operator profile](minicolab-production-window.md) closes that local writer finding and records the outstanding production gates.

The next technical decision is who may replace the digest-pinned runtime and
how to enforce that boundary. A focused follow-up can test a read-only mount
for just the MCP runtime, backed by an operator-owned immutable artifact.
Require direct writes, renames from the parent and startup/redeployment to
preserve that boundary while signed updates to other apps keep working.
This layout still needs qualification against LinuxServer startup and Coolify.
The filtered catalog is a fixture demonstration. Operating a maintained,
trusted equivalent for MinicoLab requires an owner and an approved update path.

Production qualification also needs a current baseline match, the effective database
and migrations, all processes and administrators that can replace the app, an
owner for the update policy and a coordinated, restorable backup of the actual
MinicoLab stores. The operator also needs the exact candidate and corresponding
sources available during the change window. A change in the installed origin
requires reassessing that origin against its published files.

Public HTTPS, end-to-end MCP Inspector, hosted ChatGPT and the human security,
operations/recovery, licensing, accessibility and language reviews remain open.
The earlier Inspector storage result and private TLS checks cover narrower paths.
Upstream 1.2.2 has a separate capability review. This rehearsal qualifies only
the named 1.2.1 origin and 2.0.0 candidate.

## Evidence on devbox

Use the [integration runner guide](../../../mcp/tests/Integration/README.md#minicolab-121-input-admission)
with the profile and candidate pins above. The short campaign index and original
logs are retained privately under `mcp/build/minicolab-121-rehearsal-51v_6s_1/`.
Only the synthetic test environment used those checkpoints and credentials.
The reverified 116-record index has SHA-256
`504d0ea2a4bcc639ad36ccd1e05b5d0cb41e266d784bebdcbca7fa6c3e87f306`.

| Evidence | Binding |
| --- | --- |
| Upgrade and original checkpoints | `mcp-l3-c82af05c-eb68-4037-a27a-916ed4275cd7` |
| B0 manifest SHA-256 | `3605cf279981f2ead319099dcfd1e1b7a117e58d0cca9870b9fcdeb248fc339b` |
| B1 manifest SHA-256 | `8cdeb7b703c489dfc6a2a0f706517dd254a85e7b9225d5cc14b2fa2415854adb` |
| Recovery and final protection result | `mcp-l4-1fd31507-cbbe-4aca-aace-25d783006811` |
| Offline control/filtered catalogs and Notes inputs manifest SHA-256 | `7c8406804324fd3af69cd7a0c166c282ad0046d72b0fb762bbecb129b845ef17` |

The recovery/protection result holds the three successful recovery cases and
the individual store checks even though its overall exit is nonzero. The
private cleanup record independently checks resource identity after teardown.

The runner regressions passed 44 Node checks, 70 PHP harness tests and two
archive tests. A repository check initially used the host's default Node 20
and correctly rejected that runtime. With pinned Node 24.22.1 the full check
passed both static-analysis scopes, 8,708 PHP tests, 717 browser tests and
development packaging. The package built from this working tree is a
development artifact. The native trial used the frozen source kit bound above.
Documentation validation covered 196 Markdown files and 115 package sources.
Translation provenance is valid with 100 entries awaiting human review.
