<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L2 fixtures and B0 checkpoint

Subsequent status: [L2 for the renewed profile](fork-linuxserver-l2-current-candidate.md)
now passes fixture/B0 qualification. L3 remains unstarted for that profile;
the phase-end statements below retain their original scope.

This record covers the authorized L2 work on devbox on 2026-10-29, based on
`edf2f9cb`. The implementation-stage review fixes passed native capture and cleanup, including
comparison of all eleven policy tables against the actual dump. The closure review
below records a further HTTP assertion correction and the latest qualification
evidence. Historical captures are retained separately. L3 has not started.
The intended server, published branches and PR #14 are unchanged.

## Inputs and execution

The [reviewed plan](fork-linuxserver-upgrade-recovery-plan.md),
[L0-U5 pins](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27) and
[L1 baseline qualification](fork-linuxserver-l1.md) remain authoritative.
The candidate is still `940c3da36f0d9c5310a98d7c3f355d2d99c86d33`; its 2.0.0
package is not installed. MCP 1.2.0 runs with the unchanged NC35.0.2, PostgreSQL
16.15, SeaweedFS 4.48 and five app pins. There is no production connection,
public listener, push, deployment or PR action.

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L2 --inputs ABSOLUTE_INPUT_DIRECTORY \
  --kit ABSOLUTE_KIT_DIRECTORY \
  --manifest-sha256 2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63
```

Each invocation creates a fresh owned fixture, runs L1 qualification, then L2.
It never attaches arbitrary existing containers or imports production data.
The offline planner now advertises L1 and L2; L3-L5 remain rejected.
The command retains the existing resource, runtime, disk and cleanup limits.
The public result distinguishes `baseline-checkpoint` from an upgrade or restore.

## Fixture and checkpoint contract

The baseline adapter loads only installed upstream 1.2.0 classes. It creates
synthetic allowed and denied users, an administrator, group grants, personal
connection preferences, restricted Files scope, filename policy, exclusions,
read-only sharing, Files contents and DAV event/contact/task records.
The admitted private-TLS client tests Basic and Bearer allow/deny behavior and
uses the baseline MCP tools for Notes partial editing, Deck labels, dependencies,
file links and deadlines, and Talk messages. Pending confirmation plans stay in
the private oracle. Native failures are failures, never skipped passing cases.

OAuth credentials use native issuance and revocation. Their actual database
expiry values and policy/client/generation rows are retained privately without
editing them. The pending allowed-user authorization codes must still be live
when capture finishes. A retained checkpoint does not keep a five-minute code
valid indefinitely; later timed recovery cases need a fresh fixture and issuance.

Capture closes the pre-PHP admission gate, enables maintenance and stops Nginx,
PHP-FPM and cron. It checks process and SQL-writer quiescence before and after
capture. Only the required read-only export commands run between those checks:

- PostgreSQL custom-format dump, checked with `pg_restore --list`, plus explicit
  synthetic role/bootstrap requirements. No live PostgreSQL data directory copy.
- Config/apps/themes and local data archives, preserving archived modes and
  numeric ownership. The archive validator rejects traversal, escaping/cyclic
  links, descendants below links, special files and privileged modes. Legitimate
  relative links inside a volume are allowed; MCP app content disallows links.
- Every S3 object payload, independently SHA-256 hashed, with required HTTP/user
  metadata and tags. Paginated listings must agree before and after export.
  Object keys map to hashed local filenames. Multipart ETags are inventory
  metadata, never content hashes. Empty objects, Unicode names and a genuine
  multipart upload are included.
- One generation manifest binds all components, profile and image digests,
  app/core versions, capture interval, ownership and semantic inventory. A
  separately retained trusted manifest digest is required by the verifier.

Files IDs and content hashes are checked against their primary S3 objects.
After capture, controlled CLI writes change one file and delete another; B0 must
still pass the original trusted digest. This proves backup immutability, not
restoration correctness. Restoring and upgrading remain later phases.

## Evidence handling

Local artifacts live under `mcp/build/linuxserver-l2/runs/<run-id>/`, ignored by
Git and protected by a private parent directory. Successful runs retain:

| Item | Purpose |
| --- | --- |
| `result.json`, `resources.json`, `diagnostics.log` | Sanitized phase result and resource/cleanup evidence |
| `B0/` | Database, volume archives, S3 export, semantic inventory and manifest; contains synthetic secrets in ordinary application state |
| `B0-trust.json` | Generation and trusted manifest digest, outside writable B0 |
| `private/expected.json` | Independent expected state, raw synthetic credentials, pending plans, actual expiries and policy snapshot |
| `private/post-b0.json` | Expected-state record after deliberate file mutation/deletion |
| `private/app.env`, `private/pg.env`, `private/s3.json` | Synthetic environment/bootstrap credentials required for a future isolated reconstruction |

These private artifacts are not release assets and must not be committed or
published. Cleanup removes the five containers, four volumes and internal
network. Failed unverified B0 directories and private staging inputs are removed
when resource cleanup completes. An interrupted cleanup remains a failure and
requires an exact ownership audit before another run.

## Validation results

The implementation-stage native run completed at `2026-10-29T11:57:20.037Z` with
`status: passed`, `cleanup: complete`, `checkpointsCreated: true` and
`upgradePerformed: false`. It passed 30 phase checks, including 120 HTTP assertions, then captured and
verified B0 with all application writers stopped. Capture took about 7.2 seconds;
the pending allowed-user authorization codes were live at completion.

| Evidence | Value |
| --- | --- |
| Run | `mcp-l2-e7912d44-6235-4c8d-8404-ec82508c2271` |
| Result SHA-256 | `0d536e619018116c7b5c52cc8f622dccf252f5aa9600caf09febd14b7d13033b` |
| Generation | `148502f8-ff6b-4b7b-a532-c0bc3accfbfb` |
| Trusted B0 manifest SHA-256 | `3bd1595c2bedf6555be88da0167e9d9b5c73a5287f67d9f8f73a7454ceb2811d` |
| Capture interval (UTC) | `11:57:05.166`–`11:57:12.403` on 2026-10-29 |
| Payloads | 93 files, 1,083,713,947 bytes, excluding the manifest |
| S3 | 87 objects, 85,037,764 payload bytes |
| Config archive | 28,081 entries, 34 legitimate internal links; owners `0:0` and `1000:1000` |
| Data archive | 6 entries, no links; owner `1000:1000` |
| Measured/reserved peak | 4,380,946,432 bytes, below the 30 GiB fixture limit |

The trusted digest above is outside the writable backup directory. This generation
corrected the first review findings; the closure repeat below supersedes its
filename-policy qualification. It is historical evidence, not the latest L2 input.
The manifest also binds the separately retained oracle and bootstrap files by
hash. A future consumer must verify those hashes before using the private inputs.
No SQL restore, app replacement or credential recovery is inferred from this run.

The implementation-stage registered `devbox run check` passed after both review fixes:
8,690 PHP tests/74,266 assertions, 717 browser tests, both static-analysis scopes,
1,370 PHP syntax checks, 29 documentation controls, 10 client controls and 11
release controls. Documentation validates 175 Markdown files and 115 package
sources; all 100 translations remain review-required. The harness includes 24
Node controls and two Python archive test methods with adversarial subcases.
That log is `mcp/build/linuxserver-l2/check-4-review-fixes.log`, SHA-256
`94c00d069f558a6ce3cf55503a7d0e2b47620082b47483cc3a44310e3f46d157`.
The generated 2.0.0 package is a dirty development build: its source offer is
explicitly unavailable. It is not a new release kit or the candidate used by L2.
Earlier full checks are historical evidence; the closure review below records subsequent validation.

Unit controls reject missing/mixed payloads, changed bytes, an incorrect trusted
digest, an incorrect generation, unlisted files and symlink payloads. Volume
archive tests cover legitimate links/owners and unsafe paths, link chains and
special entries. The final post-cleanup record is
`mcp/build/linuxserver-l2/post-cleanup-review-fixes.json`, SHA-256
`eb32604771c048ea5c740dd793c08cab210d14f25b4fa027704ca99826eb13e9`.
Fresh Docker label audits found no remaining fixture containers, volumes or
networks. Local private artifacts remain available for subsequent review.

Development attempts remain failed evidence: the first stopped with a generic
Files exception; a subsequent attempt identified a removed sharing constant;
a later attempt reached HTTP and exposed a wrong assumption about `files_read`
response encoding. Further attempts found a missing synthetic Talk reply grant, a disk scan racing
an intentional container restart, and an S3 integer/string comparison mismatch.
The adapter now uses the baseline operation catalog; a tested sampling gate
serializes disk scans with intentional lifecycle changes without ignoring errors.
The pinned SDK's XML parser returns S3 `long` sizes as strings. Run
`mcp-l2-f9d29bf0-f88a-4e86-9593-3fdc6ffb0444` confirmed identical keys, ETags and
sizes with `int` versus `string` types. An exact nonnegative-integer normalizer
fixes this and rejects malformed or overflowing values. No object discrepancy
was bypassed. The earlier sharing/response defects use the pinned public API
and actual text/metadata response contract. The first generic Files exception
was not reproduced in subsequent successful seeding; its limited diagnostic
does not establish a root cause. All seven failed development attempts report
complete resource cleanup and retained no unverified checkpoint. Early failed
results incorrectly listed only L3-L5 as pending; the runner now retains L2 in
that list until B0 and post-capture assertions succeed. The architecture guard
allows only five exact quoted baseline class IDs in the isolated seeder; no
whole-file or namespace exclusion was introduced. No application
production code was changed to bypass a test.

## Independent automated review

The one independent read-only review identified two Important defects despite
passing pre-review tests. The archive validator normalized `..` before following
intermediate symlinks, accepting `root -> .` and `escape -> root/../outside`.
The private policy oracle was recorded before a final repeated deny, whose mutex
acquisition advanced `lock_seq`; read-only `pg_restore` inspection confirmed the
captured counter differed by one. Neither issue is dismissed as a test skip.

The correction pass resolves symlink targets component by component, and captures
policy rows after the final mutation. The runner now decodes the selected tables
from the actual custom-format dump without restoring it, compares every recorded
row against the independent oracle, and verifies that the transferred dump bytes
match the original. Regression tests cover the escaping link chain, mutex-counter
difference, missing/duplicate/truncated COPY blocks, quoted columns, nulls and
escaped Unicode. The old B0 fails the new complete policy check; checking its ten
unchanged tables separately was diagnostic only, not acceptance. That original
run, `mcp-l2-cbeb1b66-e773-444a-bff4-c68c63386243`, remains intact with a separate
`review-status.json` marking it unaccepted. The corrected run passes all eleven
tables, transferred-dump integrity and the stricter archive validator. A second
read-only verification after cleanup checks the retained B0, four private-input
hashes, all eleven dump tables and both volume archives without restoring them.
There was no second reviewer or claim of human approval.

The review did not qualify L3-L5, production compatibility/S3/startup hooks,
public HTTPS, Inspector, ChatGPT, translations or human acceptance; these remain
separate gates. The executor independently audits fresh Docker cleanup. Artifact
validation assumes private run-owned storage, not a hostile concurrent writer
with the same local account. No broader tamper-resistance claim is made.

## Closure review (2026-10-29)

The requested pre-commit review found a false-positive risk in the HTTP probe:
any non-200 response, JSON-RPC error or tool error could count as a policy refusal.
An outage, invalid arguments or an unexpected internal failure could therefore
satisfy a negative case. The baseline has no stable machine-readable tool error
code, so the pinned English refusal text is part of this baseline-only adapter.

The probe now requires HTTP 200, a valid matching JSON-RPC result and the exact
expected refusal for hidden/out-of-scope files, read-only writes and filename
policy. Server failures, malformed envelopes, different refusals and unexpected
tool errors fail the probe. The independent admission check still requires
401/403 for denied users. Seven focused response tests cover this boundary;
six failed against the original classifier before the correction. The complete
focused LinuxServer/architecture selection then passed 36 tests/2,300 assertions.
These tests exercise the classifier used by the native client, without a mock
server or changes to production capability code.

The stricter native repeat, `mcp-l2-68ad1cf2-f366-427f-baf4-e1d60084d5d6`,
correctly failed the filename-policy case and completed cleanup without retaining
B0. The original `.exe` request hit `files_create`'s built-in text-extension
validation before the configured filename policy. The fixture now requests `.csv`,
which the pinned tool accepts but the fixture's `txt`/`md` allowlist denies.
The earlier passing native generation therefore does not establish this policy
assertion. Run `mcp-l2-575e83db-bd74-4b4a-af90-42c2d1a0458d` passed the exact
refusal and capture, but the independent retained-artifact check then rejected
its incomplete private oracle. It is not an accepted B0.

The retained oracle had lost the HTTP-created Notes/Deck/Talk readbacks and
pending confirmation plans. A minimal isolated reproduction confirmed that
Docker copied the input as UID 1000, mode 0600; the root client could read it but
its attempt to overwrite it in sticky `/tmp` returned `false` with a permission
warning. The probe ignored that return value and the successful client's stderr
was not retained. This was an evidence persistence defect, not a database loss.

The HTTP probe now writes a distinct mode-0600 output, checks the complete write
and reports its digest. The runner verifies that digest after transfer, requires
all HTTP fixture fields and both pending plans, and checks they survive the
subsequent credential refresh unchanged. Native oracle writes also fail on an
incomplete write. A regression control rejects missing fields/plans; the minimal
I/O reproduction is retained in `closure-io-repro/result.json`. Separate
`review-status.json` records withdraw acceptance from the two affected captures
without changing their original results. Only the final capture and independent
post-cleanup verification below can close L2.

The first closure full registered check (before the oracle persistence fix) exited zero: **8,697 PHP tests/74,291
assertions**, **717 browser tests**, both static-analysis scopes, 1,372 PHP syntax
checks, documentation and packaging. Its log is
`mcp/build/linuxserver-l2/closure-check.log`, SHA-256
`af366bde617204a8a727dd7b8ee03b7d02cb16180088d5eb8c5143154466974c`.
The final report edits receive separate documentation/package verification.
The retained development package still has no source offer; the frozen U5 kit
remains unchanged.

### Accepted closure capture

The final native run exited zero at `2026-10-29T13:40:40.603Z` with 30 phase checks
and 120 HTTP assertions. Independent read-only verification after cleanup also
passed: all 93 payloads, four private-input hashes, eleven policy tables and both
volume archives are intact. The retained oracle now includes every HTTP fixture
field and the pending Notes and Deck plans. No fixture containers, volumes or
networks remained. No database restore or candidate replacement was performed.

| Final evidence | Value |
| --- | --- |
| Accepted run | `mcp-l2-387f06b3-f62d-4f31-93fb-86908e790bf6` |
| Generation | `71c4f327-e677-4a44-9e2f-da482f3b95e5` |
| Trusted B0 manifest SHA-256 | `0cc2f619aab0ceb0050a8dc415b3413dedcf299c376bd48ca5ffc6f2f36a165b` |
| Native result SHA-256 | `075cf54a523ffc4391bd498f5e90980c0ac697b389d09acab6a1223b71d74621` |
| Capture interval | `2026-10-29T13:40:17.562Z` to `2026-10-29T13:40:31.440Z` |
| Payloads | 93 files, 1,083,726,560 bytes |
| S3 objects | 87 |
| Measured/reserved peak | 4,381,057,024 bytes |
| Post-cleanup verification | `mcp/build/linuxserver-l2/closure-evidence.json` |
| Verification SHA-256 | `e64be873b6d4ac886450725c8215503d2a8afe867778eabffd53b36cf3c0fd90` |

This generation supersedes all earlier L2 captures as the accepted local input.
It satisfies L2 only; expired credentials still require fresh issuance for later
timed recovery cases, and this does not establish restoration correctness.

The final registered full check after the persistence fix also exited zero:
**8,697 PHP tests/74,291 assertions**, **717 browser tests**, both static-analysis
scopes, 1,372 PHP syntax checks, documentation and packaging. The harness now
includes 25 Node controls. Its log is
`mcp/build/linuxserver-l2/closure-check-oracle.log`, SHA-256
`a17595fc899040d7f0df1117a74a34012207a3999055c311e39bfd24058bafec`. Documentation and packaging are checked again after the final report
updates. All 100 translations remain review-required. The closure commit contains
only L2 source/tests and documentation; private evidence remains ignored by Git.

## Remaining gates

L3 replacement, L4 restore/credential-cut proof and L5 regression closure remain
unexecuted. The stored B0 is not a qualified production backup. Later phases
must account for expired private certificates/authorization codes and regenerate
runtime-only isolation inputs rather than treating old credentials as live.
Intended-host update writers, backup/S3 semantics and source-delivery acceptance
remain pending. Public HTTPS, Inspector's prior 14/15/encrypted-storage finding,
ChatGPT and human reviewers remain pending; translations remain review-required.
