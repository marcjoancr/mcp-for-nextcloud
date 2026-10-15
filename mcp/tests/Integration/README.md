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

## Revocation by generation and epoch (T7/T11 OAuth part)

`OAuth/GenerationCutTest.php` runs the production token service, writer and account listener on an account it
creates and deletes. A code carries the user's next generation, its grant and the spent record of a rotation keep
it, and a second consent leaves the other sessions working. A disconnect, an account disabled in Nextcloud and the
service switched off revoke by a cut or by the global epoch, as another session reads them; the cleanup after the
commit removes the revoked rows, and repeating it never removes a credential issued after the cut. A consent whose
code fails to store leaves the official `connected` value, the generation and the revision exactly as they were. The
daily cleanup job must be registered by the app (install and upgrade) and must not remove a valid credential. The
global epoch is proven by `Last/GlobalEpochTest.php`, in the `last` suite of `phpunit.xml`, which runs after every
other test because its cleanup removes every credential of the old epoch, the baseline ones of an upgrade cell
included. It runs in every cell; in the upgrade cells it also covers a row that takes (epoch 0, generation 1) from the
defaults the upgrade ALTER added. The epoch is put back afterwards.
Recheck causes are not covered here: they belong to ticket 06.

## Recheck causes, deferred work and the issuance mutex (T5, T7, T8, T19)

`OAuth/UserCausesTest.php` (ticket 06) deletes accounts and removes members through the core itself, so the events
arrive in core's order, with the duplicate pairs `User::delete` dispatches around `Group::removeUser`, to the app's
listener as registered (default priority). It checks that one deletion records one account cause, cuts once (one
revision) and consumes the cause; that a failure of each one of the app's handlers, injected by renaming the app's
recheck table only while core dispatches that event (probe listeners at priority 1 and -1 around it), never stops the
deletion and the credential still dies (reconciler or observed use); that a lost After of a deletion is consumed by
the next consent of a recreated account, whose code and grant then survive the late After; that a removal with its
After cuts in the same request, one without it (another listener throws first) is revoked by the next pass even when
the user is added back (Q1), and one that keeps eligibility through another source touches nothing.

The F5 case delimits the app's real handler without changing its registration: inside the caller's transaction on
the official connection, the test calls the registered listener instance itself between two statement-log markers
(PostgreSQL cell: the window must be empty), then lets core remove the member with its real dispatch (no statement of
that window may touch an app table or fail), runs the caller's next query and commits or rolls back. The deferred fact
is reconciled at the next safe point without a date: committed and no longer eligible, cut; rolled back and still
eligible, nothing cut. The same is done for an account deletion (both deletion handlers called between markers, then
`User::delete` itself, committed, reconciled later: account gone, cut) and for an account disable (`setEnabled(false)`,
rolled back; inside a transaction the disable is left to the next use, which refuses the disabled account). Group
eligibility is not wired into consent and validation until ticket 09, so those cases read the effect on the user's
generations, not through a Bearer request. Limit: the listener instance and its dependency graph are built before the
caller's transaction opens (the dispatcher resolves the service on its first event in the process); none of their
constructors runs SQL, but that construction is not itself bracketed by the log.

A deletion whose Before could not store its cause is reconciled by its After without a date, so a recreated account
never inherits the old token (review F1 of ticket 06).

Two pending proofs of earlier tickets run here too: a `record()` of one cause interleaved by another session between
its read and its insert (an adapter on the first session runs the other session's record at its second query
builder), and a real lock wait on the issuance mutex held by another session, with the engine's lock timeout
shortened for the test session (MariaDB `innodb_lock_wait_timeout`, PostgreSQL `lock_timeout`, SQLite
`busy_timeout`); the elapsed time is printed to stderr. A real deadlock (MariaDB 1213, PostgreSQL 40P01) is not
produced here.

## GID lifecycle (T6, T19 group part)

`OAuth/GroupLifecycleTest.php` (ticket 07) deletes and creates groups through the core's group manager and Database
backend, with the app's listener as registered. A deletion of a group whose policy grants eligibility must, between
core's Before and the backend delete (a probe right after the app's handler), show the lifecycle as `pending_delete`
and the cause of the holder who loses its last source, with neither the mutex nor the revision moved (the one
transition that runs without the mutex, see `GroupLifeMapper::markPendingDelete()`); after GroupDeletedEvent the rows
and the lifecycle are gone, the holder is cut once and the cause is consumed (two revisions: purge and cut). A holder
eligible through another source keeps its credential. A lost GroupDeletedEvent (another app throws first) leaves
`pending_delete` until the job, which purges a group that is gone; a deletion that failed (the Before alone) is
suspended by the job (`needs_confirm`, rows kept, eligibility through it unknown, nothing cut). Recreating the id
purges a residual policy saved before the creation began (policy_rev <= R_c), a policy saved between the creation's
Before and its event is kept, and a late GroupDeletedEvent carrying the old gen never removes a policy saved after the
recreation. Creating another group while one is being deleted is never refused.

The F5 case brackets the app's four group handlers between statement-log markers inside the caller's transaction
(PostgreSQL cell: empty window), then lets core delete the group with its real dispatch (no app table touched, no
failed statement), runs the next query and commits or rolls back; at the next safe point the policy is suspended
(`needs_confirm`) and never deleted, committed or not. Delete and recreate of the same id in one committed transaction
shows the accepted window of contracts §6.3 item 3 (i): another session still reads the old lifecycle as active until
the deferred processing, which then suspends it.

Declared residue (not a failure): if the reconciler job reads a group as existing while its deletion is in flight
(after the Before wrote `pending_delete`, before the backend delete), the lifecycle goes to `needs_confirm`, and the
GroupDeletedEvent that follows, with the Before's gen, purges nothing. The suspended policy of a group that no longer
exists grants nothing; a recreation with its signals purges it, and an administrator clears it by removing the group's
policy (admin UI, ticket 11).

These tests were written while the integration workflow was disabled (Actions minutes): they have not run yet.

## Group save (T5 F3, T6, T16 server part)

`OAuth/GroupGrantsTest.php` (ticket 08) previews and saves group policies through the container's service and writer,
over the core group manager and its Database backend, reading every result from a second session. A save makes the
lifecycle active with policy_rev equal to the revision it commits, and writes no user preference (connected and the
overrides stay as they were). A second backend that serves the same id makes the group untrusted: 422 at preview and at
save, nothing written. Replacing the only member by another one between preview and save keeps the count and is still
a 409. A withdrawal of eligibility with a new consent of the member between the external phase and the commit is
replayed once (H1 differs from H0) and cuts the new credential with the old one; a holder still eligible individually
keeps its session. "Confirm current policy" brings a suspended group back to active with a new gen and the same rows,
and a deletion's pre-event between the phases (pending_delete, no revision step) is seen under the mutex: 409.

These tests were written while the integration workflow was disabled (Actions minutes, until 1 November): they have
not run yet.
