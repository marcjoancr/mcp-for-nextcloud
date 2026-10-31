<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# MinicoLab 1.2.1 upgrade origin and upstream 1.2.2 intake

On 2026-10-31 the official 1.2.1 release asset matched **all 574 installed files** in both retained MinicoLab inventories. Use those published bytes as the origin of the next isolated upgrade to the frozen fork `efb27979b6a90122ce45a6a509e52390030f7ed9` (2.0.0). Upstream 1.2.2 is a separate compatibility and capability review. The host evidence comes from the [read-only audit](fork-minicolab-update-integrity-audit.md).

## Pinned inputs

| Role | Tag and exact source commit | Published runtime archive SHA-256 | Bytes / files |
| --- | --- | --- | --- |
| Observed MinicoLab file origin | `v1.2.1` at `3c61e928f3abdb37ad60b80ed207a5e243bf0ec4` | `a3531836a0373df526908b5969370aac272e3561a07ae7c62e329cfc1358ef79` | 1,332,081 / 574 |
| Parent release for compatibility review | `v1.2.2` at `7d3e29bc21751c723d9228fce6f1426219772bbf` | `970f8ce3bbd2d524c7af02344421eaa956e8dcddaa86d2275b4aa798e61e8176` | 1,681,886 / 833 |

Retrieve `mcp-1.2.1.tar.gz` and `mcp-1.2.2.tar.gz` from the **release assets**. GitHub's source-code archives have different contents. The published sizes and SHA-256 digests were checked against both downloads. Each archive has a single `mcp` root, regular files and directories, unique member paths, and the expected app ID and version. [1.2.1 release](https://github.com/sintax-tech/mcp-for-nextcloud/releases/tag/v1.2.1) · [1.2.2 release](https://github.com/sintax-tech/mcp-for-nextcloud/releases/tag/v1.2.2)

```sh
sha256sum --check <<'DIGESTS'
a3531836a0373df526908b5969370aac272e3561a07ae7c62e329cfc1358ef79  mcp-1.2.1.tar.gz
970f8ce3bbd2d524c7af02344421eaa956e8dcddaa86d2275b4aa798e61e8176  mcp-1.2.2.tar.gz
DIGESTS
```

For 1.2.1, every relative path, size and content hash matched both captured inventories, including the 13 Composer-generated files left open by the host inspection. All 501 packaged files shared with the Git tree matched their blobs. For 1.2.2 the same check matched all 760 shared files. These checks establish file identity at the time of the audit. The database's applied migrations, effective policy and any later maintenance still require separate evidence.

For the LinuxServer 1.2.1 profile, use **inventory SHA-256** `c41d95e09dc4854f3c260d7891eb2c33279812a8f97e09b7365da63c76c7ca20`. To reproduce it, key each regular file by its full `mcp/` archive path, sort paths lexically and serialize each value with fields `sha256` then `bytes`. Use Python `json.dumps(mapping, indent=2)` followed by one LF, encoded as UTF-8. This uses the existing 1.2.0 profile's inventory format. The independent **candidate manifest** pin for fork 2.0.0 is `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.

An independent build of the 1.2.1 tag with its own packaging script, locked production dependencies, PHP 8.4.27 and Composer 2.8.8 passed its package check. It reproduced the paths and 571 file contents. The other three were `vendor/autoload.php`, `vendor/composer/InstalledVersions.php` and `vendor/composer/LICENSE`. The published asset resolves the installed-file comparison for all three. Exact source-build reproduction still requires a matching packaging toolchain. Keep the published digest as the fixture pin.

## What 1.2.2 changes for the fork

The tagged Git comparison spans two commits and 703 paths. Both tags have the same Composer lockfile and the same 11-file migration tree, `5213776af89952492bb1476cf3af05a5dd4cd3be`. The main compatibility changes are tool visibility, stored grants and native side effects. The frozen fork implements Mail account/folder discovery and the 1.2.1 backup and Tasks work. The following rows are proposed dispositions for newer capabilities. [Tagged source comparison](https://github.com/sintax-tech/mcp-for-nextcloud/compare/v1.2.1...v1.2.2) · [Fork U5 reconciliation](upstream-1.2.1-u5-integration.md)

| Area | Impact and proposed qualification |
| --- | --- |
| OAuth token endpoint | Review the narrow public-client identification fallback first. When the merged request parameters omit `client_id`, upstream accepts an eligible Basic header with empty password. Test present-but-invalid IDs, malformed headers, nonempty passwords, redirect/resource binding, PKCE and refresh. A pass qualifies that endpoint behavior only. |
| Mail | Review message/search/thread reads and attachment-text reads separately from flags, moves, drafts and sends. Each new grant starts denied. Test account isolation, UID-generation changes, bounded attachment decoding and uncertain IMAP/SMTP outcomes. An existing `mail.read` grant must grant account/folder discovery only. |
| Tables | New module for discovery, row reads and row create/edit. Test native table/view permissions, hidden filter columns and write-only shares. Check the optional app and API floor (1.0.0) before exposing tools. |
| Forms | New module for discovery, results, creation and question/option edits. Test own/all submissions, file access and shared edits independently. Account for partial results when creation requires multiple native writes. Upstream's minimum is 5.3.0. |
| Polls | New module for discovery, results, links and private poll creation. Test vote visibility, invitation/link exposure and partial creation under separate grants. Upstream's minimum is 8.6.3. |
| Files | Preview and access handling also changed. Review those paths together with the optional `files.delete` grant. Upstream limits deletion to eligible unencrypted Local home storage and warns about permanent loss. For MinicoLab's S3 primary storage the required test is refusal with zero deletions. Defer enabling deletion until a separate product and recovery decision. |

For each proposed port, exercise a pre-existing grant document, a fresh account, a group grant and an absent or incompatible optional app. Assert both `tools/list` and direct `tools/call` outcomes. Upstream gives ordinary `read` grants different defaults from sensitive reads and writes. Review the effect on existing users before adding new module columns. Keep evidence for any port tied to the fork revision that actually implements it. [Upstream grant definitions](https://github.com/sintax-tech/mcp-for-nextcloud/blob/7d3e29bc21751c723d9228fce6f1426219772bbf/mcp/lib/Service/GrantPolicy.php)

## Next isolated tests in order

| Step | Test and pass criterion |
| --- | --- |
| 1. Admit the 1.2.1 origin | Give the Apache runner a distinct `upgrade-upstream-121` mode and LinuxServer a separate 1.2.1 profile. Bind each profile ID to its allowed version, source commit and pins. Keep the 1.2.0 profile and checkpoints valid for their original origin. Apply the input checks below before starting step 2. |
| 2. Prove update exclusion and integrity | Select a candidate control for an isolated trial. Test the UI, explicit and bulk `occ` updates, install/reinstall, alternate app roots and restart/redeploy with warm and empty catalog caches. Demonstrate visibility of a higher-version `mcp` listing in the control catalog, omission under the selected policy and successful signed updating of another app. Both 1.2.x releases sort below 2.0.0. A lower version or an invalid signature cannot demonstrate exclusion. Compare loaded path, installed hashes, grants and migrations before and after each path. Record the unsigned-app warning and exit status separately from content verification. Modified, missing and extra files must each fail the trusted verifier. |
| 3. Upgrade and recover | Seed 1.2.1 backup policy, task parent/child relations, supported app state and OAuth fixtures. For the pinned 2.0.0 kit, verify continuity after upgrade and container recreation. Restore coordinated app/config, database and S3 checkpoints in isolation. Verify the matching 1.2.1 rollback and 2.0.0 recovery states, explicit reconnection, rejection of old credentials, source retrieval and exact cleanup. |
| 4. Admit a 1.2.2 origin only if needed | Require a fresh host file comparison if MinicoLab changes version, or an explicit decision to support that upgrade origin. Seed the new parent grants, test unavailable tools and check for dropped or widened permissions after reading and saving policy. Record which client-visible capabilities the fork retains or lacks. Identical migration files alone cannot establish that transition. |

For step 1, run the positive Apache plan with the explicit candidate kit and published 1.2.1 baseline package. LinuxServer's existing `--plan` reads a profile and kit only. Check its declared baseline pins there, then independently hash and validate the supplied archive and complete inventory during input admission. Use the same admission check in the executable fixture path. Exercise the retained 1.2.0 archive, the published 1.2.2 archive, the local 571-match rebuild and a tampered 1.2.1 archive. Each must fail against the reviewed 1.2.1 pins before a container, volume or network is created. Include mismatched profile/commit and inventory cases, and check that the accepted 1.2.0 planning path still passes.

The operational choice of update-control mechanism and the intended-host database, writer and backup evidence remain open. Public HTTPS, end-to-end Inspector, hosted ChatGPT and human security, operations/recovery, license/attribution, accessibility and language reviews remain pending. Step 1 has passed locally. Step 2 is the next isolated trial.

## Review evidence

The private intake directory `mcp/build/f3-upstream-122-intake/` contains a 12-record index with SHA-256 `48c4ebbf8da0090ccb3aa511ed178004d9bfba634e3b91378882826f09357dc8`. The documentation review rechecked both public tag commits and release asset metadata, rehashed the retained archives and compared their contents with the pinned Git trees. Both host snapshots matched the published 1.2.1 file inventory. The rebuilt archive retained the three documented differences. The host audit's separate 46-record index was also verified. Native upgrade and recovery acceptance is pending for this origin.

## Step 1 results

The published 1.2.1 archive now has an explicit NC35/MariaDB candidate mode and
its own LinuxServer profile. The 1.2.1 profile SHA-256 is
`3b4f842e5d47a2a149068d9983bb29f5dcda3266d75c3c8c704e4f3e890a615f`. The accepted
1.2.0 profile still hashes to `ddf56398a32a878350b50fea8d3ab22da2c192749867ce77c9e7f0110a9e90e9`.
Both profile IDs bind their baseline version, source commit, size, archive and
inventory digests, and file count to the reviewed catalog. Both runners stage the pinned archive and measure its complete extracted
inventory against the catalog. LinuxServer also checks the supplied inventory file. [Reproduction commands](../../../mcp/tests/Integration/README.md#minicolab-121-input-admission)

Six positive checks passed on the frozen candidate kit: the Apache plan,
LinuxServer plan and LinuxServer input check for each of 1.2.0 and 1.2.1. Both
runners rejected the 1.2.0 archive, 1.2.2 archive, locally rebuilt archive and
same-length tampered 1.2.1 archive when offered for the 1.2.1 origin. Additional
checks rejected a self-supplied wrong digest, checkout fallback, changed or
missing inventory, and a mismatched profile commit. A negative executable-path
probe in a private runner copy rejected the altered file before Docker preflight.
All 14 expected refusals exited nonzero. Docker, SSH and download command
tripwires remained untouched. Exact invocations and results are retained in
`mcp/build/minicolab-121-admission-hsv80ogv/` on devbox.

An initial attempt exposed Composer's global `--profile` option adding performance
counters to JSON output. The successful repeat used `--fixture-profile` through
the Composer entry point. The original shell option still selects the same file.
The implementation review added a regression test for a valid archive with a wrong inventory pin in Apache. After sharing the inventory check, all 58 selected Node controls and 70 PHP integration-harness tests (486 assertions) passed. The 1.2.1 bootstrap has not been run. Update exclusion, instance integrity,
upgrade and coordinated recovery still require their respective native trials.
F3, public HTTPS, end-to-end Inspector, hosted ChatGPT and human review remain open.

The full devbox `check` passed both static analysis scopes, 8,708 PHP tests
(74,514 assertions), 717 browser tests and packaging. The affected release,
archive and admission suites passed again with the shared inventory check. The
private `evidence-index.json` binds the case outputs, runner file hashes and
validation logs with SHA-256 `483a4c76f98ec3ca198f54e2da6340c408b6b2f61c3a35d810c1faacc0e181d2`.

During commit review, two negative tests exposed incomplete catalog entries that
Apache could accept. An upgrade now requires a baseline commit. Both the 1.2.0
and 1.2.1 origins require a valid inventory digest and file count. After the fix,
60 Node controls and 70 PHP harness tests passed, and the 20 published-input
cases produced the same six admissions and fourteen expected refusals. The
review evidence is under `mcp/build/minicolab-121-admission-0fikvx6p/` with index
SHA-256 `00ce8a815ce25f14ae52b72faae191a83ef464535294ba59967f6db056fcd704`.
