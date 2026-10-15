<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integration suite

Runs on GitHub Actions (`.github/workflows/integration.yml`) or, one cell at a time, on a Mac through `ci/run-local.sh`
(below): `ci/harness.sh` starts a disposable official Nextcloud container and database, pinned by digest, installs or
upgrades the app through the server and runs this suite inside the container with `phpunit.xml` here. Nothing is ever
installed on the host outside a container; `vendor/bin/phpunit` in `mcp/` runs the unit suite only, which excludes this
folder.

## Running a cell on a Mac (`ci/run-local.sh`)

Needs `brew install colima docker` (Colima, not Docker Desktop), plus the PHP and Composer the packaging script uses.

    mcp/tests/Integration/ci/run-local.sh <nc 32|33|34|35> <mariadb|pgsql|sqlite> <fresh|upgrade> [--prune-images]

It runs the same steps as the workflow (packages, tools, start-db, start-nextcloud, install-app, fixtures, provision,
report, test, collect) for that one cell, with the image digests, the cell and the baseline tag read from
`.github/workflows/integration.yml`. The container engine is started for the run and always stopped again: on success,
on error and on INT/TERM a trap removes the test containers, their network and volumes, the baseline worktree of the
upgrade mode, and runs `colima stop`, but only when the script itself started Colima (an engine that was already
running is left running). Images stay cached as files unless `--prune-images` is given. The pinned images are
multi-architecture and run natively as arm64 on Apple silicon; the script prints the architecture it got.

Logs and artifacts (`run.log` is the terminal output, `resources.log` the memory samples, `mcp-it/artifacts/` the junit,
Nextcloud and database logs, `observations.txt` and `provision.log`) land in
`mcp/tests/Integration/.local-runs/<cell>-<timestamp>/`, which is git-ignored. The exit status is the suite's.
A cell takes about 1.5 to 3 minutes once the images are pulled and the engine (a Linux VM with 4 CPUs and 8 GiB) uses
up to roughly 2 to 3 GiB of host memory while it runs.

What the suite gives later tests:

- `IntegrationTestCase::openIndependentConnection()`: a second real session to the instance's database (the same
  SQLite file, or the same MariaDB/PostgreSQL database), next to the official connection.
- `Support\EventProbe`: listeners on the real event dispatcher, with the caller's transaction state at dispatch.
- `Support\PostgresQueryLog`: the statements each PostgreSQL session ran between two markers, in the PostgreSQL cell.
- Fixture users and groups (`Fixtures`), created by the harness.

## Limit of the smoke tests

`Smoke/PostgresQueryLogTest` brackets a test closure registered at priority 500 with marker listeners at 501 and 499.
It proves that the log captures a session's statements and that an empty window is trustworthy; it does **not**
prove that a listener of the app runs no query inside the caller's transaction (contracts §3.3.6, validation
T19/F5). The app's listeners are registered at the default priority 0, which they share with core and other apps'
listeners that do run queries. Tickets 06/07 must delimit the app's real handler without changing its registration
or order, and also assert the caller's next query and its commit/rollback.

## Transactional policy coverage (T1/T2)

`Policy/PolicyTransactionTest.php` binds the production writer and official `IConfig`, `IUserConfig`, and
`IAppConfig` to the server connection. It checks that core config and OAuth storage actually hold that same
connection. A second real session observes config through test-only clones of core config services with their
connection rebound; app tables are read through production mappers. The tests issue no SQL against core tables.

Failures after user set, user delete, app set, group write, and revision CAS must leave all values and counters
unchanged. Each stage is observed from the independent session before commit, and the original config services
are reused after rollback. The success case must make every write visible together. Native migration seed
reexecution must preserve existing counters, and a second writer must reject a stale revision.

The event/outbox schema belongs to a later ticket; its rollback injection must be added when that schema exists.
These tests do not certify OAuth generation/cause handling or the full T19 concurrency matrix.

## Exclusion proof (issue #4, A4)

`Exclusion/` runs the real scenarios of the exclusion by tags (V2, V3, V4, V5, V7, V9 phase 1, V14) and the positive
controls; `Unit/Contract/EXCLUSION-MATRIX.md` holds the verdict and the limits.

- **Provisioning** (`harness.sh provision`, after the fixtures): groupfolders (`mcp-it-gf`, group `mcp-it-staff`), Talk
  and Notes from the project release repositories, pinned per Nextcloud major by tag and SHA-256, and three global local
  external mounts for both fixture users: `mcp-it-ext-wide` (`/mcp-it-ext`), `mcp-it-ext-alias` (the same directory) and
  `mcp-it-ext-narrow` (`/mcp-it-ext/Open`). Each part is attempted on its own; a failure is recorded and
  `ProvisioningTest` fails by name, with `provision.log` in the cell's artifact. Nothing is left silently uncovered.
- **Real logins**: an app password (HTTP Basic, created through the core token provider) and an OAuth access token from the
  app's own `TokenService` (Bearer, PKCE), used over HTTP against the container's Apache. Files change from outside the
  tools through WebDAV and the server's Files API; tags, shares and links through the core services.
- **`cache_app_config` is off** in the cell, except inside `AppConfigCacheWindowTest`: core serves the app config from the
  web server's local cache for 3 seconds and the CLI that writes the settings cannot invalidate it. That test turns the
  cache on and measures the stale window of the exclusion list (2.66 s to 3.94 s in the eleven cells measured); the result
  and its meaning are in `EXCLUSION-MATRIX.md`.
- **Diagnostics**: `DiagnosticsTest` records, in `observations.txt` of the artifact, how each kind of mount looks to the guard
  (including the ancestor walk in a fresh process, `Support/chain-probe.php`) and the answers of the tools for it. Behavior
  that is documented rather than asserted (the narrow mount of a subdirectory is a separate storage) is recorded there too.
- Not covered here: V9 phase 2 (B8), V6 (federated), groupfolder ACL rules, S-scenarios of part B.
