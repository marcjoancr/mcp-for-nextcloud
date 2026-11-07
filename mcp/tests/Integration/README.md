<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Native integration tests

These tests load the installed Nextcloud core, not unit doubles. Run an explicit
cell with `composer --working-dir=mcp test:integration -- 35 sqlite fresh`.
Prefix the three cell arguments with `--plan` to inspect pins without starting
containers. Add `--mail` for the disposable NC35/MariaDB cell; it pins and
provisions both Mail with a private IMAP fixture and Forms with the native app.
`ci/matrix.php --matrix` lists all 15 declared base cells. Ordinary
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
public fork candidate `e55e1b456c06b275f7ee2a32c4262f831ddcc772` (1.1.2) and
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
does not qualify production update controls; test the serving and maintenance
roles separately as described in [LinuxServer testing](../../../docs/en/development/linuxserver-testing.md).

The matrix pins NC33.0.10, NC34.0.5 and NC35.0.2 amd64 images. Pin validation
alone does not qualify a candidate. Run the selected cells, including the explicit
upgrade origins, against the reviewed artifact and retain their results. Bind
release evidence to the exact source, package and target environments before deployment. Deck is pinned to 1.16.8/1.17.7/1.18.5/1.19.0 for
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

## Frozen candidate acceptance

Checkout commands above build a development package. To qualify one reviewed
runtime/source kit instead, place its explicit input before the cell arguments:

```sh
composer --working-dir=mcp test:integration -- --plan --candidate-kit /absolute/kit TRUSTED_MANIFEST_SHA256 35 sqlite fresh
composer --working-dir=mcp test:integration -- --candidate-kit /absolute/kit TRUSTED_MANIFEST_SHA256 35 sqlite fresh
composer --working-dir=mcp test:integration -- --plan --candidate-kit /absolute/kit TRUSTED_MANIFEST_SHA256 --baseline-package /absolute/baseline.tar.gz TRUSTED_BASELINE_SHA256 35 mariadb upgrade-upstream-120
```

`--plan` validates the kit's exact inventory, archive paths/types, source identity
and extracted runtime bytes without Docker calls. Invalid or empty kit arguments
fail; they never select checkout packaging. Upgrades in this mode require a
retained baseline archive and its separately reviewed digest, checked against the
tracked `ci/baseline-packages.json` mode/commit/hash mapping; there is no network
fetch/rebuild fallback. Its association with the selected historical commit comes
from that reviewed build provenance, not an identity field invented for older packages.
The [tracked baseline mapping](ci/baseline-packages.json) owns the admitted
mode/commit/archive combinations; the separately trusted kit manifest identifies
the candidate. Neither filename nor version equality is proof of file identity.

A run copies the verified kit into its own directory and records candidate SHA,
manifest/archive digests, runner SHA, dirty state and runner-file hashes separately.
After install/upgrade it copies the owned container's actual application tree for
read-only comparison with the kit, failing on missing, changed, extra or linked
files before fixture/test execution. The source-offer runner uses the same gate.
The measured inventory is retained in `installed-candidate.json`; version equality
alone is not artifact identity. This does not certify a production instance.

Checkout-only `upgrade-local` reconstruction needs `MCP_IT_BASELINE_NODE_BIN`
pointing to the exact historical Node directory (24.21.0 for the pinned 1.1.2
origin). The integration workflow prepares that separate runtime. Registry cache
fetches use the current patched runtime and locked npm URLs; historical npm
installation/build then use offline mode, and Composer networking is disabled.
Missing cached inputs fail rather than weakening engines or fetching with the old
runtime. Final candidate acceptance should use the reviewed retained baseline
option above; historical reconstruction is not a claim that its old tooling is
security-current. The private-TLS Inspector fixture is also still historical.

## LinuxServer baseline admission

`upgrade-upstream-121` selects the published 1.2.1 archive on NC35/MariaDB.
It requires `--candidate-kit` and `--baseline-package`. The default CI matrix
continues to cover its 15 existing cells. The LinuxServer origin uses the
separate [`ci/linuxserver/profile-121.json`](ci/linuxserver/profile-121.json) profile.
Its `baseline` object owns the source commit, published archive SHA-256, byte/file
counts and inventory digest. The standard [`profile.json`](ci/linuxserver/profile.json)
retains the 1.2.0 origin. These frozen profiles do not follow the current checkout.

For the retained baseline archive, construct `baseline-inventory.json` by keying
each regular member by its full `mcp/` path, sorting paths lexically, and storing
fields `sha256` then `bytes` for each entry. Serialize with Python
`json.dumps(mapping, indent=2)` followed by one LF in UTF-8. The measured archive,
supplied inventory and profile digest must all agree. Download the published
runtime release asset for the pinned version, not GitHub's source-code archive.

Run these commands from the repository root with the pinned Node runtime and the
verified input directory. That directory contains `mcp-1.2.1.tar.gz` and
`baseline-inventory.json` with the file sizes and SHA-256 values required by the profile. All paths below
are absolute. Use `--fixture-profile` with Composer to keep stdout valid JSON.

```sh
kit=/absolute/path/to/efb27979b6a90122ce45a6a509e52390030f7ed9
inputs=/absolute/path/to/verified-121-inputs
profile="$PWD/mcp/tests/Integration/ci/linuxserver/profile-121.json"
kit_sha=c59d4a2547b9ddc8685b6e54e12c647fccadd419264df00116d9184836ba3289
archive_sha=a3531836a0373df526908b5969370aac272e3561a07ae7c62e329cfc1358ef79
composer --working-dir=mcp test:integration -- --plan \
  --candidate-kit "$kit" "$kit_sha" --baseline-package "$inputs/mcp-1.2.1.tar.gz" "$archive_sha" \
  35 mariadb upgrade-upstream-121
composer --working-dir=mcp test:integration:linuxserver -- --plan \
  --fixture-profile "$profile" --kit "$kit" --manifest-sha256 "$kit_sha"
composer --working-dir=mcp test:integration:linuxserver -- --check-baseline --inputs "$inputs" \
  --fixture-profile "$profile" --kit "$kit" --manifest-sha256 "$kit_sha"
```

The LinuxServer plan binds the profile to the kit. `--check-baseline` then stages
a private archive copy, checks its size and SHA-256, extracts it and hashes the
regular files. It compares both the measured inventory and the supplied inventory
file with the reviewed digest. The command removes its temporary directory on
success and failure. The executable fixture uses this same check before Docker
preflight and uses the staged bytes for installation. For the 1.2.1 rehearsal, run L3 with this
profile and its pinned inputs. L3 seeds the 1.2.1 data, captures B0, upgrades,
recreates the app and captures B1. L4 takes
the same profile and kit, plus the checkpoint directory and both generation
and manifest digest pairs from that L3 run. It verifies the bindings before restoring.
After completing the recovery cases, it exercises the update policy on a separate
restore of B1 using the closed, disposable instance. Prepare the additional catalog
inputs using [the offline input recipe](ci/linuxserver/protection-inputs.py).
Keep the private checkpoints and synthetic credentials outside Git. From the same
shell, with the five companion app archives and native test tools present:

```sh
python3 mcp/tests/Integration/ci/linuxserver/protection-inputs.py "$inputs"
composer --working-dir=mcp test:integration:linuxserver -- --phase L3 --inputs "$inputs" \
  --fixture-profile "$profile" --kit "$kit" --manifest-sha256 "$kit_sha"
# Supply the trusted evidence directory, generations and hashes from this L3 result.
composer --working-dir=mcp test:integration:linuxserver -- --phase L4 --inputs "$inputs" \
  --fixture-profile "$profile" --kit "$kit" --manifest-sha256 "$kit_sha" \
  --checkpoints "$l3_evidence" --b0-generation "$b0_generation" --b0-sha256 "$b0_sha" \
  --b1-generation "$b1_generation" --b1-sha256 "$b1_sha"
```

L4 records recovery cases and protection checks independently. An unresolved
update-control check makes the overall command exit nonzero. Record both kinds
of result; passed recovery subcases do not override a failed protection check.

For a B1 checkpoint from this 1.2.1 origin, `--check-mount` reproduces the
single-directory bypass and `--check-operator-paths` qualifies the separate
serving and maintenance roles. Both take the same profile, kit, inputs and two
checkpoint trust pairs as L4. The modes are mutually exclusive. The first must
report the substitution and exit nonzero. The operator trial must pass.
See [update controls and operator-owned paths](../../../docs/en/development/linuxserver-testing.md#update-controls-and-operator-owned-paths)
for the bypass mechanism and the separate serving/maintenance layout.

Use `--host-startup-inputs /absolute/private/inputs` only with
`--check-operator-paths` to replay the fixed OCR startup profile. Supply
`hook.sh` and the two signed APK repository views as
`{main,community}/x86_64/{APKINDEX.tar.gz,*.apk}`. The runner stages only
verified bytes for the fixed hook, indexes and archives. Docker mounts the
staged mirror read-only and the fixture retains its internal network.
[Public package inventory](ci/linuxserver/alpine-ocr-packages.json) · [LinuxServer procedure and limits](../../../docs/en/development/linuxserver-testing.md)
