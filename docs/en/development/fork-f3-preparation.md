<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# F3: independent release preparation

Status: partial local execution on 2026-10-23 from `5ff6672`. This record belongs
to the [fork continuation plan](fork-continuation-plan.md). No release is published
or deployed. The actual server facts and all human reviewer assignments remain
pending. Earlier evidence certifies only the revisions stated in its records.

## Owner decisions and candidate changes

The owner approved **2.0.0** and the proposed platform profiles, then selected the
public name **Nextcloud MCP**, preferring a descriptive Nextcloud-related name.
The earlier suggestion containing the maintainer's username was not accepted.

The manifest and settings display name now use Nextcloud MCP, with an explicit
independent-fork description. Version 2.0.0 is a development candidate. The
Nextcloud minimum is 33 and the PHP minimum is 8.4; NC35 remains the maximum.
The specific qualification profiles are NC33/PHP8.4/MariaDB10.11,
NC34/PHP8.5/MariaDB10.11 and NC35/PHP8.5 with MariaDB10.11 or PostgreSQL16,
on Linux x86-64/Apache. SQLite remains a local test/recovery target. Each profile
needs current maintained patches and final-artifact evidence before release.

App ID `mcp`, namespace `OCA\Mcp`, installation path and migration IDs stay intact.
The machine protocol name `mcp-for-cloud`, existing OAuth resource label, Basic
authentication realm and calendar PRODID are retained for compatibility; they are
not claims of upstream ownership.
Manifest project/issue/source links point to the fork. Original author, license,
NOTICE and visible Sintax attribution are retained. The inherited screenshot URLs
remain upstream historical assets, not evidence of the current fork interface.

The development Composer PHP 8.2 platform and PHP 8.2/8.3 compatibility tests are
retained deliberately. They keep historical source compatibility coverage; they
do not expand the narrower native runtime profiles declared for this release.
No dependency upgrade, schema change or protocol-tool behavior change is included.

The native harness now runs a real upgrade from pinned upstream 1.1.2 using
`upgrade-upstream-112`. Its strict newer-version check remains in place. The old
same-version replacement and NC32 candidate cells are retained in Git history at
`5ff6672`; they are not appropriate runnable modes for this new candidate. The
current matrix has 14 cells. No forced enable or rewritten candidate version is
used as release evidence. Its image pins still predate the latest maintenance
patches, so final matrix refresh and qualification remain open.

## Update control and integrity audit

Read-only inspection used the exact NC33.0.9, NC34.0.4 and NC35.0.1 images pinned
in the native matrix. Each audit process used no network, published ports or server,
a read-only filesystem and no extra Linux capabilities. This is source inspection,
not a live update or recovery test.

- `core/Command/App/Update.php` has no per-app exclusion option. `--all` enumerates
  installed apps, while an explicit app argument requests that app. A wrapper
  that omits `mcp` would not cover other update paths.
- `AppFetcher::get()` applies `appsallowlist` only when the subscription registry
  reports a valid subscription in all three inspected versions. It cannot be
  assumed to protect an unknown community installation.
- `Installer::isUpdateAvailable()` skips an application whose directory contains
  `.git/`. A genuine Git installation may be an avenue to qualify, but the current
  archive has no such directory. Do not add a dummy `.git`, claim an archive has
  this protection, or adopt a Git deployment without qualifying its source layout,
  permissions, web exposure, updater paths and operator tooling.
- Global `appstoreenabled=false` affects other apps as well and is not an accepted
  per-app solution. The read-only inspection changed no configuration and certifies
  no exclusion. The disposable native harness separately disables the App Store
  to isolate the packages under test; that is not the intended-host solution.
- `IntegrityCheck\Checker::runInstanceVerification()` checks shipped apps and
  non-shipped apps that contain `appinfo/signature.json`. An unsigned custom app
  follows a different path from a signed App Store artifact. This does not prove
  what the target host's UI, explicit checks or automation will report. The fork
  has no upstream signing key or certificate authorization; no global integrity
  bypass is introduced.

The official [code-signing documentation](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html)
explains App Store signing and certificate scope. These source findings narrow
the investigation but do not close the deployment gate: routine maintenance must
preserve this fork while keeping other apps maintained. If no reliable mechanism
can be qualified, return the technical app-ID/deployment design to the owner.

## Corresponding source and attribution

The tracked source includes original JavaScript modules, build scripts, dependency
locks, per-file notices and the complete license texts. The production package
alone excludes those build inputs and is not a complete source offer. Packaging
also needs historical English source revisions for translation provenance; a
plain source ZIP without Git history is not a sufficient clean-build input.

The administrator footer retains visible Sintax attribution. There is currently
no release-bound source download exposed to every MCP user through both normal
personal settings and protocol discovery. A repository link to a moving branch
is not sufficient to close that gate. The final release must provide a usable,
immutable corresponding-source destination, verify ordinary-user access locally,
and verify the public destination only after authorized publication. No fabricated
public release URL or partially functioning source link has been added.

A local source reconstruction and package validation is recorded below. It is an
engineering reproducibility check, not a declaration that an
unreviewed full-history bundle is safe to publish. Review historical content before
public distribution and retain dependency source/license completeness checks.

## Validation and remaining gates

The metadata/settings contracts were updated first: 5 expected failures on the old
name, project links and platform bounds, then 16 tests / 124 assertions passed.
The native planner contract failed on the missing 1.1.2 upgrade mode, then passed
14 tests / 129 assertions after the runner change. The full PHP suite passed
8,424 tests / 71,506 assertions.

| Check | Local result |
| --- | --- |
| Complete catalog `check` | Exit 0; 8,424 PHP tests / 71,506 assertions; 687 browser tests; both static-analysis scopes pass; syntax checks cover 1,286 PHP files |
| Documentation and client-runner tooling | 151 Markdown files / 110 package sources valid; 29 documentation and 10 client-runner unit tests pass |
| Translation provenance | Valid; all 96 translated drafts remain `review-required`, not human-approved |
| REUSE | 1,649/1,649 files have copyright/license information; no missing licenses or invalid SPDX expressions |
| Dependency notice inventory | All 50 Composer lock packages appear in third-party notices; production dependency licenses and app legal files are present in the archive |
| Clean-source reconstruction | 1,666 tracked source files match the working candidate; a bundle-only clone installs locked dependencies and builds the package successfully |

The unpublished source snapshot is `b53f71098647a77b8e067e3e7ce5a688be7d92f5`,
created only in an ignored verification clone. Its bundle SHA-256 is
`beeac63a0d525aa80fece8548c85f5bb96084e4f85679a85342ccb5c8b61324f`.
The snapshot includes the code and operational docs under test. Subsequent
documentation corrections, including the app README installation warning, are
not in that snapshot; the runtime, assets and test sources are unchanged. It is neither a
published release nor a commit on `fork/main`.

A strict archive-content equality probe failed on
`vendor/composer/installed.php`. Investigation found 935/936 files identical;
the remaining file differs only in the root package's Git-derived version and
reference, repeated in its two metadata entries, between the working tree and the
detached snapshot. No artifact was normalized or edited. The clean reconstruction
proves the build works from those sources, not byte-for-byte archive reproducibility.
Native qualification below uses the clean snapshot, not a claim that the dirty
working tree's Git reference describes every pending edit. Final release evidence
must use a committed, frozen source and artifact.

The clean development install emitted 34 Composer PSR-4 warnings for existing
manually loaded test doubles/fixture classes. They are recorded rather than hidden;
production dependency staging succeeds and the full suite passes. npm reported
zero known vulnerabilities in that install. The browser suite's missing-import
errors are expected negative-build tests, not ignored suite failures.

The independent code review found outdated English settings navigation after the
rename. Those links were corrected in clients, permissions and OAuth guidance;
no other material finding was reported. This engineering review does not assign
or complete the pending human reviews.

### Native candidate evidence

Both unfiltered runs use the unpublished snapshot above and the existing NC35.0.1 /
PHP8.5.11 image. Each exits 0 with no JUnit failures or errors. Their 936 packaged
file contents are identical; archive hashes differ because the packaging command
creates separate tar/gzip outputs. All run-owned containers and networks were
verified absent after cleanup.

| Cell | Result | Run ID | Candidate SHA-256 |
| --- | --- | --- | --- |
| NC35 / MariaDB / upgrade from upstream 1.1.2 | 276 cases / 4,237 assertions / 9 skips | `mcp-it-20261023174207-0e8ba7b607e9` | `8036834284de7366fdfcc7a84aca70e08b8cb68f5fc189682bb09f675c917156` |
| NC35 / SQLite / fresh plus coordinated recovery | 276 cases / 4,198 assertions / 12 skips | `mcp-it-20261023174631-36a0ecf18f78` | `61dab2bded3a754aa08da8a645afc681ba81a423124dc6ab11d4a660489dc400` |

The upgrade verifies installed version 2.0.0 and preserves migrations, policy,
resource restrictions, custom clients, generations, bearer access and built assets.
The recovery restores application plus SQLite state, rejects old access/refresh
credentials and a pending authorization code, verifies explicit reconnection, and
leaves the service closed. It does not qualify the owner's backup/storage topology.

Both runs skip six legacy 1.0.8-specific cases and three PostgreSQL-specific cases.
SQLite also skips three unsupported concurrency/deadlock cases. These skips are
not passing coverage; the strict upstream 1.1.2 preservation probe runs separately
before the shared suite, and MariaDB exercises its own lock/deadlock cases.
Only these two cells were rerun for this candidate, not the entire 14-cell matrix.

Sanitized JUnit, result, plan, package digests, migration inventory and source
revision records are retained under each run's ignored `mcp-it/artifacts/` folder
inside `mcp/build/f3-preparation/source-rebuild/mcp/build/integration/`. Local
`source-rebuild.json`, `package-comparison.json` and `native-audit.json` summarize
the reconstruction and artifact checks. Raw parent logs and fixture credentials
must not be published. These local paths are evidence retention, not public URLs.

F3 is not closed. Remaining work includes source-offer delivery and ordinary-user
acceptance, actual-host facts, update-control and integrity qualification,
actual-host recovery and the final candidate's F4 matrix. Installed upstream
1.2.0 upgrade is still unverified. Public HTTPS, Inspector's previously recorded
14/15 encrypted-storage result, ChatGPT's real journey and all human reviews
remain pending. Translation drafts and the changed localized metadata/UI still
require human review; English installation guidance governs this candidate.

The [local delivery follow-up](fork-f3-delivery.md) records the next execution:
a source/runtime kit built from a committed revision, read-only integrity tooling
and the concrete update-control candidate. Its narrower local results do not close target-host
qualification. The owner still needs to supply actual Nextcloud/PHP/database/app
versions and installation topology. No production inspection, infrastructure
change, push, tag, PR action or deployment is included.


### Review before the local F3 commit

The review corrected the changelog's obsolete 1.1.2 version and deferred Deck-link
claims, restored the native runner's App Store isolation explanation, linked the
current candidate evidence from the testing guide, and replaced the app README's
installation invitation with the release gates. These are documentation changes;
no runtime or native harness behavior changed after the tested snapshot.

The retained source bundle, native archive hashes, JUnit counts/skips and owned
resource cleanup were checked again. Fresh validation passed 30 focused
metadata/settings/harness tests with 253 assertions, 29 documentation-tool tests,
151 Markdown files / 110 package sources, translation provenance and REUSE
(1,649 files). All 96 translations remain pending human review.

The rebuilt working-tree package passed the full package validator with SHA-256
`7b1d65ecc025bdf178dcc4f5aad330f261093d71a048569d46adc78cf780e75a`.
It contains the reviewed README correction and is not either earlier native-test
archive. The source/runtime equality audit justifies retaining those native test
results without claiming a rerun or final release certification. The earlier full
PHP/browser/static-analysis results are likewise retained evidence; the fresh
checks above are the checks executed during this review.

This local commit does not replace F4 qualification at a frozen release commit,
nor complete any pending human review or actual-host acceptance. Publication and
deployment remain unauthorized.
