<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# F3: local delivery, update control and integrity

Status: local source-offer acceptance on 2026-10-23 and partial host inventory on
2026-10-24; **F3 remains open**. This follows the
[F3 identity record](fork-f3-preparation.md) and
[fork continuation plan](fork-continuation-plan.md). The owner authorized the
reviewed local source-offer batch, including disposable native acceptance, and
subsequent read-only host inspection. Publication and deployment remain
unauthorized. See the [intended-host inventory](#intended-host-read-only-inventory)
for observed facts and their limits. Human reviewers and the remaining host-control
facts are still pending. PR #14 remains a historical reference; no upstream remote
or PR action is introduced.

## Historical local source kit

The new [source delivery workflow](source-delivery.md) builds only an explicit
committed revision. This run freezes the existing candidate
`39148aa31661ba437d3a9337add97cf1eb4fa523` (Nextcloud MCP 2.0.0). The new release
tooling and this follow-up documentation are not part of that earlier commit;
the kit is a reconstruction candidate, not the final F3/F4 release artifact.
Final qualification must rebuild at the final committed revision.

The retained kit is under ignored
`mcp/build/source/39148aa31661ba437d3a9337add97cf1eb4fa523/`.
These paths are local retention, not publicly accessible evidence. Preserve the
whole kit and record its expected manifest digest in a separately trusted release
ledger. The generated kit is ignored build output, not a tracked Git artifact.

| Artifact | SHA-256 |
| --- | --- |
| Manifest (trusted digest for this local run) | `beb43cf676777e889ceceb38af2604c95a375bfd5e25707f7f48ed4470d0876b` |
| Runtime archive | `d49b18f1e1b06fa83fd985e7c57e0da8a39b22ca0fa48e1c1db19570a0f18c3c` |
| Source bundle | `33cf64efa83dc4596be979bd2668c57765234daf0e6930f54788ae6558f9691e` |
| Runtime dependency sources | `c11ceba0cc3d4eef5bb7f7410fa4bdb4440531dc759c6402ce8269f55de3f2dd` |

The kit includes reconstruction instructions, original application/build sources,
locked dependencies, attribution and dependency PHP source/licenses. The vendor
source archive exactly matches the runtime archive's vendor tree. A bundle-only
clone checked out at the same detached revision rebuilt successfully: all **936
installed file contents** match the manifest. The rebuild uses locked registries;
full offline toolchain reconstruction and byte-identical tar/gzip archives are not
claimed. No installation, running server or public listener was needed.

The source-isolation tests prove that dirty tracked files, untracked sentinel
credentials, original repository-local Git config and unrelated branch commits do
not enter the bundle. They do not certify retained history as publication-safe.
The actual source bundle still needs a historical-content review before publishing.

## Recommended update-control design

Retain the technical app ID `mcp` only if the intended host can enforce a complete
update boundary. Recommend **manually approved, digest-bound fork artifacts plus a
qualified App Store catalog that omits `mcp`**. Other official apps must retain
their normal signed update path. This is a concrete candidate design, not an
implemented or accepted target-host configuration.

The Nextcloud [configuration reference](https://docs.nextcloud.com/server/35/admin_manual/configuration_server/config_sample_php_parameters.html#appstoreurl)
documents `appstoreurl` for a self-hosted store. A filtered catalog would use this
entry point, preserve upstream metadata/signatures for other apps, and omit `mcp`
from stable and unstable responses. It requires separate infrastructure approval,
implementation and qualification; no catalog/proxy or configuration was created.
Do not assume the setting itself implements filtering.

The qualification contract for that candidate is:

1. Enumerate the actual host's UI, `occ app:update mcp`, `occ app:update --all`,
   install/reinstall commands, background jobs, container/image updates, managed
   hosting/AIO automation and direct filesystem writers. Assign ownership of each
   active path. Paths outside Nextcloud's AppFetcher need their own exclusion.
2. The controlled catalog must never return `mcp`, including unstable channels and
   warmed/cached responses. Refresh stale official-feed caches and long-running
   workers using a version-specific, tested procedure. Catalog failure must fail
   closed; never fall back to an unfiltered official feed. Monitor failures so
   maintenance of other apps does not silently stop.
3. Keep official certificate and archive-signature validation for every other app.
   Prove an ordinary signed app still updates. Test a higher-version `mcp` catalog
   fixture to prove omission rather than relying on the fork's version 2.0.0 being
   temporarily higher. A rejection caused only by an invalid fake signature is not
   proof of update exclusion.
4. Attempt every identified update/install path and compare the fork's app tree,
   loaded path, version and migrations before/after. Verify that no second `mcp`
   directory can shadow it in another writable app path. Check UI behavior and
   failure reporting rather than treating a blocked global update job as success.
5. Restrict configuration and app-code writes to approved maintenance. A dedicated
   read-only application mount can add defense in depth, but must be qualified
   with the actual app-path ordering and container/host writers. Hash verification
   detects changes; it does not enforce this boundary.
6. Stage a digest-verified fork update with coordinated app/database/config backup,
   run the normal upgrade and check integrity and authentication. Rehearse recovery
   as a coordinated restore, including credential invalidation/reconnection where
   required. Do not downgrade code against already migrated live data.

If a filtered catalog cannot be maintained or an external updater cannot exclude
`mcp`, stop deployment and return the technical identity/distribution choice to
the owner. A distinct app ID needs a separate data/identity migration decision;
it is not introduced silently. The owner has not yet accepted operating a new
catalog service, and no server-specific commands are prescribed before its
installation method is known.

### Why simpler controls are not sufficient

Read-only inspection of the exact pinned NC33.0.9, NC34.0.4 and NC35.0.1 core
sources confirms the distinctions recorded in the earlier F3 audit:

| Mechanism | Limitation |
| --- | --- |
| Version 2.0.0 | Future official versions can overtake it; it is not an update policy |
| Wrapper omitting `mcp` from one CLI invocation | Does not cover UI, direct install/reinstall or external automation |
| `appsallowlist` | Inspected AppFetcher code applies it only with a valid subscription; target subscription is unknown |
| `.git/` detection | `Installer::isUpdateAvailable()` skips Git apps, but `downloadApp()` reads the catalog directly; our runtime archive has no `.git/` |
| Read-only directory alone | `downloadApp()` selects a writable install path, removes its `<path>/<appId>` and copies there; another app root could receive a shadow copy |
| Global `appstoreenabled=false` | Blocks the ordinary maintenance path for other apps; only used for isolation in disposable tests |
| Own SHA-256 manifest | Detects different bytes against a trusted digest; does not prevent an updater writing them |

The existing core extractions were reread, not a live updater exercised. For
reproducibility, SHA-256 of `lib/private/Installer.php` is:

| Pinned core | Source-file SHA-256 |
| --- | --- |
| 33.0.9 | `6a4646ea2c3fbd68e9a5935480a76b97acd3e2b86afc203bf2e9e7dd417c790c` |
| 34.0.4 | `4b1b55f6313cbe0a0b61427be3bf3ccc32a48fc6e8ebe932d948ffae60b41226` |
| 35.0.1 | `90541cf63ae41216dd947751895233cb10e8e380b7ab06e0c0f1090a57a03962` |

## Integrity procedure and its limits

Use the source kit's manifest digest as the expected local content record, kept
outside the writable app tree. Verify the kit before staging an archive and verify
a quiescent extracted/installed tree before and after maintenance. A mismatch is
a stop/investigate result, never an automatic repair or new accepted baseline.
The verifier rejects changed, missing and extra files, symlinks, special entries
and manifest changes. It does not certify ownership/permissions or live path races.

The fork remains an unsigned custom app. The inspected core's instance-wide
integrity scan and explicit app-signature verification have different entry
conditions; a skipped unsigned app is not a signature pass. Record actual
`occ integrity:check-app mcp`, UI warnings and background integrity behavior on
the intended host, and decide whether its custom-app policy accepts the resulting
behavior. Do not hide warnings, disable checks globally, reuse an upstream
signature or claim that content hashes grant an App Store signing identity.
A separately authenticated digest publication/signing policy remains a release
gate; no signing keys or certificate authorization were provisioned here.

## Historical delivery-tool validation and current gates

| Delivery-tool validation before this source-offer batch | Result |
| --- | --- |
| Complete catalog `check` with pinned toolchain | Exit 0; PHP 8.4.27, Node 24.21.0, npm 11.19.0 |
| PHP suite | 8,424 tests / 71,509 assertions pass |
| Browser suite | All 687 tests pass across Chromium, Firefox and WebKit |
| Tooling suites | 29 documentation, 10 client-runner and 7 source-delivery tests pass |
| Syntax and static analysis | 1,286 PHP files checked; both analyzer scopes pass |
| Documentation/package | 153 Markdown files / 110 package sources valid; production archive passes all validators |
| Translation provenance | Valid; 96 drafts remain `review-required` |
| License attribution | REUSE passes for all 1,655 files |

The complete check's working-tree archive had SHA-256
`c0599ae525de6eac5d077f2f02f0af56cb52368a732473c3067521eabf43c76f`.
It includes the updated Composer command manifest and is distinct from the frozen
`39148aa` kit archive; neither is asserted to be a final qualified release.
The browser suite emits missing-import errors in its deliberate negative-build
cases; the complete suite succeeds with no suppressed test failures. Build logs,
CLI probes, full catalog results and reconstruction details are retained locally
under `/tmp/mcp-f3-delivery-39148aa/`; the reconstruction itself remains under
ignored `mcp/build/source-rebuild-atHwqJ/`. These are local engineering evidence,
not public URLs or an additional source release.

The kit build, exact 936-file reconstruction and seven release boundary tests
pass. Four direct CLI rejection probes also pass: an existing kit is not
overwritten, mutable source references and wrong manifest digests fail, and a
nested test-runner context is rejected. The existing manifest stayed unchanged.
The initial missing-module failures and the prototype-filename regression were
observed before their fixes; no failure was suppressed.

The first complete catalog run stopped at asset building because it inherited the
system Node version. The next run used the pinned Node 24.21.0/npm 11.19.0 and
found the existing command-order test had not yet registered the new release-test
stage. Its isolated command fixture and expected order were corrected, including
a failure injected specifically at `test:release`; all five command-contract tests
then passed with 43 assertions. Neither failure was skipped or reclassified as a
passing complete run. The full gate passed after that correction (exit 0).

An independent read-only engineering review found no actionable tooling defect
and confirmed the kit hashes, all 936 rebuilt file contents, dependency-source
equality and the three pinned core-source hashes. It does not substitute for the
pending human reviewers. The subsequent CI wiring uses the same locally tested
release command; its YAML parses, but no remote workflow was triggered.

| Gate | Current state and next evidence |
| --- | --- |
| Immutable local source/runtime kit | Historical `39148aa` retained; new local acceptance candidate `ae8ea636` includes the source offer. Regenerate and requalify at the final release revision |
| Ordinary-user source access | Implemented; local non-admin personal-settings/MCP retrieval and reconstruction pass for `ae8ea636` (evidence below). F5 must separately verify the authorized immutable public destination |
| Publication suitability | Pending historical-content review, final notices/source review and trusted digest publication policy |
| Update exclusion | Concrete filtered-catalog/manual-artifact candidate above; pending owner acceptance of service operation and host-specific qualification |
| Native/custom-app integrity | Local content verifier passes; native UI/signature behavior, permissions and trusted digest custody still pending |
| Server facts | Versions, container method, app persistence and S3 driver observed on 2026-10-24 (inventory below). Complete update-writer, subscription and backup/recovery facts remain pending; no secrets requested |
| Recovery and platform matrix | Earlier two native cells retained as historical evidence; final maintained-patch 14-cell matrix and intended-host recovery remain pending; installed upstream 1.2.0 upgrade unverified |
| Client exposure | Public HTTPS and ChatGPT's real journey pending; Inspector's recorded encrypted-storage result remains 14/15 |
| Human review | Security, operations/recovery, license/attribution, accessibility and languages remain unassigned/pending; 96 translation drafts are not approved |

The original delivery-tool work started no native instance. The source-offer batch
below subsequently used disposable native fixtures. Neither set of checks replaces
the remaining server, client or human acceptance. No push, release tag, PR change
or deployment to the intended server is included.

## Review before committing the delivery tooling

The review clarified that generated kits remain ignored build artifacts built
from committed sources, and that the expected manifest digest belongs in a
separately trusted ledger. No delivery/verifier behavior changed in this review.
The recorded kit digests, complete bundle history, clean reconstruction commit,
936 reconstructed file contents and three pinned core-source digests were checked
again and match the evidence above.

Fresh checks passed: seven source-delivery tests, five command-contract tests
with 43 assertions, 29 documentation-tool tests, Composer manifest/lock validation,
153 Markdown files / 110 package sources, production packaging and REUSE for all
1,655 files. The quality workflow YAML parses and includes `test:release`; no
remote workflow was triggered. All 96 translation drafts remain review-required.
The earlier full PHP/browser/static-analysis run remains retained evidence, not
claimed as a new complete run during this documentation-only correction.

The freshly rebuilt working-tree package has SHA-256
`d7fc7f38753daa217046d1193f760968698cce11e57982dfa69729bf162ce180`.
It is separate from the frozen source-kit archive and does not replace its digest
or upgrade the candidate to a qualified release. Review logs and the fresh evidence
audit are retained locally under `/tmp/mcp-f3-delivery-review/`.

Committing the delivery tooling does not regenerate the kit for the new commit,
publish a source offer or close any gate in the table above. Server facts, update
and integrity qualification, recovery, the final matrix, HTTPS, Inspector, ChatGPT
and all human reviewer assignments remain pending. No push or deployment occurs.

## What can proceed without the intended server

Review baseline: `1a9aa67`, with a clean working tree before this documentation
update. The source kit above remains bound to `39148aa`; committing its tooling
neither invalidates that historical evidence nor makes the kit describe `1a9aa67`.

The F3 plan explicitly permits source-offer/download acceptance using a non-admin
user and an isolated local service. Public destination verification belongs to
F5 after publication is authorized. Therefore, publication and intended-server
facts must not be treated as prerequisites for implementing and automatically
testing the local source-offer journey. No such journey was implemented or run
by this review.

| Work | Can proceed without host facts? | What it can establish |
| --- | --- | --- |
| Source-offer implementation and automated contracts | Yes, in the repository and isolated test fixtures | Both personal-settings users and MCP users can discover the same version-bound offer; an admin-only link is insufficient |
| Local source retrieval/rebuild acceptance | Yes, against a controlled local service and a frozen candidate | Non-admin retrieval, matching source revision/version, complete kit verification and successful reconstruction; no public-ingress claim |
| Technical history/dependency/attribution audit | Yes | Inventory and automated findings can be prepared; a scanner cannot approve historical publication safety, legal terms or human-facing attribution |
| Rebuild a kit including the committed tooling | Yes | Self-contained committed build tooling is available; schedule the next release freeze after source-offer changes to avoid treating another intermediate kit as final |
| Generic update/integrity and restore tests | Partly | Representative pinned fixtures can test defined behavior; a filtered catalog needs its own design and qualification, and none of this inventories the real host's writers or backup topology |
| Native final matrix and installed-upstream-1.2.0 upgrade | Independent of host facts for the declared reference profiles | Separate F4 work; current maintenance pins and the final artifact are prerequisites, and the actual host's installed version still needs its own acceptance |
| Production update exclusion, native integrity policy and recovery | No | Require actual installation paths/mounts, update mechanisms, subscription, versions, permissions and coordinated backup facts |
| Publication, real ChatGPT access and human sign-off | Not closed by local tests | Require their own authorization/access or reviewers; preserve the existing HTTPS/Inspector/ChatGPT limits |

### Reviewed local source-offer batch

The reviewed plan prioritized the **local source-offer journey** before the final release freeze or
introducing an update-catalog service. Intermediate builds remain necessary for
testing. This batch can close the local source-access criterion only after its
acceptance checks pass; it does not close all of F3 or select the owner's server
topology.

1. Define one version-bound source-offer contract shared by personal settings and
   MCP discovery/onboarding. Inspect the existing protocol and guide contracts
   before selecting its representation; preserve authentication, grants and
   technical app identity. Keep release metadata separate from user content,
   credentials and backup data. Specify unavailable/invalid metadata behavior so
   an unusable or fabricated destination is never presented as a valid offer.
   Missing or inconsistent metadata must fail source-offer acceptance rather
   than silently count as a completed offer.
2. Implement that contract and its adapters, preserving original attribution.
   Test it automatically with a non-admin identity and the supported protocol
   paths. Cover ordinary users whose feature grants differ; source discovery
   must not rely on administrator access or an unrelated capability grant.
3. Use a controlled local source destination for retrieval acceptance. Verify that
   the advertised source revision/version matches the frozen candidate, the
   complete kit passes a separately trusted digest check, and altered, incomplete
   or wrong-revision delivery fails. Keep the local-to-proposed-public mapping explicit; do not ship a test
   loopback URL in a release package. A real Nextcloud fixture test is a separate
   acceptance step from unit/browser doubles, and must be recorded as such.
4. Run the affected contracts, documentation, package and source reconstruction
   checks. Record the resulting immutable candidate and repeat affected F3/F4
   qualification before release. Public URL verification remains an F5 gate;
   local success does not authorize push, publication or deployment.

Acceptance for this batch requires all of the following evidence:

- A non-admin user can discover the offer through personal settings and each
  supported MCP protocol path and retrieve the complete kit without maintainer
  credentials. Source access is independent of unrelated capability grants while
  existing authentication and connection policy remain enforced.
- The retrieved kit and rebuilt app match the frozen revision/version. Keep the
  expected manifest digest outside the files it inventories: embedding that final
  digest in a hashed app file would create a checksum cycle. Do not derive trust
  from a fresh digest calculated from the same untrusted download.
- Positive and negative contract tests pass, and the local user journey passes
  against a real isolated Nextcloud fixture. If that native test cannot run, mark
  it blocked; unit/browser doubles do not close this acceptance criterion.
- The local-to-public destination mapping is recorded before final qualification.
  No loopback test URL is shipped and no post-qualification package edit supplies
  the public URL. Public reachability is still verified separately in F5 after
  authorization. All remaining F3/F4/host/client/human gates stay explicit.

The preceding documentation-only review did not implement that batch, start an instance, configure a catalog,
create a public endpoint or assign reviewers. The data needed from the owner stay
the same: Nextcloud/PHP/database/app versions, installation method, app paths and
mounts, automatic update entry points, subscription and backup/storage topology.
Human security, operations/recovery, license/attribution, accessibility and language
reviews remain unassigned/pending. Public HTTPS, Inspector's recorded 14/15
storage result and the real ChatGPT journey remain explicit limitations.

## Local source-offer implementation and acceptance

The owner authorized this batch after the reviewed plan above. Implementation
started from `bee9ffd` on local `fork/main`. The shared `Release/SourceOffer`
service binds operator-controlled `mcp_source_offer` system configuration to the
installed version and generated `appinfo/source.json`. Personal settings and both
MCP discovery paths present the same five artifact links, source revision and
expected manifest digest. Authentication, connection admission and capability
policy remain enforced. No application file-serving endpoint or outbound URL
fetch was added. Original attribution remains visible.

A clean committed package generates its identity; a dirty development package
explicitly has no valid source offer. Missing, malformed or mismatched metadata
shows an unavailable offer without source links. The expected final manifest
digest and destination remain outside the hashed runtime, avoiding a checksum
cycle. See the [configuration contract](../reference/configuration.md#source-delivery)
and [source acceptance command](source-delivery.md#disposable-native-source-acceptance).

### Frozen candidate and downloaded-source reconstruction

The verification-only snapshot is
`ae8ea63612ca8da27f4351601614651a1fbaa4ba`, Nextcloud MCP **2.0.0**, committed solely
inside ignored `mcp/build/source-offer-final-evecc7la/repository/`. This is not a
release commit on `fork/main`, a pushed reference or a published source offer.
It contains the implementation and corrected native runner; the completion report
and testing-guide update were written afterward. Regenerate the final kit from
the final reviewed release commit rather than relabeling these artifacts.

| Artifact | SHA-256 |
| --- | --- |
| Manifest, recorded separately before native retrieval | `ccc86dab9d08fe492b9184be1e86da344c23db065411a500a5ec3bbd6c8ad754` |
| Runtime archive | `2b6c49d7614d4bffe04c74f96d6da33b40c8b6896d3f7d7a72bb3dad799a16f5` |
| Source bundle | `8d566e087663dc2dcbd25cf9a4d2c726a8e3c4091960c4ed27a147e2f55cb1d4` |
| Runtime dependency sources | `047d4e0f5cdf570c8291961f4a7905bc633296faad65a451eeb1d705b06a0c29` |
| Reconstruction instructions | `fdf5ed0a7a46b790f851e7c9b98c17d13595a66829222f81337c1a9d2b810bc1` |

Final native run: `mcp-source-dce9f4e3b2c60115`, using
`nextcloud:35.0.1-apache@sha256:8f2f0e93aca5e9457019933936dea1f313052e72c696b4aecb31580a208b859f`
and fresh SQLite. This is the existing pinned reference profile, not a new claim
that the final maintained-patch matrix has been qualified.

All **124 native checks** passed, including:

- Real personal-settings HTTP access by a verified non-admin user, with the source
  revision, expected digest, complete links and attribution visible.
- Both `initialize` and `server/discover`, with Basic and Bearer authentication,
  with Files read grants enabled and disabled, advertising the same offer.
- Unauthenticated MCP denied with HTTP 401 and disconnected MCP denied with
  HTTP 403; personal source access remains available when MCP is disconnected.
- All five artifacts downloaded with no credentials and checked against the
  separately recorded digest. Missing/wrong-revision settings show no source
  links. Altering the downloaded bundle is rejected by the host verifier; restoring
  its original size restores the verified downloaded kit.

The actual HTTP-downloaded bundle was cloned into ignored
`mcp/build/source-offer-rebuild-xViFuI/source/`, checked out at the same detached
revision, and rebuilt with the locked dependencies. The trusted verifier confirms
all **938 installed file contents and sizes** match the frozen manifest. The
separate dependency-source archive also matches all **73 packaged vendor files**.
No differences were normalized away. Tar/gzip byte identity, offline toolchain
reconstruction, server permissions and live database/configuration integrity are
not asserted by this comparison.

### Local-to-public destination mapping

The native fixture used `http://127.0.0.1:8765`, served inside its container from
`/tmp/mcp-source-fixture`, outside the Nextcloud document tree. Neither the source
server nor Nextcloud published a host port. The test URL is supplied through the
fixture's system configuration; it is not embedded in the generated runtime
identity or its production configuration. Test source necessarily records that
fixture address so the acceptance can be reproduced.

The proposed future base is
`https://github.com/marcjoancr/mcp-for-nextcloud/releases/download/fork-v2.0.0`,
with the same five filenames. This is a **proposed mapping only**, not an existing
or verified publication. F5 must authorize publication, upload the final kit,
retain its independently trusted digest, verify ordinary-user HTTPS retrieval
and set external `mcp_source_offer` metadata for that final revision. No package
edit after qualification is needed to change the destination. Do not point a
served release at the local fixture or claim this intermediate kit is final.

### Checks, corrections and evidence retention

| Validation | Result |
| --- | --- |
| Complete catalog `check`, pinned Node 24.21.0/npm 11.19.0 and PHP 8.4.27 | Exit 0 |
| PHP suite | 8,428 tests / 71,609 assertions pass |
| Browser suite | 690 tests pass across Chromium, Firefox and WebKit |
| Tooling suites | 29 documentation, 10 client-runner and 9 release tests pass |
| Syntax and static analysis | 1,289 PHP files checked; both analyzer scopes pass |
| Documentation/package | 153 Markdown files / 110 package sources valid; production archive validates |
| Native source-offer acceptance | 124 checks; non-admin settings and both MCP paths; exit 0 |
| Downloaded-source reconstruction | All 938 installed files match; all 73 vendor source files match |
| Final runner checks | 9 release tests, 14 integration-harness tests / 129 assertions and 3 affected PHP syntax checks pass |
| Attribution | REUSE passes for all 1,664 files |
| Cleanup | Read-only Docker audit found no remaining integration-labeled containers or networks |

The first complete check exposed expected protocol instruction fixtures, missing
Portuguese interface strings and the new service's required attribution header.
Those failures were corrected explicitly; the affected 22 tests then passed with
5,412 assertions before the complete check passed. Protocol fixture changes only
record the new explicit unavailable-source message; other frozen behavior remains
unchanged. Six new interface strings are translated in Spanish, German and
Brazilian Portuguese, with human language review still pending.

Native development also exposed an exception-completion gap in the probe and an
HTTP 404 when serving fixture artifacts under Nextcloud's web tree. The runner
never reported those attempts as accepted. The probe now uses the harness's
completion guard, and the runner requires matching structured completion evidence.
A dedicated static, filename-restricted loopback fixture solved retrieval without
relaxing Nextcloud protections. The successful final native run includes these
corrections. The complete catalog run preceded those test-runner corrections;
final targeted checks and the real native run validate the corrected runner.
Application runtime code did not change after the complete catalog passed.

An independent read-only engineering review found no actionable static defect;
it did not certify native execution, reconstruction, publication or human sign-off.
The execution results above supply the local native and reconstruction evidence.
Private raw logs, full catalog JSON and rebuild results are retained locally under
`/tmp/mcp-f3-offer/`. Final native `acceptance.json`, `result.json`, image plan and
actually retrieved artifacts are under ignored
`mcp/build/source-acceptance/mcp-source-dce9f4e3b2c60115/`. These are local retention
paths, not public evidence URLs; do not publish raw fixture failure logs.

### Remaining gates and next step

The local ordinary-user source-access criterion is now demonstrated for the
frozen candidate. **F3 remains open.** The pre-commit review is recorded below;
rebuild and requalify the final release revision when the remaining release
gates permit it. Do not treat a source-offer success as permission to deploy.

Still pending: intended-server versions, installation method, app paths/mounts,
updater jobs, subscription and backup/storage topology; owner acceptance and
host-specific qualification of update exclusion; custom-app integrity policy,
trusted digest custody, coordinated recovery, the final maintained-patch platform
matrix and installed-upstream-1.2.0 upgrade. Historical-content/source publication
review and actual public retrieval remain separate gates. Public HTTPS, Inspector's
recorded **14/15** encrypted-storage result and the real ChatGPT journey remain
explicit limitations.

Security, operations/recovery, license/attribution, accessibility and language
reviewers remain unassigned/pending. The **96 documentation translation drafts**
remain `review-required`; the new source-delivery configuration section also needs
translation into those language editions. No new human approval is inferred from
automated checks. No push, release tag, PR action, updater infrastructure change
or intended-server deployment occurred.

## Review before committing the local source offer

This review checked the actual implementation, protocol-fixture diff, retained
kit and native evidence. It found a runner cleanup defect: failed network
inspection was treated as an ownership mismatch and could leave acceptance
successful without confirming cleanup. The runner now fails on inspection errors,
foreign ownership or removal failures, attempts cleanup of both owned resources,
and reports success only after cleanup completes. Two regression tests cover
these boundaries, including refusal to delete another run's resources and omission
of raw Docker diagnostics from reported errors. The release suite now has 11 tests.

Evidence directories now explicitly use mode `0700`. Earlier retained evidence
already had private permissions in this devbox; the correction removes reliance
on the invoking process's umask rather than asserting a previous disclosure.
No application runtime behavior, source-offer metadata or authentication/grant
policy changed during this review. The protocol-fixture audit confirms exactly
12 instruction strings changed from the original baseline, with all other JSON
values unchanged.

The corrected runner completed another real native run,
`mcp-source-c9195a4eb3341e79`: **124 checks, exit 0**, against the same frozen
`ae8ea63612ca8da27f4351601614651a1fbaa4ba` runtime and separately trusted manifest
digest recorded above. All five artifacts were downloaded and verified again;
Docker inspection afterward found no remaining integration-labeled containers
or networks, and the new run directory is mode `0700`. The runner corrections
are in the reviewed working tree, not in that earlier frozen bundle. This
explicitly remains candidate-runtime evidence, not qualification of a later
release commit.

Fresh read-only verification confirms the retained reconstruction still matches
all **938 installed files**, and the separate dependency archive still matches all
**73 runtime vendor files**. A new reconstruction was not needed: runtime/build
inputs did not change. REUSE passes for all **1,666 files**. Review logs are
retained under `/tmp/mcp-f3-offer/review-*`; the repeat native artifacts are under
ignored `mcp/build/source-acceptance/mcp-source-c9195a4eb3341e79/`.

The fresh complete catalog `check` passed with exit 0: **8,428 PHP tests /
71,617 assertions**, **690 browser tests**, 29 documentation tests, 10 client
runner tests and **11 release tests**. Syntax validation checked **1,290 PHP
files**; both static-analysis scopes passed. Documentation validated **153 Markdown
files / 110 package sources**, and production packaging passed. The three expected
`missing.js` errors belong to deliberate browser build-failure tests; the suite
passed without suppressing them. All 96 documentation translations still require
human review. The final working-tree package is a dirty development build, so its
source offer is deliberately unavailable; the separate frozen candidate above
supplies the positive native evidence.

The commit records the local offer, packaging identity, acceptance runner, tests,
translations and documentation together. It does not publish the kit or turn this
intermediate snapshot into a final release. **F3 remains open**: server facts,
update/integrity/recovery qualification, the final platform matrix and installed
upstream upgrade, source-history/publication review, trusted public delivery,
HTTPS, Inspector **14/15**, ChatGPT and the human reviewers remain pending. No
push, PR action, public exposure or intended-server deployment is included.

## Remaining local work after the source-offer commit

Assessment on **2026-10-24**, from clean local `fork/main` at
`f8fb2abd1140186c53f27d55fd9ae197b7da5fa2`. This is a review of the existing F3
criteria, not authorization to publish, deploy or introduce an update-catalog
service. **F3 remains open.** This assessment preceded the owner's host access
details; the [later inventory](#intended-host-read-only-inventory) supplies part
of the previously missing facts without changing the source-audit scope.

Fresh read-only verification confirms that the retained `ae8ea636` kit still
matches its separately recorded digest and all 938 reconstructed installed files.
Both retained native acceptance records report 124 checks, a non-admin user and
exit 0. No native instance was started in this assessment, and the complete suite
was not rerun. Those records still describe the earlier frozen candidate; the
new `f8fb2ab` commit includes the reviewed runner and documentation but has no
newly generated kit from this assessment. Do not transfer the old manifest digest
or release qualification to it.

### What can be completed without server facts

| Work | Current state | Independent local completion or limit |
| --- | --- | --- |
| Identity and version implementation | Nextcloud MCP / 2.0.0 committed; technical app identity preserved | Done for development; this is not a published release |
| Local ordinary-user source offer | Implemented; actual personal-settings/MCP retrieval and reconstruction pass for the frozen candidate | The local behavior criterion is demonstrated. A later release still needs its own revision-bound kit and affected qualification |
| Technical source/dependency/notice completeness | Rebuild, vendor equality and REUSE evidence exist; publication inventory and historical-content findings are not consolidated | Can audit now; report concrete coverage and gaps, without claiming human license/security approval |
| Source kit containing the reviewed implementation and runner | Latest reviewed implementation is `f8fb2ab`; retained positive kit is the verification-only `ae8ea636` snapshot | Can generate and validate one new local kit from an explicitly selected full reviewed commit after the audit; not a final F4 freeze |
| Documentation translation coverage | New source-delivery configuration section is English-only; 96 existing drafts need review | Can prepare equivalent drafts with real English provenance; human language approval stays pending |
| Generic update, native integrity and recovery checks | Candidate update-control design exists; no accepted host-specific mechanism | Representative isolated tests can be designed independently, but a new catalog service needs separate design/operational acceptance. These tests cannot close intended-host gates |
| Final platform matrix and installed-upstream-1.2.0 upgrade | Not complete for the final artifact | Reference-profile work can proceed in the separate F4 batch after reviewing maintenance pins. The later host inventory supplies the reported installed version; exact installed source/migrations and target-profile qualification are still needed |
| Public source retrieval and digest custody | Proposed destination only; no release published | F5 authorization and verification required; local file hashes do not establish trusted public publication |
| Human approval | Reviewers unassigned/pending | Automated checks cannot assign reviewers or approve security, licensing, accessibility or translations |

### Recommended next step: technical source-distribution audit

Prioritize the existing F3 source-completeness audit, then bind its evidence to a
reviewed branch commit. This addresses the remaining local distribution work
without assuming a server topology. Preparing the final platform matrix now would
not settle source-publication suitability; implementing a filtered catalog now
would introduce an operational service the owner has not accepted. Translation
drafts remain useful independent work, but do not replace this source audit.

The next execution batch should:

1. Select and record the full reviewed source revision. `f8fb2ab` is the current
   implementation baseline, not an instruction to use a mutable branch name or
   to label it as the final release. If the audit requires corrections, resolve
   and review those first, then select the corresponding committed revision.
2. Inventory preferred application sources, JavaScript/build inputs, dependency
   locks, required reconstruction history, runtime dependency source/licenses,
   notices and visible original attribution. Compare the actual package and kit
   with that inventory; distinguish shipped runtime dependencies from build/test
   tooling. Record unavailable source/version facts as gaps rather than filling
   them with guesses. REUSE passing alone does not prove this completeness.
3. Audit the exact history reachable from that revision for unintended private
   content, credentials, host details and material unsuitable for distribution.
   Record tooling/version, scope, exclusions and findings; select any scanning
   tool before running it rather than assuming one is installed. Keep raw findings
   private and publish only sanitized evidence. A clean scan is not human
   clearance; a confirmed disclosure blocks publication. Do not rewrite history
   or remove original attribution as an automatic remedy.
4. Prepare the complete five-file kit once the selected inputs are ready. Record
   its manifest digest separately; verify it with the trusted local verifier,
   run `test:source` with a non-admin fixture, then clone the actually downloaded
   bundle and reconstruct the app at the same detached revision. Compare the
   complete installed inventory and dependency sources, without normalizing
   differences. Record the new source SHA and hashes separately from `ae8ea636`.
5. Consolidate the inventory, sanitized findings, native retrieval, reconstruction
   and cleanup evidence in this delivery record. Reuse existing commands; do not
   add an application download endpoint, public ingress or unrelated tooling.
   Run affected checks if corrections are needed, keeping failed/blocked checks
   explicit. Preserve the local-to-proposed-public mapping and all F3/F4/F5 gates.

Acceptance for that batch: an explicit committed input; an accounted-for source,
dependency and notice inventory; documented historical-audit coverage and unresolved
findings; matching package/source identity; complete verified non-admin download;
exact reconstruction and dependency-source correspondence; no remaining owned
fixture resources; and no claim of public availability or human sign-off. If a
required input or check is unavailable, record the limitation instead of closing
that part of acceptance. Requalify the final release after any later source change.

### What still needs the owner, infrastructure or reviewers

The later host inventory supplies the reported Nextcloud/PHP/app versions,
container method, app persistence and S3 driver. Remaining facts include the
active database connection/server version, complete update writers/jobs,
subscription and backup/storage topology. These determine actual update exclusion,
native integrity behavior and coordinated recovery. Do not invent missing values
or deploy the generic filtered-catalog proposal on the owner's behalf.

**Public HTTPS** remains unverified. **Inspector** retains its earlier **14/15**
encrypted-storage result and pending public-ingress journey; this is not evidence
of a newly observed server disclosure. **ChatGPT** still lacks the real
consent/tool/revocation acceptance. These limitations do not prevent the local
source audit, but they prevent unsupported client/production claims and any
operation that depends on those unverified paths.

Security, operations/recovery, license/attribution, accessibility and language
reviewers remain unassigned/pending; the owner retains the previously accepted
operation/recovery responsibility. The new audit does not substitute for their
sign-off. No push, release tag, PR action, infrastructure change or deployment is
part of this assessment or the proposed local audit batch.

## Intended-host read-only inventory

On **2026-10-24**, the owner supplied the SSH destination for the intended
Nextcloud installation in Coolify. Read-only inspection used the existing SSH
trust and account permissions. This supplies part of the previously missing host
facts; it does not authorize deployment, maintenance operations or changing the
approved support matrix. Access coordinates, container/service identifiers,
credentials and raw environment values are deliberately absent from this public
record.

| Observed item | Result |
| --- | --- |
| Orchestrator / ingress components | Running containers reference `coollabsio/coolify:4.5.0` and `traefik:v3.7.13`. Application-reported versions, traffic routing and public HTTPS/client journeys were not tested |
| Nextcloud runtime | Installed 35.0.2, internal version 35.0.2.2; maintenance false; no database upgrade pending according to `occ status` |
| Container image | `lscr.io/linuxserver/nextcloud:version-35.0.2@sha256:e7f790011bf023d33517b4be70231d7aa2e19d54c619664a8533e8bedd4716ac` |
| PHP / architecture / web server | PHP CLI 8.5.12; x86-64; Nginx 1.30.4. FPM-specific settings and extensions were not inventoried |
| Database | Nextcloud reports `pgsql`. A database container associated by service naming reports PostgreSQL 16.15 from `postgres --version`; the actual connection target and active SQL-server version were not independently verified |
| Installed MCP | Enabled app reports version 1.2.0. Its origin and exact installed bytes have not been compared with upstream or the fork |
| Other relevant enabled apps | Calendar 6.6.3, Contacts 8.9.2, Deck 1.19.0, Notes 6.1.0, Talk (`spreed`) 25.0.6, OAuth2 2.0.0 |
| Other app observations | `files_external` 2.0.0 is disabled; Tasks and Groupfolders were not listed in the selected app inventory |
| App persistence | Logical MCP path `/app/www/public/apps/mcp` resolves to `/config/www/nextcloud/apps/mcp`; app directory owned by `abc:users`, mode `0755` |
| Persistent mounts | Named Docker volumes at `/config` and `/data`, both writable; an additional read-only initialization-script bind is present. No backup inspection performed |
| Primary file storage | Configured data directory `/data`; object-store class `\OC\Files\ObjectStore\S3`. Endpoint, bucket, credentials, versioning, retention and backups were not queried |
| Background mode | Nextcloud configuration reports `cron`; this does not prove scheduler execution or identify all maintenance/update jobs |
| Container restart policy | `unless-stopped`; this is not an app/image update-exclusion mechanism |

The initial `docker ps` required the account's existing noninteractive sudo
permission. A plain `occ status` invocation failed because the object-store
environment was absent from that CLI process. The same read-only query succeeded
when invoked with the container's existing s6 environment and service identity:

```sh
docker exec CONTAINER s6-envdir /run/s6/container_environment \
  s6-setuidgid abc php /app/www/public/occ status --output=json
```

This was an execution-context issue for the query, not proof of a web outage.
No environment values were printed or retained in this record.
`config:system:get apps_paths` returned exit 1 without an explicit value; the MCP path was subsequently resolved
with `app:getpath mcp` and filesystem link inspection, rather than assuming an
app-path configuration. Other recorded facts came from selected `occ` queries,
image/mount metadata and version commands. No full configuration dump, database
contents, user files or secret values were requested.

### Consequences for the remaining qualification

The intended host uses **LinuxServer/Nginx**, while the approved reference
profiles and prior native source-acceptance fixture use Apache. Do not transfer
that evidence into an assertion that the LinuxServer deployment is qualified.
Record the additional target profile before changing the declared support matrix;
prepare an isolated equivalent profile for relevant tests. The installed MCP
version makes **1.2.0 → 2.0.0** the concrete upgrade journey to qualify, with a
comparison of the actual installed source and migrations first. Version equality
alone does not prove that the installed app is the unmodified upstream release.

The source-distribution audit above remains actionable without production changes.
The host inventory also makes the subsequent isolated upgrade/update/recovery
proposal concrete. Before executing that broader batch, inventory the active
Coolify redeploy/image-update paths, Nextcloud app-update paths and other writers;
review the initialization hook's role without exposing secrets; and establish the
coordinated backup/restore procedure for database, configuration/app state and S3
objects. A `/data` volume snapshot alone is not evidence of complete recovery for
this object-store configuration. Do not access storage credentials or change
production writers merely to fill an evidence gap.

Still unresolved: active database connection/server version, actual updater/job
ownership and schedules, catalog/subscription policy, S3 and database/config
backup topology, retention and restore evidence, custom-app integrity policy,
final platform qualification and human review. The
owner need not supply the versions and basic app paths already observed; request
only facts that cannot be established through authorized read-only inspection.
**Public HTTPS, Inspector 14/15 and its pending public journey, real ChatGPT
acceptance, and all human reviewers remain pending.** Seeing an ingress proxy or
receiving `occ status` does not close any of those gates.

Only read-only remote inspection was performed. No app was installed, updated or
removed; no configuration, image, schedule, storage policy or service state was
changed. No push, publication, PR action or deployment occurred. **F3 remains open.**

### Documentation review and acceptance limits

The 2026-10-24 review compared this inventory with the prior command results;
it made no further server connection or server change. It distinguishes Docker
image references from independently queried runtime versions and the database
binary version from the active SQL-server version. Service-name association does
not prove the database connection target. CLI status does not certify the web UI,
TLS ingress, background execution, backups or recovery.

The source-access criterion remains demonstrated only for the recorded local
candidate. A new source kit and the future LinuxServer/Nginx qualification must
have explicit inputs and their own evidence. The declared support matrix is
unchanged; neither the observed host nor the proposed local audit closes F3.
HTTPS, Inspector **14/15**, ChatGPT and all human reviews remain pending.

Fresh documentation validation passes for **153 Markdown files / 110 package
sources**, all **29 documentation-tool tests** pass, and translation provenance is
valid with **96 drafts still review-required**. The changed files are the three
F3/fork development records; none belongs to the runtime package allowlist. No
application code, package, full PHP/browser suite or native fixture was rebuilt
or rerun for this documentation-only review. The diff excludes the supplied SSH
coordinates and observed private service/container identifiers. These checks do
not replace historical-content review or any pending host/client/human acceptance.
