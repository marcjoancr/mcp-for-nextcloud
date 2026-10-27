<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Native integration tests

These tests load the installed Nextcloud core, not unit doubles. Run an explicit
cell with `composer --working-dir=mcp test:integration -- 35 sqlite fresh`.
Prefix the three cell arguments with `--plan` to inspect pins without starting
containers. `ci/matrix.php --matrix` lists all 14 candidate cells. Ordinary
`composer check` excludes only this native suite and never starts Docker.

Docker and Linux `ip`/`flock`, PHP, Composer, the locked Node/npm versions, curl and OpenSSL
must already be available. The runner does not manage the shared daemon. Each run
has unique labeled containers/network, a non-overlapping private subnet and no
published ports. Its exit trap deletes only matching resources, including their
anonymous disposable volumes. A failed required app install fails the run. Candidate packaging is serialized with
`flock` because npm dependencies and generated assets belong to this checkout.
Run ordinary checks separately from native packaging; their build steps do not join
this native-runner lock.

Artifacts live under ignored `mcp/build/integration/<run>/mcp-it/artifacts`:
package hashes, runtime identities, JUnit and sanitized logs. Private raw step/SQL
logs and disposable credentials stay outside this upload directory. Never upload
its parent. Missing or empty tests fail. Database-only and baseline-only tests
explicitly explain their inapplicability; missing applications are failures.

Guests v4.10.1 is pinned and unpacked for every cell; its native security tests
enable it only for their own scenarios and restore the prior configuration.
They cover app-password and OAuth admission, module restrictions, hidden external
storage, group-only sharing and sessionless checkout-owner restrictions.

The native suites run in order: `compat`, integration, then `last` (global epoch).
Two independent database sessions, real core events and Basic/Bearer requests are
part of the evidence. The shared `tests/Support/Access/Policy/PolicyServices.php`
file composes real owners only; the unit bootstrap and its doubles are never loaded.

`ci/legacy-policy.php` and the corresponding assertions in
`Compat/UpgradeSnapshotTest.php` deliberately name the released 1.0.8 layout.
The probe runs in a separate process and checks each loaded class's source. These
are exact baseline-fixture exceptions to retired-name scans, not candidate loaders.
`ci/replacement.php` seeds the pinned baseline layout, then verifies unchanged
database state and bearer access after Apache restart. `upgrade-local` uses the
public fork candidate `14a76df26817fb8a4540eb9c9d1d0043e3e87506` (1.1.2) and
its capability owners. Its baseline must be reachable from candidate history; no
upstream fetch can substitute for a missing fork baseline. This replaces the old
local-only 1.0.8 preparation commit, which was absent from public clones. The
separate `upgrade` cells retain the real 1.0.8 schema/OAuth migration coverage. `upgrade-upstream` installs 1.1.0 and runs `occ upgrade` to
the candidate 2.0.0; `upgrade-upstream-111` separately upgrades upstream 1.1.1.
`upgrade-upstream-120` pins upstream 1.2.0 and uses the same state/credential
probe on NC35/MariaDB. It does not replace the LinuxServer/Nginx rehearsal.
`upgrade-upstream-112` installs the pinned upstream 1.1.2 and runs a real
`occ upgrade` to the newer candidate. The fixture verifies policy, scopes, clients,
credentials and migration state. The historical same-version replacement remains
recorded at revision `5ff6672`; it is not a valid mode for a 2.0.0 candidate and has
not been retained as a bypass. NC32 native cells likewise belong to that historical
revision, whose manifest admitted NC32; current candidate cells start at NC33.

Before installing either app package, the harness disables the App Store on the
throwaway Nextcloud instance and verifies the boolean setting. `occ upgrade`
still runs the real local migrations; its subsequent App Store check cannot
replace the pinned candidate with a newer published release. Archive hashes and
exact installed-version assertions remain required. This disposable test setting
does not qualify production update controls, which remain a separate F3 review.

The current matrix still uses the previously qualified image pins. It does not
certify newer security patches or an upgrade from installed upstream 1.2.0. F3/F4
must bind final source and artifact evidence to the approved release profiles and
the intended host before deployment. Deck is pinned to 1.16.8/1.17.7/1.18.5/1.19.0 for
NC32/33/34/35; Contacts is pinned to 8.3.21 for NC32 and 8.9.2 for NC33–35.
Archive hashes and identity/version/server-bound checks precede provisioning.
The three dependency tools are present only for verified NC34/35 Deck APIs;
unsupported/disabled paths must remain covered as well as supported behavior.

CLI probes require explicit completion: Nextcloud can log an exception and exit
with status zero, so `Support/ProbeCompletion.php` makes an incomplete probe fail.
Replacement verification removes only its own seeded user/client/scope after
checking their preservation, before the shared suite's fresh-fixture assertions.

The NC35/SQLite/fresh cell also rehearses coordinated recovery after the suite.
While maintenance remains enabled, it copies the actual SQLite database and app,
revokes credentials after the backup, restores both, restarts the host to clear
caches, invalidates restored credentials and explicitly disconnects the fixture
account. Only after checking the restored restrictions does it allow a new
connection; old access/refresh tokens and pending codes stay invalid. It leaves
MCP closed. Backups and fixture secrets remain private inside the disposable
container, never uploaded. This covers a controlled same-version restore, not a
file-only downgrade or every operator's backup system. Nextcloud app passwords
have their own lifecycle and still need a separate recovery review.

During pre-version harness qualification only, the explicit environment variable
`MCP_IT_QUALIFICATION_VERSION=1.1.0` changes the extracted candidate copy and writes
`qualification.txt`. Such a run cannot qualify the final package. Final release
validation must omit it and repeat every cell. Filtered diagnostic PHPUnit runs
(`MCP_IT_PHPUNIT_ARGS`) also do not count as full-cell evidence.

See [testing](../../../docs/en/development/testing.md) for the validation layers
and [upgrade and recovery](../../../docs/en/installation.md#upgrade-and-recovery)
for coordinated restoration and credential invalidation. Record the exact source,
package hash, selected cell, exit status and inapplicable skips with each run;
filtered runs and evidence from an older artifact do not qualify a new candidate.
