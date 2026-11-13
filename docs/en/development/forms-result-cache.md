<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Forms result-cache contract

The qualified Forms integration stores complete-result jobs in an encrypted,
private cache. Legacy page exports keep their existing limits and destination
behavior. The cache is separate from uploaded answers, recovery copies, Files
destinations and operation receipts.

## Admission and capacity

A confirmed result job reserves 128 MiB of ciphertext capacity before its job row
is inserted. Admission allows at most 512 MiB per actor and 4 GiB per instance,
with at most 256 retained jobs per actor and 4,096 per instance. A refused
reservation creates no result job or Files output. If admission stops after
reserving capacity, the conservative reservation remains until cache expiry.

Every content-addressed blob is charged by its actual encrypted byte length
before allocation. Repeated content has one charge. Each job permits at most
4,096 distinct blobs. Page chunks and the assembled artifact coexist within
the reservation. A completed cache is sealed and its reservation shrinks to the
exact bytes already charged; it accepts no additional blobs.

The plaintext output limit remains 25 MiB. Native encryption adds padding and
metadata, so reads allow the corresponding bounded ciphertext overhead rather
than rejecting an otherwise valid maximum-size plaintext. Decryption, byte
length and SHA-256 must all agree before a blob is accepted.

Short accounting transactions serialize global reservation changes through the
existing, seeded MCP policy mutex. They do not advance the policy revision.
Per-job accounting uses its own checked row increment. Accounting never holds a
database transaction across native Files IO: pinned PostgreSQL Files behavior
can catch a uniqueness violation that aborts an encompassing transaction.

## Physical write evidence

A domain-separated, hashed native lock key serializes physical cache reads,
writes and cleanup for each job. The key fits the native database lock table.
A durable, random write token is committed before allocation and cleared only
after the synchronous write and plaintext verification finish.

A provider lock's expiry is not proof that a physical write has finished.
An unresolved write token therefore blocks subsequent allocation and automatic
cleanup even after ordinary cache expiry. Charges and exact identities remain
retained. Do not clear these tokens merely because a worker lease or seven days
elapsed. Establish storage quiescence and inspect the retained evidence before
any manual recovery; normal cleanup never adjudicates ambiguous writes.

A failed or unacknowledged write retains its charge. An unacknowledged accounting
commit prevents physical IO from starting. Successful content-addressed reads
can reuse previously charged blobs after sealing; they cannot recreate missing
sealed blobs.

## Expiry and cleanup

Each lease expires seven days after its initial reservation. Progress, reads
and retries do not renew it. Continuations, downloads and direct cache access
recheck the persisted lease. Download credentials last at most five minutes and
never outlive the job's advertised cache expiry.

The hourly Forms result cleanup job processes at most ten leases, one hundred
registered files and twenty seconds per invocation. These time checks are
cooperative; native blocking IO is not preempted. Cleanup obtains the same
per-job lock, irreversibly retires the lease, deletes only its registered hash
keys and verifies absence before releasing capacity. Partial deletion retains
the remaining identities and reservation.

Cleanup does not list an entire SimpleFS directory, recurse through a folder or
match a broad filename prefix. It leaves empty container folders and terminal
lease tombstones in place. A delayed callback cannot renew an expired identity.
User Files, export destinations, Versions, backups, native Forms recovery data
and operation receipts have independent lifecycles and remain untouched.

## Namespace and upgrades

Generated blobs use the dedicated `mcp_forms_results` appdata namespace and
`forms-results-v2-<job-id>` folders. The ciphertext budget covers registered
generated data in that versioned namespace. Unknown objects are never adopted
or removed through inference.

The supported pre-extension baseline has no complete-result cache. Unreleased
earlier candidates used unbudgeted `forms-results-<job-id>` folders. Their jobs
cannot be resumed without current lease records. Preserve and separately
inventory any such candidate data before asserting a bound over all historical
appdata; changing the namespace is not an accounting import or permission to
delete it.

## Verification

The focused host suite includes actual SQLite accounting queries, exact
ciphertext charges, duplicate reservations, per-actor and instance limits,
expiry, late callbacks, lost database acknowledgements, physical write
acknowledgement loss and bounded cleanup. Complete-result tests also require
sealing to finish within the step deadline before readiness is recorded.

`tests/Integration/Forms/FormsSpoolNativeTest.php` and its `fixtures/spool.php`
probe require an explicitly selected disposable native cell, the app's
migration and `MCP_FORMS_SPOOL_BOUNDARY=1`. They exercise the native crypto
implementation, 25 MiB round-trip, native locking, quota sealing, verified
expiry cleanup, refusal of late writes and unchanged operation receipts.
They are skipped in ordinary host tests.

[Development guide](README.md) · [Testing](testing.md)
