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

Use the reviewed source-kit workflow in [source delivery](source-delivery.md).
Keep local kit paths, logs and fixture state outside public records. A local
manifest digest is an integrity input, not a publisher signature or proof of a
public distribution channel.

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
The final review records read-only
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
- Production deployment qualification remains separate and requires an identified
  update owner plus a coordinated backup and recovery rehearsal.
- Trusted immutable source publication, retained-history/attribution review and
  unauthenticated retrieval for the final served version.
- Public HTTPS: DNS, trusted certificates, ingress and hosted-client reachability.
- Inspector end-to-end acceptance. The historical 14/15 remains recorded; the
  [local storage/provider blocker is resolved](inspector-storage-resolution.md).
- Hosted ChatGPT consent, tool use and revocation.
- Human security, operations/recovery, license/attribution, accessibility and
  language review; 100 translations remain review-required.

Next, follow the [isolated platform/source plan](fork-platform-source-acceptance-plan.md).
M0 addresses the independently identified Node build-runtime advisory and exact
kit consumption, freezes a successor, and precedes the source-offer/native cells.
The RE2JS finding stays closed; this is a separate runtime prerequisite. Production
deployment and public-client acceptance remain separate gates. No server access, push,
deployment or PR #14 change occurred. All existing accepted L5 checkpoints remain
under their recorded retention decision; none were removed by this batch.


### Successor prepared in M0 (2026-10-30)

The [M0 successor and reconstruction record](fork-platform-m0.md) closes the
independent Node build-runtime prerequisite and exact-kit controls. It records
`efb27979b6a90122ce45a6a509e52390030f7ed9`, its separately trusted manifest digest,
full validation and the two commit-metadata differences from this immutable kit.
The current next batch is M1, not another RE2JS remediation. No new native,
LinuxServer, public-client or human acceptance is inferred from M0 preparation.
