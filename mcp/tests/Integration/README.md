<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Integration suite

Runs only on GitHub Actions (`.github/workflows/integration.yml`): `ci/harness.sh` starts a disposable official
Nextcloud container and database, pinned by digest, installs or upgrades the app through the server and runs this
suite inside the container with `phpunit.xml` here. It never runs locally; `vendor/bin/phpunit` in `mcp/` runs the
unit suite only, which excludes this folder.

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

The policy outbox is one more injection point: an event the command emits is written after the revision CAS, in the
same transaction, and must be invisible to the second session until the commit.

## Administrative commands and undo (T3/T4)

`Policy/AdminCommandsTest.php` runs the commands of the admin routes through the production writer. A command over
two users and four cells, whose last cell fails after every config write ran, must leave each raw value, the
revision and the event table exactly as another session saw them before. A receipt's undo must bring back absence
where a key was absent and the old value elsewhere, seen from another session, and a second undo of the same
receipt must be refused without touching anything. Route mapping (428/409/410/503) is covered by the unit suite.

## Authorization schema (T12/T18)

`Policy/AuthStateTest.php` checks the B2b tables and the OAuth generation columns on the real database of each cell,
and that generations, recheck causes and group lifecycles written by the composition mappers commit or roll back with
the policy writer. In the upgrade cells, `ci/legacy-credentials.php` runs while the baseline release is still
installed and issues a code, a rotated grant (with its spent record) and a second grant through that release's own
store; `OAuth/LegacyCredentialsTest.php` then checks after `occ upgrade` that every row, id and hash is unchanged,
that they carry epoch 0 and generation 1, and that they still authenticate, exchange, rotate and detect the replay.
It is skipped in fresh cells. These tests do not certify how later tickets use the columns, nor the full T19 matrix.

## Resource scope schema (issue #4, B1)

`Policy/ResourceScopeTest.php` checks that `mcp_scope_modes` and `mcp_scope_items` exist and hold no row after the
fresh install and after the upgrade from the baseline release, so every user stays unrestricted; that the BIGINT
`policy_rev` holds the largest counter; and that scopes written by `ResourceScopeMapper` commit or roll back with the
policy writer, seen from a second session, with ids never case-folded. The revision these tests store is arbitrary:
which revision the scope writer records is B2's contract, not the schema's.

## Resource scope writer (issue #4, B2)

`Policy/ResourceScopeWriterTest.php` checks that the container wires the scope mapper, the native enumerator and the
activation barrier into the policy writer and the matrix, and that the container's writer refuses `setResourceScope`
for files, calendar and tasks while their barrier is closed, leaving the revision, the events and both tables as they
were. Behind an open barrier (built by hand, with a stand-in enumerator, since native folders and calendars are not
provisioned here) the saved scope carries `policy_rev` = the committed revision, another session reads the mode and
its items through the one-statement join and the grouped count, no epoch moves, and undo restores the absence of the
mode. The native resource checks themselves are unit contracts; the real Files and CalDAV enumeration belongs to B8.
