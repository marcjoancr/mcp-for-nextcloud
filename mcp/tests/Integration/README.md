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

Several runs can be started from different places: each names its containers and network with its own id and removes
only those, and they take the engine in turn through `~/.colima/mcp-it.lock` (waiting runs queue in
`~/.colima/mcp-it.queue`; a lock whose owner is gone is taken over). The run that started Colima stops it only when
no other run is waiting, otherwise the next run takes over stopping it.

**Never run `colima stop` (or `docker rm` on the `mcp-it-*` containers) by hand while a run may exist**: it cuts the run
in progress. To shut the engine down yourself use `ci/run-local.sh --shutdown`: it waits its turn in the same queue,
removes what dead runs left behind, and stops Colima only when no run is waiting.

Diagnostics: mode `baseline` installs the released baseline (the tag in the workflow) and never upgrades it, so a scenario can
be run against the published code; `MCP_IT_PHPUNIT_ARGS="--filter SomeTest"` limits the suite to one test. They were built
to find why a groupfolder version restore was refused (`Exclusion/MountsTest` now holds it as a regression, see
`EXCLUSION-MATRIX.md`).

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
rolled back; inside a transaction the disable is left to the next use, which refuses the disabled account). Those
cases read the effect on the user's generations; group eligibility over consent and Bearer is proven by
`OAuth/GroupConsumersTest` (ticket 09). Limit: the listener instance and its dependency graph are built before the
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

After the review of ticket 08: a new member who joins and consents after the save's enumeration replays the attempt
(new holder in H1) and ends in a 409 (member set changed); the next save cuts it, and after a regrant its old token,
refresh and unused code stay rejected. A holder kept by a second group whose deletion pre-event lands between the
phases makes the withdrawal unavailable (503, eligibility without the first group unknown, nothing written); after
the real deletion the withdrawal cuts it. With two real sessions (MariaDB, PostgreSQL; SQLite skips, it has no such
window), a pre-event committed by the other session after the read under the mutex makes the conditional lifecycle
write touch no row: replay, 409, the other session's pending_delete intact.

Not covered here (unit only, `GroupGrantsWriterTest::testADeletionBeforeThatLosesItsTransitionToAWithdrawalJudgesTheHoldersAgain`):
a withdrawal committing between a deletion Before's judgement of the holders and its pending_delete transition, which
then fails (the save renewed the gen) and makes the Before judge the holders again. The real server offers no point
between those two steps of the app's own handler; the order is reproduced with the production listener, writer,
cause processor and mappers.

These tests were written while the integration workflow was disabled (Actions minutes, until 1 November): they have
not run yet.

## Resource scope schema (issue #4, B1)

`Policy/ResourceScopeTest.php` checks that `mcp_scope_modes` and `mcp_scope_items` exist and hold no row after the
fresh install and after the upgrade from the baseline release, so every user stays unrestricted; that the BIGINT
`policy_rev` holds the largest counter; and that scopes written by `ResourceScopeMapper` commit or roll back with the
policy writer, seen from a second session, with ids never case-folded. The revision these tests store is arbitrary:
which revision the scope writer records is B2's contract, not the schema's.

## Resource scope writer (issue #4, B2)

`Policy/ResourceScopeWriterTest.php` checks that the container wires the scope mapper, the native enumerator and the
activation barrier into the policy writer and the matrix, and that the container's writer refuses `setResourceScope`
for files while its barrier is closed (B5 opens it), leaving the revision, the events and both tables as they were,
and saves calendar and tasks, whose barrier B6 opened. Behind an open barrier (built by hand, with a stand-in enumerator, since native folders and calendars are not
provisioned here) the saved scope carries `policy_rev` = the committed revision, another session reads the mode and
its items through the one-statement join and the grouped count, no epoch moves, and undo restores the absence of the
mode. The native resource checks themselves are unit contracts; the real Files and CalDAV enumeration belongs to B8.

The candidate listing of Files uses the native API on purpose (contracts F4), with its native side effects, which
these tests neither suppress nor certify: `getUserFolder()` creates the home of a user who never signed in, and
`getDirectoryListing()` (`View::getDirectoryContent` in stable32–35) may create the missing parent folder of a nested
mount (R4 of the plan review) and scans uncached sub-storages. It also materializes every child of the listed folder
before the folder filter and the page of 50, so there is no width cap yet; measuring a folder with thousands of
children belongs to B7, and if that fails the mitigation is a recoverable refusal above a fixed width, not a
cache-based listing (which would lose the mounts and diverge from the native view).

## Calendar and tasks scope (issue #4, B6)

`Policy/CalendarScopeTest.php` creates calendars, task lists and a share of bob's in the container's DAV app (the
share and its withdrawal go through bob's own CalDAV request) and saves the scope with the container's writer, which
checks each calendar against alice's native calendars. With the calendar scope set, only its calendars reach the
calendar tools, and a calendar outside it answers a read, a plan and a confirmed write exactly as a missing calendar
does, with nothing written (S7). The tasks scope is independent: one calendar with VEVENT and VTODO stays a calendar
while leaving the task tools, which are called over HTTP through the MCP endpoint. The shared calendar is kept by the
owner's calendar id through a rename, a withdrawal and a new share, and its recreation under the same URI gets a new
id that the old scope does not reach (S15). The calendar tools need the Calendar app, which the harness does not
install, so they run through the container's `CalendarModule` inside one policy operation, as the endpoint runs them.

## Access inspector (ticket 13; issue #4 A5, B6)

`Exclusion/InspectorTest.php` checks the inspector against what the tools really do: each reason it gives for a path
(direct tag, tagged folder named by its path, share origin for the recipient, unreadable configuration) comes with
`files_read` refusing that path exactly like a missing one, and a path it reports as reached is read; the effective
access of `notes.delete` and `files.create` is whether the endpoint's `tools/list` holds `notes_delete` and
`files_mkdir`, and with the service off every operation is blocked by the service and the endpoint admits nothing; the
container's inspector explains a task list through its scope; and a non-administrator gets a refusal before anything is
inspected on each route.

## Resource scope panel (issue #4, B7)

`Policy/ScopePanelTest.php` sends the requests of the admin panel over HTTP from an administrator session opened like a
browser (`/csrftoken`, its cookies and the requesttoken), so the routes run with the framework's admin and CSRF checks:
show, candidates, save, a stale save refused with 409 and nothing written, the matrix cell and the personal page reading
the saved scope, and undo through the receipt. Files is browsable but refused on save (422 ScopeInactive) while its
barrier is closed. A non-administrator session gets 403 on every route. The test also measures one page of the folder
picker over a folder of 1000 subfolders (F3 of the B2 review) and records it in `observations.txt`.

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
