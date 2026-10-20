<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Final local candidate qualification and fork publication

Status: **PR opening remains blocked**. Upstream advanced to 1.1.1 and its new
fixes have not been integrated. HTTPS, public Inspector, ChatGPT and human reviews
remain pending. The owner authorized publishing the complete reviewed branch to
the fork on 2026-10-20, leaving the fork's `main` intact and opening no PR. Branch
publication is distinct from satisfying the [PR-opening checklist](2026-10-19-final-pr-opening-checklist.md).

## Candidate and live remote comparison

- Qualified source head: `c4ffe9da4caa81829abc49cad0fa0abddc274acd`, branch
  `refactor/complete-upstream-pr`. This report and its evidence are a later
  documentation-only overlay; they do not change the tested application.
- Upstream `main` and HEAD observed through HTTPS `git ls-remote` on 2026-10-20:
  `65e4e08b68c3f8d1ba78398a082ec567b0b0f4e4` (release 1.1.1).
- Actual merge base: `0911f9123a661968e707fc4c391adcf9d37c3e84` (previous 1.1.0 base).
  The candidate and current upstream diverge by 14 candidate commits and three
  upstream commits. No merge, rebase or release metadata update was performed.
- Fork discovery found only `main` at
  `a1a7911cc72dcf2487d17e06e23edfa89b0ecf92`; the complete branch was absent before
  this task. The authorized destination is
  `marcjoancr/mcp-for-nextcloud:refactor/complete-upstream-pr`, with no force push.

The three upstream commits are `6115eaf`, `86d8947` and `65e4e08`. Their 15-path
delta contains three administration-script fixes, five frontend/native test paths,
one asset test, the manifest, changelog and four README files. Upstream's 1.1.1
release fixes unreadable successful save responses; it does not add migrations or
claim to resolve every report of a lost “Can connect” setting.

| Comparison at `c4ffe9d` | Paths | Add / modify / delete |
| --- | --- | --- |
| Displayed PR contribution, merge base to head | 1,964 | 1,305 / 158 / 501 |
| Current upstream tree to head | 1,967 | 1,305 / 159 / 503 |
| Qualified application candidate `b968846` to head | 44 | 35 / 9 / 0 |

The direct and three-dot comparisons now differ. Treating the old 1.1.0 base as
current would conceal the new fixes and two upstream test files. The
[machine-readable evidence](../contracts/final-candidate-c4ffe9d.json) records
reference identities, inventory hashes, the 44-path overlay and the 15 upstream
paths. Historical V0 inventory remains frozen; these are new comparisons.

## 1.1.0 continuity and architecture

Exact Git object comparisons show no change since `b968846` in production PHP,
authored JavaScript, app metadata, templates, runtime languages, styles/images,
locked dependencies or native integration sources. The 44-path delta comprises
validation documentation/evidence, client acceptance tooling, Composer development
commands and their test expectations.

Fresh comparisons against the pinned 1.1.0 source establish:

- All 11 migration files, all 48 normalized route records, production Composer
  requirements and locked production dependency records remain identical.
- The complete manifest remains byte-identical to 1.1.0, including the restored
  English, Spanish and Brazilian Portuguese descriptions.
- The retired `lib/Tools`, `lib/Service`, `tests/Unit/Tools` and `tests/Unit/Service`
  trees remain absent. The complete architecture suite checks capability/shared
  boundaries and prohibited compatibility loaders as part of the ordinary gate.
- The [32-item feature matrix](2026-10-18-upstream-1.1.0-feature-parity-review.md)
  continues to apply to the identical application implementation. The existing
  tests and new full gate support that scoped conclusion; neither source identity
  nor test counts certify every host behavior or human-facing interaction.

## Newly identified 1.1.1 integration requirement

Source inspection identifies a real semantic delta, not only a version mismatch:
`src/platform/http.js` returns `response.json()` on a successful response. An
unreadable body rejects, so the fork already avoids the old empty-object success
fallback, but the rejection is not classified as an uncertain write. Existing
matrix/group/scope recovery branches do not consistently receive the uncertainty
signal needed for the new reload-and-warning behavior.

Before claiming alignment with current upstream or opening the PR:

1. Port the three script corrections into the existing HTTP and section-owned
   recovery boundaries; retain capability/module structure and lifecycle guards.
   Do not restore the removed monolithic scripts.
2. Preserve all upstream regression cases in the current Playwright suite: cell,
   bulk, undo and service changes; group save; scope save/undo; unreadable reads;
   reload failure with Retry; no automatic write replay or false success notice.
3. Adapt the new native `GroupGrantsHttpTest` to current policy namespaces and
   preserve its independent persistence, preview and legacy-setting assertions.
4. Integrate 1.1.1 release/manifest/documentation changes with attribution and
   translation provenance. Review package version and the parity fixture together;
   do not merely change the version or weaken the old feature-description checks.
5. Resolve ancestry against the recorded upstream base, inspect the resulting full
   contribution, run the appropriate unified/browser/native delta qualification,
   freeze a new archive, then refresh client evidence under candidate-change rules.

No 1.1.1 code was integrated in this qualification/publication task. Existing
PHP/native results cannot certify a future changed implementation. This upstream
integration is now the next application task, ahead of final PR-opening readiness.

## Executed checks and evidence reuse

The fresh catalog command was
`devbox run check --cwd /home/dev/worktrees/mcp-for-nextcloud/talk-capability-plan --json`,
with `/home/dev/.local/share/mise/installs/node/24.21.0/bin` prepended to PATH.
Host PHP was 8.4.26, Composer 2.8.8. The complete command exited **0**.

| Check | Fresh result |
| --- | --- |
| Manifest, documentation and provenance | Passed; 197 Markdown files initially and 198 at packaging after adding this development report; 110 package sources, 96 translations still review-required. |
| Documentation and runner safety | 29 documentation tests and 10 runner safety tests passed, no skips. |
| PHP syntax and both static-analysis scopes | 1,144 files; lib level 0 and core level 5 passed without baselines or suppressed errors. |
| Complete PHPUnit suite | 7,660 tests / 65,230 assertions passed, including architecture and manifest parity. |
| Complete browser suite | 609 passed across Chromium, Firefox and WebKit, zero retries/skips. |
| Production package | Build and content/documentation verification passed. |
| REUSE 5.1.1 | 1,553/1,553 files; no copyright/license/read errors. |
| Git continuity, inventory and whitespace checks | Passed within the recorded 1.1.0 scope; 1.1.1 delta remains unintegrated. |

The three `missing.js` diagnostics are the expected negative build fixtures, one
per browser engine, inside the passing suite. They are retained in the logs.
The development report/evidence was added while the suite ran; application/test
sources remained at `c4ffe9d`. Final documentation changes are checked again before
commit, rather than claiming the new report was present at the initial docs step.

The new 863-file archive is preserved at
`mcp/build/verification/pr-candidate-c4ffe9d/mcp-1.1.0.tar.gz`, SHA-256
`8e5766fff39a00ae36a354fe56a2a71fbd88bd798d8ad2fffb4b6ca5a4bd6081`.
Compared with the frozen `b968846` archive, only `mcp/composer.json` (development
scripts/descriptions) and `mcp/vendor/composer/installed.php` (exactly two root
reference replacements) differ. All other member content and every path, type,
mode and link target match. This justifies carrying application/native evidence;
it does not make the two archives identical.

Raw gate logs, the failed preparation, license output and comparison scripts are
retained privately under `~/.local/state/mcp-client-tests/final-c4ffe9d-20261020/`;
the linked JSON records their hashes. That directory is not an upload bundle.
No tracked private key, credential file, HAR or generated build/node_modules path
was found in the outgoing file inventory; this is a bounded path check, not an
exhaustive secret-scanner certification.

The first catalog run selected default Node 20.19.2 and correctly stopped at the
asset build: the repository requires Node 24.21.x. Documentation, runner safety,
syntax and static analysis had passed; PHP/browser/package qualification had not
completed. The second invocation prepended the existing Node 24.21.0 installation
to this process's PATH and reran the entire catalog gate. Both results are retained;
no runtime requirement, assertion or error was suppressed and no host configuration
was changed.

Strict PHP 8.2/8.3/8.5 and the 14 native cells are carried from
[the frozen candidate](2026-10-19-complete-pr-local-qualification.md), whose
application and native test objects remain identical. The native evidence remains
2,800 cases / 34,686 assertions with 84 explicitly inapplicable skips, including
only the bounded SQLite recovery scenario. No native containers were started in
this task. The full host/browser gate is the fresh execution recorded here.

Inspector storage retains its recorded **14/15, exit 1** result; it was not rerun
for this documentation-only qualification. The public-client `none` journey has
no static client secret, but universal credential isolation is not claimed.

## Concrete HTTPS prerequisite handoff

Read-only `devbox preview expose --help` on 2026-10-20 still exposes only `--cwd`,
`--port`, `--name` and `--json`; there is no supported application-auth option.
No live route was opened to repeat the already diagnosed GitHub gateway block.
The [existing P2 proposal](2026-10-19-https-ingress-client-validation-proposal.md)
remains the consumer contract. The concrete infrastructure task is:

> In a separately selected and authorized devbox infrastructure project, implement
> and qualify a per-exposure application-auth policy. Keep ordinary previews behind
> GitHub authentication. Bind the exception to one owner, exact hostname, loopback
> backend, exposure ID and lease; preserve it across reconciliation/restart, and
> revoke it on expiry. Fail closed on policy/render errors and test isolation from
> other previews. Provide the supported command/API and exact rollback behavior.

Do not edit `/opt`, generated routes or global authentication here. The owning
project must be resolved before a separate implementation session; it was not
selected or inspected in this task. No new task/message was sent to another project.

After that capability exists, prepare the exact path/method filter, trusted proxy
configuration, synthetic fixture, external TLS vantage and independent closure
timer before a new bounded window. Perform public Inspector first, then the actual
ChatGPT CIMD/OAuth journey with constrained outbound metadata access. The owner
handles any required ChatGPT login/MFA/workspace consent. Record account access
before opening a combined window; do not leave exposure open while awaiting it.
This task changes no infrastructure and starts no public window.

## Publication and remaining verdicts

Publishing the validated branch is explicitly authorized; changing fork `main`,
opening a PR and deploying are not. Record the pushed commit and verify the remote
branch matches it after publication. The fork's default branch remains at its
previous revision, and the published branch is not yet synchronized with 1.1.1.

Pending gates remain explicit: upstream 1.1.1 integration/new-candidate qualification;
public HTTPS and Inspector; actual ChatGPT; external Inspector static-secret
contract/security disposition; human security/technical, accessibility and language
review (all 96 documentation translations and German runtime UI). Human reviews
may occur during PR review under the accepted scope; no reviewer is assigned or
approval inferred. Broader staging/recovery and other clients remain uncertified.
