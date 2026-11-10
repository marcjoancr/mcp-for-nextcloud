# Mail B0 and cached metadata batch evidence

This local continuation starts B after the PostgreSQL deployment. Production
remains MCP2.7.0 commit `49b00cc1f9d4285e8ee14530247a8fefcd46ad37` with native
Mail5.12.5.9 and 41 tools. The new 43-tool source line and native v10 are not
installed there. Actual MCP transmission remains disabled.

## Implemented boundary

`mail_read_outbox` uses the independent denied-by-default `read_outbox` operation
and its own tool assignment/connection selection. Optional account filtering,
limit 20 by default/50 maximum, a descending ID cursor and a SQL limit+1 produce
only owned outgoing queue metadata. Returned fields are local/account IDs, a
subject capped to 256 UTF-8 characters, its truncation flag, native failed flag,
scheduled time and update time. No body, recipients or attachment bytes are read.
Timestamps are native Unix seconds or null. A live queue view is never a delivery
receipt; a missing row cannot establish delivery or authorize a retry.

`mail_read_message_metadata` uses `read_message` and its own selected tool. It
accepts 1–50 unique positive cached IDs and returns only owned cached IDs,
account/folder IDs, bounded subject, date and seen/flagged state, ascending by ID.
Foreign and missing IDs are omitted alike. It does not read previews, bodies or
attachments, contact IMAP, synchronize or mark anything as seen.

Both readers check per-user Mail enablement, the exact runtime and v10 source
before resolving the native helper. Native `Db\BoundedMailMetadata` uses scalar
SQL projections, owner joins, explicit row limits and primary topology checks.
It does not call the unbounded native `OutboxService`/local-message hydration API.
There is no queue writer, dispatch worker, schedule/cancel action or SMTP method.

## Native identity and compatibility

| Artifact | SHA-256 |
| --- | --- |
| v10 manifest (`mcp-mail-mutations-v10`, Mail5.12.5.10, 132 files) | `b7a921df7c49ab95e5f59b70d49fce7d4482e7b170428fbbf55fe40ea653a859` |
| Normalized v10 overlay | `ed05c27b7bc19958a5b02083633252a2bb9c025f9e8e0164895e583d8523b19c` |
| Preserved v9 manifest | `ba435b7851ebf7a0365a1e931c1446264d7c1866dbeffa914dbde07af5ffe9f0` |

The pinned builder reproduces the checked-in manifest byte-for-byte. Relative to
v9, the only changed existing native file is appinfo/info.xml (installation
version); BoundedMailMetadata.php is added. Existing native implementation files
remain byte-identical. v9 stays admitted through its immutable fallback but never
provides the new metadata capability. Unknown core patches stay unqualified.

## Verification

- MCP tool, grant, registration, input-validation, privacy and delegation tests:
  **49 tests / 6,477 assertions**.
- Native helper/runtime/artifact tests: **11 / 108**; native API gate: **2 / 223**.
- Real NC35.0.2 / PostgreSQL16.15 / LinuxServer read-only application fixture:
  **2 / 48** verifies new MCP source preserves native v9 source/capability admission.
- Actual native v10 PostgreSQL, source and runtime integration: **3 / 86**,
  no skips. It exercises both registered MCP tools and the native helper, Alice/Bob
  account isolation, outgoing-versus-draft exclusion, cursor pagination, Unicode,
  scalar shapes, credential admission and disabled sending.
- Synthetic outgoing rows exist only inside an uncommitted transaction. A finally
  block rolls it back and verifies the rows are absent. No other worker can see
  those rows and no dispatch path is invoked.

The first real PostgreSQL run caught raw SUBSTRING text being quoted as a column
by Nextcloud QueryBuilder. The corrected helper uses the native function builder
and selectAlias with typed integer parameters. The real failure then passes with
the corrected generated artifact. UTF-8 truncation is also checked across byte 256;
byte-based substr would corrupt or lose valid subjects. A review caught the v10
credential contract missing from its whitelist; the correction is exercised by
actual v10 source/credential assertions, while v9 fallback remains accepted.

Complete PHP and browser regression components pass: **10416 PHP tests / 96256 assertions and 837 browser tests**, both static-analysis scopes, documentation and packaging. Final read-only review found no actionable issue.

The full catalog attempt passed every PHP test and reached the browser suite; its three failures were the same missing outbox tier entry on each browser. After that one-line JavaScript correction, the full 837-test browser suite, package build and translation cross-check pass. PHP source/tests remain byte-identical to the successful PHP run; the seven-minute PHP suite was not repeated for a tier-list-only change. This is component verification, not a claim that the earlier failed catalog command returned success.

The catalog preserves the original 41-tool prefix and appends the two new definitions; the current canonical publication adds only their raw definitions for its three recorded locales. Protocol/result captures and prior publication entries are unchanged.

The expanded assignment contract exhausted the fixed 1 GiB test process. The same 21 assertions pass with local cycle collection: peak 580 MB before, 240 MB after, with about 16 seconds additional time. The complete ToolsList class passes 14 tests / 4,684 assertions. This changes only one test's temporary-object collection and restores the prior GC state; it raises no limit and skips no check.

The development fixture acceptance does not install or authorize B transmission.

## Remaining work

Install a separately prepared clean B0 source/runtime/native kit if desired.
Full B still needs reviewed immutable approval snapshots, native attachment/alias
correctness, durable attempts/status, worker/cancel exclusion, scheduling and
SMTP uncertainty/Sent-copy repair without duplicate sends. Raw EML and larger
incoming attachment retrieval need a native hard-capped same-generation stream.
Other extras and legacy cache provenance are tracked in the
[continuation plan](../plans/2026-11-10-mail-postgresql-deployment-plan.md).

## Final package comparison

The final clean runtime at source commit `fb4a7f70d17d35b06e0709224620aa350832725b`
has SHA-256 `2e2c4c19b3c19fda30c9901218f13332460ab73053d7a3d68d53daa4417574a1`.
Local token comparison proves the three reader/tool implementations are identical
to those executed successfully in PostgreSQL, excluding their added SPDX notices.
Factory values are identical; their order and the catalog's append-only ordering
changed. Other differences are source/composer identity, reserved operation
translations, and the compiled access-tier entry covered by all 837 browser tests.
No native helper or execution-boundary implementation changed after PG acceptance.

Automatic approval review rejected an additional copy of the final private
package/script to the existing test host because it required explicit destination
authorization. That extra transfer was cancelled. Final verification uses the
local comparison and the recorded PostgreSQL execution instead; there is no claim
that the final clean package itself was reinstalled in the remote fixture.


The synthetic fixture and its labeled containers, volumes and network were removed
after acceptance; the private continuation summary distinguishes the original v9
qualification from the final v10 fixture state. The test SOCKS listener is closed.
Production HTTPS was independently rechecked healthy with no pending upgrade,
MCP source 49b, native v9, preserved policy and MCP sending disabled.
