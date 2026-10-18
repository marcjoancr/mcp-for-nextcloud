<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Devbox catalog parity and available validation report

**Date:** 2026-10-17. **Initial code under test:** `67b2bc5`.
**Correction commit:** `1ed6936`, based on `9e97717`.
**Current status:** the local PHP compatibility, deprecation and attribution blockers
are resolved. The correction results below supersede the initial failures retained
here for traceability. Remote GitHub Actions and real-host checks remain pending.
This follow-up supersedes the pending catalog update and previously unexecuted local
matrix/REUSE checks in the [DevEx execution report](2026-10-17-developer-commands-static-analysis-report.md).
The earlier report remains a historical record of implementation on PHP 8.4.
The initial validation task changed no application code, tests, fixture bytes,
dependencies or workflow rules. The correction scope is recorded below.

## External catalog

Only the selected project's private catalog and its context were updated. Project
identity, checkout, remotes, worktree root, setup and status tasks remain unchanged.
The original profile/context are backed up outside the repository. No harness
configuration was copied into the repository.

| Catalog task | Final argv |
| --- | --- |
| `test` | `composer --working-dir=mcp test` |
| `check` | `composer --working-dir=mcp check` |
| `setup` (unchanged) | `composer --working-dir=mcp install --no-interaction --no-progress` |
| `status` (unchanged) | `git status --short --branch` |

`devbox context` resolves the same registered project and isolated worktree after
updating the profile. The JSON results of both catalog invocations confirm these
exact argv arrays and child exit code 0. No shell-level copy of PHPUnit flags remains.

The profile applies to all this project's checkouts. Use a revision containing the
Composer scripts: the selected worktree has them, but the base `main` checkout at
verification time has no Composer scripts. Integration into that branch remains
pending; no fallback to the obsolete runner was introduced, and no branch was moved.

## Initial observed results

| Environment and command | Result |
| --- | --- |
| Host PHP 8.4.26 / Composer 2.8.8: `devbox run check` | Exit 0; manifest valid; 903 syntax-checked files; both PHPStan scopes zero errors; 5,268 tests / 44,813 assertions; package valid. Suite: 27.933 s, 516.92 MB. |
| Same host: `devbox run test` | Exit 0; 5,268 / 44,813; 27.717 s, 516.92 MB. |
| PHP 8.2.34 / Composer 2.8.12: locked install and platform requirements | Pass. Existing foreign-namespace test-double autoload warnings remain visible. |
| PHP 8.2.34: `composer test` | Exit 255 during discovery; no completed suite. First parser failure in ConversationResolverTest.php:91. |
| PHP 8.2.34: `composer lint` | Exit 1; six test files fail parsing, listed below. |
| PHP 8.2.34: manifest validation and `composer analyse` | Exit 0; both scopes report zero errors. Static scope is `lib/`, not tests. |
| PHP 8.2.34: `composer package` | Exit 0; archive built and checked using the normal per-user Composer cache, offline. |
| PHP 8.3.35 / Composer 2.8.12: locked install and platform requirements | Pass. |
| PHP 8.3.35: `composer test` and `composer lint` | Test exit 255 during discovery; lint exit 1 for the same six files. |
| PHP 8.5.11 / Composer 2.8.12: platform requirements and `composer test -- --display-deprecations` | Exit 0; 5,268 / 44,813; 26.417 s, 520.92 MB; **three PHP deprecations**, detailed below. This is not a warning-free result. |
| REUSE 5.1.1: `reuse lint` | Exit 1; 13 JSON fixtures lack copyright and licensing information. Initial run: 1,019 / 1,032 files covered; zero missing license texts or bad licenses. |

After adding this report, REUSE still exits 1 for exactly the same 13 fixtures:
1,020 / 1,033 files have attribution. The documentation introduces no new gap.

The configured PHPUnit command does not fail on deprecations; the PHP 8.5 exit code
therefore does not mean there were none. They were displayed explicitly and retained
as pending work. No check was weakened or error suppressed to produce these results.

## PHP 8.2 / 8.3 parser blocker

Both interpreters reject member calls directly on `new Class(...)` without wrapping
the construction in parentheses. The first parser failure in each affected file is:

| File | Line |
| --- | --- |
| [mcp/tests/Unit/Modules/Talk/Access/ConversationResolverTest.php](../../../../mcp/tests/Unit/Modules/Talk/Access/ConversationResolverTest.php) | 91 |
| [mcp/tests/Unit/Modules/Talk/Integration/NativeIntegrationTest.php](../../../../mcp/tests/Unit/Modules/Talk/Integration/NativeIntegrationTest.php) | 49 |
| [mcp/tests/Unit/Modules/Talk/Messaging/BatchSenderTest.php](../../../../mcp/tests/Unit/Modules/Talk/Messaging/BatchSenderTest.php) | 31 |
| [mcp/tests/Unit/Modules/Talk/Messaging/MessageDeliveryTest.php](../../../../mcp/tests/Unit/Modules/Talk/Messaging/MessageDeliveryTest.php) | 82 |
| [mcp/tests/Unit/Modules/Talk/References/ReferenceLinkerTest.php](../../../../mcp/tests/Unit/Modules/Talk/References/ReferenceLinkerTest.php) | 62 |
| [mcp/tests/Unit/Modules/Talk/TalkModuleTest.php](../../../../mcp/tests/Unit/Modules/Talk/TalkModuleTest.php) | 1207 |

The linter checks all default source trees and reports all six files, rather than
stopping at PHPUnit's first discovery failure. These are test-source errors, so a
clean production-only PHPStan run does not contradict this result. A follow-up
should use construction/member-access syntax supported by PHP 8.2, preserving test
assertions, then rerun lint and the full matrix. A file can contain additional
occurrences beyond the first parser error listed above.

## PHP 8.5 deprecations

Six tests trigger three distinct diagnostics:

- `tests/Unit/Modules/Files/Checkout/CheckoutControllerTest.php:203`:
  `ReflectionProperty::setAccessible()`.
- `tests/Unit/Modules/Files/Images/FilesImageToolsTest.php:48`: `imagedestroy()`.
- `tests/Unit/OAuth/OAuthControllerFlowTest.php:75`:
  `ReflectionProperty::setAccessible()`.

The first PHP 8.5 attempt lacked JPEG support in the disposable GD build. It ended
with one image-fixture error and one consequent risky-test report. The runtime was
rebuilt with JPEG enabled, `imagejpeg()` availability was checked, and the complete
suite rerun. The final result above has no errors or risky tests. That initial
failure was an environment defect, not repaired by changing project code.

## REUSE blocker

These captured JSON fixtures have neither sidecar licensing nor a matching
annotation in the current REUSE.toml:

- [mcp/tests/Support/Dav/Compatibility/definitions.json](../../../../mcp/tests/Support/Dav/Compatibility/definitions.json)
- [mcp/tests/Support/Dav/Compatibility/plans.json](../../../../mcp/tests/Support/Dav/Compatibility/plans.json)
- [mcp/tests/Support/Logs/Compatibility/audit.json](../../../../mcp/tests/Support/Logs/Compatibility/audit.json)
- [mcp/tests/Support/Logs/Compatibility/definitions.json](../../../../mcp/tests/Support/Logs/Compatibility/definitions.json)
- [mcp/tests/Support/Logs/Compatibility/results.json](../../../../mcp/tests/Support/Logs/Compatibility/results.json)
- [mcp/tests/Support/Mcp/Compatibility/protocol.json](../../../../mcp/tests/Support/Mcp/Compatibility/protocol.json)
- [mcp/tests/Support/Mcp/Compatibility/publication.json](../../../../mcp/tests/Support/Mcp/Compatibility/publication.json)
- [mcp/tests/Support/Mcp/Compatibility/results.json](../../../../mcp/tests/Support/Mcp/Compatibility/results.json)
- [mcp/tests/Support/Notes/Compatibility/definitions.json](../../../../mcp/tests/Support/Notes/Compatibility/definitions.json)
- [mcp/tests/Support/Notes/Compatibility/plans.json](../../../../mcp/tests/Support/Notes/Compatibility/plans.json)
- [mcp/tests/Support/Notes/Compatibility/results.json](../../../../mcp/tests/Support/Notes/Compatibility/results.json)
- [mcp/tests/Support/People/Compatibility/definitions.json](../../../../mcp/tests/Support/People/Compatibility/definitions.json)
- [mcp/tests/Support/People/Compatibility/results.json](../../../../mcp/tests/Support/People/Compatibility/results.json)

Remediation needs verified attribution through explicit REUSE annotations or
`.license` sidecars, preserving captured JSON bytes. Do not exclude the fixtures
from scanning or infer ownership from a passing test. The previously corrected
AGPL-3.0-only declaration identifiers and their license text are accepted by this run.

## Isolation and reproducibility

The three additional PHP runtimes used disposable CLI containers from official
`php:8.2-cli-bookworm`, `php:8.3-cli-bookworm` and `php:8.5-cli-bookworm` images, with
Composer 2.8.12 and the required intl, zip and GD extensions. Base images supply
mbstring, SQLite and the other platform extensions. Final PHP 8.5 GD enables JPEG.
PHP 8.2/8.3 abort during discovery before image fixtures execute; their test suite
cannot be claimed as exercised. No server interpreter or global package was replaced.

Each runtime received an independent export of the tested Git revision and a copy
of its locked vendor tree. Composer install regenerated autoload metadata and verified
the lock under each interpreter before tests. Execution containers had no network
or published ports. PHP 8.2 packaging reused the existing standard Composer cache.
Temporary exports/build recipes and task-owned runtime image tags are removed at
closure; raw logs and external profile backups remain in private per-user task state.

The local REUSE run used read-only source/Git mounts and the same major image as
[reuse-action v5](https://github.com/fsfe/reuse-action/blob/v5/Dockerfile), with Git's
safe-directory configuration scoped to that container. This reproduces the lint
command, not GitHub Actions scheduling or runner setup.

| Input | Observed image digest |
| --- | --- |
| `composer:2.8` | `sha256:5248900ab8b5f7f880c2d62180e40960cd87f60149ec9a1abfd62ac72a02577c` |
| `php:8.2-cli-bookworm` | `sha256:0d6ec82fde85307fd9c672527017fccf9bac55f254756f035e542f8c566b63c9` |
| `php:8.3-cli-bookworm` | `sha256:c24b55afdf860c874b9ec6267ced132ff52de97d308ab08e6cf41e8596e509f7` |
| `php:8.5-cli-bookworm` | `sha256:819080ed413631f6ec564184dd9acd08d9425bd863b9f8b5876b5ea2147d55e7` |
| `fsfe/reuse:5` | `sha256:11eb8a423ea82776bc2890bb255b61736bec277ef6e2141f8c91d6d88864f9da` |

## Work identified by the initial validation

1. Correct the six Talk test files for PHP 8.2/8.3 and rerun full suites; current
   discovery failures do not establish whether further test failures exist.
2. Resolve the 13 fixture attribution gaps and rerun REUSE. This is an observed
   failure, no longer an unexecuted check.
3. Remove obsolete calls producing the three PHP 8.5 deprecations without reducing
   assertions or diagnostic visibility, then rerun the suite.
4. Run GitHub Actions against the integrated revision. Local CLI equivalents ran;
   no remote workflow was triggered because no push was authorized.
5. Run real Nextcloud 32–35 integration checks with disposable, configured host
   instances and synthetic accounts/data. No such target was provisioned or exposed
   for this project, so native authentication, ACL, locks, audit and persistence
   behavior remain unverified here. No app/server was deployed to create a target.
6. Integrate the Composer-command revision into any older checkout before invoking
   the updated external test/check tasks there. No merge, push or deployment occurred.

Stricter capability analysis, administrative JavaScript separation and broader
README/docs navigation/i18n remain separate milestones from this validation task.

## Compatibility and attribution corrections

The authorized follow-up started from `9e97717` and produced `1ed6936`. It changes
nine test files and one attribution configuration; production code, dependency locks,
Composer scripts, PHPUnit configuration, workflows and captured JSON bytes remain
unchanged. The external devbox profile already delegates to Composer and was not
modified again.

### Minimal corrections and preserved contracts

- Parenthesize 15 construction/member-call expressions across the six Talk test
  files listed above. Ten occur in NativeIntegrationTest; the other five files each
  contain one. Normalized ASTs before and after are identical: the additional
  parentheses change accepted parser syntax, not constructors, arguments or calls.
- Remove the two obsolete `ReflectionProperty::setAccessible(true)` calls from
  response-header helpers. The same properties are still read and the existing
  HTTP header/status assertions remain intact.
- Replace `imagedestroy($im)` with `unset($im)` after the JPEG bytes are captured.
  The image fixture, metadata assertions and output-buffer handling remain intact.
- Add one exact-file REUSE.toml annotation for the 13 listed JSON captures, retaining
  AGPL-3.0-or-later and both the original app copyright and MCP contributors. No
  wildcard, ignore or exclusion was added. The attribution follows the existing app
  metadata and the capture READMEs/test headers, not inferred ownership from a green
  lint result. The READMEs document captures of pre-migration app output; their
  addition commits are DAV `72d8308`, Notes `6e98107`, People `c4102e5`, Logs `ee5ff69`
  and shared MCP `0526581`. In particular, the Notes capture test retains the original
  app copyright while its companion README credits project contributors.

Existing tests already cover the relevant behavior; no duplicate permanent tests
were added. Before/after expanded discovery has the same **5,268 ordered case IDs**,
and all **17 tracked JSON fixture SHA-256 hashes** are unchanged. Neither assertions
nor cases were removed or skipped to achieve compatibility.

### Failure reproduced, then correction verified

Before editing, PHP 8.2 lint reproduced all six parse failures; REUSE reproduced the
13 missing attribution entries. The three affected PHP 8.5 test files ran with
`--fail-on-deprecation --display-deprecations`: **86 tests / 327 assertions**, three
deprecations and exit 1. After correction, the same focused command reports **86 /
327**, no deprecations and exit 0 (0.269 s).

| Corrected environment | Full suite | Suite time / peak memory | Result |
| --- | --- | --- | --- |
| PHP 8.2.34, Composer 2.8.12 | 5,268 tests / 44,813 assertions | 27.702 s / 518.92 MB | Exit 0 |
| PHP 8.3.35, Composer 2.8.12 | 5,268 tests / 44,813 assertions | 27.552 s / 518.92 MB | Exit 0 |
| PHP 8.4.26, Composer 2.8.8, through `devbox run check` | 5,268 tests / 44,813 assertions | 29.335 s / 516.92 MB | Exit 0 |
| PHP 8.5.11, Composer 2.8.12 | 5,268 tests / 44,813 assertions | 28.324 s / 520.92 MB | Exit 0 |

The isolated PHP 8.2, 8.3 and 8.5 full suites use:

```sh
composer --working-dir=mcp test -- --fail-on-deprecation --display-deprecations --fail-on-warning --fail-on-risky
```

All complete with no reported errors, warnings, deprecations or risky tests. These
flags strengthen this validation; committed gate configuration was not relaxed or
changed. Existing Composer optimized-autoload warnings about explicitly loaded
foreign-namespace test doubles remain visible during installation and are not
PHPUnit/PHPStan diagnostics suppressed by this task.

Additional checks:

- PHP 8.2/8.3 lint and host PHP 8.4 lint: **903 files**, zero syntax errors.
- PHP 8.2 manifest validation, both PHPStan scopes and package: exit 0; zero analyzer
  errors; production archive built and accepted. Host devbox `check` independently
  runs all these gates successfully on PHP 8.4.
- REUSE **5.1.1**: exit 0, **1,033 / 1,033 files** with copyright and license metadata;
  no bad, missing, deprecated or unused licenses, and no read errors.
- Fixture hashes, ordered discovery, changed Markdown links and `git diff --check`
  pass. A fresh read-only reviewer found no actionable compatibility or attribution
  issues. No runtime adapters or transitional test helpers were introduced.

The isolated runtimes use the same base image versions recorded above; all three
now include GD JPEG support. Tests run offline in separate source/vendor copies,
with no published ports or shared mutable test caches. The temporary copies, build
recipe and task-owned runtime image tags are removed after verification. Logs and
before/after evidence remain in private per-user task state outside the repository.

### Remaining external gates

The three observed local blockers are closed. Remaining release/integration work is:

1. Run the repository's GitHub Actions jobs against the integrated revision. These
   local executions do not claim a remote workflow result; no push was performed.
2. Run real Nextcloud 32–35 checks against configured disposable host instances with
   synthetic users/data. Native authentication, ACLs, locks, audit and persistence
   still require that environment; no deployment was performed to create one.
3. Integrate the Composer-script revision into older checkouts before using the
   updated devbox tasks there. The base `main` branch was not merged or moved.

Broader static analysis, administrative JavaScript separation and documentation/i18n
migration remain independently scoped milestones, not unfinished parts of this fix.
