<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# README and documentation localization plan

> **For agentic workers:** Implementation requires separate authorization. Once
> authorized, use the executing-plans workflow, execute phases in order, and pass
> each gate before advancing. Keep the selected isolated worktree and branch.

**Status:** Implemented; phases 0–5 passed local acceptance gates. See the [execution report](2026-10-17-documentation-i18n-report.md)
for outcomes. The phase lists below preserve the reviewed implementation brief.
**Date:** 2026-10-17.
**Implementation scope amendment:** The user also requested Catalan (`ca`, Català).
It follows the same reviewed-publication and explicit English fallback policy as
Spanish; no placeholder pages or automatic review certification.
**Baseline:** `52c82580b927afeecf209d76f7a4f952b022bbb7`.
**Goal:** Make documentation easy to find, contribute to and translate, with one
English source per subject and usable documentation in both GitHub and the app archive.
**Architecture:** Short README entry points; audience-oriented Markdown under
`docs/`; explicit translation provenance; a curated operational bundle generated
from the same sources during packaging.
**Tech stack:** GitHub-flavored Markdown, Git, existing Composer entry points and
Node/npm development toolchain; no website framework or runtime dependency.
**Spec:** [Architecture specification, section 8](../architecture-specification.md#8-documentation-and-language-policy).
**Lessons:** [DevEx report](2026-10-17-developer-commands-static-analysis-report.md),
[compatibility validation](2026-10-17-devbox-catalog-validation-report.md) and
[administration JavaScript report](2026-10-17-admin-javascript-responsibilities-report.md):
inventory consumers, preserve behavior and attribution, verify the actual archive,
and separate local evidence from external validation.

## Global constraints

- Code, comments, filenames and canonical technical prose stay English. Preserve
  Brazilian Portuguese content. Publish Spanish and Catalan only after translation and review.
  Documentation `pt-BR` and application catalog `pt_BR` remain separate systems.
- Change documentation organization and its development/distribution support only.
  Do not change endpoints, tool schemas, security policy, application translations,
  supported platform versions or runtime behavior as part of this migration.
- Preserve operational caveats, licensing, credits and source attribution. A shorter
  README must not silently remove a limitation or turn a diagnostic into a safe,
  read-only command. Never execute installation, OAuth or destructive examples as
  a documentation link test.
- No duplicate maintained manuals, committed package documentation mirror,
  `legacy/` or temporary redirect tree at completion. Short, permanent entry points
  and meaningful preserved public anchors are part of the final design.
- Existing plans and reports remain historical records at their current paths.
  Do not rewrite their old command examples to look like current instructions.
- No website generator, GitHub Pages publication, automatic translation service,
  language-detection quality gate, push or deployment. No new security contact or
  `SECURITY.md` policy without an established reporting channel.
- Missing or outdated translations do not block ordinary code changes. Broken
  links, invalid provenance, missing package documentation and failed validators
  are real errors and must remain visible.

## Baseline findings

| Evidence | Consequence |
| --- | --- |
| Four operational READMEs: root English/Portuguese about 30/33 KB, app English/Portuguese about 62/62 KB | Installation, clients, security and development instructions have multiple owners and drift independently. |
| `docs/README.md` and `docs/en/README.md` predominantly list migration plans/reports | Users have no task-oriented documentation entrance; contributors must scan implementation history. |
| No `docs/pt-BR/`, `docs/es/` or Spanish root README | UI translation coverage is not documentation coverage. Do not advertise nonexistent pages. |
| Root manual-build/development examples omit the Node/npm preparation now required by packaging | Separate runtime requirements from source-build prerequisites; use the current setup/testing guides as authority. |
| Both app READMEs link to `../docs/en/development/setup.md`; the package only contains `mcp/` | Repository-valid links already fail in the standalone archive. Final acceptance must test both contexts. |
| No documentation link/provenance checker | Existing PHP/UI localization tests cannot detect documentation regressions. |
| Current app version is 1.0.8; new documents are not published at that release tag | A link to that tag would not solve archive documentation. Do not invent an available release URL. |
| Original README and screenshot attribution lives in `REUSE.toml` | Extraction must carry the original copyright, not replace it with contributors-only attribution. |

At this baseline there are 45 tracked Markdown files. Contextual READMEs under
`mcp/phpstan/` and `mcp/tests/Support/*/Compatibility/` stay beside their owners;
they are not operational manuals to relocate or translate. Source paths inside
historical prose and code examples are not automatically navigable links.

GitHub discovers READMEs in `.github/`, then the repository root, then `docs/`.
Keep the root entry point and avoid a competing `.github/README.md`. GitHub supports
relative Markdown links; this locale layout is our convention, not a mandatory
GitHub localization format. See [About READMEs](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-readmes).
Heading edits can change fragment targets; validate localized fragments separately
and preserve intentional public anchors. See [GitHub Markdown syntax](https://docs.github.com/en/get-started/writing-on-github/getting-started-with-writing-and-formatting-on-github/basic-writing-and-formatting-syntax).

## Final ownership and layout

Proposed paths below are implementation targets, not claims that these pages exist.

```text
README.md                         English overview, requirements, quick start, links
README.pt-BR.md                    Equivalent Brazilian Portuguese entry point
README.es.md                      Only when reviewed; not required for completion
CONTRIBUTING.md                    Contributor entrance, linked commands and policy
mcp/README.md                      Concise package introduction and operational index
mcp/README.pt-BR.md                Equivalent existing package introduction
mcp/README.es.md                   Optional, only with a reviewed package translation

docs/
  README.md                       Languages and audience entrances
  documentation.json              Supported locales and explicit package inclusion
  assets/                         Shared documentation images
  en/
    README.md                     User, administrator and contributor navigation
    installation.md               Runtime requirements, App Store/manual installation
    guides/
      README.md                   Operational index, also usable in the package
      clients.md                  Client setup, app passwords and revocation
      permissions.md              Access policy, optional apps and administrator scope
      file-protection.md          Locks, hidden tags and their precise limitations
      server-logs.md              Log access, configuration and operational cautions
      troubleshooting.md          Diagnostics and post-installation verification
    reference/
      tools.md                    Capability index and tool-guide entry points
      resources.md                Resource discovery and URI contracts
      configuration.md            Settings and configuration key reference
      oauth.md                    Protocol details and client-registration constraints
    development/
      setup.md                    Existing authoritative source-build prerequisites
      testing.md                  Existing unified verification instructions
      static-analysis.md          Existing analysis ownership
      adding-a-tool.md             Existing capability authoring guide
      admin-javascript.md          Existing frontend ownership
      architecture-specification.md
      packaging.md                Artifact contents, build and distribution rules
      documentation.md            Authoring, ownership, links and checker commands
      translations.md             Source metadata, review and freshness policy
      plans/
        README.md                 Historical plan/report index
        ...                       Existing records retain their paths and contents
  pt-BR/                          Matching English filenames; actual translated pages
  es/                             Only actual reviewed pages, no placeholder tree
```

`docs/en/guides/README.md` is a curated operational entrance, not a second manual.
It links to installation, guides and references; it must not depend on development
or historical pages to complete an installation or resolve an operational issue.
The full locale index additionally links to contributor documentation. Operational
pages link back to their operational index, not the full locale index or root
README: those repository-only entrances are outside the package allowlist. Source
builds are discovered through the root README, CONTRIBUTING and full locale index;
installation in the operational bundle describes installing a built artifact. Contextual
READMEs and legal files remain in place. Move the two existing shared screenshots
from `screenshots/` to `docs/assets/`, update actual consumers and REUSE annotations,
and remove the old directory when empty; do not add screenshots merely to fill a page.

### Content migration map

| Current subject | Canonical destination and boundary |
| --- | --- |
| Root overview, features, requirements, quick start | Root README keeps a short useful introduction; detailed procedures link to installation and guides. Separate runtime PHP/Nextcloud requirements from contributor tooling. |
| Root and app installation/SSH instructions | `installation.md` owns installation of built artifacts. Source-build instructions belong to existing setup plus `development/packaging.md`, reached from repository entry points rather than package-local links. |
| Client setup, OAuth administration, app passwords, revocation | `guides/clients.md` owns steps; `reference/oauth.md` owns protocol/registration constraints, linked from those steps. |
| Security, optional apps, exclusions | `guides/permissions.md`; links to file protection and log access rather than repeating their policy. |
| Locked files and hidden tags | `guides/file-protection.md`, including fail-closed behavior, non-atomic checks and the Text editor exception. |
| Server-log operation | `guides/server-logs.md`; settings keys link to the configuration reference. |
| Tool tables, Deck/Contacts/Tasks notes, image behavior | `reference/tools.md` provides capability navigation and necessary user-facing caveats; `mcp_guide` remains the executable schema/usage authority. Preserve unique information before removing redundant schema tables. |
| MCP resources | `reference/resources.md`. |
| Configuration and keys | `reference/configuration.md`; one key reference rather than copies in client and log guides. |
| Calendar diagnostics and post-installation tests | `guides/troubleshooting.md`, preserving write/delete warnings and required permissions. |
| Packaging, dependencies, artifact policy | `development/packaging.md`, linking setup/testing rather than copying commands into every README. |
| Release history and roadmap | Link `CHANGELOG.md` for released behavior; reconcile unique release facts before removing duplicates. Keep unreleased intentions clearly separate in the root overview. |
| Legal notices and Sintax credits | Retain concise attribution and valid links to existing legal files at both repository and app roots. |

Record every meaningful source section, including translated sections and anchors,
in a migration ledger in the execution report. Each entry names its final owner or
explains deliberate removal of duplication. No information is dropped solely
because its old paragraph is long. Do not regenerate public interfaces from an
independent documentation schema or introduce a generic documentation framework.

## Translation contract

- Use **English**, **Português (Brasil)** and **Español**, not flags. Link the
  equivalent page when it exists. Otherwise explicitly label the English fallback;
  never send the reader silently to a different-language landing page.
- Locale structure uses English filenames. Only existing translated pages appear
  as translated navigation. Missing sections may link directly to English.
- Preserve existing Portuguese material while reorganizing it; do not replace it
  with English placeholders. New development guides may remain English-only.
- Each translated page, including localized root/app READMEs and locale indexes,
  records its real canonical repository path and the full commit used as its
  translation/comparison source. English source commits must exist before these
  metadata values can be written. This requires separate source and translation
  commits during implementation, not a guessed future SHA.

```markdown
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: FULL_SOURCE_COMMIT_SHA -->
<!-- translation-review: pending -->
```

The SHA token is illustrative only. The additional review field is `pending` or
`reviewed`; it separates provenance from human language review. For relocated
Portuguese, actually compare and align against the recorded English revision.
Unresolved differences require `pending` and a visible notice linking to the
canonical English page. Recording the source used for that comparison does not
claim the translation is accurate. Preserve the old wording when a safe
translation correction cannot be reviewed; document the difference. Spanish pages
are published only with reviewed status. A recorded reviewer/PR in the execution
report supplies evidence; automation must never set `reviewed` on its own.

| State | Meaning | Required handling |
| --- | --- | --- |
| Missing | No translated page | Explicit English fallback; informational, not a failed gate. |
| Review required | Retained translation has `pending`, or source content changed | Report prominently; known outdated/pending pages carry a visible notice and English link. Human review is needed, not an automatic rewrite. |
| Current | `reviewed` and source bytes match the recorded source blob | No freshness warning; this is provenance evidence, not machine certification of language quality. |
| Invalid | Bad/missing metadata, absent source, invalid/unavailable commit, or incorrect locale mapping | Fail with file and cause; never count as current or silently skip. |

Compare the source file at the recorded commit with its current working-tree
contents, not merely commit IDs: unrelated commits do not stale a translation.
Validate that the source exists at both revisions. Root/app README mappings are
explicit exceptions to the `docs/en/` source pattern. Reject escaping paths and
unresolvable history. Recorded commits must be reachable ancestors of the checked-out
HEAD, not dangling local objects or transient CI merge commits. CI supplies full
Git history; local shallow checkouts receive an actionable error, with no hidden
fetch from the validator. Test ancestry as well as object existence.

Preserve the English source commits when integrating this migration. Squashing or
rebasing away a recorded source commit breaks provenance even if its files look
unchanged. If history must be rewritten later, land the English source first,
then re-establish translation metadata against its durable commit in a follow-up
commit after checking the source bytes and review state. Do not invent a future
SHA, mark pending pages reviewed, or rely on an abandoned branch to retain objects.
No integration action is authorized by this documentation plan.

Freshness reporting is advisory for valid missing/changed translations. Structural
validation and command execution failures are blocking. When an English edit makes
an existing translation stale, the author adds a short visible review notice
without needing to translate the changed content. The freshness report identifies
missing notices as follow-up work, not a reason to block an unrelated code change.
At migration closeout, all known pending/stale pages must have their notice. Only
human review can advance the revision, remove the notice and set `reviewed`.
A generic language switch is not an outdated-translation notice.

## Distribution contract

Choose the specification's **bundled documentation** option. Published release
URLs cannot currently resolve new pages at the declared version; branch links would
also describe changing behavior. Packaging therefore carries the relevant docs
from the exact working tree used to build the application.

- `docs/documentation.json` owns the supported locale identifiers and explicit
  package page/asset allowlist, including the operational indexes. It does not
  duplicate page prose, store computed freshness, or enumerate every historical
  document. Require the English operational set and every Portuguese operational
  page actually preserved by this migration, not a Portuguese counterpart for
  every new English page. Missing counterparts use explicit English fallbacks.
  Spanish is included only when actual reviewed pages are added to the manifest.
  Define one manifest schema in the repository checker: reject unknown locales,
  duplicate entries/destinations, missing files and paths outside the permitted
  operational/asset roots or the explicitly supported app README paths. Derive locale equivalence from path conventions and
  metadata; do not maintain a second page-pair inventory.
- Copy selected `docs/...` sources into staged `mcp/docs/...`; never create a
  tracked `mcp/docs/` mirror. Include only required assets and operational pages,
  not contributor guides, migration history, checker scripts or Node dependencies.
- Package READMEs link to `../docs/<locale>/guides/README.md` in source. A narrow
  staging transform maps repository source paths to archive destinations, rewriting
  local links/images and reference definitions in the staged copies only. Preserve
  fragments, labels, metadata and SPDX comments. Use a Markdown parser, not a
  repository-wide regular-expression replacement; process supported HTML links too.
- All package-local links must resolve inside the extracted `mcp/` root, including
  language switches, English fallbacks, images, fragments and legal references.
  Links from operational pages to other local pages require explicit inclusion;
  fail on a missing allowlist entry rather than silently bundling development files.
- Use existing app-root legal files for package references. General upstream,
  App Store and issue links may stay external; essential operational procedures
  must work offline. Package introductions may identify the source repository for
  contributors but must not link to nonexistent version-specific pages.
- A full repository checkout is the packaging input. Running a source build from
  a copied `mcp/` directory without its canonical docs fails clearly; installing
  the already built archive requires neither the repository nor Node/Git.
- The mapping is fixed: `docs/<path>` becomes `mcp/docs/<path>`; app READMEs
  and app legal files retain their `mcp/<path>` archive destinations. Root READMEs,
  repository-only indexes and contributor pages have no package destination.
  Select optional localized app READMEs from the manifest rather than adding an
  independent locale list to the shell script. Never rewrite prose or source metadata.
- Run repository documentation/provenance validation and advisory freshness reporting
  inside the package pipeline, including direct `sh scripts/package.sh` invocation.
  Use the same checker functions/CLI as the Composer wrappers; do not recurse into
  `composer check` or implement a second ruleset. Invalidate stale output before
  these fallible steps, just as before asset building.
- Validate the extracted candidate archive before publishing the final filename.
  Check entry paths/types before extraction: reject absolute/escaping paths,
  duplicate destinations and link entries capable of escaping the extraction root.
  Use the existing archive inspection boundary for these checks, not a second tar
  parser in the documentation checker. Extract only into a private build-stage
  directory and remove it on success or failure.
- Preserve existing asset, production-dependency and licensing checks. Verify the
  exact expected docs/README inventory and compare bytes against the deterministic
  staged transformation; link validity alone must not accept a stale but internally
  consistent manual. Legal files and included assets remain byte-for-byte copies.

The exporter is permanent distribution support, not transition code. It has no
network access, language inference or content-authoring responsibility.

## Validation ownership and command interface

Use the existing locked Node development toolchain. Select and lock small maintained
Markdown parsing/linting and GitHub-compatible fragment libraries during
implementation; do not add a second language runtime or a website build system.

| Owner | Responsibility |
| --- | --- |
| `mcp/scripts/docs/markdown.mjs` | Parsed links/images, reference definitions, supported HTML targets and heading/custom-anchor resolution. Ignore code examples as links. |
| `mcp/scripts/docs/repository.mjs` | Repository Markdown discovery, manifest schema validation, boundaries and actionable structural diagnostics. |
| `mcp/scripts/docs/translations.mjs` | Canonical source mapping, Git provenance and separate freshness/review results. |
| `mcp/scripts/docs/package.mjs` | Consume the validated manifest, stage deterministic path transforms and verify extracted inventory, bytes and links. |
| `mcp/scripts/docs/cli.mjs` | Small explicit command dispatch; no second implementation of validation rules. |
| `mcp/tests/documentation/*.test.mjs` | Node built-in test runner: real temporary Git histories, link fixtures and package-boundary failures. |
| `mcp/scripts/package.sh`, `check_package.php` | Retain archive orchestration and PHP archive inspection; enforce safe extraction before docs verification and final publication. |
| `mcp/composer.json`, `mcp/package.json`, `mcp/package-lock.json`, `.github/workflows/quality.yml`, `.github/workflows/package.yml` | Unified entry points, pinned dev dependencies, full-history checkouts and matching CI checks. |

Proposed public commands, always run against the complete repository from `mcp/`
(or with `composer --working-dir=mcp` at root):

| Command | Contract |
| --- | --- |
| `composer docs:check` | Markdown structural rules, repository links/fragments, locale navigation and provenance validity. Fixed scope, nonzero on errors. No HTTP link crawling. |
| `composer docs:translations` | Print every locale's missing/review-required/current state and notice follow-ups. Exit zero for valid pending work; nonzero for invalid metadata, unavailable history or execution errors. |
| `composer test:docs` | Run all documentation-tooling tests using Node's test runner; fail on empty discovery or any failure. |
| `composer check` | Add docs checks, translation reporting and docs-tool tests before the existing PHP/JS/package gates; retain every existing gate and fixed argument behavior. |
| `composer package` | Validate source docs/provenance, report freshness, then stage and verify bundled docs through the package script, independently of `check`. |

All three new documentation commands have fixed scopes and ignore forwarded
arguments through Composer's existing `@no_additional_args` convention. The CLI
rejects unsupported flags rather than allowing file filters to bypass whole-project
gates. Focused fixtures use programmatic test interfaces, not public skip options.

The repository checker covers tracked and untracked non-ignored Markdown, including
contextual and historical READMEs, plus manifest targets. Discover from the repository
root regardless of the caller's directory and exclude ignored build/dependency
output through Git's existing ignore rules, not documentation-specific exceptions.
Adopt useful structural lint rules, not line-length or prose-language guessing. Fix genuine old link defects without
rewriting historical claims; no blanket exclusions or permanent debt baseline.
Root-relative links resolve against the repository; archive links use archive
roots. Decode URL paths/fragments correctly, distinguish external/mail links,
and reject path escapes or symlink escapes. Unsupported local-link constructs
must produce an explicit diagnostic, not disappear from validation.

Update `DeveloperCommandsTest.php` when adding fixed Composer gates. Extend
`CheckPackageTest.php` and the documentation-tooling tests for actual archive
failures; do not replace existing package coverage. Store intentionally invalid
Markdown as strings in the documentation test code and generate fixture files and
Git histories under an ignored `mcp/build/` test directory, with cleanup. Do not
commit broken `.md` fixtures that the whole-repository checker would then need to
exclude. Include valid new untracked pages in discovery tests.

CI's documentation job uses the locked Node/npm setup, `npm ci`, Composer and these
same commands, without a changed-files-only bypass. Every job running documentation
or packaging checks needs full Git history, including the existing package workflow;
changing only the quality workflow would leave standalone packaging broken.
Keep network link availability outside required deterministic checks; manually verify essential external links
and record unavailable checks honestly.

## Ordered implementation phases

### Phase 0 — Inventory and baseline

- [ ] Create an execution report alongside this plan. Record HEAD, dirty-tree
  status, available tools, baseline checks and every known limitation separately.
- [ ] Inventory all four README section/anchor sets, existing documentation links,
  screenshots, legal attribution and distribution consumers. Start the migration ledger.
- [ ] Inspect the actual package and confirm the known `../docs/` failure. Record
  it as a defect, not a passing documentation check or an accepted final exception.
- [ ] Run the existing `composer check` and available REUSE validation. Resolve
  newly introduced failures before moving on; report environmental blockers rather
  than disabling gates. Do not perform real installation/host smoke tests implicitly.
- [ ] Confirm the prerequisites for all planned mandatory local checks before
  beginning the migration; new checker commands do not exist until Phase 3. An
  unavailable required local gate blocks advancement; optional remote rendering or
  pre-existing runtime matrix checks may be recorded separately.

**Acceptance:** Every current subject has an owner in the ledger; repository and
archive inputs are understood; existing executable gates pass in the available
supported environment, with unavailable external matrix checks explicitly recorded.
The archive-link defect has a concrete Phase 4 closure criterion.

### Phase 1 — Canonical English and navigation

- [ ] Extract English content into the final paths above. Reduce root/app READMEs
  to useful introductions and topic links. Preserve public section destinations
  with meaningful summary headings or explicit anchors when an existing heading changes.
- [ ] Correct known build-prerequisite drift using setup/testing and actual command
  definitions. Keep runtime requirements distinct; do not change supported versions.
- [ ] Reconcile repeated release information against `CHANGELOG.md`; preserve
  unique operational caveats. Keep schema authority with implementation/`mcp_guide`.
- [ ] Add full-locale and operational indexes; move history navigation to
  `development/plans/README.md` without moving historical files. Update CONTRIBUTING.
- [ ] Add documentation/translation contribution guides and packaging guidance;
  move shared screenshots and preserve original attribution on extracted material.
- [ ] Verify repository links/anchors and the content ledger, then commit the
  canonical English sources locally to establish real source revisions for Phase 2.
  Until Phase 3 supplies automation, record the manual link/anchor inventory checks
  and REUSE/whitespace checks in the execution report; no empty gate is a pass.

**Acceptance:** One canonical owner per subject; short READMEs route to complete
procedures; no lost warning, legal credit or operational section. Existing code
examples are identified as historical where appropriate. Repository navigation works.
Package documentation closure remains explicitly pending Phase 4; no intermediate
artifact is presented as release-ready.

### Phase 2 — Preserve Portuguese and establish honest provenance

- [ ] Relocate existing Portuguese content to matching paths; adapt its README
  entrances and language navigation. Compare each page against the actual committed
  English source before recording metadata. Preserve attribution on every extraction.
- [ ] Mark unresolved translations pending with visible notices and English links;
  obtain and record review for anything marked reviewed. Do not certify all moved
  files merely because their paths changed or their source revision now exists.
- [ ] Add Spanish only where a real reviewed translation is available. Its absence
  does not prevent migration completion; do not create empty pages or advertised links.
- [ ] Add the locale/package manifest with only actual pages and needed assets.
  Check equivalent-page switches, fallback labels and localized fragments manually.

**Acceptance:** Portuguese information survives; every published translation has
valid provenance and honest review status; every pending page has a visible notice.
Missing translations route explicitly to English. Manual link/anchor, provenance,
REUSE and whitespace checks pass before advancing; canonical source revisions are
reachable from HEAD. No UI catalog was renamed or edited.

### Phase 3 — Deterministic documentation checks

- [ ] Implement the focused checker modules, locked development dependencies and
  unified commands. Add CI with full history and the same commands, including the
  package workflow that will consume provenance validation in Phase 4.
- [ ] Write failing behavioral fixtures before implementing their validator rules:
  broken page/image/fragment, reference-style and HTML links, accented/duplicate
  headings, root-relative paths, path escapes and fenced-code false positives.
- [ ] Exercise translation states with real commits: unrelated commit, changed
  source blob, reviewed and pending pages, missing locale, invalid/absent source
  revision, dangling/non-ancestor commit and shallow history. Include preservation
  of source commits through an ordinary merge. Invalid provenance must never become
  a freshness warning.
- [ ] Prove missing/stale translations are advisory while malformed metadata and
  checker exceptions fail. Capture missing-notice diagnostics without a silent skip.
- [ ] Verify fixed command scopes and error propagation; resolve all structural
  findings without global ignores, fake revisions or swallowed exit codes.

**Acceptance:** `composer test:docs`, `docs:check`, `docs:translations` and command
contract tests pass with meaningful negative cases. Quality CI is specified to run
identical commands; an unexecuted remote CI run is not claimed as passing.

### Phase 4 — Package documentation from canonical sources

- [ ] Implement explicit allowlisted staging and parsed local-link rewriting.
  Replace the inherited broken app README links with archive-valid transformed links.
- [ ] Check documentation inside the extracted candidate; keep final archive
  publication atomic and all existing archive/asset/legal checks active.
- [ ] Test complete English/Portuguese navigation offline and partial reviewed
  Spanish fixtures with English fallbacks. Include images, fragments and legal links.
- [ ] Prove missing source docs, omitted linked pages, escaping paths, broken
  fragment targets, invalid provenance and export/check failures reject the package
  and leave no stale final artifact. Exercise standalone package-script invocation
  as well as Composer. Confirm staging does not edit repository sources.
- [ ] Reject extra or stale-but-link-valid documents in the extracted package.
  Reject hostile archive entry paths/types before extraction. Verify clean stage
  removal after a failed check.
- [ ] Assert the archive excludes contributor/history docs, tooling, tests and Node
  dependencies; extend existing package fixture expectations instead of weakening them.

**Acceptance:** A newly built archive contains precisely the declared operational
closure and passes extracted-link checks. Source checkout and archive navigation
both work. The baseline `../docs/` defect is resolved with no tracked mirror and
no unpublished release URLs. Focused package and command-contract tests pass.

### Phase 5 — Closure and evidence

- [ ] Reconcile the ledger: every section retained, consolidated or deliberately
  retired with a reason; every old duplicate manual removed. Check for obsolete
  screenshot paths, broken public anchors and links to temporary locations.
- [ ] Review the current architecture documentation and contribution entry points
  against the implemented tree. Keep accepted/historical records intact; update
  only current status or policy statements when implementation changes their truth.
- [ ] Run the full unified `composer check`, REUSE validation and `git diff --check`.
  Inspect final archive inventory and all documentation check output, including
  advisory translation warnings. Record exact commands, versions and outcomes.
  Repeat source/provenance and package checks from a fresh full-history local clone
  of the final committed implementation, so untracked sources, dangling objects or
  previously generated files cannot supply a false pass. Install locked development
  dependencies there using standard cache locations; no push is needed.
- [ ] Review representative GitHub-rendered navigation and extracted Markdown in
  a compatible local viewer; if GitHub rendering cannot be inspected before push,
  record that external check as pending, without pushing to obtain it.
- [ ] Finalize the execution report with language/review coverage, package evidence,
  unresolved translation work and external checks. Record content review ownership.

**Acceptance:** All locally executable gates pass; no invalid provenance, broken
local link, unexplained content loss, duplicate maintained manual or transition tree
remains. All known outdated/pending translations visibly say so. Remaining work is
explicitly limited to optional translations/human review and unavailable external
validation, not unfinished local migration or silently bypassed errors.

## Review focus and remaining boundaries

1. **A reader installs the archive without the repository:** Phase 4 validates the
   actual extracted link closure and preserves operational cautions offline.
2. **English changes while Portuguese review is unavailable:** Phase 3 reports
   freshness without blocking ordinary code changes; notices and pending status
   avoid presenting carried translations as newly reviewed.
3. **A source SHA exists locally but is lost on integration:** Ancestry checks,
   source-commit preservation and a fresh-clone gate prevent dangling objects or
   shallow-history uncertainty from passing as valid provenance.
4. **Shortening a manual loses a lock/privacy caveat or attribution:** Phases 0–2
   require a section ledger, original copyright preservation and content review.
5. **Repository checks pass while the packaged README or a localized anchor fails:**
   Parsed targets and independent archive validation cover both document contexts.

This migration can finish without transition folders. It does not require all
languages to reach equal coverage, publication of a website, or rewriting the
historical migration archive. Spanish/Catalan coverage and additional Portuguese review
are independent follow-ups with explicit state. Real Nextcloud/PHP matrix checks
still pending in earlier reports remain separate; documentation validation cannot
retroactively claim those runtime checks passed.
