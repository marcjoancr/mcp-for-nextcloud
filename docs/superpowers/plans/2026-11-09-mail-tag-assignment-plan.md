# Mail C5 Exact Message Tags — Implementation Plan

> **For agentic workers:** Execute inline with superpowers:executing-plans, TDD and one fresh final review. Keep the progress ledger outside the repository.

**Goal:** Complete C5 assignment/unassignment for one owned native tag and one exact cached message; unlock only a qualified native dependency and keep sending disabled.
**Architecture:** Native v4 extends the existing one-shot selected-connection guard, journal and worker. A nullable exact cached-message key is added beside preserved legacy Message-ID associations. Fresh native snapshots establish message generation/provenance; native readers/writers/search/classification/deletion use exact keys. Legacy associations are never guessed into a new identity.
**Tech stack:** Existing PHP/Nextcloud APIs, pinned Mail5.12.5/Horde, disposable NC35.0.2/MariaDB and SQLite tests. Baseline b8fc394d, MCP2.7.0.
**Spec:** [Approved Mail completion design](../specs/2026-11-07-mail-completion-design.md), C5 in the [master plan](2026-11-07-mail-completion-plan.md). The user explicitly authorized this remaining native dependency and conditional unlock.

## Constraints and decisions

- One `mail_set_message_tag` / `tag_messages` permission, denied by default and selected per connection. Inputs: account_id, message_id, tag_id, explicit boolean value and existing approval fields. No arbitrary keywords or system flags.
- Pin v4 source and schema; preserve immutable v1/v2/v3 contracts for their original scopes only. Stock and older builds refuse assignment. Local creation remains qualified on its original native sources and v4.
- Native migration adds nullable `cached_message_id` with FK/cascade and unique tag/message index, plus nullable per-message `tag_cache_generation` / non-secret `tag_cache_profile` (including the native XOAUTH2 email principal) and a monotonic mailbox `tag_cache_revision`. Preserve all legacy rows with NULL exact key, never adopt by header/name. Native JSON explicitly reports unknown tag cache until fresh native header import establishes exact provenance. Existing legacy/profile-changed cache needs an explicit native cache rebuild; flag-only sync cannot adopt its headers. Preview refuses unknown target provenance; it does not initiate synchronization.
- Native import/update capture generation on the actual selected authenticated connection around fetching, refusing reconnect/reselection/epoch changes. Fresh update objects resolve by owned mailbox+UID, never header Message-ID. Native CRUD/search/related-data/classifier/maintenance use cached IDs; all legacy header-only follow-up jobs refuse before AI; new jobs bind exact cached ID/UID/generation and recheck after AI.
- A shared pre-fetch snapshot revision advances only through its own acknowledged batch/classifier commits. Native UI captures that revision before its wire effect too; a concurrent cache writer causes refusal.
- C5 assignment storage is qualified only for the MySQL/MariaDB primary provider; other backends refuse before schema/transport. SQLite remains a unit model, because the native Mail matrix only admits NC35/MariaDB.
- Assignment requires actual PERMANENTFLAGS for the keyword or wildcard, selected read-write source UIDVALIDITY, one UID STORE +/-FLAGS and complete same-selection readback. Preserve all unrelated flags; no create-on-assignment, other UID, sync, draft/outbox/SMTP/MDN call or generic raw client callback.
- Revalidate original permission, account profile, tag metadata and exact cache provenance at admission, wire and cache. Native cache transaction locks mailbox/message/tag identities; update only the selected association. Tag metadata changes refuse. Remote/cache/events evidence stays durable and uncertain stages never retry or redispatch.

## Review focus

1. Duplicate/missing/overlong Message-ID, same UID in another mailbox/account and stale cache generations must never share an association.
2. Legacy rows must survive migration without being treated as exact evidence; import/update/read/search/classifier/delete must not reintroduce header matching.
3. Reconnect, UIDVALIDITY change, revoked source permission or deleted/renamed tag after preview must refuse or retain uncertainty without second STORE.
4. Tagged NO, missing UID, unsupported PERMANENTFLAGS or acknowledgement/cache/event loss must not report successful complete assignment.
5. Existing unsent reply holds, unrelated flags/labels, other users, outbox, MDN and SMTP state must survive all qualified paths and faults.

## Task 1 — Native exact association and provenance

**Files:** native/mail-guard/build.php, src/Db/ExactTagAssociations.php, src/Migration/Version5125Date20261109000000.php, src/IMAP/TagSnapshot.php; bounded patches to pinned native TagMapper, MessageMapper, MessageTagsMapper/MessageTags, Message, MailManager, ImapToDbSynchronizer and classification paths.
**Interfaces:** native TagMapper methods consume Message objects/cached IDs; `replaceSnapshot(Account,Message)` consumes freshly captured tag_cache_generation; `beginTagSnapshot(string)`, `finishTagSnapshot():string`, `cancelTagSnapshot():void` stay native. Native JSON exposes `tagsVerified`.

- [x] Write migration/association RED tests proving legacy quarantine, duplicate header isolation, foreign owner refusal, exact UID resolution, cleanup/cascade and snapshot provenance. Observe RED, implement and prove GREEN.
- [x] Add native HTTP/database characterization for insertBulk/updateBulk, related-data/search/classification and UI/bulk tag deletion; preserve original source credits and bounded source builder.

## Task 2 — One-shot tag mutation and MCP publication

**Files:** native src/IMAP/GuardedTagMutation.php and Service/GuardedMessageMutations.php; MCP Integration/MailTagTarget.php, Tags/MailMessageTags.php, Tools/SetMessageTag.php; existing request/session/worker/fence/capabilities/manifests/services/DI/catalog/grants/presentation/l10n/API fixtures.
**Interfaces:** request kind=tag with immutable tag_id/imap_label/tag_revision; `MailMutationSession::tag`, native `setTag`/`setTagGuarded`. Proof/cache validators bind exact IDs/generation/tag/value. Reuse the existing journal, tokens, rate limits and signed worker.

- [x] Write RED tests for approval, stale/foreign tag/message/provenance, exact request/proofs and replay/revocation; implement GREEN with separate permission and truthful preview.
- [x] Add real Dovecot assignment/unassignment/already-desired tests and scripted protocol refusals/crash evidence. Qualify exact native cache and no-send state.
- [x] Generate v4 manifest/normalized artifact without changing old artifacts. Regenerate pinned API contracts; append one raw tool definition per locale and preserve prior contracts.

## Task 3 — Qualification and delivery

- [x] Run focused native/unit/API/architecture cases, full devbox check and previous Mail regressions. Keep assignment closed unless all required identity, protocol, cache and no-send guarantees pass.
- [x] Obtain one fresh final review, fix Important/Critical findings RED→GREEN and rerun affected/full checks. No re-review loop.
- [x] Update master plan, canonical Mail reference and native dependency/evidence with exact qualification and migration/legacy limits; C6 remains separate and closed.
- [x] Commit locally after successful checks, preserve worktree. No deployment, push, PR or publication.


## Completion

The [delivery evidence](../specs/2026-11-09-mail-tag-assignment-evidence.md) records
40 native tests / 1,053 assertions, stock compatibility 5/68, full check
10,205 PHP / 93,247 assertions (whole check), followed by 10,206 / 93,249 (final PHP catalog) and 837 browser tests. One Important QRESYNC review
finding was reproduced and fixed in one pass; no review findings are deferred.
The qualified v4 boundary is enabled, other native/storage combinations refuse,
and sending remains disabled. No deployment, push or worktree removal is included.
