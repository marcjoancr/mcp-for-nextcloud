# Native Mail draft lifecycle completion

Baseline: MCP2.7.0, `9c2e1ffcc16c67e107c26a46bd2dbbc2e712efd2`.
The user authorized the remaining-work sequence with “Adelante”: first the full
unsent draft lifecycle, then native authorization evidence and compatibility.
This design specifies the first independently testable slice; later slices keep
the same no-send and native-credential constraints and are tracked by the master plan.

## Behavior and architecture

An MCP reply keeps its reviewed local parent, owned sender alias, body,
recipients and Files attachments. A person may subsequently edit it in native
Mail and allow native local-draft conversion to IMAP. That conversion must keep
the reply headers and selected identity. No MCP path starts synchronization,
releases the existing hold, dispatches an outbox worker or submits SMTP.

Extend the existing pinned Mail5.12.5 dependency as v6. Patch the serialization method
`MailTransmission::saveLocalDraft`: resolve an explicit alias through native
AliasesService with exact account/owner checks, refuse a vanished/foreign alias,
and serialize the stored parent as In-Reply-To and References through native
MIME headers. Never silently fall back to the account sender for an explicit
alias. Validate a nonempty, bounded, single parent header before native client
creation. Preserve native MIME/attachment serialization and null transport.
Actual SMTP sending methods remain byte-identical to the pinned source.

The existing `failed=true` hold remains the MCP default. Qualified new writes
also persist native `mcp_draft_only=true`, with a false default for historical
rows. Native updates retain both the mode and hold under the persisted row lock;
stale objects cannot clear protection, schedule delivery or become outgoing.
Ordinary native Mail messages retain their existing behavior. SMTP implementation
methods remain unchanged; native outbox admission rejects protected drafts.

All native local-message and attachment mutations share a primary-session
owner mutex. The owner key covers messages without IDs and staged attachments
without a parent. Native draft, outbox, mapper and attachment entry points reuse
one scope; different owners remain independent. Short database transactions
remain balanced, while filesystem and IMAP phases stay inside the owner scope
without inheriting a transaction. This avoids relying on an outer SQL transaction
across Nextcloud filesystem operations, which may commit their cache work.

A transient complete snapshot is captured after native mapper hydration and
compared with current persisted content after acquisition. It includes recipients,
attachment identities and metadata, bodies and draft-only mode, so a same-second
change invalidates a cached converter. Background conversion refuses held or
protected drafts. Explicit conversion must use the current snapshot. Existing
partial-write and repair semantics remain in effect; admission failure after a
potential mutation is uncertain rather than an invitation to retry.

The scope is checked at native IMAP command dispatch and before local cleanup.
A lost physical database session invalidates its permit, and an old process
cannot release a successor's mutex. Attachment association cannot take a
protected attachment into another message. Removal by a previous parent refuses
when its current association differs. Automatic MCP cleanup uses the qualified
native atomic orphan operation; stock or unqualified builds retain staged bytes
and operation references when safe orphan deletion cannot be established. The
24-hour eligibility policy, active/uncertain protection and original Files remain
unchanged.

A bounded native Page entry reads only an owned, ordinary, unscheduled protected
draft and passes an allowlisted initial state to the existing native composer.
A content-addressed native chunk bridge starts that local composer session,
prevents signature reinsertion, and disables Send. Server enforcement is required
independently of the UI. The composer snapshots the original Text value and its
first-ready canonical editor body/mode. Metadata-only saves preserve that exact
original value while canonical body and mode remain unchanged. Explicit body or
mode edits use native serialization; no server-side trimming or guessed rewrite
is applied. Missing, foreign, oversized or cryptographic drafts are
not exposed through this entry. The build has its own version 5.12.5.6 and runtime
chunk hashes, and is restricted to the qualified NC35 line.

A person may explicitly use native local-draft conversion to IMAP. This is a
serialization guarantee, not a new exactly-once IMAP APPEND primitive or full
ancestral References reconstruction. Status never invokes conversion or retries
it. No new MCP tool or permission is added. Native conversion failures retain
the usual uncertainty limitation; they must not be automatically retried by MCP.

## Approaches and decisions

The authorized native extension fits the existing architecture: modify the
serializer that already owns native UI/IMAP conversion. A second MCP MIME or
IMAP writer would duplicate ownership and is rejected. Merely hiding the
warning or removing the hold would leave the characterized data loss and is
rejected. Keeping the MCP hold until a separately requested native action is
an implementation decision preserving the user's no-send policy.

Fingerprint the complete v6 dependency and relevant unchanged native draft,
identity and MIME boundaries. Preserve the immutable v5 manifest/artifact and
accept it only for its existing scopes. v6 gains no qualified draft capability
until real native tests prove it; stock/unknown builds retain their limitations.
The builder never installs into a live server. Rollback keeps the existing
matched code/database requirements inherited from v4/v5.

## Acceptance and trust boundaries

Use actual HTTP MCP draft creation, native UI update, native null-transport
conversion and independent IMAP header/MIME readback. Protect wrong-account,
foreign/missing alias and header injection boundaries; verify Files originals,
attachment bytes, recipients, outgoing identities and SMTP capture. Retain a
stock negative control. Rerun the whole guarded Mail suite for C1–C6/D+ and
source tampering, then full catalog and a fresh final review.

NC33/34 qualification and native credential-write evidence are subsequent
slices; neither is inferred from this serializer test. Sending remains disabled.
