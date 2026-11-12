# PostgreSQL Mail deployment evidence

## Deployed identity

The user authorized PostgreSQL support, deployment and starting B/extras while
keeping actual MCP sending disabled. Production deployment completed on the
existing NC35.0.2.2 LinuxServer / PostgreSQL16.15 / primary S3 installation.

| Artifact | Verified identity |
| --- | --- |
| MCP | 2.7.0, commit `49b00cc1f9d4285e8ee14530247a8fefcd46ad37` |
| Source kit manifest SHA-256 | `f1b6d621ab760a5484d9c68a883ce56f196fa1fef87bba64cabab56efb2f12ee` |
| Native Mail | 5.12.5.9, `mcp-mail-mutations-v9` |
| Native manifest SHA-256 | `ba435b7851ebf7a0365a1e931c1446264d7c1866dbeffa914dbde07af5ffe9f0` |
| Native overlay archive SHA-256 | `f89f1f9b26117b8a029f10819ec6e93ce1fe35c359e9df0593da07228a3180ba` |

The v8 artifact and its qualified NC33/34/35 identities are preserved. v9 adds
physical PostgreSQL session ownership, direct primary endpoint proof and exact
schema/provider admission. Version strings alone never authorize a write.

## Acceptance

- Complete catalog: **10,394 PHP tests / 95,843 assertions**, **837 browser tests**,
  both static-analysis scopes, documentation and production packaging pass.
- Exact LinuxServer / PostgreSQL16.15 / S3 native Mail acceptance:
  **59 tests / 1,433 assertions**. The fixture also restores its actual stock
  database dump with `pg_restore --exit-on-error`, verifies the original draft
  and upgrades again.
- Real plain and HTML editor workflows preserve reply identity and Files
  attachments across MCP composition, native editing/saving and IMAP conversion.
- Native manual password, Google and Microsoft credential workflows pass:
  completion, one-shot evidence, refresh exclusion, expired/repeated states,
  rollback, session loss, unchanged profile, status recovery and replay.
- Password, OAuth-state and account-creation cross-tab regressions prove that a
  later MCP connection cannot acquire the originating page's evidence or target.
- Source-tamper refusal runs on owned copies, preserving read-only installed code;
  the coupled boundary regression passes **1 test / 23 assertions**.
- Real FPM parent-file exclusion blocks independent CLI contenders before and
  after explicit zero-byte S3 creation. A pre-existing one-space file is preserved.
  Unsupported APCu/Redis/CLI creation refuses before preview/link issuance.
- The final source changes after the full catalog affect only acceptance tests;
  deployed business code matches that catalog's accepted runtime.

The OAuth fixture originally could not bind its local provider to port 443 because
nginx already owned it. Moving only the disposable fixture's TLS listener to 8443
allowed the fake provider to serve 443. Persisted auth revision advanced and exactly
one accepted refresh was observed before the complete browser acceptance passed.
No production provider settings or credentials were changed by that correction.

## Deployment and recovery

Authenticated source delivery and both complete applications were staged from the
sealed artifacts. Admission closed with HTTP503 and PHP/cron workers drained.
A consistent private PostgreSQL dump, configuration and data archives, and primary
S3 object copy were taken. S3 copied bytes were hashed before the code exchange.
The final private copy contains 7,834 S3 objects / 19,463,951,964 bytes; the
PostgreSQL archive SHA-256 is
`371cf47b0253c9736135deec9da02dd23562bd6a45653a900530c756aa905037`.
Only file locking changed from APCu to the native database provider; local APCu
caching remains configured.

The first isolated migration bootstrap rejected a missing comma in the generated
operator configuration. Admission stayed closed. The invalid configuration was
preserved, syntax was verified RED→GREEN, and the corrected file was replaced
atomically. An isolated worker with an ephemeral writable config.php verified the
original installed database versions and unchanged policy before resuming the
original migration/postcheck sequence. This writable worker is necessary because
NC35 rejects its ordinary read-only CLI while an app upgrade is pending. No
code-only rollback or repeated directory exchange occurred.

Native Mail and same-version MCP migrations completed. Required migration rows,
source fingerprint, draft/credential/move/tag/refresh gates, database file locks,
source-offer commit and unchanged OAuth tool/grant/scope hashes passed. Sending
was checked as disabled for every Mail owner before fresh workers started and
admission reopened. Independent HTTPS health then reported no pending upgrade;
the operator success record reports policy preservation and sending disabled.

Recovery retains the matched private SQL/config/data/S3 backup and original app
directories. Any post-migration recovery must restore the whole matching state or
perform a verified forward repair; replacing code alone is not a valid rollback.

## Remaining operational limits

Legacy cached messages retain unknown exact tag provenance; deployment does not
silently rebuild them or infer associations from header Message-IDs. Native Mail
synchronization or an explicitly reviewed bounded cache reset must establish fresh
provenance before guarded actions that require it. Originals and old tag history
are preserved. Unknown core patches, storage topologies and Redis creation remain
unqualified. Actual MCP send/schedule/cancel/dispatch is still absent.

B and extras started after this deployment are a separate local continuation.
They do not change the deployed source identity recorded above.
