<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Local 2.0.0 candidate after RE2JS remediation

Date: 2026-10-29. Status: the reported documentation-toolchain vulnerability is
resolved and the replacement candidate is reconstructed locally. **Unpublished,
unsigned and not deployment-approved; F3 remains open.** This supersedes the
[previous candidate](fork-candidate-after-l5.md) for local release preparation.

## Scoped dependency correction

The prior lock resolved `markdownlint` 0.42.0 to `re2js` 2.8.6. A fresh
`npm audit --json` reproduced exit 1 with two high-severity affected entries,
tracing to [GHSA-gg6h-894w-4m9c](https://github.com/advisories/GHSA-gg6h-894w-4m9c).
The advisory identifies 2.8.7 as patched. The registry still offered markdownlint
0.42.0 with an exact 2.8.6 dependency, so a scoped `markdownlint.re2js` override
selects **2.8.7**. The existing KaTeX override is unchanged.

Only RE2JS's locked version, registry URL, integrity and engine requirement change
in the lockfile. Node >=20 remains compatible with the pinned Node 24.21.x build
host. A clean `npm ci --ignore-scripts` followed by `npm ls markdownlint re2js`
confirms the installed resolution. Full `npm audit --json` now exits **0 with no
reported vulnerabilities**, including development dependencies. Locked production
Composer audit also exits 0 with no advisories or abandoned packages. These are
dated registry results, not proof of absence of undisclosed vulnerabilities.

The [documentation tooling guide](documentation.md) records why the override is
needed and when to remove it: after a reviewed markdownlint update resolves a
patched dependency itself. No forced downgrade, audit exception, application-code
change or lock-wide dependency update was used. The meaningful red/green check is
the security audit, supplemented by the existing Markdown behavior tests; no
permanent test duplicates the package manager's version resolution or introduces
a timing-sensitive dependency benchmark.

## Frozen candidate and source integrity

The replacement is **Nextcloud MCP 2.0.0**, app ID `mcp`, frozen at
`cfeb26d4fdc7d4417e1e0d604e65cedf42040c4d`. The fix commit includes the two npm
manifests and tooling guide. This report and follow-up navigation/F3 updates are
subsequent documentation only; they do not mutate the kit or redefine its source
revision.

The five-file kit is retained under the existing `fork/main` worktree:

```text
/home/dev/worktrees/mcp-for-nextcloud/fork-continuation-plan/mcp/build/source/cfeb26d4fdc7d4417e1e0d604e65cedf42040c4d/
```

| Artifact | SHA-256 |
| --- | --- |
| `manifest.json` | `b643a1596eab2612ec7881a8ebc76bc4a8e27611a8bf445c6daea679626004d9` |
| `BUILDING.md` | `e61be7bf14d1fd5777ebbbb84e43d6a5e676e6698afee011ae394c89f28f87a5` |
| `mcp-2.0.0.tar.gz` | `73f12143d51984a1a3469540a5381661dd427a166ee3255c7e27dbba08018af9` |
| `runtime-dependencies.tar.gz` | `5088fb4d7be5b7d27d06243b9651fd76aa1a9e5fb5600464d1b397d260a5d828` |
| `source.bundle` | `724b385a0f69a2e6eb418943a718ed5fb00cdb3378f4b45777e1ba026011cb6a` |

Retain the complete kit. Keep its expected manifest digest in this trusted local
release record, outside the writable kit. Hashes are not a publisher signature.
Generated files remain ignored private build artifacts; no public URL, release
tag or ordinary-user publication claim is introduced.

## Reconstruction and validation

The complete registered `devbox run check --cwd WORKTREE --json` passed on the
reviewed fix inputs before committing them:

| Check | Result |
| --- | --- |
| PHP | 8,707 tests / 74,469 assertions |
| Browser | 717 tests across Chromium, Firefox and WebKit |
| PHP syntax/static analysis | 1,390 files; both fixed PHPStan scopes pass |
| Tooling tests | 29 documentation, 10 client-runner and 11 release tests pass |
| Documentation/package | 179 Markdown files / 115 package sources valid; development package passes |
| Security audit | npm before: exit 1; clean installed and reconstructed trees: exit 0, zero reported vulnerabilities; production Composer: exit 0 |

The pre-commit package emits the expected
`Dirty development build: source offer unavailable.` Its source identity is not
used for this candidate. The later clean frozen preparation and reconstruction
both package the correct committed source identity. The browser log retains
intentional missing-import diagnostics from the build-failure regression; all
717 cases pass. No audit finding or test failure is suppressed.

An independent clone of `source.bundle`, detached at the exact candidate SHA,
installed locked dependencies with lifecycle scripts disabled and rebuilt through
`composer package`. The clone stayed clean. Both extracted packages pass the
trusted manifest verifier: all **975 runtime files** match in paths, bytes and
SHA-256, with no differences normalized away. All **73 packaged vendor files**
also match the delivered runtime dependency source archive. The rebuilt package
SHA-256 is `fab4991584fcbb1dfe22297928a8cd49832c9adce906326d6e4b3d37f70b35ad`;
tar/gzip bytes differ from the original. Exact extracted contents, rather than
identical compressed bytes or a complete offline toolchain, are the verified
reproducibility boundary.

The reconstructed dependency tree also resolves RE2JS 2.8.7; its full audit reports
zero vulnerabilities. The clean frozen package has a valid source identity and
contains no node_modules or development scripts/tests. Builds use Node 24.21.0,
npm 11.19.0, PHP 8.4.27, Composer 2.8.8, Git 2.47.3 and GNU tar 1.35.

Compared with the previous verified candidate, the 975-file runtime inventory has
no additions or removals. Only `appinfo/source.json` and
`vendor/composer/installed.php` change, carrying the new source revision and
Composer root references. Runtime application logic, PHP dependencies and served
documentation are unchanged. Historical [L5 evidence](fork-linuxserver-l5.md)
remains preserved; native upgrade/recovery and ordinary-user source-offer journeys
were not rerun or relabelled as fresh acceptance of this new kit.

## Evidence and review

Private logs and verification records are under `mcp/build/candidate-re2js/` in the
same worktree. The evidence index binds 20 files and records command exit codes;
its SHA-256 is `093049223a45d6bb41b9d2024836a1791744919a8d76d79b72faaebbf8c3faf8`.
The initial failing audit remains retained alongside the passing result, registry
metadata, clean install/tree, full check, preparation/reconstruction logs and
inventory comparisons. Final documentation validation is recorded separately in
`docs-check.log`: 180 Markdown files / 115 package sources pass. The added report
and navigation updates are development-only documentation, outside the packaged
documentation inventory. Local evidence is not publicly accessible release evidence.

A fresh-context automated review of the dependency change and tooling guide found
no actionable findings. It checked the narrow override, lock integrity/engine
compatibility, documented removal criteria and diff hygiene. It did not execute
the full suite or replace a human review; the validations above were executed by
the implementing agent. A subsequent read-only review also matched the candidate
report, F3 update, trusted digest, audit results and evidence index with the retained
records, without rerunning tests or claiming human acceptance.

## Closure review (2026-10-29)

The follow-up review found no dependency or candidate-integrity defect. It
confirmed that the lock changes only RE2JS and that the scoped override is applied
after another clean `npm ci --ignore-scripts`. The package manifests and tooling
guide still match the frozen fix commit in both the working tree and retained
bundle clone. This review changes documentation only; it neither regenerates nor
redefines the candidate at `cfeb26d4`.

Fresh full npm audit exits 0 with no reported vulnerabilities; locked production
Composer audit exits 0 with no advisories or abandoned packages. The 29 existing
documentation tests and 11 release/integrity tests pass again. The security audit
is an explicit separate check: `composer check` does not run `npm audit`, so its
success alone must not be read as a current vulnerability assessment.

The trusted manifest, all 20 indexed historical evidence files, bundle head and
retained clone, reconstructed archive, both 975-file extracted trees and 73-file
vendor source tree verify again without modifying the original records. The
previous full 8,707-test PHP / 717-test browser execution remains verified evidence
for unchanged code inputs; neither that full suite nor native/hosted-client
acceptance was rerun for this documentation review. Rechecking retained rebuilt
bytes is not a new reconstruction run.

Fresh review evidence is kept separately under ignored
`mcp/build/candidate-re2js-review/`. `integrity.json` records the read-only
comparisons; `install.log`, `dependency-tree.json`, `npm-audit.json`,
`composer-audit.json`, `docs-tests.log` and `release-tests.log` record the repeated
checks. Final documentation and package validation are in `docs-check.log` and
`package.log`. The package built from the documentation worktree is a development
artifact; its expected dirty-checkout source-offer warning does not alter the
frozen source kit or qualify a new release.

The review index binds 10 files, including the passing checks for 180 Markdown
files and 115 package sources, and the package log. Its SHA-256 is
`3f574854346ab1b6b9351561f302f4afefe01511f4b6124979100af432baee72`.
The final report edit is rechecked separately in `final-docs-check.log`; all earlier records remain intact.

## Pending acceptance and next step

The specific RE2JS/markdownlint finding is closed for this frozen candidate. The
older kit remains historical evidence and must not be presented as remediated.
The following gates stay open:

- Final release/support matrix and candidate-specific native/source-offer acceptance.
- Intended MinicoLab host qualification: installed bytes/migrations, DB target,
  FPM/startup hooks, update writers, real S3 and coordinated recovery controls.
- Trusted immutable source publication, retained-history/attribution review and
  unauthenticated retrieval for the final served version.
- Public HTTPS: DNS, trusted certificates, ingress and hosted-client reachability.
- Inspector: prior 14/15, nonzero outcome and encrypted-storage finding.
- Hosted ChatGPT consent, tool use and revocation.
- Human security, operations/recovery, license/attribution, accessibility and
  language review; 100 translations remain review-required.

Next, qualify the remaining declared platform matrix and final candidate source
offer in isolated fixtures with an explicit scope. Production deployment and
public-client acceptance remain separate gates. No server access, push,
deployment or PR #14 change occurred. All existing accepted L5 checkpoints remain
under their recorded retention decision; none were removed by this batch.
