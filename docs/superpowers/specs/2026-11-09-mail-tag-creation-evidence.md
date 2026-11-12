# C5 local Mail tag creation — delivery evidence

## Scope and implementation

Baseline: MCP2.7.0 plus A/D/C0/C1/C2/C3/C4 at `9b818f4f`.
This next independent C5 slice adds `mail_create_tag` / `create_tags` with its own
denied-by-default permission and current connection selection. The catalog has
35 tools. It creates local user-owned tag metadata across accounts; it never
assigns a message, contacts IMAP/SMTP, synchronizes, enqueues or sends.

The code uses the existing native `ImapFlag`, `Tag` and `TagMapper`, with no new
native build/schema. `MailManager.createTag` is deliberately bypassed because it
returns existing records by canonical label. The native mapper is explicitly
constructed with the same Nextcloud IDBConnection used by the journal; its native
constructor/source contract is pinned. Native insertion and the opaque observed
`tag_id` checkpoint share one transaction. No direct native SQL write is added.

Creation requires NC35.0.2/Mail5.12.5 and exact native Tag/TagMapper/ImapFlag file
hashes in `Integration/MailTagBoundary.php`. The actual schema must retain a unique
owner/label index and transactional tag/journal tables (InnoDB on MySQL/MariaDB).
Database sharding is refused, using the configuration key read by the pinned
Nextcloud ConnectionFactory. The prefix comes from the same native dbtableprefix
configuration: QueryBuilder.prefixTableName returns a SQL macro, not a physical
schema-table name. Stock Mail and the existing v1/v2/v3 guard overlays share the
unchanged tag APIs. Unknown pairs/builds refuse creation; inventory remains usable.

The preview shows full name/color and user-wide scope and binds native canonical
encoding plus the owned inventory revision. Names are bounded to 128 UTF-8 bytes;
controls/bidi, edge whitespace, invalid/reserved keywords, canonical collisions and
63-encoded-byte truncation are refused. Colors are explicit #RRGGBB; inventory is
bounded to 1,000. A matching existing label is a refusal, never creation proof.
Current original permission/inventory are rechecked before insertion; five
creations/user/minute apply across connections. Ordinary native message tag
associations and all original flags/roles/account security remain unchanged.

## Failure and operation semantics

The existing journal reserves a user-wide creation target, shared across
connections. Replays/status never insert, commit, probe or reconcile. Known
rollback restores both persisted and in-memory checkpoint state and reports
failed_before_effect. Commit/rollback acknowledgement ambiguity discards the
connection and marks the operation uncertain without rewriting any resource
references or releasing the target. An interleaving test verifies newer persisted
IDs survive that transition. If connection discard itself fails, no outcome write
is attempted on that connection: the original protected reservation remains.

Current original create permission is required for status/replay, and another
connection/user cannot read the receipt. Receipt references are opaque observed
IDs, not proof of current row existence in an uncertain outcome. Display names,
colors and canonical labels are not recorded in operation resource references.
The opaque tag ID also survives the existing 30-day receipt compaction, while
private repair metadata is removed. Status and replay remain useful afterward.
No automatic retry, name-based adoption or force-unlock is introduced.

The existing tag inventory also now preserves native nullable colors as null;
the pinned native migration allows that value.

## Verification

RED/GREEN tests cover atomic row/checkpoint commit, known rollback with no phantom
ID, lost acknowledgements before/after commit, failed connection discard, retained
user-wide claims across connections, a newer checkpoint during uncertainty,
canonical collisions/reserved/invalid/lossy labels, stale inventory and nullable
color. API surfaces for NC31–35 were regenerated from their pinned official source
archives; runtime support is not widened. Catalog, independent grants, titles,
confirmation rendering and maintained l10n dictionaries are updated. One new raw
catalog entry per locale is appended; old definitions/protocol/results stay frozen.

The stock native qualification passed two tests / 36 assertions for HTTP approval,
replay, owner isolation, source revocation, collisions and native TagMapper/journal
rollback and commit-loss scenarios. Final full v3 native qualification passed
28 tests / 850 assertions, including the state-only uncertainty and failed
connection-discard guards. The final review found one Important receipt-retention
issue: compaction dropped tag_id after 30 days. A RED/GREEN get/replay regression
now verifies its preservation. Focused retention, licensing and real 20 MiB Files
boundary tests pass 25 tests / 117 assertions. The two large Files cases run in
separate processes, retaining the full byte limits and checks; the production
Files code and the fixed 1 GiB suite limit are unchanged.

Final `devbox run check` passed: 10,171 PHP tests / 92,755 assertions,
837 browser tests across Chromium/Firefox/WebKit, both static-analysis scopes,
1,690 PHP syntax checks, documentation (213 Markdown files / 116 package sources)
and production packaging. PHP peak memory was 1,022.52 MiB, within the unchanged
1 GiB limit; this remains close to the ceiling and is not a general suite-memory
optimization claim. API/architecture/schema qualification separately passed
3,163 tests / 12,382 assertions. Existing translation freshness notices are
advisory; documentation provenance and package checks passed.
Native safety captures cover held drafts, message flags, outbox/MDN and SMTP state.
No shared instance was changed and no deployment/publication is included.

## Remaining C5/C6 dependency

C5 message assignment stays closed. The pinned native association table and its
readers use header Message-ID: TagMapper, MessageMapper import/update/search/
related-data, MailManager UI/bulk/deletion and new-message classification all
participate. A guarded UID STORE alone cannot fix that identity contract. A future
native migration must carry exact cached message/account/mailbox/generation/UID
identity through all those writers/readers, preserve legacy cache provenance and
handle resync/deletion without guessing another copy by Message-ID. No such
migration or message-assignment tool is silently enabled here.

C6 remote refresh stays closed pending unique-owner native synchronization leases,
bounded work and held-draft/event guarantees. Sending remains disabled. D+ native
credential evidence, explicit reconciliation and alias administration remain
separate pending plan items. This slice does not claim all C complete.
