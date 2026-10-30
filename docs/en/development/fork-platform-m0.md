<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# M0: verified platform inputs and source candidate

Date: 2026-10-30. Scope: [M0 of the platform/source plan](fork-platform-source-acceptance-plan.md).
**M0 complete.** This is local preparation and control validation, not native acceptance. M1-M4,
public HTTPS, end-to-end Inspector, hosted ChatGPT and human reviews remain open.
No production server access, deployment, push or PR #14 change is included.
Subsequent [M1 acceptance](fork-platform-m1.md) is recorded separately; the
zero-native-cell statements below describe this M0 checkpoint.

## Runtime and compatibility

Current app builds and candidate verification require Node **24.22.1**; the npm
engine, package-manager pin and integration workflow select **11.19.1**. Locks,
build/test guards and the maintained setup guide agree. The
[Node security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases)
is assessed separately from npm audit. The system default runtime is unchanged. Official Node distributions bundle the
required npm versions: [24.22.1 includes npm11.19.1](https://github.com/nodejs/node/blob/v24.22.1/deps/npm/package.json),
while [24.21.0 includes npm11.19.0](https://github.com/nodejs/node/blob/v24.21.0/deps/npm/package.json).
The existing setup-node workflows therefore satisfy the exact npm engine.

The historical `upgrade-local` baseline still requires Node 24.21.0. A real
packaging-only reproduction first failed with `EBADENGINE` under the new runtime.
The checkout/CI path now fetches locked npm registry tarballs with the patched
runtime, then installs and builds the historical source offline with its exact
runtime. Composer networking is disabled for that build. The packaging-only
repetition passes without starting Docker. A missing historical runtime/cache
fails explicitly. No engine override is used and old tooling is not declared
security-current. Final candidate upgrades consume the reviewed retained archive
instead of rebuilding it. The separate historical Inspector TLS runner retains
its original pins and remains pending refresh/acceptance.

## Kit and installed-tree controls

`test:integration` accepts `--candidate-kit ABSOLUTE_KIT TRUSTED_DIGEST` before
NC/DB/mode. Upgrade cells additionally require
`--baseline-package ABSOLUTE_ARCHIVE TRUSTED_SHA256`. See the
[native runner instructions](../../../mcp/tests/Integration/README.md#frozen-candidate-acceptance).

- Preflight checks absolute paths, the externally trusted manifest digest, exact
  kit inventory, archive paths/types, source/version identity and all extracted
  runtime bytes. `--plan` never calls Docker. Empty/invalid explicit inputs never
  fall back to checkout builds.
- Execution stages verified kit bytes in the owned run, rechecks copied hashes
  and retains candidate SHA, manifest/archive hashes, runner SHA/dirty status and
  runner-file hashes separately. Source acceptance uses the same controls.
- After install/upgrade, before fixtures or acceptance tests, the gate checks the
  exact container name and ownership label, copies its installed app tree, and
  compares the complete measured inventory with the kit. Changed, extra, missing
  or linked files fail without repair. `installed-candidate.json` preserves the
  measured hashes and verdict. Version equality is not enough.

The two acceptance runners are wired to the installed-tree gate, but their real
container execution belongs to M1. Tests exercise the filesystem verifier with
controlled Docker transport responses; they are not native Nextcloud evidence.

## Image and optional-app inputs

Registry manifests and configuration were read without pulling layers or running
containers. The matrix now pins these linux/amd64 leaves:

| Profile | Observed version | Manifest SHA-256 |
| --- | --- | --- |
| Nextcloud Apache 33 | 33.0.10 / PHP 8.4.27 | `9ef9786fec0274ff88d7467e246034f8ad4e5742e344de6e049e05a3ee2bb74d` |
| Nextcloud Apache 34 | 34.0.5 / PHP 8.5.12 | `e881320833451d8320727d7e20386c39021d51ba03bbb1e153ddfeb114ce6145` |
| Nextcloud Apache 35 | 35.0.2 / PHP 8.5.12 | `c73d6b4ada5126dc56bbd6357f9c73c9caf99f4a692bd2da4187ea58b9089eec` |
| MariaDB | 10.11.19 | `6c65a843396dc6c78dd0da19eccaa0a91d25d9d26a4c2afcb75010ac3f59f5ea` |
| PostgreSQL | 16.15 | `b95b87e93ceb7594715d5c71fb6e09f6fc878851ab537735d617325eb9ba8b1b` |
| Dovecot | Pinned 2.4.5 release | `1b3041c09854aa53b6b57ac0e613fbfb9b859bbf976d808b1b12dba0444db4c6` |

The database release lines/patch numbers are retained; platform image digests are
resolved from current tag metadata, including the PostgreSQL image rebuild. This
does not overwrite the LinuxServer profile or its historical evidence. Observed
configuration is not a new runtime execution: M1/M2 must record actual versions.

The [Nextcloud maintenance schedule](https://github.com/nextcloud/server/wiki/Maintenance-and-Release-Schedule),
[PostgreSQL support policy](https://www.postgresql.org/support/versioning/) and
[MariaDB release notes](https://mariadb.com/docs/release-notes/community-server/10.11/10.11.19)
support retaining these lines. The rendered NC34/35 manuals were inaccessible;
the official [stable34 requirements source](https://github.com/nextcloud/documentation/blob/stable34/admin_manual/installation/system_requirements.rst)
and [stable35 requirements source](https://github.com/nextcloud/documentation/blob/stable35/admin_manual/installation/system_requirements.rst)
confirm PHP8.5, MariaDB10.11 and PostgreSQL16 compatibility. The
[NC33 manual](https://docs.nextcloud.com/server/33/admin_manual/installation/system_requirements.html)
includes PHP8.4. SQLite remains a test-only profile.

All 21 retained optional-app/profile combinations (Deck, Groupfolders, Talk, Notes,
Calendar, Contacts and Guests on NC33/34/35) verify their pinned archive hash,
identity/version and declared Nextcloud bounds. PHP bounds are checked where
present: Deck, Groupfolders, Talk and Guests do not declare them in any of these
three profiles (12 combinations). Missing PHP bounds are not positive evidence of
PHP compatibility; actual enablement and runtime APIs remain M1-M3 acceptance.
The downloaded Mail 5.12.5 archive also matches its pinned hash and declares
NC32-35/PHP8.1-8.5 compatibility; Mail tests remain restricted to the existing two
NC35/MariaDB cells. Do not infer other Mail support.

## Baseline provenance

The six matrix baseline SHAs remain unchanged. The checked-in
`baseline-packages.json` binds each mode and commit to one reviewed archive hash
and size. Both planning and staging refuse a valid hash from another origin. These retained archives match their
original package-hash record (or LinuxServer profile for 1.2.0), and each retained
source clone points at its declared commit:

| Mode | Version | Source commit | Archive SHA-256 |
| --- | --- | --- | --- |
| upgrade | 1.0.8 | `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92` | `7371124da35accd35061e18496169b5c6cb1c686d2395d05f297e3ef55a5115b` |
| upgrade-local | 1.1.2 | `14a76df26817fb8a4540eb9c9d1d0043e3e87506` | `a243104c16b3efaa0e0457a0802eeb2aba402501b774844aba33b4dd8ba0b3a3` |
| upgrade-upstream | 1.1.0 | `0911f9123a661968e707fc4c391adcf9d37c3e84` | `0e86c0947e9b4af7833711074489f6762216d2c2ee8f6fbb69bf6143cea6ae0e` |
| upgrade-upstream-111 | 1.1.1 | `65e4e08b68c3f8d1ba78398a082ec567b0b0f4e4` | `6e5550fc06ec9fcc4acb6d05b775bb5af509a62b4ddadd30e3f992ff73966e0c` |
| upgrade-upstream-112 | 1.1.2 | `27da5e107ca06d92b7af9b8a80ad9c77c12ba91e` | `846e63066d3eadd084815efe63786d23fedd99e7e019f8c8864e3e7e6dbb005c` |
| upgrade-upstream-120 | 1.2.0 | `02526119b845ccc1d8399be002da3e30b5527df6` | `dc7aff3427c527641440c3f553c4abe300ad96109079c7fa0ea37a0972d0e801` |

`mcp/build/m0/baselines.json` retains private local paths, provenance and comparisons.
Tracked non-vendor runtime files compare directly against each source commit:
279 / 684 / 367 / 367 / 368 / 459 respectively. The 1.1.2 fork baseline has five
README differences from its preferred source because its package stage rewrites
documentation links; these differences are recorded, not normalized. Other
compared tracked runtime files match. Dependency and generated-file provenance
still comes from the recorded build and full archive hash; this comparison alone
does not attest every dependency's source. Association of old archives with commits
is a reviewed provenance assertion, not an invented embedded identity.

No retained archive or accepted LinuxServer checkpoint was changed. Historical
baseline security is not a production-support claim. Missing input or a digest
mismatch must block that cell, not select another origin or fetch a replacement.

## Validation and candidate preparation

An independent review found a baseline-origin binding gap: a caller-supplied hash
alone could associate the wrong archive with a selected commit. The regression
first reproduced acceptance of a wrong origin, then passed after enforcing the
reviewed mode/commit/hash/size catalog in planning and staging. No other actionable
finding was reported; runtime/Node-bundled npm assumptions were checked against
the official versioned source. This automated review is not human acceptance.

The final complete catalog `check` passes after the review correction: 8,707 PHP
tests / 74,493 assertions, 717 browser tests, 29 documentation tests, 10 client
runner tests and 23 release tests, including 12 candidate-control tests. Both
PHPStan configurations, 1,390 PHP syntax checks, documentation and packaging pass.
The missing-module errors in browser output are deliberate failure-path fixtures,
not ignored build failures. All 15 pre-freeze plans also pass with the retained
kit; this is input validation, not execution of native cells.

The reviewed inputs are frozen in commit `efb27979b6a90122ce45a6a509e52390030f7ed9`. The complete
check ran before this freeze; no executable inputs changed afterwards. The
following closure documentation is a separate commit and does not redefine the
candidate. Final npm audit reports zero vulnerabilities; the production Composer
lock audit reports no advisories or abandoned packages.

### Frozen candidate and independent reconstruction

- App/version: `mcp` / `2.0.0`, unpublished and unsigned.
- Source and initial verified runner commit: `efb27979b6a90122ce45a6a509e52390030f7ed9`.
- Local kit: `mcp/build/source/efb27979b6a90122ce45a6a509e52390030f7ed9/`.
- Separately trusted manifest SHA-256: `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289`.

| Kit file | Bytes | SHA-256 |
| --- | ---: | --- |
| `BUILDING.md` | 1549 | `85e41099700b8edbb0b04622d561c1c5263139a79db8242e9f33cc5f0f17f57b` |
| `mcp-2.0.0.tar.gz` | 1415469 | `e31aa5a9619b2a6181456d97b0af2da38569eae01079303ad7010b1b36d81297` |
| `runtime-dependencies.tar.gz` | 133168 | `7c989cf1d2286e34babcbaad1e612eaddd10c6f12bd3c8929dacb0d93cbac27a` |
| `source.bundle` | 9445585 | `b7dc7095ecc6c2494777121827fca5aba4013215de34508ecbd56aa6ce40a111` |
| `manifest.json` | 159899 | `c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289` |

`composer package:source -- efb27979b6a90122ce45a6a509e52390030f7ed9` created the kit from the reviewed
commit. A separate clone from its `source.bundle`, detached at that SHA, then ran
`npm ci --ignore-scripts` and `composer package` under Node24.22.1/npm11.19.1,
PHP8.4.27 and Composer2.8.8. The reconstruction used locked registry dependencies;
fully offline toolchain reconstruction is not claimed.

All **975 extracted runtime files** match the original manifest byte for byte,
including all **73 vendor files** also supplied by `runtime-dependencies.tar.gz`.
The rebuilt archive SHA-256 is `d758c8a8e3f2503693d89eca4f9a606776fea6c9fb8c4ae9eaea49856a901903`.
Its compressed bytes differ: 1,123 tar entry timestamps changed, while entry
paths, modes, owners and types match. Timestamps were not normalized; acceptance
compares every extracted path, size and hash, not archive equality.

Compared with the prior `cfeb26d4` candidate, exactly two files differ:
`appinfo/source.json` records the new SHA and `vendor/composer/installed.php`
records that SHA in Composer's root version/reference. The textual differences
were inspected; app behavior, compiled assets, served documentation and dependency
code are unchanged. No difference was suppressed or rewritten. The previous kit
and LinuxServer checkpoints remain immutable.

All **15 final runner plans** pass against this successor, with the six reviewed
upgrade archives and Mail enabled only on NC35/MariaDB fresh/upgrade-local. Each
records the candidate/runner SHA separately and a clean runner state. A refusal
shim confirms that no plan invoked Docker. Real-file staging retains the original
archive hash and validates its copied kit. This is **0/15 native cells executed**;
it does not certify the installed-tree gate against real Nextcloud yet.

### Evidence and next step

Local evidence is retained under ignored `mcp/build/m0/`. The 68-file
`evidence-index.json` has SHA-256 `f515c6f0239b57e3908040fd5b82c114816d868e89d5bd5b01dcadb24a7ff255` and binds the final check,
regression logs, pins, baselines, candidate, final plans, staging, reconstruction
and audits. `reconstruction.json` records both archive hashes and every prior-kit
difference; `rebuilt-inventory.json` records all 975 measured files. Historical
packaging RED/GREEN logs remain alongside it. Private raw packaging state,
clones, caches and generated trees are deliberately outside the index; the local
evidence directory is not an approved upload bundle.

Closure documentation and packaging checks pass again. The working-tree package
is explicitly a dirty development build with its source offer disabled; it is
not the frozen acceptance archive. Both immutable kits and all 68 indexed
evidence files were reverified without changes. These closure checks are retained
in `docs-final.log`, `package-final.log` and `closure-integrity.log` outside the
already sealed evidence index.

No M0 technical blocker remains. The next bounded batch is **M1**: ordinary-user
source-offer acceptance and reconstruction from the downloaded kit, then full
NC35/MariaDB fresh with Mail and upgrade from upstream1.2.0, all consuming this
exact kit. M2/M3 complete the remaining matrix; M4 reconciles LinuxServer and
release boundaries. Recheck input availability/pin changes before executing, and
retain exact resource-ownership/cleanup evidence. M1 was not started here.

M0 does not execute any of the 15 native cells, ordinary-user source retrieval,
real OAuth journeys or a production recovery. HTTPS, Inspector end-to-end,
ChatGPT, security/operations/license/accessibility/language reviewers and trusted
source publication/history review remain pending. All 100 translated documents
retain their human-review requirement.


## M0 closure review (2026-10-30)

Reviewed the implementation in `efb27979` and closure record in `56435092`.
No additional executable-code correction was identified. The changes in this
review clarify two acceptance boundaries: 12 optional-app/profile combinations
lack explicit PHP bounds, and completed M0 checklist items describe implemented
controls with local tests rather than completed native installation tests.

Fresh evidence verification confirms the original 68-file index, both immutable
kits, all 975 runtime files, 73 vendor files, six baseline archive/commit records,
21 optional-app/profile archives and the Mail archive. The retained reconstruction
was reverified directly from its archive; it was not rebuilt again. The reported
1,123 timestamp differences and two commit-metadata file differences also match.

All 15 plans were repeated with a Docker refusal shim and passed without resource
creation. The clean runner at `564350922db7c2acb8ecf1958de0e67d7a0ab949` still
selects candidate `efb27979b6a90122ce45a6a509e52390030f7ed9`, with the same trusted
manifest digest. Its eight recorded runner-file hashes match the frozen code
commit. This review changes documentation only and does not regenerate or
redefine the acceptance kit. Zero native cells have run for this candidate.

The fresh complete catalog `check` passes: 8,707 PHP tests / 74,493 assertions,
717 browser tests, 29 documentation tests, 10 client-runner tests, 23 release
tests, both PHPStan scopes, 1,390 PHP syntax checks, documentation and package.
Fresh npm and production Composer lock audits report no vulnerabilities/advisories;
Composer reports no abandoned packages. The checkout package is a development
validation artifact, not a replacement for the frozen acceptance archive.

Review evidence is retained separately under ignored `mcp/build/m0-review/`.
Its 38-file `evidence-index.json` has SHA-256
`4773fb28593cb8ae8adb015704c998a9d4c339e85233a0c8b9ecb31d20403688`.
It binds the fresh check, evidence-verification result, bundle check, audits and
15 plans. The final documentation check is retained as `docs-final.log` outside
that sealed index. The original M0 evidence and index remain unchanged.

HTTPS, Inspector end-to-end, hosted ChatGPT, all human reviewers,
intended-host/final platform acceptance and
trusted source publication/history review remain pending. M1 was not started;
no push, deployment, production-server operation or PR #14 change occurred.
