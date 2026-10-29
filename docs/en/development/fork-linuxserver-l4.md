<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# LinuxServer L4 isolated restore and recovery

This record covers the authorized L4 work on devbox on 2026-10-29, starting at
harness revision `8cdc71fd`. The application remains the frozen U5 candidate
`940c3da36f0d9c5310a98d7c3f355d2d99c86d33`, version 2.0.0, on the unchanged
[L0-U5 profile](fork-linuxserver-l0.md#l0-u5-renewal-2026-10-27).
The [reviewed L4 plan](fork-linuxserver-upgrade-recovery-plan.md#l4--restore-200-and-roll-back-a-failed-upgrade)
defines acceptance. L5 is not started. The intended server, published branches
and PR #14 are unchanged. There is no push, deployment or public listener.

## Inputs and execution

L4 consumes the retained **accepted L3** checkpoints, not arbitrary containers or
production backups. Generation IDs and trusted digests are supplied independently
of each backup manifest. The runner verifies all payloads, private oracle bindings,
archive paths/types, profile identity, object inventory and candidate source kit
before destroying any storage. It copies the accepted inputs into its private run
and verifies both copies and originals again at completion.

```sh
composer --working-dir=mcp test:integration:linuxserver -- \
  --phase L4 --inputs ABSOLUTE_INPUT_DIRECTORY \
  --kit ABSOLUTE_KIT_DIRECTORY \
  --manifest-sha256 2dd804e59c35f9784113bde8c1b1c579345965c3cfd8c157e06b0842b0aa1f63 \
  --checkpoints ABSOLUTE_ACCEPTED_L3_RUN_DIRECTORY \
  --b0-generation e5e36a32-eb84-49ce-88fd-9b27cb7669df \
  --b0-sha256 c54bc4b3dbe88b4b6aa7f72901e3121db863f29abd82c149713c24da769ba0f5 \
  --b1-generation c7bc8ea4-2c8b-4cb8-b43f-7a31697dad2e \
  --b1-sha256 fb365c8e175304c67071f9e759a489afa905f1c8c8aa8579b2886964a9be082c
```

The original directory is
`mcp/build/linuxserver-l3/runs/mcp-l3-140d56d2-a971-4e2b-a587-d5523a106f77`.
Its private authentication material is necessary and must not be published.
Original pending codes are not used as evidence of live revocation: each case
refreshes credentials and captures a separately named, version-matched generation.
The runner rejects a proof with less than 30 seconds remaining on any tested
access token, refresh token or pending code, including after each HTTP assertion.

## Restore protocol

Each case starts from an independently restored original, captures its own
coherent checkpoint, changes and deletes a file, changes a DAV record and revokes
the owner's credentials. R1 restores B1 with 2.0.0. R0 has two distinct stops:
immediately after app replacement, and after `occ upgrade` completes before
reopening. Both R0 cases recover the entire 1.2.0 generation. The migration trees
are identical; this does not simulate interruption inside an SQL migration.

There is one active storage generation at a time. Every exact container/volume
ID is checked against the run journal and ownership label before retirement.
The next generation has four fresh volumes, an empty database and an empty bucket.
A temporary import container has no Nginx, PHP-FPM or cron process. Before normal
startup, extraction readback must match archive bytes, numeric UID/GID, modes and
links; `pg_restore --exit-on-error --single-transaction` must complete; S3 object
bytes, metadata, tags and inventory must match; and app bytes must match the
frozen baseline or candidate. Multipart ETags are recorded separately from the
SHA-256 content proof. No PostgreSQL data directory is copied.

The original bootstrap descriptor names `nextcloud` as the database owner and
bootstrap superuser. Native restore exposed an additional requirement:
Nextcloud's installer created the `oc_admin` application login and table owner.
The runner recreates that fixed login with its password from the same verified
private config, without logging it or placing it in command arguments. It retains
original table ownership and verifies the application role has no superuser,
create-role or create-database privilege. Import failures remain fatal; ownership
is not discarded with `--no-owner`.

Only fixture transport configuration is adapted: a new private CA/server
certificate and the current admitted client's private IP. The original one-day
certificate can expire between phases. The application configuration and Nextcloud
instance secret are preserved; this is not byte-identical activation of old TLS
configuration and does not qualify public HTTPS. Empty effective crontabs are
installed before normal container startup, cron is stopped, and Nginx admission
stays closed through restoration. Candidate source-kit files are restored from B1
and verified separately from the installed app.

## Recovery safety and proof boundaries

Before disabling maintenance, native probes compare the restored business,
file, policy and authentication state with the case oracle. This demonstrates
that pre-revocation state really returned. They then perform the actual globally
enabled → disabled transition, verify an advanced auth epoch and disconnect every
synthetic account. Eligibility, grants, scopes and the instance secret must remain
unchanged. `maintenance:data-fingerprint` must change the stored value.

In probe-only state, Basic and Bearer access must fail while MCP is globally
closed. Enabling MCP alone must still deny the disconnected owner. After explicitly
reconnecting only that owner, historical pre- and post-snapshot access tokens,
refresh tokens and pending codes must fail, while fresh OAuth can read an allowed
file and remains subject to exclusions, scope and filename policy. Other accounts
stay disconnected. This is MCP policy enforcement, not global revocation of
Nextcloud app passwords.

Recovery requires fresh confirmation previews. An unchanged Notes fingerprint
can still match: it is a stateless HMAC, not a recovery epoch. Separate checks
reject tampered fingerprints, changed arguments and stale state without writes;
a fresh approved plan must work. The procedure does not rotate the instance secret
or claim blanket invalidation of old fingerprints. MCP and maintenance/admission
are closed again after each successful case.

Host controls reject corrupt archives, missing object payloads, foreign generation
or profile identity, wrong candidate digest, traversal/escaping links and foreign
resource IDs before mutation. Runtime faults separately remove a restored policy
table, omit an S3 payload and stop S3 during import. Only a failure at the intended
validation boundary counts. The normal app process must never start; the private
client must receive connection refusal, not a fabricated successful 503 check.

The recorded `restoreSeconds` runs from completion of trusted-input verification
through fresh-storage import and closed core readiness. `checkpointAgeSeconds`
measures snapshot completion to that restore start. Neither includes the earlier
case preparation/capture nor the later credential/HTTP assertions; the run's
start/end timestamps describe the complete rehearsal.

## Accepted execution and validation

The final execution **passed**, with **72 recorded stages**, complete cleanup and
no remaining owned containers, networks or volumes. It ran from
`2026-10-29T18:05:11.824Z` to `2026-10-29T18:14:39.060Z`.
Evidence is retained privately under:

```text
mcp/build/linuxserver-l4/runs/mcp-l4-ee13f47c-50d2-4648-922d-0a64c865bb34/
```

| Case | Restored MCP | Restore seconds | Checkpoint age seconds | Minimum live-credential margin | Reconnection probe checks |
| --- | --- | --- | --- | --- | --- |
| `B1-r1` | 2.0.0 | 39.297 | 17.029 | 209 s | 87 |
| `B0-r0-replaced` | 1.2.0 | 31.607 | 10.879 | 241 s | 87 |
| `B0-r0-upgraded` | 1.2.0 | 38.701 | 32.411 | 207 s | 87 |

Each case additionally passed two globally-closed and two disconnected HTTP
checks: **273 checks across the three HTTP probe sets**, including expiry guards.
The normal app process was never started in the three failed-import cases.
Candidate source delivery served all five files with matching hashes over private
CA-verified HTTPS. The denied client remained excluded. Peak measured aggregate
fixture storage was **9,567,731,712 bytes**, within the 30 GiB limit; the fixed
4-CPU/8-GiB allocation and one-hour deadline remained in force.

| Case generation | Generation ID | Trusted manifest SHA-256 |
| --- | --- | --- |
| `B1-r1` | `4424ff0b-7da9-41f6-b744-5a1050863404` | `4adc8b39dba8076bd72dab17f9d73e18b90209cd25259973b0c6c806069c4da4` |
| `B0-r0-replaced` | `68b14314-ed5c-4929-9869-681961ba8da3` | `1d175d80a526d3372b3bcd523a8ac6c334b71f23cf104b8d72b3ea87e2d6a1df` |
| `B0-r0-upgraded` | `f3569e6d-64f8-42b7-b81f-290613c9d725` | `bde5a3e816b33288d7ae619ccee504e4f5fbf81c7fb2a6d6bf521d0117f21df1` |

Post-cleanup verification passed for original B0/B1 and all three case generations:
**93 / 293 / 293 / 93 / 93 payloads**, respectively, with four bound private input
files each. The **26** retained private files are `0600` under `0700` private
directories. Original B0/B1 hashes remain exactly those in the invocation above.
The existing archive-mode normalization item remains in L5: Docker-copied
archives can be `0644` inside their private `0700` checkpoint directories.

- Final `result.json` SHA-256:
  `7d0b92a13a9fed6a6bc1c050d704a6117bbd90ff603e5b14bf8d8026ea4a6216`.
- Final `resources.json` SHA-256:
  `2a88fc01ad1a8850911a65b27e9be17540b9d9ef47bff5d30e04a6a408dfba84`.
- Changed runner/test input inventory: `mcp/build/linuxserver-l4/review-runner-files.json`,
  **23 files**, SHA-256 `b207ce9951cfa5d78c8867c8da5ca9812bc9cea0eb64bc016c86523b734a3562`.
  This binds the tested runner/test bytes on top of `8cdc71fd`; documentation
  changes are excluded from that code inventory. The application candidate and its
  source kit are unchanged.
- Retained-artifact verification: `mcp/build/linuxserver-l4/review-retained-verification.json`.

The final `devbox run check` passed: **8,707 PHP tests / 74,469 assertions**,
**717 browser tests**, **1,390 PHP syntax checks**, both fixed PHPStan scopes,
**177 Markdown documents** and **115 package documentation sources**. The seven
recovery-control Node tests are included through the harness suite. The focused
architecture/harness/tool-response/effects run passed **44 tests / 2,327 assertions**.
Logs are `mcp/build/linuxserver-l4/review-final-check.log`, `review-native.log`
and `review-effects-green.log`. Translation provenance is valid;
**100 translations remain review-required**, not human-approved.

Packaging succeeds with the explicit expected warning
`Dirty development build: source offer unavailable.` This development package is
not a published release or the qualified source offer. The unchanged frozen U5
kit, restored from B1 and served by the fixture, supplies that separate evidence.

## Diagnostic attempts

Earlier runs are retained rather than relabelled as the final acceptance:

| Run suffix | Outcome and correction |
| --- | --- |
| `ffdc51c5-023b-4abc-87a7-bb4b82a6070c` | Failed: missing `oc_admin` role; import correctly refused its table ownership. Restore now reconstructs the private application login. |
| `74c94254-c684-47c6-9cf2-9c95ddf93376` | Failed: preliminary counts compared all migrations/preferences with an MCP-only oracle. Counts now use the same filters; semantic comparison still checks row contents. |
| `7fab74f6-a3f0-4db8-9bcc-9d035041e961` | Failed: report identifier outside its lexical scope after import. Corrected; this run was not classified as passing. |
| `9b25daba-7581-4c44-b477-e6bf165e1e1f` | Diagnostic success with 71 stages; superseded by source HTTP readback and stronger result/fault attribution. |
| `8ea849d2-df05-4651-9506-0ca65c4ba4b9` | Diagnostic success with 72 stages; superseded by exact stale-confirmation refusal checks. |
| `d1e2d098-3881-4f08-91f8-a302ff281d33` | Implementation-stage success with 72 stages; independently reverified, then superseded by the closure review's exact revocation-cut and DAV-write assertions. |

Each attempt completed owned cleanup. The first full check found the L4 baseline
adapter's legacy policy reference. It now uses the existing narrow, exact quoted
class-ID exception for a separate-process immutable-baseline probe. All other
symbols remain scanned; no production compatibility shim was introduced. The
second full check passed before the final confirmation-oracle correction; the
third full check and sixth native run established the implementation-stage result. The closure-review repeat above supersedes them with stronger mutation
evidence and a fresh full check.

## Automated review and confirmation oracle

One independent automated review found no actionable issue. This is not human
acceptance. A subsequent author check tightened the stale-confirmation oracle:
any generic tool error could previously count as refusal. Two regression cases
first failed, then passed when the probe required either the exact current-etag
error or the fingerprint of a fresh preview. The implementation-stage sixth native
run included that correction; the accepted closure-review repeat retains it. The private application-role password is also
registered with the runner's redactor before SQL execution, including its SQL
quoted representation; it is not exposed in diagnostics.

## Closure review (2026-10-29)

The review found two weak assertions in post-snapshot mutation evidence. Comparing
whole authentication tables could count newly issued credentials as a revocation,
and a DAV write was recorded without reading back its stored contents. The probe
now checks the exact owner's generation immediately before and after revocation:
issuance must remain unchanged, there must have been active credentials, and the
revocation cut must reach the last issued generation. DAV must change from its
original bytes and read back exactly the requested content.

Eight focused test cases cover issuance-only, partial, no-op and backward cuts,
ignored or different DAV writes, and the successful effects. Six failed against
the previous assertions before the corrected implementation passed. The complete
native repetition above proves the tightened assertions on both frozen app
versions; each case records its generation cut and before/after DAV hashes.
No application production code or candidate kit changed.

Before repeating, independent verification matched the previously documented
result/resource hashes and all five retained generations, including the original
L3 B0/B1 digests. That read-only check is recorded in
`mcp/build/linuxserver-l4/review-existing-evidence.json`. The final repeat has its
own accepted generations and digests above; neither original checkpoint was
modified. Local verification is not human approval or closure of L5/F3.

## Remaining boundaries

- L5, broader Apache/support-matrix regression closure and final F3 acceptance
  remain separate. This phase does not authorize them.
- Public HTTPS, the outstanding Inspector acceptance, hosted ChatGPT and human
  security, operational, accessibility and language review remain pending.
- The production LinuxServer configuration, exact installed app bytes, SQL target,
  startup hooks and external S3 provider are not qualified by this isolated fixture.
- No real desktop/mobile synchronization is exercised. Measured checkpoint age
  and restore duration are evidence for this fixture, not promised RPO/RTO.
- Mail, Groupfolders, external storage and additional production apps remain
  outside this frozen profile. The source-kit contents remain U5, not a new release.
