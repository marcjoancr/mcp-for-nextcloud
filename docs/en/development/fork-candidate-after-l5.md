<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Local 2.0.0 candidate after LinuxServer L5

Date: 2026-10-29. Status: local source/runtime candidate prepared and reconstructed;
**unpublished, unsigned and not deployment-approved**. F3 remains open.

This is a historical record. The [RE2JS-remediated candidate](fork-candidate-re2js.md)
supersedes this kit for release preparation; the audit failure recorded here is
preserved and does not describe the replacement candidate.

## Branch integration and frozen identity

The owner authorized incorporating the seven reviewed LinuxServer commits into
`fork/main`, preparing a reproducible candidate and documenting its limitations.
The clean local branch advanced without conflicts from
`940c3da36f0d9c5310a98d7c3f355d2d99c86d33` to
`8d6b4f28b31ce90edc30e24eaa20283a242a4b3d` with `git merge --ff-only`:

| Commit | Incorporated work |
| --- | --- |
| `59d602a1` | Reviewed LinuxServer plan for U5 |
| `1a5e8e5f` | Renewed L0 inputs |
| `edf2f9cb` | Isolated L1 baseline qualification |
| `017bce7d` | L2 fixtures and B0 |
| `8cdc71fd` | L3 upgrade and B1 |
| `b664495f` | L4 restoration and recovery |
| `8d6b4f28` | L5 evidence, regression checks and cleanup |

The candidate is **Nextcloud MCP 2.0.0**, app ID `mcp`, frozen at the full
`8d6b4f28` revision above. This report and its navigation/F3 updates follow that
revision as documentation only; they are not silently included in its kit. Do
not substitute the moving `fork/main` head when verifying or rebuilding it.
No branch/tag was published, server changed or deployed, and PR #14 was untouched.
Remote refs were not refreshed; local integration is not remote CI acceptance.

## Kit and trusted local digest

The kit resides in the existing `fork/main` worktree:

```text
/home/dev/worktrees/mcp-for-nextcloud/fork-continuation-plan/mcp/build/source/8d6b4f28b31ce90edc30e24eaa20283a242a4b3d/
```

Its five files must be retained together. The manifest digest below is the trusted
local preparation record outside the writable kit; it is not a publisher
signature or a public distribution channel.

| Artifact | SHA-256 |
| --- | --- |
| `manifest.json` | `614371cc2343505b75fc73075af5c3ca3577fefc7a93b3c2dfe7f879e9598e31` |
| `mcp-2.0.0.tar.gz` | `418e4c801ad350eab52b57feec18d9831b5079faef83acb183cc22b095033e6c` |
| `source.bundle` | `ea4465f628f9e71df99b192f57b0174ae5be3cd7555c6af9b3ef8bbb35d594fc` |
| `runtime-dependencies.tar.gz` | `387e41b13672141f24a537abf869fcada578c513ef1bd336ef68dadbd40beb6c` |
| `BUILDING.md` | `d50e10693a0010324f3a4dc96a068c543e6b386a4ffc584b98a746466788eb4c` |

Preparation used `composer package:source --` with the full frozen SHA.
`composer verify:source -- KIT DIGEST` and
`composer verify:installed -- KIT DIGEST APP_DIRECTORY` pass using this digest,
where the app directory is the local reconstructed tree. No installed production tree is claimed as verified. See
[source delivery](source-delivery.md) for the command contract and trust boundaries.
Generated artifacts and private logs remain ignored, not committed.

## Reconstruction and evidence continuity

A fresh clone of **only the bundle**, detached at the frozen SHA, ran
`npm ci --ignore-scripts` and `composer package`. Its Git checkout remained clean.
The original and rebuilt packages pass exact verification against the same
manifest: all **975 regular runtime files** have identical paths, sizes and
SHA-256 values. All **73 vendor files** also match the separately delivered
runtime dependency sources. Git verifies that the bundle has complete reachable
history and points to the frozen commit.

The rebuilt archive has SHA-256
`8ee095e608c9cac90a4fdb1f6bdcdae98dc85e3bb4cfc4eff77111b9136fac13`.
It differs from the original archive; no normalization or exclusions were used to
claim archive equality. Reproducibility here means exact extracted file contents,
not identical tar/gzip bytes or a complete offline toolchain. Builds used locked
registries/caches with Node 24.21.0, npm 11.19.0, PHP 8.4.27, Composer 2.8.8,
Git 2.47.3 and GNU tar 1.35. This is one build environment, not the full support matrix.

Both the retained U5 kit and this kit were verified against their separately
recorded digests. Their 975-file inventories have no additions or removals and
exactly three changed files:

- `appinfo/source.json`: the frozen source commit.
- `composer.json`: the LinuxServer command description.
- `vendor/composer/installed.php`: Composer's root commit/version references.

Application PHP, original JavaScript, templates, app metadata source and dependency
locks are unchanged from U5. The [L5 report](fork-linuxserver-l5.md) retains the
native LinuxServer and Apache evidence and its failures, skips and boundaries.
Those native runs and ordinary-user source-offer journeys were **not rerun for
this kit**. The inventory comparison supports continuity of unchanged runtime
code; it does not convert previous runs into new candidate-specific acceptance.

## Fresh validation

The registered `devbox run check --cwd WORKTREE --json` completed successfully
on the clean frozen revision, with exit code 0:

| Check | Result |
| --- | --- |
| PHP | 8,707 tests / 74,469 assertions |
| Browser | 717 tests across Chromium, Firefox and WebKit |
| Syntax/static analysis | 1,390 PHP files; both fixed PHPStan scopes pass |
| Tooling boundary tests | 29 documentation, 10 client runner and 11 release tests pass |
| Documentation/package | 178 Markdown files / 115 package sources; package and source identity valid |
| Separate LinuxServer controls | 42 Node tests pass without starting containers |
| Source/integrity | Complete kit verification, bundle verification, reconstruction and both extracted-tree checks pass |

The browser log contains intentional missing-import diagnostics from the build
failure regression in each engine; all 717 cases pass. No failure was filtered
out. Translation provenance is valid, with **100 translations review-required**.
The npm security failure below is separate from the passing canonical check,
which does not include `npm audit`.

Private evidence resides under `mcp/build/candidate-l5/` in the same worktree.
`evidence-index.json` binds 15 logs/results and their explicit exit codes; its
SHA-256 is `5dcf87f54de4ffb98896d7bb9e4323870f1421c6b611bca8f7a399ce5e9caa44`.
It includes `check.json`, `prepare.log`, `reconstruction.log`,
`reproducibility.json`, `u5-runtime-comparison.json`, integrity/bundle verification,
LinuxServer controls and the dependency audit reports. These are local retained
artifacts, not publicly accessible evidence. Documentation checks for this
follow-up report are recorded separately as `docs-check.log` (179 Markdown files /
115 package sources); the report and navigation changes affect development docs
only, outside the packaged documentation inventory.

## Dependency security finding

The build's npm audit reported **two high-severity affected package entries**:
`markdownlint` 0.42.0 and its transitive `re2js` 2.8.6. They trace to one reported
advisory, [GHSA-gg6h-894w-4m9c](https://github.com/advisories/GHSA-gg6h-894w-4m9c),
for quadratic-time lookbehind matching in RE2JS. The recorded affected range ends
before 2.8.7. The full `npm audit --json` exits **1**; this is an unresolved
finding, not a passing security gate. npm's suggested forced downgrade of
markdownlint was not applied, and no dependency or lock file changed.

These packages are development documentation tooling. `npm audit --omit=dev`
exits 0 with no reported vulnerabilities; `composer audit --locked --no-dev`
exits 0 with no advisories or abandoned packages. The production asset builder
rejects non-app runtime imports, and the runtime package contains no node_modules.
This bounds the observed exposure but does not clear the build environment or
prove absence of undisclosed vulnerabilities. Untrusted documentation can enter
the validation pipeline, so resolve the toolchain finding before release
publication, validate the lock change and rebuild a new frozen kit. Audit results
are dated observations, not permanent security guarantees.

## Remaining gates and next step

| Gate | Status and required evidence |
| --- | --- |
| Development dependency finding | Open: resolve the RE2JS/markdownlint advisory with a reviewed dependency change; rerun documentation/tooling/full checks and reconstruction for a new frozen revision. |
| Final release and platforms | Open: freeze the approved final artifact, complete the declared support matrix and candidate-specific acceptance. This local kit is not a release. |
| Trusted source publication | Open: historical-content and attribution review, approved immutable source destination/digest and retrieval by ordinary users for the final served version. The bundle retains reachable history, including deleted tracked files. |
| Intended MinicoLab host | Open: installed bytes/migrations, effective DB target, FPM/startup hooks, update writers, S3 and coordinated backup/restore controls. No server access occurred in this batch. |
| Public HTTPS | Pending: DNS, trusted chain, ingress and hosted-client reachability. Private TLS evidence does not satisfy it. |
| Inspector | Pending: prior 14/15, nonzero outcome and encrypted-storage finding. No new Inspector acceptance is claimed. |
| ChatGPT | Pending: real hosted consent, tool use and revocation. |
| Human reviewers | Pending: security, operation/recovery, license/attribution, accessibility and languages. Automated checks are not human acceptance. |

The next bounded engineering step is the development-dependency remediation,
followed by a replacement frozen candidate and its checks. Own development can
continue locally; F3 and production acceptance remain open. Publishing the branch,
opening/changing a PR, tagging a release or deployment still requires its own
authorization. Existing accepted checkpoint retention remains governed by the
[L5 retention decision](fork-linuxserver-l5.md); this batch deletes none of it.
