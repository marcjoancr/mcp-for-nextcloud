<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# MinicoLab update and integrity audit

Date: **2026-10-31**, Europe/Madrid. **Read-only audit completed; F3 remains open.**
This follows [renewed L5 closure](fork-linuxserver-l5-current-candidate.md) and the
[F3 update-control contract](fork-continuation-plan.md#f3-qualify-identity-licensing-and-upgrade-control).
The installed MCP files now declare **1.2.1**, superseding the older 1.2.0 host
observation. Existing 1.2.0 → 2.0.0 evidence keeps its original scope; it does not
qualify an upgrade from the newly observed installation.

The subsequent local comparison matched all 574 captured files against the published 1.2.1 archive. It also fixes the 1.2.2 release input for compatibility review. [Package pins and isolated tests](upstream-1.2.2-intake.md)

## Scope and method

Existing trusted SSH access and noninteractive administrative permissions worked.
Queries selected the single running LinuxServer Nextcloud container, then resolved
its Coolify service from its label. Only selected image/mount metadata, file
inventories, static core/startup code, configuration-key declarations, cron
indicators and service-related Coolify metadata were inspected. SQL sessions used
`default_transaction_read_only=on`; commands, connection secrets and full Compose
or configuration documents were not exported. Private evidence stays under
`mcp/build/f3-host-update-audit/`, with directories `0700` and files `0600`.

The PHP probes read files without bootstrapping Nextcloud. They did not invoke
`occ`, execute update/install/startup scripts, open the application UI, run native
integrity verification, restart services or perform backup/restore operations.
No production data, OAuth credentials, S3 credentials or raw environment were
exported. No server files or configuration were deliberately changed. Normal
access logging is not suppressed. There was no push, deployment or PR #14 action.

The installed app and copied core-source inventories matched at the beginning
and end of the audit. This is a dated observation, not an atomic snapshot or a
guarantee against subsequent external maintenance.

## Installed source and runtime

| Observation | Result and boundary |
| --- | --- |
| Nextcloud source / PHP CLI | 35.0.2 / 8.5.12; effective FPM settings and extensions were not qualified |
| Container | Same LinuxServer 35.0.2 digest used by the renewed isolated profile; root filesystem writable, not privileged, restart policy `unless-stopped` |
| Coolify | Running image 4.5.3; the earlier inventory observed 4.5.0 |
| MCP manifest | Version 1.2.1, 574 regular files, no app-tree symlinks; database `installed_version` and enabled state were not refreshed |
| Source comparison | All 501 installed paths also tracked at upstream `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4` match byte counts and SHA-256; no mismatches in that intersection |
| Migration source | All 11 installed migration files match that reference; this does not inspect applied database migrations |
| Dependencies | Installed names/versions/references match the upstream production lock: pdfparser 2.12.5 and polyfill-mbstring 1.43.0. All 60 files under those dependency directories also match the trusted frozen candidate's runtime inventory |
| Published runtime | All 574 file paths, sizes and hashes in both host inventories match the published 1.2.1 archive, including the 13 generated vendor files. A separate local build reproduced 571 file contents. |
| App paths | `/app/www/public/apps/mcp` resolves to `/config/www/nextcloud/apps/mcp`; the inspected conventional aliases identify one physical tree; the two inspected `custom_apps` paths are absent |
| Write access | UID 1000 can write the app directory, its parent and `appinfo/info.xml`; FPM workers were observed at UID 1000. Directory mode is `0755`, file modes `0644` |
| Signature/Git markers | No app `signature.json` or `.git` directory; MCP is absent from the core shipped-app list |

The private host inventory has SHA-256
`163a92f9a9ffccaff699c4ccab7cd06195baa0620cdcdffb313abff59c71f5c6`.
This digest identifies the serialized map of app-relative paths, file sizes and
content hashes. The release tarball and the LinuxServer fixture inventory each
have their own pins. [Pinned inputs](upstream-1.2.2-intake.md#pinned-inputs)

The frozen fork remains `efb27979b6a90122ce45a6a509e52390030f7ed9`, version 2.0.0,
manifest SHA-256 `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.
No candidate or test-profile change was made. The current harness catalogs a
1.2.0 upstream baseline, not 1.2.1; silently relabelling that cell is unacceptable.

## Update paths and integrity findings

| Path or control | Observed evidence | Disposition |
| --- | --- | --- |
| Nextcloud catalog/UI and `occ app:update` | No literal `appstoreenabled`, `appstoreurl` or `appsallowlist` declaration found in the two inspected config files. Copied core uses the enabled official-store defaults when absent | No demonstrated per-app fork exclusion. Static declaration inspection is not a complete runtime configuration evaluation |
| `occ app:update mcp` / `--all` | Copied command checks update availability and selects app IDs; Installer uses a greater-version comparison and skips Git installations | Version 2.0.0 is not durable protection; a later official version can overtake it |
| Install/reinstall or direct installer use | `downloadApp()` reads catalog releases and selects a writable app path; its implementation does not use the Git-update shortcut | Test these paths separately; excluding one update command is insufficient |
| `appsallowlist` | The copied AppFetcher applies it only with a valid subscription; subscription was not queried | Do not assume this protects the host |
| `upgrade.disable-web=true` | Literal setting observed; app-update/catalog code has separate controls | Do not interpret the core web-updater setting as app-update exclusion |
| Native integrity | MCP is unsigned and non-shipped. Copied `integrity:check-app` skips such apps and can return 0; instance verification also selects shipped/signed apps | A skip/zero exit is not signature or content verification. No live integrity result is claimed |
| Content hashes | Installed-file and trusted-candidate inventories can detect changed bytes | Detection does not prevent writes, grant a signing identity or replace permission/update policy |
| Image/redeploy/startup | Image digest is pinned, but `/config` and `/data` are writable and app code persists in `/config` | A fixed image does not freeze the persistent app tree; qualify redeploy and startup behavior separately |
| Direct filesystem/administrative writers | Service UID can modify app code and its parent; Docker/host administrators can access persistent state | Define approved maintenance writers and audit/exclusion controls; no enforcement boundary is established here |

Copied core files, including Installer, AppFetcher, the integrity command and
Checker, are hash-bound in the evidence. These observations describe the code
read on this host. Native UI warnings, runtime configuration resolution and
end-to-end update behavior require isolated tests. Integrity routines can store
results; they were deliberately not executed during this read-only audit.

## Startup, schedules and backup limits

The actual startup script is `init-nextcloud-config/run`, SHA-256
`0dc72849a82fb5a0c53cb0a4170c4a9ea51e8c3659887675c9ac3ee6fc99c6c4`.
Its conditional paths migrate legacy layouts, replace app/config/theme links,
copy image content, change ownership and run `occ upgrade` during core upgrades.
The observed explicit removal loop targets `richdocumentscode`, not `mcp`;
this is not a promise about future image scripts. A read-only app mount must be
qualified against these behaviors before adoption.

The custom read-only OCR initialization hook contains `apk add` and an OCRmyPDF
reference, with no matched Nextcloud update command. Its hash is
`1a3c0d4e4223b6e7f6d6730353afab022b2554edcb6c6961d571e3fe71dc2617`.
It was inspected, not executed. The container's `abc` cron file schedules
Nextcloud `cron.php`; no explicit app-update command was matched in the inspected
cron files. Keyword inspection does not rule out indirect scripts or queued jobs.

Read-only Coolify metadata showed:

- No scheduled-task rows linked to the selected service.
- No scheduled database-backup rows linked through its nondeleted service-database
  records. The active Nextcloud SQL target remains unverified, so this does not
  establish that its database lacks backups.
- Coolify self-update enabled, schedule `15 0 * * *`, with its pre-update backup
  flag enabled. Scheduler timezone and execution history were not verified.
  These are Coolify controls, not evidence of a Nextcloud or S3 backup.
- Host security auto-updates disabled in the selected server settings; forced
  Docker cleanup disabled, with a configured cleanup schedule still present.
- A backup-related token in the selected service's Compose metadata, but no
  matched explicit app-update or app-store policy token. No backup mechanism,
  destination, retention or recovery claim follows from that token.

A Diun image was present among running containers; that observation alone does
not identify it as an app-update writer. Manual Coolify/API actions, deployment
credentials, external CI, indirect scheduler commands and operators' practices
remain outside the established writer inventory. The 1.2.0 → 1.2.1 change does
not identify who performed the update or which mechanism was used.

## Necessary isolated tests and acceptance order

The next batch admits the verified 1.2.1 archive as a distinct runner input.
The test plan defines the positive input, wrong-origin controls, isolated update
and integrity checks, and recovery to matching 1.2.1 and 2.0.0 checkpoints. Preserve the
accepted 1.2.0 profile and its checkpoints for comparison. [Test order and acceptance criteria](upstream-1.2.2-intake.md#next-isolated-tests-in-order)

Host qualification also requires the active database and applied migrations,
effective update configuration, external and manual writers, and the owner of
each control. The recovery evidence must cover the database, configuration, app
and S3 as one coordinated backup. Complete the writer inventory before accepting
any exclusion policy for MinicoLab.

The existing proposed policy is manual digest-bound fork updates plus a filtered
catalog omitting `mcp`, while retaining normal updates for other apps. This remains
an unapproved service design, not a deployed or tested solution. First compare it
with the host's available supported controls and operating cost. If no reliable
exclusion can cover all writers, return the technical app-ID/migration decision
to the owner; do not silently change identity or disable all app maintenance.

Do not restart the entire validated platform matrix just because these documents
change. Reuse unchanged-artifact evidence explicitly; add the newly relevant
1.2.1 origin and update/integrity checks, and repeat only checks affected by actual
candidate, dependency, runtime or control changes.

## Evidence and remaining decisions

The private `evidence-index.json` binds **46 records**, SHA-256
`fae6ab049cede21ff876108714c8ce1a3949d4057d284a319e257867965c5f2e`.
It covers the before/after file inventories, source comparison, filtered metadata,
read-only probes and copied static sources. Internal identifiers and raw evidence
stay private. The subsequent package comparison and its validation have a
separate index. [Intake evidence](upstream-1.2.2-intake.md#review-evidence)

The owner still needs to select the production update-control mechanism and
accept its maintenance responsibility. External writer coverage, coordinated
backup ownership and the policy for custom-app integrity warnings also need an
operational decision.

F3 remains open. Public HTTPS, refreshed Inspector end-to-end, hosted ChatGPT and
human security, operations/recovery, license/attribution, accessibility and language
review remain pending, as do source-history/publication decisions. This audit does
not approve a release, production upgrade, recovery RPO/RTO or client-support claim.
