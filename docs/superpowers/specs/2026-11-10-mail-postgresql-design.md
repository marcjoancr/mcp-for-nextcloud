# PostgreSQL Mail support and deployment design

The user authorized PostgreSQL support, production deployment, and starting B and optional extras on 2026-11-10, after reviewing the deployment preflight and implementation requirements. Retain the production database and existing native account credentials. Actual MCP transmission remains disabled while B is built and qualified.

## Approach

Extend the reviewed native dependency with primary PostgreSQL support; do not convert production to MariaDB or install a dependency whose ordinary UI paths reject the production provider. Existing MySQL behavior remains covered. Only qualified unreplicated, unsharded primary storage is admitted.

PostgreSQL owner/mailbox coordination uses nonblocking session advisory locks, checked physical backend identity and an unguessable session marker. Refuse recursive acquisition; stale holders cannot release a successor. Detect reconnect and missing ownership before writes. Schema gates retain exact constraints and journal transaction participation; PostgreSQL does not require InnoDB metadata. Unsupported storage stays closed. PostgreSQL admission additionally requires a single configured TCP address and port matching the backend server endpoint; transaction-pool frontends, ambiguous DNS, Unix sockets and NAT endpoint mismatches are unqualified. Only session-dependent draft/credential gates require the native proof; ordinary local journal transactions retain their existing provider contract. A new immutable native version and manifest distinguish this implementation from v8.

Validate real PostgreSQL sessions, native Mail operations, credential evidence and stock-to-custom upgrade behavior on the production core/profile. Use disposable fixtures without production credentials. Preserve historic manifests and packages. Deployment uses a matching local source kit, consistent private backup, operator admission freeze, worker drain, complete staged directory swaps, explicit pending migrations for same-version MCP, fresh workers and post-deployment checks. Do not use a code-only rollback after migration.

## B and optional first slice

After deployment, implement owned bounded outbox inspection with a distinct read permission and no transmission side effects. Begin the optional raw message export to Files using authorized destination preview, generation-safe reads, actual byte limits and operation status/replay. Sending, scheduling, cancellation, worker admission and SMTP uncertainty remain a separate larger implementation; do not claim them complete or enable transmission. Native missing-attachment omission, alias fallback and queue deletion must be resolved before B sends.

## Failure requirements

Concurrent holders, recursive locking, reconnect, transaction rollback, process termination, stale release, changed UIDVALIDITY, schema mismatch, legacy tag cache, interrupted migration and uncertain remote outcomes must not transfer ownership or replay effects. Credential handoffs remain one-shot and owner/source/page-bound. No raw credentials in APIs or logs. Preserve existing production grants and connections.
