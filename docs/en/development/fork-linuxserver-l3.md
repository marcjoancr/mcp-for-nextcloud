<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L3 isolated upgrade

Subsequent status: [L4 for the renewed profile](fork-linuxserver-l4-current-candidate.md)
now passes isolated recovery qualification. L5 remains unstarted for that profile;
the phase-end statements below retain their original scope.

This record covers the authorized L3 implementation on devbox on 2026-10-29,
starting from harness revision `017bce7d`. The application candidate remains the
frozen U5 commit `940c3da36f0d9c5310a98d7c3f355d2d99c86d33`, version 2.0.0.
The [reviewed plan](fork-linuxserver-upgrade-recovery-plan.md) and
[L0-U5 inputs](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27) define the boundary.
L4 recovery is not executed. The intended server, published branches and PR #14
are unchanged; there is no push, production deployment or public listener.

## Execution contract

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L3 --inputs ABSOLUTE_INPUT_DIRECTORY \
  --kit ABSOLUTE_KIT_DIRECTORY \
  --manifest-sha256 2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63
```

In addition to L1/L2 inputs, the directory must contain `phpunit.phar`, PHPUnit
10.5.65, SHA-256
`ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835`.
Also retain `php85-tokenizer-8.5.12-r0.apk`, x86_64 from the
[official Alpine v3.24 repository](https://dl-cdn.alpinelinux.org/alpine/v3.24/community/x86_64/php85-tokenizer-8.5.12-r0.apk),
SHA-256 `5020af709d77e877db0bd5ed4b3fa3fa8eed037bfbcc4e5852aa5a72390accd1`.
Both inputs are hash-checked before resource creation. The APK is additionally
signature-verified with the pinned image's Alpine keys and extracted under the
test tools directory. `tokenizer` is loaded only for PHPUnit and its CLI actors
through an explicit test-only INI scan directory; PHP-FPM and the installed package
database are unchanged. No runtime dependency download or egress is enabled.
The core, PostgreSQL, SeaweedFS and five optional-app pins remain unchanged. Mail, Groupfolders and external storage are absent from this profile.

Every L3 invocation builds a fresh L1 baseline and L2/B0, then upgrades that same
synthetic state. It does not restore an old checkpoint or attach arbitrary
containers. The pre-upgrade oracle describes the live state **after** L2's
intentional post-B0 file mutation/deletion. B0 remains immutable.

The candidate is verified outside the scanned apps path and replaces the sole
persistent MCP tree while admission, maintenance and writer gates remain closed.
Native `occ upgrade` runs against the same core and database. Before HTTP probes,
the restarted installation must match the trusted manifest and CLI version.
Native unsigned-app output is retained explicitly; no global integrity bypass or
upstream signature is installed. Deliberately changed downloaded kits and copied
installed snapshots must fail the release verifiers. These negatives do not
modify executing PHP or imply runtime tamper enforcement by `SourceOffer`.

## Assertions and retained state

- Compare MCP schema, migration ledger, raw policy/authentication tables and
  application preferences before any probe writes; compare seeded DAV, Deck,
  Talk and owner file state as well. PostgreSQL bytea streams are compared by
  content, not PHP resource identifiers. New `mail.read` remains denied.
- Exercise retained Basic/Bearer credentials, revoked credentials, Files scope,
  read-only sharing, exclusions and filename policy. Confirm an unchanged
  baseline Notes plan, reject its tampered form without writes, and separately
  exercise changed-state confirmation rejection in the native regression suite.
- Reuse native Tasks, Notes, Deck, backup-configuration and Talk tests through an
  explicit LinuxServer transport. The default Apache transport stays unchanged.
  Additional assertions cover numeric parents, allowed depth 64, rejected depth
  65/cycles/cross-calendar parents, shared backup aliases, ordinary nested names,
  and seeded DAV edits/deletion with an actual mandatory vCard backup.
- Test absent backup settings without inventing a migration write, revisioned
  admin changes, optional copies under native Versions, and refusal of edits
  after Versions is disabled. Record the deliberate final backup policy and
  actual copy payloads separately from pre-upgrade preservation.
- Exercise the actual admin and non-admin personal routes and assets. Discover
  the complete source offer through authenticated MCP, download all five kit
  artifacts without credentials, and verify against the separately trusted
  manifest. Anonymous MCP is denied; disconnected users keep personal source
  access; missing/mismatched offers are unavailable.
- Recreate only the owned Nextcloud container using the same image and volumes,
  initially closed. Recheck installed bytes, persisted state and permissions,
  then return to closed state for coherent B1 capture. No recovery is inferred
  from recreation with existing volumes.

The native PHPUnit process uses the real candidate, database and APIs. Only the
owned app IP is temporarily added to the existing admitted client IP for its
private HTTP requests; the original ACL is restored before final recreation.
TLS verification stays enabled. Native errors, skipped tests, empty suites or
missing completion markers fail the run. Regression accounts are distinct from
the retained L2 accounts. Baseline and final state are compared before intentional
probe mutations, so policy changes made by tests are not mistaken for migration.

B1 uses the same quiescent cross-store capture protocol as B0: database dump,
config/data archives, every S3 object, private bootstrap inputs and an independent
oracle bound by a generation manifest. Capture validates primary file bytes,
policy rows from the actual dump, archive safety and live pending-code expiry.
`finishedAt` records the quiescent snapshot end; a separate `verifiedAt` is taken
after source-kit checks, sealing and complete payload verification. Codes must
still be live at that later instant before trust or acceptance is published.
Fresh final credentials are issued through native services; deliberate
connection revocation is not hidden by altering database expiry or generation.
Credential liveness is asserted at capture, not indefinitely: later L4 runs must
create the version-matched, fresh case generations required by the plan. Retained
checkpoints are evidence, not a promise that their short-lived codes remain usable.

## Evidence and current result

**L3 passed locally** in the post-fix run
`mcp-l3-140d56d2-a971-4e2b-a587-d5523a106f77`, completed
2026-10-29 at 16:05:14 UTC. Native upgrade, preservation, all mandatory probes,
same-image recreation, B1 capture and owned cleanup passed. The native suite
reports **55 tests / 1,144 assertions**, zero errors, failures or skips, in
2 min 50.666 s. The runner records 64 completed checks, including 210 named HTTP
assertions. Peak measured run usage was 5,714,026,496 bytes against the 30 GiB
limit; aggregate allocations remained 4 CPUs and 8 GiB RAM. No ports were
published.

| Accepted checkpoint | B0 | B1 |
| --- | --- | --- |
| Version | 1.2.0 | 2.0.0 |
| Generation | `e5e36a32-eb84-49ce-88fd-9b27cb7669df` | `c7bc8ea4-2c8b-4cb8-b43f-7a31697dad2e` |
| Manifest SHA-256 | `c54bc4b3dbe88b4b6aa7f72901e3121db863f29abd82c149713c24da769ba0f5` | `fb365c8e175304c67071f9e759a489afa905f1c8c8aa8579b2886964a9be082c` |
| Bound payloads / S3 objects | 93 / 87 | 293 / 282 |
| S3 payload bytes | 85,037,764 | 150,836,306 |
| Policy-oracle tables compared with actual dump | 11 | 17 |
| Primary owner files / pending plans / private inputs | 7 / 2 / 4 | 11 / 2 / 4 |
| Verification completed (UTC) | 15:58:23.353 | 16:05:10.557 |
| Minimum pending-code lifetime remaining at verification | 289 s | 288 s |

The wider B1 oracle includes all MCP tables plus the MCP migration/preference
rows; the table-count difference does not claim six new schema migrations.
The pre-upgrade comparison required identical schema and preserved rows before
intentional tests. B1 includes deliberate policy/content changes, refreshed
credentials after the disconnect/reconnect test, both pending plans and all five
source-kit artifacts. B0 remains unchanged.

Post-cleanup verification independently rehashed every B0/B1 payload, all four
private inputs per checkpoint, the complete B1 source kit, primary-file object
bytes and both pending plans. It checked recorded liveness at `verifiedAt`, not
current validity. A separate Docker query confirmed **zero owned containers,
networks and volumes**. This is checkpoint integrity/persistence evidence, not
restoration evidence.

| Private evidence file | SHA-256 |
| --- | --- |
| `runs/<run-id>/result.json` | `ca6cebe478fae118feefe52b98520b9f350cc2d62939857b9b8a1c055de1114d` |
| `runs/<run-id>/native-junit.xml` | `bf148fcd5ef275fdeb04b3a468edeb49e32bbc82cb9d017c95974b64e85e9ec8` |
| `runs/<run-id>/native-tests.log` | `5da61f607318218bf255042a1489ec52bda503735a8169c8aadc5f5e469da39d` |
| `post-cleanup-verification.json` | `c6377439d9671f83d6f7b3a773009a2055773a0d17533e163a5755dc43bb0349` |
| `post-cleanup-resources.json` | `9a3e984527306d6716a381dfcd277182820afd3a6d448c0a22939d0499be3a15` |

These paths are relative to the ignored `mcp/build/linuxserver-l3/` directory.
Native integrity returned exit zero with the explicit message
`mcp: App signature not found, skipping app integrity check`, alongside the
maintenance-mode notice. Exact installed-byte verification and tamper rejection
passed separately, both after upgrade and after recreation. This is an unsigned
fork; no global signature bypass or upstream signature was applied.
Private artifacts are under `mcp/build/linuxserver-l3/runs/<run-id>/`, ignored by
Git. Checkpoints contain ordinary synthetic application secrets and must remain
private. Published documentation contains only sanitized counts, hashes and
outcomes. Run/checkpoint directories are `0700` and private credential inputs
are `0600`. At L3 close, Docker-copied database/config archives retained `0644`
inside their `0700` checkpoint directory; they are not accessible to other host users, but
normalizing all secret-bearing payloads to `0600` was left for L5. The subsequent
[L5 retention audit](fork-linuxserver-l5.md#permission-correction-and-retention)
completed that restriction without changing accepted payload hashes and removed
superseded diagnostic payloads. The owner retains responsibility for keeping and
removing the accepted private campaign artifacts after review.
`private/B1/pre-upgrade.json` and `pre-recreation.json` distinguish the
preserved state from intentional regression mutations; `private/B1/expected.json`
is the final credential/state oracle, separate from B1 payloads.

Diagnostic runs are rejected evidence, even when their earlier stages passed.
Their owned resources were removed. The corrections are to the harness; the
frozen application candidate has not changed:

| Diagnostic failure | Correction and boundary |
| --- | --- |
| DAV bytea comparison used PHP resource identifiers | Read and compare stream bytes; a regression compares independent streams and rejects changed content. |
| Tampered Notes fingerprint expected an RPC error | Assert the actual contract: a fresh approval plan with warnings, unchanged valid fingerprint and no write; then confirm the unchanged baseline plan. |
| App-tree probe used a removed core method and file-based XML parsing under the core's external-entity restriction | Use the native app manager, read manifest bytes with `LIBXML_NONET`, retain the restriction and deduplicate physical aliases. Regression tests cover aliases and disabled external loading. |
| Modern source discovery omitted the required Accept header | Send both supported media types and assert status before decoding JSON. |
| Pinned image lacks tokenizer, preventing PHPUnit startup | Add the separately pinned, signature-verified CLI-only test input described above. This is not production extension qualification. |
| Depth-64 test seeded a parent already at level 64 | Seed 63 levels, accept the 64th and reject the 65th. The hierarchy counts its root as level 1; the candidate behavior and existing unit contract agree. |

The first native-suite execution reached 55 tests / 1,133 assertions and failed
only the incorrect depth-boundary expectation. It is not a passing suite or L3
acceptance. Other diagnostic runs stopped earlier and likewise do not qualify B1.

## Independent review and acceptance corrections

One read-only automated review with a fresh reviewer context found two important
harness defects. Both were reproduced in failing regressions and corrected in one pass:

- Recreated containers now receive the empty effective `/etc/crontabs` inputs
  **before startup**, through the same helper as the baseline. A failed copy
  prevents startup. Native checks require empty effective tables and stopped
  cron before recreation is accepted.
- Checkpoint acceptance now checks actual time after sealing and full verification,
  before writing trust. A clock-controlled regression expires a code during that
  interval and requires rejection with no trust file. Credential lifetimes and
  host time are never changed.

The focused control suite passes **29 tests**, without skips. The pre-fix ninth
native run reached **55 tests / 1,144 assertions**, source checks, recreation and
B1 successfully, with complete cleanup. It is retained as diagnostic evidence,
not final acceptance; the post-fix complete repetition is authoritative.

The first post-review repeat rejected `cron.update` as if it were a job table.
A disposable networkless reproduction confirmed that the image's `crontab`
creates this control file while both effective user tables remain empty. The
native gate now queries `crontab -l` for `abc` and `root`, rejecting either query
failure or nonempty output. Its focused regression covers both conditions; the
failed repeat is excluded from acceptance and its owned cleanup completed.

The reviewer set aside L4 recovery, production update/provider qualification and
the then-running validations. The first two remain explicit future gates; only
completed post-fix validation is eligible for the results below. No minor findings
were deferred by that review. A subsequent filesystem audit records the
archive-mode normalization above as a minor L5 item; directory isolation is in
place, but this does not mark L5 complete.

## Local repository validation

The final code check, `devbox run check` (`check-5.json`), exits zero:

| Gate | Result |
| --- | --- |
| PHP lint | 1,383 files |
| PHP unit tests | 8,697 tests / 74,392 assertions |
| Browser tests | 717 passed |
| PHPStan | Both configured scopes pass |
| Documentation | 176 Markdown files / 115 package sources |
| Package | Build, staged documentation and package verification pass |
| Focused L3 controls | 29 tests, no failures or skips |

The catalog result SHA-256 is
`837007eb285cc8b1fde92a599efd8574b0f01494126ad2063f815eb4da0f9769`.
The exact local harness diff against `017bce7d`, including new files, is retained
privately as `mcp/build/linuxserver-l3/harness.patch`, SHA-256
`8968e1cd29b06d59e87d2eca1c494d1ded28bb4b5146afa13ef443eca1593b32`.
It changes the test harness and command description, not the frozen application
artifact. Translation review remains advisory/pending; no human sign-off is
inferred from the documentation check. After the final evidence report was
written, `docs:check` and `package` passed again (`docs-final.log` and
`package-final.log`), with the same documentation/source counts. The package
correctly reports a dirty development build with its source offer unavailable;
it is not the frozen source kit used for native acceptance and is not a release
or deployment artifact.

## Commit closure review (2026-10-29)

The pre-commit review confirmed that the application/test code still matches the
accepted harness patch above. Rechecking the retained B0/B1 payloads, private
input hashes, source kit and recorded code-liveness timestamps reproduced the
accepted post-cleanup verification byte for byte. The hashes in this report match
the retained result, JUnit, native log and checkpoint manifests. A fresh owned
resource query found zero containers, networks and volumes. No native fixture or
recovery phase was started during this closure review.

A fresh full `devbox run check` passed with the same totals listed above. Its
private `check-closure.json` has SHA-256
`ad8bebacd30fb79ca64dbc541f9334f946543b94484a4dc9d7fced7a8c792916`.
The testing guide now explicitly names both pinned extra inputs: PHPUnit PHAR
and the CLI-only tokenizer APK. No runtime change was required. L4/L5 and all
external/human acceptance gates below remain pending.

## Remaining gates

L4's B1 restoration, failed-upgrade rollback to B0, fail-closed restore negatives
and credential cuts remain unimplemented and unexecuted. L5 regression closure,
production LinuxServer startup/update controls, actual storage/provider
qualification and release decisions remain open. The existing Apache
upgrade/recovery cells were not rerun in this L3 batch; the L5 regression gate
remains separate from the new native LinuxServer results. Positive Mail evidence
belongs
to the separate [U5 Mail cell](upstream-1.2.1-u5-integration.md); this profile only
checks Mail absence and denied effective read.

Public HTTPS, the remaining Inspector encrypted-storage acceptance, ChatGPT and
human review (security/operations, accessibility and translations) remain
pending. Automated private-TLS tests do not close those reviews or authorize a
production deployment.
