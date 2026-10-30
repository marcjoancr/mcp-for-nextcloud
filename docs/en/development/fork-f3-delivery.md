<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# F3: local delivery, update control and integrity

Status: local source-offer acceptance on 2026-10-23, partial host inventory on
2026-10-24, L5 closure and a reconstructed local candidate on 2026-10-29;
**F3 remains open**. See the [current candidate record](fork-candidate-re2js.md)
for its frozen identity and resolved development-dependency security finding. This
follows the [F3 identity record](fork-f3-preparation.md) and
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
| Immutable local source/runtime kit | Historical kits retained; reviewed branch commit `0000d8c` now has a verified/rebuilt local kit (technical audit below). Regenerate and requalify at the final release revision |
| Ordinary-user source access | Implemented; local non-admin personal-settings/MCP retrieval and reconstruction pass for `0000d8c` as well as the earlier candidate. F5 must separately verify the authorized immutable public destination |
| Publication suitability | Technical source/history audit recorded below; retained historical developer paths, screenshots, final notices and trusted digest publication still require human disposition |
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

## Technical source-distribution audit

Executed on **2026-10-24** against reviewed commit
`0000d8c807cfae300c2009ce53f9efb4a5df8081`, starting from clean local `fork/main`.
Kit integrity, non-admin retrieval and reconstruction pass. The scanners retain
their nonzero finding results and the classifications below. **Publication
suitability and F3 remain open**: retained historical metadata, human review and
the other release gates are not cleared by this audit. No production connection or change was made.
This completion report is subsequent documentation, not part of that frozen kit.

### Source and dependency inventory

The selected commit contains **1,683 tracked files**, including 725 application
library files, 46 JavaScript source files, 17 build/documentation/release scripts,
653 test files, dependency manifests/locks, notices and original attribution.
The downloaded bundle retains exactly the original **17,287 reachable objects**:
1,056 commits, 8,328 trees and 7,903 blobs. All historical trees were inspected for
external gitlinks and all blobs for Git LFS pointers; neither was found. Required
historical English revisions therefore remain available for translation provenance.

| Distribution area | Checked content and result |
| --- | --- |
| Runtime package | 938 regular files; generated identity agrees with version 2.0.0 and the selected source commit |
| Preferred application source | PHP/templates/assets and all 46 JavaScript inputs retained in the bundle; the clean build's asset graph has only app-owned inputs and no external runtime imports |
| Build inputs | Canonical packaging/asset/docs scripts, Composer/npm locks and source-delivery/reconstruction tooling retained |
| Runtime dependencies | `smalot/pdfparser` v2.12.5 and `symfony/polyfill-mbstring` v1.43.0 match locked versions and source references; no development Composer packages installed in the runtime |
| Dependency source archive | All 73 vendor files, including loader and licenses, match the runtime vendor tree byte for byte |
| Notices and attribution | Application LICENSE/NOTICE, THIRD-PARTY-NOTICES, LGPL/GPL/MIT/Unicode texts and both dependency/Composer license files are present; personal settings retain Sintax attribution |
| Development dependencies | All 50 locked Composer package names (2 runtime / 48 development) are accounted for in THIRD-PARTY-NOTICES; PHPStan has a separate section. This name inventory does not certify every dependency's license/notice accuracy |
| JavaScript development toolchain | npm lock contains 112 entries including the root, with 8 direct development tools. Playwright 1.64.1 (Apache-2.0) and esbuild 0.28.2 (MIT) are recorded in the lock but not individually listed in THIRD-PARTY-NOTICES; six documentation tools are listed there. None of these tool packages is shipped in the runtime |
| Reconstruction scope | Locked registries or trusted caches are still needed for build tools/dependencies. The kit is not an offline toolchain distribution; archive timestamp identity is not claimed |

License identifiers above report repository/lock evidence, not legal sign-off.
Existing notices explicitly retain unknown embedded upstream versions (such as
TCPDF-derived code and Unicode data provenance); this audit does not invent those
versions. Human license/attribution review remains required. REUSE passes for all
**1,666 covered files**, but is not used as proof of historical privacy or complete
legal clearance.

### Historical-content audit and limits

[Gitleaks 8.30.1](https://github.com/gitleaks/gitleaks/releases/tag/v8.30.1) was
obtained from its official release and its archive checked against that release's
checksum list. Archive SHA-256:
`551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb`.
The explicit [versioned default rules](https://github.com/gitleaks/gitleaks/blob/v8.30.1/config/gitleaks.toml)
have SHA-256 `e163e53b9e7e8a8511e77271e2b323ed057759542a6d988258afe3a1fa329caf`.
This establishes the scanner inputs, not a publisher-signature verification.

Scans used full redaction, no size cutoff, archive/decode depth 2, no baseline or
repository-specific suppressions, and ignored inline `gitleaks:allow` comments.
The scanner's shipped default rule allowlists still apply. Reports and original
exit codes were retained; no finding was removed to obtain a green scanner exit.

| Audit scope | Recorded result |
| --- | --- |
| Initial Git diff scan | 887 commits reported; 52 findings; exit 1 |
| Explicit merge-parent diffs (`--full-history -m` at the full SHA) | 1,054 commits reported; 69 findings: 50 evidence hashes and 19 documented example credentials; exit 1 |
| Direct object-content scan | All 7,903 blobs and 1,056 complete commit objects exported by object ID and scanned: 8,959 inputs / 225,941,400 bytes; 212 findings: 45 evidence hashes and 167 documented example credentials; exit 1 |
| Separate binary fixture scan | Five binary blobs supplied, with archive/decode depth 2; no findings; exit 0 |
| Supplemental object checks | All blobs and commit objects checked for private-key markers, two selected production access/service indicators, SSH coordinates and developer paths; all trees checked for gitlinks |

The merge scan's reported commit count was not assumed to equal reachable-history
coverage. An empty-commit explanation was tested and disproved. Direct scanning
of every reachable blob and complete commit object removes dependence on that
count for content coverage. The reports overlap and their findings must not be
summed as distinct secrets.

Every scanner finding was checked against the corresponding committed line or
exact object bytes. Generic-key matches were values in `evidence_sha256` or
`package.fileSha256` maps; curl-auth matches were explicit documentation password
placeholders. **No confirmed credential disclosure was identified by these
checks.** That statement is narrower than proving that no secrets exist.

Supplemental checks identified 161 historical SSH example occurrences, classified
as placeholder hosts, and **14 historical blob objects across 10 removed document
paths containing developer-machine paths**. Those paths are absent from the
selected tree but remain in the bundle. Their publication disposition stays
pending with the owner/reviewers; nothing was silently removed or rewritten.
Neither of the two selected production access/service indicators was found;
private-key markers and external LFS pointers were also absent under these checks.
The exact private indicators remain outside this document. This is not a complete
inventory of server identifiers or an exhaustive private-data rule set. Contributor metadata is retained, and screenshots were not visually/OCR
cleared; encoded content beyond depth 2, scanner blind spots and human publication
judgment remain explicit limits. A newly confirmed disclosure would block
publication without automatically authorizing history rewriting.

### New kit, non-admin retrieval and reconstruction

The complete local kit is under ignored
`mcp/build/source/0000d8c807cfae300c2009ce53f9efb4a5df8081/`. Its bundle advertises
only `HEAD` at the selected SHA. The manifest digest was recorded separately before
retrieval; it was not obtained from an untrusted download and treated as approval.

| Artifact | SHA-256 |
| --- | --- |
| Manifest | `032b62b39bd9c56055bb3306b4a06c12efb77f876ea6da0087a550a89fc39e6f` |
| Runtime archive | `62d4a3724d75da91006ad3969d7344c0fed50eaf1766d05d1ce183056046d421` |
| Source bundle | `1a41322f79c67c3a2b75bbed4db0912c0b6cf6c56669c5d5ba12a232f5479d87` |
| Runtime dependency sources | `7691814a000196b86ed4d98b6d36f2677e7601f5cd81ed3ff8b98acd3fc4075a` |
| BUILDING.md | `3ed1b1c2581950a863c4b1888908e10d6a1d853db7ce40b44d201759b301fe48` |

The accepted native run is **`mcp-source-413047499830643b`**, with **124 checks**,
runner result 0 and an independently recorded Composer subprocess return code 0.
It exercised non-admin personal settings, both MCP discovery paths, Basic/Bearer,
varying Files grants, denied anonymous/disconnected MCP, unavailable metadata,
credential-free download and tamper rejection. It used the existing pinned
NC35.0.1/Apache/SQLite fixture, with no host ports; it does not qualify the observed
LinuxServer/Nginx/PostgreSQL/S3 production topology or public HTTPS.

An earlier run (`mcp-source-226f8cfed16c8578`) recorded 124 checks and runner result
0, but the execution wrapper reported exit 1. No runner failure marker was found;
the discrepancy's cause was not established. It was not used as the completion
gate. The repeated run above explicitly recorded the subprocess status and passed.

The actually downloaded bundle was cloned and rebuilt at the same detached SHA in
`mcp/build/f3-audit-rebuild-l1cyvhff/`. Trusted installed-tree verification confirms
all **938 file contents and sizes** agree. All **73 dependency-source files** agree
with the packaged vendor tree, and the reconstructed source retains all **17,287
reachable objects**. No differences were normalized away. Docker inspection after
both runs found no remaining integration-labeled containers or networks.

Fresh checks also pass: **11 release boundary tests**, canonical source packaging,
package/documentation validation during both build and reconstruction, and REUSE.
The full PHP/browser suite and final platform matrix were not rerun: this batch
changed no application code. Earlier full-suite evidence remains bound to its
recorded inputs, not relabeled as a fresh run.

Final documentation checks pass for **153 Markdown files / 110 package sources**
and all **29 documentation-tool tests**. Translation provenance is valid; the
**96 drafts remain review-required**. An independent automated engineering review
found no actionable inconsistency and independently compared all kit hashes,
downloaded-file equality, reconstructed object IDs, runtime files and dependency
files. It reviewed saved scan/native evidence without rerunning those commands;
cleanup relies on the recorded Docker audit. It does not approve human privacy,
licenses, screenshots, production/client behavior or an absolute absence of secrets.

### Evidence retention and next gate

Sanitized inventories, redacted scanner reports, per-finding classifications and
private diagnostic scripts are retained under mode-0700 ignored
`mcp/build/f3-distribution-audit/`. Build, reconstruction and subprocess-status logs
are retained under private `/tmp/mcp-f3-audit/`. Native evidence and the downloaded
kit are under ignored `mcp/build/source-acceptance/mcp-source-413047499830643b/`.
These are local retention paths, not public evidence links. Do not publish the
raw object export, reports or execution logs as a substitute for a reviewed source
release. The audit tool is cached outside the repository; no new product tooling
or runtime endpoint was introduced.

This completes the executed local inventory, bounded technical history scan,
reviewed-commit kit, ordinary-user retrieval and reconstruction checks. It does
not clear the retained historical metadata or screenshots for publication. The
source-audit coverage and those remaining human decisions must accompany the
future release review. A later final release revision requires a new kit and
affected qualification; the proposed public mapping remains unverified.

The next engineering scope is the isolated target-profile and 1.2.0 → 2.0.0
upgrade/update/recovery proposal using the observed host facts, while the actual
writers, active database facts and coordinated S3/config/database backup evidence
remain incomplete. A new catalog service or production change is not authorized.
**HTTPS, Inspector 14/15, the real ChatGPT journey and security, operations,
license/attribution, accessibility and language reviewers remain pending. F3 is
still open.** No production access, push, release publication or deployment occurred.

### Review before committing the source audit

The review rechecked the retained kit hashes, downloaded-file equality, exact
reconstructed runtime inventory, complete bundle object set and saved native
results. It confirmed the scanner finding counts and classifications while
preserving their nonzero exit statuses. The text now distinguishes successful
kit acceptance from scanner findings, bounds the server-indicator search to the
two inputs actually checked, and avoids equating dependency-name coverage with
legal approval.

Fresh review checks passed: trusted kit/installed-tree verification, documentation
validation for **153 Markdown files / 110 package sources**, all **29 documentation
tests**, and translation provenance with **96 drafts still review-required**.
Only this development report changed; it is outside the runtime package allowlist.

This documentation commit does not rebuild the kit for its own new revision or
transfer the `0000d8c` digest to it. No scanner or native fixture was rerun for this
review; their recorded evidence remains explicit. Historical metadata, screenshot
and license/privacy disposition, target-profile/upgrade/update/recovery evidence,
public source delivery, **HTTPS, Inspector 14/15, ChatGPT and human reviewers**
remain pending. F3 stays open. No server connection/change, push or deployment
is part of this review.

## Proposed isolated LinuxServer upgrade and recovery qualification

On **2026-10-24**, the requested next test batch is specified in the
[LinuxServer/Nginx isolated upgrade and recovery plan](fork-linuxserver-upgrade-recovery-plan.md).
It covers immutable inputs, a synthetic PostgreSQL/primary-S3 fixture, an actual
upstream **1.2.0 → 2.0.0** replacement, restoration of the healthy 2.0.0 checkpoint
and full rollback to the matching pre-upgrade 1.2.0 state. Both recovery paths
require historical credential invalidation before client admission, consistent
app/config/database/local-data/object snapshots and run-owned cleanup.

This is **planned, not executed**. The existing Apache/SQLite source-acceptance
record is not LinuxServer/Nginx recovery evidence. The proposed initial candidate
remains the audited `0000d8c807cfae300c2009ce53f9efb4a5df8081` kit; this documentation
does not create a kit for its own revision. L0 must resolve the local S3 release
and image digest, freeze the optional-app inputs and verify all selected pins.
The local PostgreSQL reference is not a verified copy of the host database image
or proof of its active connection.

F3 remains open. The exact installed app, effective database/FPM settings,
initialization hook, update writers, actual S3/backup configuration and production
recovery procedure still need qualification. Final release/matrix and trusted
source-publication gates remain separate. **HTTPS, Inspector 14/15, ChatGPT and
human reviewers remain pending.** This plan neither expands the declared support
matrix nor authorizes server changes, publication or deployment. No server access,
fixture execution, push or deployment was performed for this planning task.

Planning validation passed: **154 Markdown files / 110 package sources**,
**29 documentation tests**, and valid translation provenance with **96 drafts
still review-required**. The plan, this report and the development index are
outside the runtime package allowlist. No runtime package, full application suite
or native fixture was rebuilt or run for this documentation-only task.

### Review of the isolated test plan

The 2026-10-24 review checked the proposed assertions against the pinned baseline
and candidate code. It separates static L0 input validation from live L1 storage
checks, and defines closed/probe-only states so maintenance does not invalidate
the HTTP test sequence. Each recovery case receives its own coherent checkpoint
and an explicit expiry budget: OAuth authorization codes last **300 seconds**,
so an expired code cannot count as proof of recovery invalidation.

Two acceptance claims were narrowed to the actual implementation. Confirmation
fingerprints are stateless and may still match unchanged state after recovery;
changed-state/tampered confirmations and old credentials must fail, while the
procedure requires fresh previews. Source-offer discovery checks metadata identity;
full installed-file and downloaded-kit integrity belong to the separate trusted
verifiers. Source downloads do not require MCP authorization, and disconnected
users retain access from personal settings.

The selected commits have identical migration trees. Failure injection therefore
targets app replacement and native upgrade orchestration, without claiming an
in-flight SQL-migration test. The plan also specifies safe backup extraction,
private source-endpoint lifecycle and separate probe responsibilities. None of
these changes implements the harness or alters runtime code.

The review keeps **HTTPS, Inspector 14/15, ChatGPT and human reviewers pending**,
along with the unresolved fixture pins and actual-host qualification. No server
access/change, fixture execution, push, PR action or deployment is included.

Fresh review validation passed: **154 Markdown files / 110 package sources**,
**29 documentation tests**, and valid translation provenance with **96 drafts
still review-required**. `git diff --check` passed. The three documentation files
remain outside the runtime package allowlist; no package or application/native
suite was rerun for this documentation-only review.

## LinuxServer L0 offline planning

The [L0 execution record](fork-linuxserver-l0.md) fixes the app/image inputs and
records baseline package preparation, candidate kit verification and the new
strict offline `test:integration:linuxserver -- --plan` interface. It implements
no container lifecycle or native probe. Standard SeaweedFS 4.49 was unavailable
as an image tag; the available standard 4.48 release is selected explicitly by
immutable index and linux/amd64 digests. The record keeps that decision and its
limits visible. Registry manifest hashes and app archive/manifest checks do not
certify runtime behavior or vulnerability status.

L1-L5, the intended-host qualification and F3 remain open. **HTTPS, Inspector
14/15, ChatGPT and human reviewers remain pending.** No server access/change,
fixture execution, push, PR action or deployment was performed for L0.

Fresh L0 validation passed: **23 focused tests / 165 assertions**, the actual
audited kit in offline plan mode, and the complete catalog `check` with **8,451 PHP
tests / 71,806 assertions**, **690 browser tests**, both static-analysis scopes
and packaging. Documentation checks cover **155 Markdown files / 110 package
sources**; all **96 translation drafts remain review-required**. The L0 record
retains full counts, expected negative-test diagnostics and the dirty development
package limitation. A fresh read-only review found no actionable issue. None of
these results closes native LinuxServer or client/human acceptance.

## LinuxServer plan review after U5 (2026-10-27)

Published U5 commit `940c3da36f0d9c5310a98d7c3f355d2d99c86d33` passes all four CI
workflows and the full **15-cell Apache matrix**, including upstream 1.2.0
replacement. The [LinuxServer plan](fork-linuxserver-upgrade-recovery-plan.md#u5-evidence-and-immediate-next-batch)
now selects it as the recommended candidate for renewed L0 input qualification.
The committed profile still names `0000d8c8`: a new complete source kit, trusted
manifest digest, reviewed profile hash and external-pin recheck are required
before implementing/running L1. This review neither changes that lock nor
prepares a kit or creates a fixture.

The plan explicitly covers U5 backup policy/privacy, Tasks hierarchy and denied
Mail defaults across upgrade and version-matched R0/R1 restores. The five-app
LinuxServer fixture keeps Mail absent; positive Mail acceptance remains separate
Apache evidence. Identical migration source trees do not certify persisted state,
Nginx/FPM execution or coherent PostgreSQL/S3 recovery.

L0-U5 refresh is the next bounded batch; L1-L5, intended-host qualification and F3
remain open. Public **HTTPS**, **Inspector 14/15/encrypted storage**, **ChatGPT**,
security, operations/recovery, license/attribution, accessibility and language
reviewers remain pending. No production access/change, deployment, push or PR #14
operation is part of this review.

Review checks pass: **23 LinuxServer planning tests / 165 assertions**,
**29 documentation-tool tests**, **173 Markdown files / 115 package sources**,
valid translation provenance (**100 drafts still review-required**), and
`git diff --check`. Migration tree equality was checked directly against the two immutable Git objects. The
planning tests use synthetic trusted kits and exercise guardrails; no real U5
kit or U5 `--plan` result is claimed. Only these three development documents
changed; no runtime package or native fixture was rebuilt for this review.

The documentation review also narrows acceptance to the actual contracts:
Apache probe teardown must not erase the LinuxServer recovery fixtures; R0 has
no Mail catalog entry; disabled Versions still refuses `files_edit`; and the
Tasks locking refusal applies to confirmed parent assignment, not removal.
Unexplained baseline-inventory differences block L0 refresh, and a hard-killed
run remains incomplete until its exact owned resources are audited. These are
plan corrections only; no runner, profile, runtime code or source kit was changed.

## LinuxServer L0-U5 input refresh (2026-10-27)

The authorized [L0-U5 renewal](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27)
prepares and verifies the complete local kit for published `940c3da3`, rechecks
all three OCI index/platform pairs and five app archives, and verifies the
retained 1.2.0 archive against its original 532-file inventory. Baseline, image,
app and isolation pins are unchanged; only the candidate commit/digest changed.
The record retains the trusted kit/profile hashes and exact checks separately
from the harness revision. The real offline planner accepts U5 and rejects the
six deliberately invalid inputs; the existing suite passes 23 tests/165 assertions.

L0-U5 has no remaining input blocker. **L1 implementation and live qualification
are next**, followed by L2-L5 upgrade/recovery evidence. No image layers, containers,
production connection, push, deployment or PR #14 action occurred. This local
kit does not close source-publication/history review or intended-host acceptance.
**HTTPS, Inspector 14/15/encrypted storage, ChatGPT and all human reviewers remain
pending**, as do the F3/F4/F5 release and recovery gates.

Final validation passes 173 Markdown files/115 package sources, 29 documentation
tests and 11 release controls; 100 translations remain review-required with valid
provenance. One independent read-only review found no actionable issue and
accepted L0-U5 only. It did not certify L1-L5, independent source reconstruction,
production compatibility or any client/human gate; the L0 record preserves those
limits. The subsequent owner-authorized review reverified the retained kit,
baseline, OCI/app inputs and real-kit refusal controls before the local commit.
No pin discrepancy or implementation change was required. The only corrections
clarify this review/publication status and distinguish retained-byte verification
from the original timestamped network checks. L1, HTTPS, Inspector, ChatGPT and
human reviewers remain pending; no push or runtime operation was performed.


## LinuxServer L1 baseline qualification (2026-10-27)

The separately authorized [L1 implementation and native acceptance](fork-linuxserver-l1.md)
passed on the unchanged L0-U5 pins: LinuxServer NC35.0.2, PostgreSQL 16.15,
authenticated persistent SeaweedFS and the six pinned baseline apps. The final
native run passes 26 checks and removes its five containers, four volumes and
internal network. The measured/reserved disk peak is about 2.06 GiB. Private TLS,
CLI/FPM identity, pre-PHP admission, source-kit delivery, unavailable dependencies,
restart persistence and ordinary-interruption cleanup have separate evidence.
SIGTERM during execution and SIGINT during cleanup both correctly leave failed
qualification with complete cleanup. No U5 app replacement or B0/B1 checkpoint
was performed; the 2.0.0 kit is an unadvertised read-only integrity fixture.

The full registered check passes 8,688 PHP tests/74,201 assertions, 717 browser
tests, both static-analysis scopes, documentation and packaging. The independent
automated review's three Important runner defects were fixed with failing-then-
passing tests. Final focused controls and documentation/package checks cover the
last preflight guard and report edits; see the linked report for command hashes,
failed development attempts and the private evidence locations.

**L1 is qualified locally; F3 is not closed.** The next separate batch is L2
baseline capability seeding and coherent B0 capture, followed by L3-L5 replacement,
recovery and regression closure. The intended server's runtime/update writers,
backup and S3 semantics still require qualification. Public HTTPS, Inspector's
prior 14/15/encrypted-storage finding, ChatGPT and all human reviewers remain
pending. The 100 translations are still review-required. No intended-server
connection, deployment, push, publication or PR #14 modification occurred.


The 2026-10-29 [L1 closure review](fork-linuxserver-l1.md#review-and-local-closure-2026-10-29)
adds fail-safe cleanup after diagnostic exceptions and complete-scan disk
measurement under concurrent admission-file changes. The final native repeat
passes 26 checks and cleanup; 16 Node controls and the focused harness pass.
The registered full check also passes again; the L1 record distinguishes its
coverage from the subsequent focused budget validation and retains the failed
intermediate native attempt. No phase or client/human gate beyond L1 is closed.

## LinuxServer L2 fixture and checkpoint work (2026-10-29)

The authorized [L2 batch](fork-linuxserver-l2.md) adds baseline-specific synthetic
data, private-TLS capability/policy checks and coherent B0 export. Native
runner checks and cleanup passed with 120 HTTP assertions and 87 S3 objects,
but independent review found archive-containment and policy-oracle defects.
Both findings were corrected with regression tests and a new successful native
capture. The old B0 is superseded; the corrected dump matches all eleven policy
tables in its independent oracle. Failed attempts remain documented. The unchanged
U5 candidate remains uninstalled. L3-L5, intended-host acceptance, public HTTPS,
Inspector's prior 14/15/encrypted-storage finding, ChatGPT and human reviewers
remain pending. No production connection, push, deployment or PR #14 change is
part of this batch.

The implementation-stage L2 full check passes 8,690 PHP tests/74,266 assertions, 717 browser
tests, both static-analysis scopes, documentation and packaging. Independent
read-only verification after cleanup confirms all B0 payloads, four private-input
hashes, all eleven policy tables and both volume archives. L2 is qualified
locally; F3 remains open. The linked L2 record identifies the accepted generation,
trusted digest and final evidence hashes.

The [L2 closure review](fork-linuxserver-l2.md#closure-review-2026-10-29) corrects
two further evidence defects: generic errors could count as policy denials, and
the HTTP client silently failed to persist its private fixture state. A real
filename-policy refusal now replaces the earlier invalid-extension case; checked
private export, transfer hashes and mandatory state/plan checks prevent loss of
HTTP-created evidence. Earlier captures remain recorded as unaccepted. The final
native repeat and post-cleanup verification pass, including both pending plans,
all eleven policy tables and all B0 payloads. The plan's obsolete next-step text
now names L3, which remains unimplemented and requires separate authorization.
HTTPS, Inspector, ChatGPT and human reviewers remain pending; F3 is not closed.
The final post-fix full check passes 8,697 PHP tests/74,291 assertions,
717 browser tests, both static-analysis scopes, documentation and packaging.
The accepted generation and verification hashes are in the linked L2 closure
record; no source-kit identity or published branch changed.


## LinuxServer L3 isolated upgrade work (2026-10-29)

The authorized [L3 batch](fork-linuxserver-l3.md) implements replacement of the
frozen 1.2.0 baseline with U5 2.0.0, preservation assertions, native capability
and source checks, same-image container recreation and coherent B1 capture.
The post-review run passed 55 native tests / 1,144 assertions, all required
probes, recreation and B1 capture. Post-cleanup verification passed, with zero
owned containers, networks or volumes remaining. The final full local check
also passed 8,697 PHP tests / 74,392 assertions, 717 browser tests, both static
analysis scopes, documentation and packaging. The linked execution record names
the accepted generations, hashes, rejected diagnostic attempts and minor L5
archive-permission normalization item. No recovery is inferred from this upgrade.

L4/L5, intended-host acceptance, public HTTPS, Inspector (prior 14/15), ChatGPT
and human review remain pending. The target server, published branches and
PR #14 remain unchanged; F3 is not closed.

## LinuxServer L4 recovery work (2026-10-29)

The authorized [L4 batch](fork-linuxserver-l4.md) implements full restoration into
fresh run-owned storage and both failed-upgrade rollback boundaries, using the
accepted retained L3 B0/B1. It creates separate case generations with live
credentials, verifies restored pre-revocation state, cuts the global auth epoch
and user generations before admission, and checks rejection after controlled
reconnection. The final run passed 72 stages, all three recovery cases and three
failed-import negatives, with complete cleanup and post-cleanup artifact verification. The final
local check passed 8,707 PHP tests / 74,469 assertions, 717 browser tests, static
analysis, documentation and packaging. The linked report records accepted hashes,
timings, diagnostic failures and the corrected exact stale-confirmation oracle.
The closure review also requires an exact owner revocation cut and persisted DAV
readback; focused regression tests and a fresh native repetition pass.
Original checkpoints stay immutable.

This work does not start L5 or close F3. Intended-host acceptance, public HTTPS,
Inspector (prior 14/15 with its encrypted-storage finding), hosted ChatGPT and
human reviewers remain pending. No production operation, publication, push or
change to PR #14 is authorized by this local recovery work.


## LinuxServer L5 local closure (2026-10-29)

The authorized [L5 evidence and cleanup record](fork-linuxserver-l5.md) closes the
scoped local LinuxServer campaign. L0's U5 kit/profile and local image pins verify
again; accepted L1-L4 result hashes and all eight retained checkpoint copies match
before and after retention work. L4's final versions remain R1 2.0.0 and both R0
cases 1.2.0. No application candidate changed.

The sealer now normalizes copied checkpoint permissions; 42 LinuxServer controls
pass. The full local check passes 8,707 PHP tests / 74,469 assertions, 717 browser
tests, both static-analysis scopes, documentation and packaging. Initial Apache
runs exposed three LinuxServer-only tests in the recursive Apache suite. The
selection is corrected; both unfiltered repeats pass: NC35/MariaDB upgrade from
1.2.0 has 300 tests / 4,435 assertions / 11 skips, and NC35/SQLite has 300 tests /
4,396 assertions / 14 skips plus successful coordinated recovery. The report keeps
the failed attempts, conditional skips and profile boundaries explicit.

Cleanup removed obsolete private working copies after exact previews, retained
accepted checkpoints with restrictive permissions, preserved historical results
and verified absence of LinuxServer and all four Apache attempts' owned resources,
including anonymous volumes. Retention/removal responsibility remains with the
owner. This closure review retains the accepted synthetic checkpoints for the
remaining F3/intended-host comparison and human review; revisit disposal at the
next F3 release or intended-host acceptance decision.

F3 remains open: intended-host qualification, final release/support-matrix gates,
public HTTPS, Inspector's prior 14/15/encrypted-storage finding, hosted ChatGPT and
human reviewers remain pending. Nothing was pushed or deployed and PR #14 was
not changed. The closure review reverified the unchanged evidence and code,
passed 42 Node controls and 57 focused PHP tests / 398 assertions, and validated
documentation/package for the local commit. This is not production recovery
approval or independent human acceptance.


## Local fork/main integration and candidate after L5 (2026-10-29)

The seven reviewed LinuxServer commits were incorporated by fast-forward into
local `fork/main`, from `940c3da3` through `8d6b4f28`. The
[candidate record](fork-candidate-after-l5.md) freezes the full latter revision,
records its trusted manifest digest and distinguishes the subsequent documentation
commit from the immutable source/runtime kit. No push, deployment or PR #14 change
occurred. The complete local check and 42 LinuxServer controls pass. A clean
bundle-only reconstruction matches all 975 runtime files and 73 vendor files.
Relative to U5, only source identity, a Composer command description and root
Composer metadata differ; historical native evidence was not relabelled as a
fresh execution of this candidate.

A separate npm audit reports one high-severity RE2JS advisory through markdownlint,
represented by two affected development-package entries, and exits 1. Production
npm and locked production Composer audits report no advisories. This toolchain
finding remains open and must be resolved before release publication; dependency
locks were preserved in this batch. The next bounded engineering step is that
remediation and a newly frozen, reconstructed candidate.

F3, final support-matrix and intended-host qualification, trusted source
publication/history review, public HTTPS, Inspector's prior 14/15 and encrypted
storage finding, hosted ChatGPT and all human reviewers remain pending. The
current kit is local, unpublished and unsigned; branch integration is not remote
CI or production acceptance. Accepted L5 checkpoint retention is unchanged.


## RE2JS remediation and replacement candidate (2026-10-29)

The [replacement candidate](fork-candidate-re2js.md) closes the reported
RE2JS/markdownlint finding. Commit `cfeb26d4` scopes an override to RE2JS 2.8.7
under markdownlint 0.42.0, updates only that dependency's lock entry and documents
the removal condition. The full npm audit moves from exit 1/two affected package
entries to exit 0/no reported vulnerabilities after a clean install, and passes
again in a bundle-only reconstruction. Production Composer audit is also clear.
No broad dependency update, audit suppression or application code change occurred.

The complete local check passes 8,707 PHP tests / 74,469 assertions, 717 browser
tests, both static-analysis scopes, documentation and packaging. The new frozen
2.0.0 kit verifies against its separately recorded digest. Reconstruction matches
all 975 runtime files and all 73 dependency-source files. Compared with the prior
candidate, only packaged source identity and Composer root references change.
The linked record preserves the original failed audit and distinguishes the
pre-commit development package from the clean frozen candidate. It supersedes the
older local candidate without changing historical evidence or accepted checkpoints.

F3 remains open. Final support-matrix/candidate acceptance, intended-host controls,
trusted source publication and history/attribution review, public HTTPS, Inspector
(prior 14/15 and encrypted-storage finding), hosted ChatGPT and all human reviewers
remain pending. No push, server operation, deployment or PR #14 change occurred.
The next bounded step is the remaining isolated platform and final source-offer
acceptance, with its scope explicitly selected; this batch starts neither.


### RE2JS candidate closure review (2026-10-29)

The [candidate closure review](fork-candidate-re2js.md#closure-review-2026-10-29)
confirms that no further dependency change is required. A fresh clean install
selects RE2JS 2.8.7; npm and locked production Composer audits remain clear. All
29 documentation and 11 release/integrity tests pass again. The candidate's
trusted digest, 20 original evidence records, source bundle/clone and retained
975-file runtime and 73-file vendor reconstruction inventories verify unchanged.
The earlier full suite remains historical evidence, not a new execution. npm
audit remains a separate check outside `composer check`.

Only documentation is updated, including the distinction between new checks,
retained reconstruction evidence and the immutable `cfeb26d4` kit. Review logs
are retained separately and documentation/package are revalidated. F3, intended
host/final platform acceptance, trusted publication, HTTPS, Inspector, ChatGPT
and human reviews remain open. No push, deployment, server operation or PR #14
change occurred.


## Platform/source plan and Inspector storage resolution (2026-10-29)

The [next isolated campaign](fork-platform-source-acceptance-plan.md) names all
15 current native cells and a separate ordinary-user source-offer/reconstruction
gate. M0 first updates the independent Node build runtime and adds verified kit
consumption/installed-tree gates, then freezes the reviewed successor kit.
No matrix or source-offer container is started by this planning batch; the
reviewed `cfeb26d4` kit and accepted LinuxServer checkpoints remain unchanged.

The [Inspector storage investigation](inspector-storage-resolution.md) reproduces
the original nonzero 14/15 outcome, corrects its unsupported static-client oracle,
and demonstrates a genuine issuer-stamp persistence regression on 2.11.0. Pinned
Inspector 2.13.0 / Node 24.22.1 passes the expanded 21-case encrypted backend suite,
including fresh-process persistence and resource/issuer boundaries. This closes
the local component blocker, not SDK network rebinding or end-to-end Inspector.

F3, final platform/intended-host acceptance, immutable source publication and
history/attribution review, public HTTPS, refreshed end-to-end Inspector, hosted
ChatGPT and all human reviewers remain pending. No push, deployment, production
server modification or PR #14 operation occurred.


### Inspector and platform-plan review

The [pre-commit review](inspector-storage-resolution.md#pre-commit-review) retains
the original storage failures, confirms a fresh 21/21 result with unchanged runner
bytes, and verifies the retained candidate and evidence indexes. Client-runner,
documentation and release tests, documentation checks and packaging pass again.
The plan now freezes M0 inputs after its prerequisite changes and requires an
actual installed-tree comparison before native/source-offer acceptance. Neither
gate is implemented by this review. F3, HTTPS, end-to-end Inspector, ChatGPT and
all human reviewers remain pending; the frozen candidate is not regenerated.


## M0 candidate and platform-input closure (2026-10-30)

[M0 is complete](fork-platform-m0.md): patched Node24.22.1/npm11.19.1,
reviewed image/baseline inputs, explicit immutable-kit consumption and measured
installed-tree gates are implemented and covered by failure tests. The complete
local check passes. The frozen successor is `efb27979b6a90122ce45a6a509e52390030f7ed9`;
its bundle-only reconstruction matches all 975 runtime and 73 vendor files.
Only source/Composer commit metadata differs from the prior candidate.

All 15 successor plans pass without invoking Docker; **zero native cells have
been executed for this kit**. Next is M1 ordinary-user source retrieval,
downloaded-kit reconstruction and the two primary native cells. Prior L0-L5
acceptance and Inspector component results remain scoped to their recorded inputs.
F3, final/intended-host acceptance, trusted source publication/history review,
HTTPS, end-to-end Inspector, hosted ChatGPT and all human reviewers remain open.
No push, deployment, production-server operation or PR #14 change occurred.


## M1 isolated source and primary-platform acceptance (2026-10-30)

[M1 passes](fork-platform-m1.md) for the unchanged `efb27979` candidate:
124 source-offer checks, exact reconstruction from the anonymously downloaded
kit, and both full NC35/MariaDB suites (fresh with Mail and upstream 1.2.0 upgrade).
All three installed trees match the kit, and all owned containers, networks and
volumes were independently confirmed absent. Coverage is **2/15 native cells**,
plus the distinct source fixture; M2 is next. HTTPS, Inspector end-to-end,
ChatGPT, all human reviewers, final publication/history review and intended-host
qualification remain pending. No push, deployment or PR #14 change occurred.


## M2 core and database acceptance (2026-10-30)

[M2 passes](fork-platform-m2.md) for the unchanged `efb27979` candidate: eight
sequential native cells covering NC35/PostgreSQL, NC35/SQLite, NC34/MariaDB and
NC33/MariaDB, each fresh and upgraded from the reviewed 1.0.8 baseline. The suites
report 2,400 cases, 37,303 assertions, 64 expected conditional skips and no
failures/errors. Candidate inventories and all four installed baselines match;
SQLite coordinated recovery passes. All 14 containers, eight networks and
14 volumes were independently confirmed absent.

Coverage is now **10/15 native cells**. NC33/Deck 1.17.7 lacks the native dependency
API; tests establish tool hiding/refusal there, not feature parity. M3 is next
and was not started. M4, intended-host/S3/recovery qualification, final source
publication/history review, HTTPS, Inspector end-to-end, ChatGPT and all human
reviewers remain pending. No push, deployment or PR #14 change occurred.


## M3 remaining upgrade-origin acceptance (2026-10-30)

[M3 passes](fork-platform-m3.md) for the unchanged `efb27979` candidate: five
sequential NC35/MariaDB updates from 1.0.8, fork 1.1.2 with Mail, upstream 1.1.0,
1.1.1 and 1.1.2. The suites report 1,500 cases, 22,432 assertions, 47 expected
conditional skips and no failures/errors. All installed candidates and preserved
baseline trees match; legacy/continuity checks and the two Mail cases passed.
All 11 containers, five networks and 11 volumes were independently confirmed absent.
A startup race in the external progress reader was reproduced and corrected;
native runs and evidence were unaffected.

Coverage is now **15/15 declared native cells**. M4 was not started and remains
the next reconciliation gate. LinuxServer qualification for this successor,
intended-host/S3/recovery acceptance, final source publication/history review,
HTTPS, Inspector end-to-end, ChatGPT and all human reviewers remain pending.
No push, deployment or PR #14 change occurred.
