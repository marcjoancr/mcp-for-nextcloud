# PostgreSQL Mail support and deployment design

The user authorized PostgreSQL support, production deployment, and starting B and optional extras on 2026-11-10, after reviewing the deployment preflight and implementation requirements. Retain the production database and existing native account credentials. Actual MCP transmission remains disabled while B is built and qualified.

## Approach

Extend the reviewed native dependency with primary PostgreSQL support; do not convert production to MariaDB or install a dependency whose ordinary UI paths reject the production provider. Existing MySQL behavior remains covered. Only qualified unreplicated, unsharded primary storage is admitted.

PostgreSQL owner/mailbox coordination uses nonblocking session advisory locks, checked physical backend identity and an unguessable session marker. Refuse recursive acquisition; stale holders cannot release a successor. Detect reconnect and missing ownership before writes. Schema gates retain exact constraints and journal transaction participation; PostgreSQL does not require InnoDB metadata. Unsupported storage stays closed. PostgreSQL admission additionally requires a single configured TCP address and port matching the backend server endpoint; transaction-pool frontends, ambiguous DNS, Unix sockets and NAT endpoint mismatches are unqualified. Only session-dependent draft/credential gates require the native proof; ordinary local journal transactions retain their existing provider contract. A new immutable native version and manifest distinguish this implementation from v8.

Validate real PostgreSQL sessions, native Mail operations, credential evidence and stock-to-custom upgrade behavior on the production core/profile. Use disposable fixtures without production credentials. Preserve historic manifests and packages. Deployment uses a matching local source kit, consistent private backup, operator admission freeze, worker drain, complete staged directory swaps, explicit pending migrations for same-version MCP, fresh workers and post-deployment checks. Do not use a code-only rollback after migration.

## B and optional first slice

After deployment, implement owned bounded outbox inspection with a distinct read permission and no transmission side effects. Begin the optional raw message export to Files using authorized destination preview, generation-safe reads, actual byte limits and operation status/replay. Sending, scheduling, cancellation, worker admission and SMTP uncertainty remain a separate larger implementation; do not claim them complete or enable transmission. Native missing-attachment omission, alias fallback and queue deletion must be resolved before B sends.

### Implemented first-slice boundary

The B foundation uses a new native `Db\BoundedMailMetadata` helper rather than
`OutboxService::getMessages()`: that existing API is unbounded and hydrates local
message bodies, recipients and attachments. The helper selects only scalar owned
metadata, limits subjects in SQL and caps each result. Outbox pages seek by local
ID; they are live observations and never delivery receipts. No queue mutation,
transport, synchronization or second operation journal is introduced.

The MCP reader requires the exact v10 source fingerprint and runtime before
resolving that helper. v9 remains an immutable fallback for its already deployed
A/D/C scopes. `mail_read_outbox` adds an independent denied-by-default `read_outbox`
grant and tool selection. The optional first extra,
`mail_read_message_metadata`, accepts at most 50 unique IDs, returns only owned
cached metadata and uses the existing `read_message` operation with its own tool
selection. Missing and foreign IDs have the same observable result.

Raw EML export stays unavailable until a native hard-capped raw stream and its
same-generation read proof exist. A reported size or a check after materializing
the full RFC822 string is not an actual resource limit. Sending/scheduling/cancel
and durable SMTP attempt handling remain separate unfinished B work.

## Failure requirements

Concurrent holders, recursive locking, reconnect, transaction rollback, process termination, stale release, changed UIDVALIDITY, schema mismatch, legacy tag cache, interrupted migration and uncertain remote outcomes must not transfer ownership or replay effects. Credential handoffs remain one-shot and owner/source/page-bound. No raw credentials in APIs or logs. Preserve existing production grants and connections.

## Production-profile findings incorporated during acceptance

A refresh worker exhausted its fixed 20-second process deadline because each revocation callback evaluated unrelated draft/schema capabilities. Selective admission now evaluates only the requested capability, retaining the same version, source, ownership, permission, target and journal checks. A parity regression compares selective admission with the full capability report; the complete report still checks every advertised boundary. The native refresh budget and process deadline are unchanged.

Primary S3's native touch operation creates a one-space marker, so the existing Files writer could not prove a newly created file empty. Do not treat an arbitrary one-space file as disposable. Creation instead takes the native parent-folder exclusion, rechecks collision and creates explicit empty content. Capture identity/ETag/size before releasing that exclusion, then use the ordinary guarded content write. Real FPM-to-CLI contention proved that an existing single-space file is preserved, another creator is refused before and after empty creation, and creation becomes available only after release.

This creation boundary requires web execution with native database file locking; Nextcloud disables that provider's shared-lock cache in CLI. APCu cannot coordinate FPM with independent CLI processes, and Redis's nested locking contract is unqualified. Production currently uses APCu; switch only the locking provider to the default database implementation after admission closes and all old workers drain. Keep local memory caching unchanged. Creation previews must refuse unsupported locking configurations before issuing a confirmation or upload link. Existing edit/read/status behavior remains separate.
