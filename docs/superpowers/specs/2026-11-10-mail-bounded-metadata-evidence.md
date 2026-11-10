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

Complete regression and final review are recorded below once finished. The
development fixture acceptance does not install or authorize B transmission.

## Remaining work

Install a separately prepared clean B0 source/runtime/native kit if desired.
Full B still needs reviewed immutable approval snapshots, native attachment/alias
correctness, durable attempts/status, worker/cancel exclusion, scheduling and
SMTP uncertainty/Sent-copy repair without duplicate sends. Raw EML and larger
incoming attachment retrieval need a native hard-capped same-generation stream.
Other extras and legacy cache provenance are tracked in the
[continuation plan](../plans/2026-11-10-mail-postgresql-deployment-plan.md).
