<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Inspector encrypted-storage resolution

Date: 2026-10-29. **The local storage/provider blocker is resolved with pinned
Inspector 2.13.0 and a corrected, expanded 21-case qualification.** End-to-end
Inspector, public HTTPS, hosted ChatGPT and human reviews remain pending. This
work changes development acceptance tooling, not the Nextcloud MCP application,
its immutable candidate kit, the production server or PR #14.

## Diagnosis and correction

The historical Inspector 2.11.0 result (14/15, nonzero) was reproduced unchanged.
Its final assertion incorrectly required a preregistered static client secret to
be unavailable when reading another issuer for the same resource. Inspector's
provider contract distinguishes issuer-keyed **dynamic registrations and tokens**
from **resource-wide static registrations**. Static credentials may be returned
for another issuer, with the issuer stamp removed for subsequent SDK binding.
The original result therefore does not establish an MCP server defect or network
exfiltration. The failed record is preserved, not relabelled as a passing run.

Review of the official [provider implementation](https://github.com/modelcontextprotocol/inspector/blob/2.13.0/core/auth/providers.ts),
[provider tests](https://github.com/modelcontextprotocol/inspector/blob/2.13.0/clients/web/src/test/core/auth/providers.test.ts)
and [issuer-stamp fix](https://github.com/modelcontextprotocol/inspector/pull/2794)
also identified a genuine older-client defect: the SDK's issuer-stamp save,
without `registrationKind`, failed to persist the static registration correctly.
A new regression fails on 2.11.0 at `static-stamp-a`. Inspector 2.13.0 preserves
the stamp across disk reload and unchanged credential reseeding, returns a
mismatched issuer unstamped without rewriting storage, and clears the stamp when
static credentials change.

The resolution uses the upstream release; no installed library body is patched.
The runner appends only exports to a private copy of the exact CLI bundle so its
real provider, OAuth storage and encrypted file backend can be exercised. Each
scenario executes in a fresh process. The original installation remains intact.

## Reproducible inputs

The development-only [`inspector/` manifests](../../../mcp/tests/ClientAcceptance/inspector/package.json)
lock the separate client installation. Follow the
[runner instructions](../../../mcp/tests/ClientAcceptance/README.md#encrypted-storage-across-issuers)
with Node 24.22.1 / npm 11.19.1; use `npm ci --ignore-scripts`, not a floating
installation. The runner rejects a different runtime, lock or CLI bundle before
executing scenarios.

| Input | Qualified value |
| --- | --- |
| Inspector | 2.13.0 |
| Node | 24.22.1 |
| Install lock SHA-256 | `bb115f7ef3eea6cc1d2fb7f6370c140064e0de71662384e5791b0ff952bfa828` |
| Original CLI bundle SHA-256 | `d86de678206f583d2d9072d712f54dce33d03ecc8783ac63526c92fc5590d6f7` |
| Export-only suffix SHA-256 | `0b77e795fbf3af7bc9bea89c45a8bab5a9a8f7e2c7f9c0934f98abcaff460deb` |
| Instrumented copy SHA-256 | `a11aff0b9e0617f7ad2fd201a8cc22498e0ac7bd3ba3f221fca71b2268c80967` |

The separate user-local installation was recreated with the locked clean install;
its npm audit reports no known dependency advisories. This is not an attestation
of arbitrary installed `node_modules`, a future audit, or all client security.
The application's build still pins Node 24.21.0. Updating that independent runtime
for the [October security release](https://nodejs.org/en/blog/vulnerability/october-2026-security-releases)
is M0 in the [platform/source plan](fork-platform-source-acceptance-plan.md), not
silently covered by this client installation. No global runtime default changed.

## Executed evidence

| Private run | Inputs and purpose | Result |
| --- | --- | --- |
| `storage-T3wZh6` | Original 2.11.0 runner, unchanged old assertion | 14 passed, 1 failed; exit 1 |
| `storage-em6tqW` | 2.11.0 with corrected static contract and new stamp regression | 15 passed, 1 failed, 5 blocked; exit 1 at `static-stamp-a` |
| `storage-85iB8L` | 2.13.0 first expanded run | 21/21; exit 0 |
| `storage-ZDkFua` | 2.13.0 after clean locked reinstall | 21/21; exit 0 |
| `storage-MZV1ZC` | Added resource boundary and tracked-lock verification | 21/21; exit 0 |
| `storage-DUuVJi` | Final runner, other-resource check uses existing issuer-B tokens | 21/21; exit 0 |

The final run checks DCR/token separation, persistence, rotation and deletion;
AES-256-GCM/scrypt, private file modes and absence of plaintext synthetic secrets;
read/write/delete refusal with wrong/missing keys or modified ciphertext; and
static registration stamp persistence, unchanged reseeding, rebinding and
credential replacement. Another resource receives neither the static client nor
the existing issuer-B tokens. Static operations preserve the existing DCR/token
slots. Missing or failed required cases remain nonzero; no expected-failure
exception turns an acceptance failure green.

Two separate preflight negative controls reject the old Node runtime
(`storage-ZXraJl`) and old Inspector installation (`storage-mGFbfo`), both exit 1
with all 21 scenarios blocked before component execution. These intentional
refusals are not Inspector acceptance passes.

Private run directories are under `~/.local/state/mcp-client-tests/`. Raw errors,
keys, synthetic credentials and stores are **not upload bundles**. Sanitized
summaries, runner/result hashes, clean-install/audit logs and image metadata are
retained separately in ignored `mcp/build/inspector-resolution/`. Its
`storage-summary.json` preserves every execution above and verifies that the
final result's runner hashes match the reviewed files. This report is the
repository-readable evidence; private paths are local provenance, not public
release artifacts.

## Repository validation and review

The unified `devbox run check` completed with exit 0 when its PATH explicitly
selected the repository's Node 24.21.0 / npm 11.19.0 toolchain:

| Check | Result |
| --- | --- |
| PHP suite | 8,707 tests, 74,485 assertions passed |
| Browser suite | 717 passed across Chromium, Firefox and WebKit |
| Documentation / client-runner / release-integrity tests | 29 / 10 / 11 passed |
| PHP syntax | 1,390 files checked |
| Static analysis | Both configured scopes passed |
| Documentation / package sources | 182 Markdown files / 115 sources valid |
| Package | Built and verified; development source offer unavailable as expected |

The first catalog attempt inherited system Node 20.19.2 and npm 9.2.0. It stopped
at the existing asset runtime guard (Composer exit 1, catalog exit 10); its
`check.log` is retained. The successful repetition is `check-pinned.log`. Neither
the guard nor a test was bypassed. The deliberately missing import in the build
failure tests still emits its expected diagnostic; all 717 browser tests pass.
The 100 translated documents remain `review-required`.

That execution used a dirty development checkout, so its generated package was
not a new release candidate and correctly had no source offer. The immutable `cfeb26d4` kit
was separately reverified against its trusted digest (`candidate-integrity.log`).
Native source-offer acceptance and the 15-cell campaign have not run here. Current
`matrix.php --matrix` output is retained as `matrix-inventory.json`.

An independent automated review found no actionable findings in the runner,
static/DCR contract, pins or plan, then matched the report against the six sanitized
run results and the final source hashes. It did not execute tests, inspect raw
secrets or replace any human reviewer.

The local evidence index binds 21 files; its SHA-256 is
`dc330fb17844d06991860eae9603c87d4b664538c05db5ffec7de053a287b026`.
It includes the failed and successful catalog attempts, all retained storage run
logs/summary, guard results, audit/install records and observed image metadata.
The final report edit is checked separately in `final-docs-check.log`, outside
that immutable index. Local evidence remains private and unpublished.

## Pre-commit review

The follow-up review corrected two sequencing/evidence gaps in the platform plan:
M0 now freezes the successor only after its tooling and prerequisite checks, and
both native and source-offer runners must compare the **installed** application
tree with the trusted kit before acceptance tests. Copying an archive and checking
its version are insufficient. These gates remain planned work, not implemented
or executed platform acceptance. A second read of the corrected plan found the
two issues resolved.

No further Inspector code change was needed. Fresh run `storage-tHKBjt` passes all
21 cases with the exact same runner hashes as `storage-DUuVJi`; the installed
bundle remains unchanged. The original 21-file evidence index and all six previous
run results verify intact. The frozen candidate also verifies against its trusted
digest, and a fresh audit of the locked Inspector installation exits 0 with no
reported dependency vulnerabilities.

Fresh scoped validation passes 10 client-runner, 29 documentation and 11
release/integrity tests, documentation (182 Markdown files, 115 package sources)
and packaging. The full PHP/browser/static-analysis run above remains verified
prior evidence for unchanged application code; it was not repeated in this review.
The package built before this commit is a dirty development artifact, with the
expected unavailable source offer; it does not replace the immutable candidate.

Review evidence is retained separately under ignored `mcp/build/inspector-review/`.
Its index binds 9 files with SHA-256
`0dfcfd7af1287b6e6a5a32332b3d787d6154576183773094c271a93898f20335`.
`integrity.json` binds the fresh run and previous results to their hashes.
The final report edit is rechecked separately in `final-docs-check.log`, outside
the index. No raw client state or generated artifacts are committed. Public HTTPS,
end-to-end Inspector, hosted ChatGPT and all human reviews remain pending; no push,
deployment, production operation or PR #14 change is part of this review.

## Limits and next action

This closes the local encrypted storage/provider issue only. It does not claim
cross-issuer confidentiality for static credentials, execute SDK network rebinding,
or contact two OAuth servers. Prefer dynamic registration for the qualified
isolated multi-issuer scenario. No real OAuth secrets were used.

The historical private-TLS runner still binds Inspector 2.11.0, Node 24.21.0 and
its older contribution fixture. Its history is not overwritten and it must be
refreshed and rerun against the selected final fixture before new end-to-end
Inspector acceptance. Public trusted HTTPS and hosted ChatGPT still need their
own authorized route and client journey. Human security, operations/recovery,
license/attribution, language and accessibility reviews remain pending.

The immediate platform batch is M0 followed by M1: qualify patched build inputs
and verified kit consumption/installed-tree gates, freeze the reviewed successor
kit, then exercise ordinary-user source retrieval/reconstruction and two primary
native cells. The remaining cells and LinuxServer applicability are explicit later
gates. No matrix containers, deployment, push or PR #14 operation ran here.
