<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Testing and verification

All examples run from the repository root; omit `--working-dir=mcp` inside `mcp/`.
See [setup](setup.md) for prerequisites and [static analysis](static-analysis.md)
for the precise analyzer scope and host model.

```sh
composer --working-dir=mcp docs:check
composer --working-dir=mcp docs:translations
composer --working-dir=mcp test:docs
composer --working-dir=mcp test:clients:unit
composer --working-dir=mcp test:release
composer --working-dir=mcp assets:build
composer --working-dir=mcp test:js
composer --working-dir=mcp test
composer --working-dir=mcp test -- tests/Unit/Modules/Files/Reading/ReadFileTest.php
composer --working-dir=mcp test -- --filter 'EditFileTest|TextFileEditorTest'
composer --working-dir=mcp test:architecture
composer --working-dir=mcp test:api
composer --working-dir=mcp lint
composer --working-dir=mcp analyse
composer --working-dir=mcp package
composer --working-dir=mcp check
```

`test` first builds both browser entries, then preserves the complete PHPUnit discovery under `tests/` (the existing suite
is named `unit`, including contract and architecture tests). It applies the same
1G memory/post/upload settings to all selections and fails on zero selected tests.
Focused aliases delegate to it and accept additional PHPUnit arguments. Use quoted
filters to preserve spaces and regular-expression alternatives.

`lint` parses PHP in `lib/`, `appinfo/`, `templates/`, `scripts/` and `tests/` without
executing it. An optional file/directory argument narrows a direct lint invocation;
relative paths are app-relative. Missing inputs, no PHP files, parse errors and
failed child processes return failure. Generated/dependency subtrees and symlinks
are not traversed. Syntax compatibility follows the interpreter used; CI uses 8.2.

`analyse:lib` and `analyse:core` are mandatory components of `analyse`. `check`
validates manifest/lock, checks documentation/provenance, reports translation state,
runs documentation and client-runner safety tests, lints, analyzes, runs all PHP tests, runs the complete
JavaScript suite and builds/checks the package in that order. **Fixed gates ignore additional script arguments**, including
filters, path selections and analyzer level overrides. For example, passing a
filter to `check` still runs the full suite. Use `test` for deliberate focused work.

## Interpreting failures

- Missing vendor executables: install locked development dependencies, then run
  `check-platform-reqs`. Do not substitute the production vendor tree.
- No tests executed: correct the file path or filter; empty selections are failures.
- Syntax/analysis error: fix the actual source or an evidenced incorrect annotation.
  No baselines, inline ignores, disabled rules or omitted production paths.
- Package rejection: inspect the reported member or missing asset. The checker
  rejects development dependencies, tooling, tests, scripts and caches; never disable it.
- Composer PSR-4 warnings for existing foreign-namespace test doubles: these are
  loaded explicitly by their tests and excluded from optimized Composer discovery.
  They predate these commands; do not add them to production autoload to silence warnings.

## Native integration

`check` and `test` exclude only `tests/Integration`; they never start containers.
Run a pinned disposable cell explicitly:

```sh
composer --working-dir=mcp test:integration -- --plan 35 sqlite fresh
composer --working-dir=mcp test:integration -- 35 sqlite fresh
```

The first command prints immutable inputs only. The second needs an existing Docker
engine and creates its own labeled containers and private network without published
ports. It cleans up only those resources, including their anonymous volumes. It
never starts/stops the shared daemon, prunes images or creates linked worktrees.
Linux `ip`, PHP, Composer, OpenSSL, curl and the locked Node/npm versions are required.

The source checkout's `mcp/tests/Integration/README.md` describes all
15 cells, separate suite ordering, baseline probes and private versus publishable
artifacts. The integration workflow uses the checkout command and pinned image
manifest. Final artifact acceptance uses the explicit `--candidate-kit` and, for
upgrades, `--baseline-package` inputs described in that README; it must not
substitute a fresh checkout build. Full Git history is required for source/translation provenance.
A failed app installation, empty selection or test failure is a failed cell.
Harness qualification with an explicitly substituted version and filtered diagnostic
runs do not certify the final package; report them separately.

## LinuxServer planning, checkpoints, upgrade and recovery

The separate `test:integration:linuxserver` command supports offline planning and
explicit L1 baseline bootstrap, L2 fixture/checkpoint capture, L3 isolated upgrade
and L4 recovery. It uses LinuxServer/Nginx, PostgreSQL and S3 rather than the
Apache matrix. See [LinuxServer testing](linuxserver-testing.md) for prerequisites,
trusted inputs, phase arguments and serving/maintenance controls.

Planning creates no containers. Execution needs an explicit frozen source kit,
matching fixture profile, retained baseline and test inputs. L5 is an evidence
review label, not a runtime phase; `--phase L5` is rejected.

Runner boundary tests run in ordinary `test`/`check` with synthetic inputs. Native
execution is separate. The [integration harness guide](../../../mcp/tests/Integration/README.md)
owns Apache cell arguments, fixture pins, baseline admission and installed-tree
verification. The pins are test inputs, not a promise about every app/server patch.

## Optional Mail acceptance

Run `composer --working-dir=mcp test:integration -- 35 mariadb upgrade-local --mail`
(or `fresh --mail`) to add pinned Mail 5.12.5 and Dovecot 2.4.5 to the existing
private disposable cell. Add `--plan` before the cell to inspect pins without
starting containers. Other cells reject this flag; ordinary runs do not install
Mail and explicitly skip its two native cases. CI opts in on NC35/MariaDB/fresh.

`mcp/tests/Integration/ci/mail-pins.json` records immutable image/archive inputs.
No host ports are published; provisioning checks run ownership and cleanup only
removes containers bearing that run's label. The IMAP password is a public fixture
constant accepted only in that throwaway network. No real mail account is used.
The tests exercise native DI, Basic/OAuth, revoked grants, ownership and unchanged
cache/IMAP state with a server-only folder and stale-sync timestamp canary.
API snapshots add only Mail surfaces from SHA-256-verified official archives;
`generate-mail.php` preserves unrelated surfaces and `mail-sources.json` pins them.

## Private real-client acceptance

`test:clients:unit` checks runner safety without containers and is included in
`check`. `test:clients` is an explicit private TLS/Inspector campaign against the
retained frozen fixture; it is never included in `check` and cannot target an
arbitrary instance. See its [prerequisites, invocation and evidence limits](../../../mcp/tests/ClientAcceptance/README.md).
The separate `test:clients:storage -- /absolute/inspector-install` command checks
pinned Inspector encrypted persistence in fresh processes without containers or
network. It is opt-in, uses Inspector 2.13.0 with Node 24.22.1, and checks DCR
isolation separately from static-client issuer-stamp persistence; see [encrypted storage across issuers](../../../mcp/tests/ClientAcceptance/README.md#encrypted-storage-across-issuers).
Hosted-client journeys, public HTTPS and human verdicts require separate evidence
for the selected artifact and endpoint.

## Coverage and CI

Tests use OCP interfaces, explicit native-host doubles and in-memory fixtures.
The shared file tree is `tests/Support/Platform/FileAccess/FakeTree.php`. Existing
compatibility captures must retain their meaning and byte output during structural
changes. Reconcile expanded PHPUnit case IDs, including datasets, when moving tests.

CI splits work without redefining commands: PHP 8.2–8.5 runs `test`; PHP 8.2 quality
runs manifest validation, `lint` and `analyse`; packaging runs once and REUSE remains
its own job. A documentation quality job uses the same commands and full Git history;
packaging also validates documentation independently. Local `check` does not run the REUSE action or other PHP interpreters.
Local completion must report these limits and real Nextcloud checks for the approved candidate profiles separately.
Native API snapshots are useful contracts, not proof of real ACL, locks, shares,
optional applications, audit effects or persistence behavior.

External development environments should delegate their `test` and `check` tasks
to these Composer commands. Environment profiles stay outside the repository;
no repository tooling depends on a particular development server.

## Administration browser tests

After the frontend prerequisites in [setup](setup.md) are installed, run
`composer --working-dir=mcp test:js`. This fixed gate builds both entries, then runs all required Chromium,
Firefox and WebKit cases; `check` includes it before packaging. Focused development
runs first build with `composer --working-dir=mcp assets:build`, then use `npm --prefix mcp test -- admin/navigation.spec.js --project=chromium`.
Empty discovery, focused-only declarations, skipped mandatory tests, browser
startup failures, uncaught page errors and unexpected API requests fail the gate.
Retries are disabled. Traces from failures are kept in `mcp/build/browser-results/`.

The builder rejects missing imports, cycles, unreachable production sources and
admin modules in the personal bundle. Packaging also builds when invoked directly,
invalidates only the current version's previous archive before building, and publishes
the archive filename only after validation. A failed build removes both generated
entries; a failed package attempt leaves no result at the expected archive path.

Browser tests render actual PHP templates with synthetic host interfaces and API
responses. They do not establish real Nextcloud CSP, authentication, theme/layout or
cache compatibility. Check those on a real host before certifying a release;
keyboard and assistive-technology review also remain separate. See
[administration JavaScript](admin-javascript.md) for source ownership.

## Documentation verification

`docs:check` checks Markdown structure, local targets/fragments, equivalent-language
navigation and real source provenance. `docs:translations` reports missing and
review-required pages without failing ordinary development; invalid metadata or
missing history still fails. `test:docs` runs every Node documentation test and
rejects empty discovery or inherited nested-runner contexts. None of these commands
executes documentation examples or performs network link crawling.

Package failure probes cover documentation validation, build, staging, archive
inspection and extracted verification. The actual archive must match the explicit
manifest and transformed source bytes, including legal files and assets. Follow
[source packaging](packaging.md) for the complete distribution contract.

## Source delivery verification

`test:release` exercises committed-source isolation and trusted-manifest/installed
file checks, including altered, missing, extra and symlink entries. It runs in
`composer check` and the CI quality workflow, rejects empty discovery and does not
start Nextcloud or publish artifacts. See [source delivery](source-delivery.md)
for kit preparation, read-only verification and its separate deployment gates.

`test:source -- ABSOLUTE_KIT TRUSTED_MANIFEST_SHA256` is a separate, explicit
Docker acceptance command. It starts a disposable NC35/SQLite instance without
published ports, verifies non-admin personal-settings and MCP source discovery,
and retrieves the kit without credentials. It is not part of the ordinary
`check` command. Follow the [native source-acceptance procedure](source-delivery.md#disposable-native-source-acceptance),
including the separate reconstruction check and cleanup/evidence limits.
