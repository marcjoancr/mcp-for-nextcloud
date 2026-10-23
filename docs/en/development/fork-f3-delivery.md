<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# F3: local delivery, update control and integrity

Status: local preparation on 2026-10-23; **F3 remains open**. This follows the
[F3 identity record](fork-f3-preparation.md) and
[fork continuation plan](fork-continuation-plan.md). The owner authorized local
source delivery and control preparation, not publication or deployment. No target
server facts or human reviewer assignments have been supplied. PR #14 remains a
historical reference; no upstream remote or PR action is introduced.

## Local source kit

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

## Validation and unresolved gates

| Fresh validation | Result |
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
| Immutable local source/runtime kit | Prepared and rebuilt for `39148aa`; regenerate at final frozen release revision |
| Ordinary-user source access | Local implementation and non-admin personal-settings/MCP acceptance remain pending; they can be prepared without host facts or public publication. F5 must separately verify the authorized immutable public destination |
| Publication suitability | Pending historical-content review, final notices/source review and trusted digest publication policy |
| Update exclusion | Concrete filtered-catalog/manual-artifact candidate above; pending owner acceptance of service operation and host-specific qualification |
| Native/custom-app integrity | Local content verifier passes; native UI/signature behavior, permissions and trusted digest custody still pending |
| Server facts | Owner must provide Nextcloud/PHP/database/app versions, installation method, app paths/mounts, updater jobs, subscription and backup/storage topology; no secrets requested |
| Recovery and platform matrix | Earlier two native cells retained as historical evidence; final maintained-patch 14-cell matrix and intended-host recovery remain pending; installed upstream 1.2.0 upgrade unverified |
| Client exposure | Public HTTPS and ChatGPT's real journey pending; Inspector's recorded encrypted-storage result remains 14/15 |
| Human review | Security, operations/recovery, license/attribution, accessibility and languages remain unassigned/pending; 96 translation drafts are not approved |

No new native instance was deployed for this work. These build/integrity checks do
not replace the remaining server, client or human acceptance. No push, tag, PR
change or deployment is included.

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

### Recommended next implementation batch

Prioritize the **local source-offer journey** before the final release freeze or
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

This review does not implement that batch, start an instance, configure a catalog,
create a public endpoint or assign reviewers. The data needed from the owner stay
the same: Nextcloud/PHP/database/app versions, installation method, app paths and
mounts, automatic update entry points, subscription and backup/storage topology.
Human security, operations/recovery, license/attribution, accessibility and language
reviews remain unassigned/pending. Public HTTPS, Inspector's recorded 14/15
storage result and the real ChatGPT journey remain explicit limitations.
