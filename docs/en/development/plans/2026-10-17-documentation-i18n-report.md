<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Documentation and i18n migration execution report

Plan: [reviewed migration plan](2026-10-17-documentation-i18n.md).
Baseline: `aa29b8f`. Work stays in the selected isolated worktree. No push or deployment.

## Phase gates

- Phase 0 complete: catalog `check` passed: PHP 5,279 tests / 45,130 assertions;
  browser suite 156 cases; production archive built. REUSE: 1,081/1,081 files.
  Both archive READMEs contain `../docs/en/development/setup.md` without any bundled
  `docs/` directory: confirmed baseline defect, scheduled for Phase 4.
- Phase 1 complete: English extraction, source build prerequisites and audience
  navigation. Local link inventory, whitespace and REUSE pass before the source
  commit. The old Files pilot link to the retired ToolModule is now an explicit
  historical code path, preserving the claim without a broken navigable link.
- Phase 2 complete: Portuguese material retained in matching paths. All 24
  translations are explicitly pending human review, based on committed English
  source `0c95ccf`; no Spanish placeholder or claimed review was added.
  Equivalent links, source paths/commits, whitespace and attribution checked.
- Phase 3 complete: documentation suite 22/22 (including CLI empty/failure
  discovery and fixed-scope probes), source validation covers 94
  Markdown files and 44 package sources; translations report 24 review-required
  Portuguese pages and 48 missing Spanish/Catalan pages, all advisory. Full catalog
  gate passed: PHP 5,279 / 45,140 assertions; browser 156/156; archive produced.
  Additional CLI probes exposed a Node nested-test false success; an explicit
  context error now prevents it (RED → GREEN). The root ignore rule also matched
  authored `mcp/scripts/docs/`; a narrow source exception makes those modules
  versionable. REUSE: 1,137/1,137 files.
- Phase 4 complete: 28 documentation tests; 51 PHP archive tests / 156 assertions;
  five standalone pipeline failure probes (Chromium). New tests were observed
  failing before staging, byte verification and extraction checks were implemented.
  `composer package` succeeds with 44 canonical sources: 42 operational documents
  plus two package READMEs. Both archive READMEs now use `docs/` links; the inherited
  `../docs/` defect is closed. No contributor/history tree is bundled. Failures at
  source validation, asset build, staging, archive inspection and docs verification
  remove only the current version artifact and clean the stage.
- Phase 5 complete; independent review found no Critical/Important issues: current architecture,
  setup/testing/packaging guides now match the implementation. Full catalog gate
  passes: PHP 5,280 tests / 45,152 assertions; browser 165/165 across Chromium,
  Firefox and WebKit; documentation 28/28; both PHPStan scopes clear; archive
  validates all 44 documentation sources. REUSE: 1,139/1,139 files. A fresh full-history
  clone of `a374093` independently passes source/provenance checks, all 28 docs
  tests and production packaging after locked npm installation (zero audit findings).
  The first clone run exposed a race: two test modules assumed the build directory
  already existed. Each now creates its own artifact parent before fixture setup;
  the clean-clone run changed from RED to GREEN. An SPDX marker inside a fixture
  string also confused REUSE; splitting that source literal preserves the fixture
  bytes and restores licensing validation without exclusions.

## Decisions

- Ruling: use this project execution report as the phase ledger; keep disposable
  extraction/check scripts outside the repository. User instructions prohibit
  assistant/harness state inside the managed repository. Cost: manual phase recording.
- Ruling: split detailed tool descriptions into capability pages below
  `reference/tools/`, with a short index. This implements the plan's capability
  navigation without concentrating another technical manual in one file. Cost:
  more small pages to navigate and include in the manifest.

- Scope amendment: the user requested Catalan; `ca` / Català now follows the same
  reviewed-publication rule as Spanish. There are no placeholder translations.
- Ruling: input discovery/manifest validation has a small `sources.mjs` owner,
  shared by repository and translation checks. This avoids a circular dependency
  without duplicating validation. Cost: one extra focused development module.
- Ruling: pin development-only KaTeX to 0.18.2 through npm overrides because the
  newly added markdownlint dependency chain selected an affected version
  (GHSA-238p-pmpm-9mq7). `npm audit` reports zero vulnerabilities after correction;
  revisit the override on dependency upgrades. Cost: explicit dependency maintenance.

## Final independent review

Fresh-context review covered `aa29b8f..131c155`, with the clean-checkout fixture
initialization fix at `a374093` separately disclosed. It checked operational content
preservation, canonical ownership, provenance, repository/archive links, exact
package inventory and the plan's five review scenarios. No Critical or Important
finding remained; the small navigation finding below was deferred at that review
and is now resolved by the authorized follow-up recorded below.

- Final: minor (resolved in follow-up): app README `Configuration keys` and
  Portuguese `Chaves de configuração` links targeted the permissions guide instead
  of the configuration reference. Both now lead directly to their equivalent
  `reference/configuration.md` pages in the repository and generated archive.
- Final: Ruling: human linguistic approval is outside this technical review.
  Retain all 24 Portuguese pending notices and publish no Spanish/Catalan pages
  without review. Cost if wrong: linguistic issues can remain visible until a
  language reviewer checks them; no automated quality certification is implied.
- Final: Ruling: actual GitHub rendering and remote CI remain external checks.
  Local GFM preview, full local gates and full-history workflow configuration
  establish local evidence only. Cost if wrong: platform-specific differences may
  still need correction when publication is authorized.
- Final: Ruling: runtime compatibility and deployment remain separate from this
  documentation migration. Preserve earlier reports' open host/matrix checks.
  Cost if wrong: environment-specific issues are not ruled out by this work.

## Content ledger

Original sections are identified at the baseline commit, including Portuguese.
Old README headings remain useful topic navigation; detailed procedures have one
owner. Release history is consolidated in CHANGELOG, which already records releases
1.0.0–1.0.8 and their fixes/limitations. The upgrade schema-migration note is retained
in installation. Original legal credit remains in each README and extracted page.

| Source section | Final owner | Disposition |
| --- | --- | --- |
| `README.md`: Why | `README.md` | Retained legal/overview entrance. |
| `README.md`: What's new in 1.0 | `CHANGELOG.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Features | `docs/en/reference/tools.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Contacts and tasks | `docs/en/reference/tools/contacts-tasks.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Requirements | `docs/en/installation.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Installation | `docs/en/installation.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Connecting a client | `docs/en/guides/clients.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Security | `docs/en/guides/permissions.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Development | `docs/en/development/setup.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Changelog | `CHANGELOG.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.md`: Roadmap | `README.md` | Retained legal/overview entrance. |
| `README.md`: License | `README.md` | Retained legal/overview entrance. |
| `README.md`: Credits | `README.md` | Retained legal/overview entrance. |
| `README.md`: About Sintax | `README.md` | Retained legal/overview entrance. |
| `mcp/README.md`: Contacts and Tasks (0.8.0) | `docs/en/reference/tools/contacts-tasks.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Tools | `docs/en/reference/tools.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Resources (MCP Resources) | `docs/en/reference/resources.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Packaging | `docs/en/development/packaging.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Install | `docs/en/installation.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Server log (1.0.2) | `docs/en/guides/server-logs.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Locked file (1.0.5) | `docs/en/guides/file-protection.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Hidden tags (1.0.6) | `docs/en/guides/file-protection.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Configure | `docs/en/guides/permissions.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Connecting through claude.ai (OAuth) | `docs/en/reference/oauth.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Connecting through ChatGPT and Gemini CLI (OAuth) | `docs/en/reference/oauth.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Revoking | `docs/en/guides/clients.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Manual test after installation | `docs/en/guides/troubleshooting.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: Development | `docs/en/development/setup.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.md`: License | `mcp/README.md` | Retained legal/overview entrance. |
| `mcp/README.md`: Credits | `mcp/README.md` | Retained legal/overview entrance. |
| `mcp/README.md`: About Sintax | `mcp/README.md` | Retained legal/overview entrance. |
| `README.pt-BR.md`: Por quê | `README.pt-BR.md` | Retained legal/overview entrance. |
| `README.pt-BR.md`: Novidades da 1.0 | `CHANGELOG.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Funcionalidades | `docs/pt-BR/reference/tools.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Contatos e tarefas | `docs/pt-BR/reference/tools/contacts-tasks.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Requisitos | `docs/pt-BR/installation.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Instalação | `docs/pt-BR/installation.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Conectando um cliente | `docs/pt-BR/guides/clients.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Segurança | `docs/pt-BR/guides/permissions.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Desenvolvimento | `docs/en/development/setup.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Histórico de versões | `CHANGELOG.md` | Consolidated at this owner; old heading retained as navigation. |
| `README.pt-BR.md`: Roadmap | `README.pt-BR.md` | Retained legal/overview entrance. |
| `README.pt-BR.md`: Licença | `README.pt-BR.md` | Retained legal/overview entrance. |
| `README.pt-BR.md`: Créditos | `README.pt-BR.md` | Retained legal/overview entrance. |
| `README.pt-BR.md`: Sobre a Sintax | `README.pt-BR.md` | Retained legal/overview entrance. |
| `mcp/README.pt-BR.md`: Contatos e Tarefas (0.8.0) | `docs/pt-BR/reference/tools/contacts-tasks.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Tools | `docs/pt-BR/reference/tools.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Recursos (MCP Resources) | `docs/pt-BR/reference/resources.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Empacotar | `docs/en/development/packaging.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Instalar | `docs/pt-BR/installation.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Log do servidor (1.0.2) | `docs/pt-BR/guides/server-logs.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Arquivo travado (1.0.5) | `docs/pt-BR/guides/file-protection.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Etiquetas ocultas (1.0.6) | `docs/pt-BR/guides/file-protection.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Configurar | `docs/pt-BR/guides/permissions.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Conectar pelo claude.ai (OAuth) | `docs/pt-BR/reference/oauth.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Conectar pelo ChatGPT e pelo Gemini CLI (OAuth) | `docs/pt-BR/reference/oauth.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Revogar | `docs/pt-BR/guides/clients.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Teste manual após a instalação | `docs/pt-BR/guides/troubleshooting.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: Desenvolvimento | `docs/en/development/setup.md` | Consolidated at this owner; old heading retained as navigation. |
| `mcp/README.pt-BR.md`: License | `mcp/README.pt-BR.md` | Retained legal/overview entrance. |
| `mcp/README.pt-BR.md`: Credits | `mcp/README.pt-BR.md` | Retained legal/overview entrance. |
| `mcp/README.pt-BR.md`: About Sintax | `mcp/README.pt-BR.md` | Retained legal/overview entrance. |

Nested tool subsections map to capability owners: Deck and Images to their tool
pages; Calendar diagnostics to troubleshooting. The source tool table is separated
by module; `mcp_status` remains in the index. Contacts/Tasks technical details have
one shared page and two capability entrances. Root feature summaries accompany the
corresponding capability. Configuration keys have their own reference. Shared
protocol/authentication requirements live in the OAuth reference; user setup and
revocation steps live in the client guide.

## Reproducible closure evidence

Environment: PHP 8.4.26, Composer 2.8.8, Node 24.21.0, npm 11.19.0,
Git 2.47.3 and the cached REUSE 5 image. The pinned Node directory was prepended
explicitly to PATH; no cache location or external catalog was changed.

| Command or check | Outcome |
| --- | --- |
| `devbox run check --cwd <selected-worktree> --json` | Exit 0; includes docs, lint, both analysis scopes, PHP, JavaScript and actual package checks. Full code gate at `131c155`; the later `a374093` changes only independent test-directory initialization and passes the complete documentation suite. |
| `composer docs:check` | 94 Markdown files and 44 package source files valid, including non-ignored new files. |
| `composer docs:translations` | 24 Portuguese review-required pages; 48 missing pages across Spanish and Catalan. Advisory state remains visible; invalid provenance fails. |
| `composer test:docs` | 28/28, also in the fresh clone. |
| `composer package` | Fresh-clone archive contains 42 operational pages plus two app READMEs; exact inventory, bytes, links and existing runtime/legal checks pass. |
| `npm ci` in fresh clone | Locked installation; zero audit findings. |
| Offline `fsfe/reuse:5 lint` | 1,139/1,139 files with licensing and attribution. |
| `git diff --check` | Pass. |
| Local GFM/Chromium preview | Root/app README, locale entrance, English/Portuguese permissions guides and extracted archive README/guide rendered; pending Portuguese notice visible and Catalan/Spanish fallback explicit. |
| Essential public entry links | Repository and Nextcloud App Store returned HTTP 200; no installation/OAuth examples executed. |

The clone used `git clone --no-local --branch docs/talk-capability-plan` from the
selected worktree, with full history and independent Git objects. It lived under
ignored `mcp/build/`, received locked npm dependencies, then ran `docs:check`,
`docs:translations`, `test:docs` and `package`. No untracked authored document,
pre-existing build tree or object reachable only in the original checkout was used.

Content review ownership: migration author checked the section ledger, canonical
placement, caveats and original attribution; the independent technical reviewer
checks preservation and implementation. Neither certifies Portuguese language
quality. All carried Portuguese material remains pending human language review.

## Configuration-link follow-up — 2026-10-17

The authorized follow-up corrects only the two app README destinations and this
report. Existing section headings, canonical configuration tables and Portuguese
pending-review provenance remain intact. No application code or tooling changed.

Fresh validation with the pinned Node 24.21.0 toolchain:

- `composer --working-dir=mcp docs:check`: pass; 94 Markdown files and 44 package
  sources checked.
- `composer --working-dir=mcp docs:translations`: pass; 24 Portuguese pages require
  review and 48 Spanish/Catalan pages are missing. These advisory counts remain
  visible; correcting a link does not certify a translation.
- `composer --working-dir=mcp test:docs`: 28/28 pass, no skipped or cancelled tests.
- `composer --working-dir=mcp package`: pass; the newly generated archive passes
  structural checks and verification of all 44 documentation sources.
- Direct inspection of both source README links and both README members in the
  actual tarball: destinations resolve to the matching configuration reference,
  including the `service_enabled` table entry. Archive links use `docs/`, while
  repository links use `../docs/`.
- `git diff --check`: pass. No tracked package documentation mirror or retired
  screenshots tree was introduced.

The checked `mcp/build/mcp-1.0.8.tar.gz` has SHA-256
`b8db4a126bbb80aef88d9983bcb97a0bb0ff7b620c63cbc890e157e6a285f479`.
This records the local artifact, not a reproducible-build guarantee.

No known local documentation-migration implementation or review finding remains.
The full PHP/browser results above remain evidence from the earlier migration;
this prose/link-only follow-up reran the relevant documentation and package gates.
The remaining items below concern human translation review, external verification
or separately scoped development; they require no migration scaffolding.

## Pending external work and next scope

The [operational translation follow-up](2026-10-17-operational-translations-report.md)
now supplies Spanish, Catalan and European Portuguese drafts and records the current
coverage. The missing-page counts below describe the migration closure before that
follow-up; human review remains outstanding.

- Human review of the 24 Portuguese documents, with reviewer identity and source
  revision recorded when accepted.
- Reviewed Spanish and Catalan documentation: 24 missing pages in each language.
  English fallback is deliberate; there are no empty locale trees or invented
  reviewed translations.
- GitHub's actual rendering and Actions on the integrated revision. The local
  GFM preview and full-history CI configuration do not establish remote results.
- Real Nextcloud host checks and full supported-PHP-runtime validation still
  unavailable in the preceding JavaScript report. This documentation gate does
  not replace or retroactively certify them.
- Further static-analysis coverage and any CSS or PHP Settings/controller
  restructuring require their own scope and evidence. The current documentation
  migration needs no transition folder to support those follow-ups.

No push, pull request, merge, publication or deployment was performed.
