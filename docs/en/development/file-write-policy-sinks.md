<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Filename write policy sink inventory

This inventory adapts upstream W01 from release
`02526119b845ccc1d8399be002da3e30b5527df6`, including its bounded tree-copy and
sink-recheck corrections, to the fork's capability owners. It covers writes
through this connector. See [integration evidence](upstream-1.2.0-integration.md)
for candidate-specific execution, including the native matrix. This document is
an ownership and coverage map, not a claim that the native matrix has passed.

## Configuration and decisions

`Access/FileWritePolicy/FileWritePolicy` owns the `mcp/file_write_policy` key.
The canonical object has exactly `version: 1`, `mode: off|allowlist`, a sorted list
of at most 128 normalized `extensions`, and boolean `allowExtensionless`.
Only actual absence defaults to off. Present malformed, wrongly typed, lazy or
unreadable values refuse guarded writes. Names are checked by their final
basename and last extension, independently of MIME and content. A dotfile such
as `.env` is extensionless. Active mode also refuses ambiguous trailing dots or
whitespace, separators, colons, control characters and invalid extensions.

The config reader invalidates the native app-config instance cache at each
boundary, checks all-key presence and then explicitly checks non-lazy presence.
This second check is necessary: in Nextcloud 32–35, `hasKey(..., null)` populates
both caches, and `getValueString(..., false)` can still return a previously loaded
lazy value. `FileWritePolicyTest` models this behavior, and
`FileWritePolicyRecoveryTest` exercises it against core. The selected native
sources inspected were `lib/private/AppConfig.php` in pinned Nextcloud 32.0.15,
33.0.9, 34.0.4 and 35.0.1, matching the
[native matrix](../../../mcp/tests/Integration/ci/matrix.json).

Cache invalidation and rereading do not create a transaction joining an
administrator's policy change to a file mutation. Shared-cache repopulation,
database transaction visibility and changes after a check remain native
concurrency limits. A projected sequence shares one immutable decision snapshot;
a mutation callback reads again immediately before its native sink.

Wrong-type repair uses the official app-config and database APIs, verifies the
stored value and owns its transaction. It refuses to repair inside a caller's
transaction and never commits or rolls back that transaction. Ordinary saves also
verify canonical read-back. Admin-only, CSRF-protected `GET/PUT
/api/file-write-policy` returns explicit field errors (400) or an unavailable
response (503), without backend details.

## Public writes and aliases

Paths below are relative to `mcp/lib/`. Each guarded write also retains native
ACL, Guests, Files scope, exclusion, ETag, lock and shared-confirmation checks
where they apply. The filename policy never grants access, including to an
administrator.

| Surface | Preview and projected name | Native sink and fresh check |
| --- | --- | --- |
| `files_create`, `files_upload` | `Modules/Files/Creation/FileCreation::target` checks the resolved destination basename after parent resolution. | `FileCreation::write` checks before `Folder::newFile`, then checks the newly resolved current file again before `putContent`, inside the lock callback. |
| Create capability over HTTP PUT/POST | `FileCreation::target` rechecks the token's destination before spend; the local upload filename and content type grant no exception. | `CheckoutWriter::create` delegates to the same `FileCreation::write`. A refusal before spend leaves the capability usable; a later refusal retains any empty file and reports failure. |
| `files_checkout`, replace capability over HTTP PUT/POST | `Modules/Files/Checkout/FileCheckout` checks the resolved existing file before preview or link issue. `CheckoutWriter::replace` rechecks before spend and backup. | `CheckoutWriter::replace` checks the current file inside the lock callback immediately before `putContent`, after the backup required by the effective copy policy. Old tokens cannot bypass a newly enabled policy. |
| `files_edit`, `files_replace` | `TextFileEditor` and `TextReplacer` check the resolved file in both execution and preview. | `Modules/Files/Editing/ContentWriter::commit` checks before backup and inside the lock callback before `putContent`. Mandatory backup stays available. |
| `files_version_restore` | `Modules/Files/Versions/VersionRestorer` checks the current file name; the historical version's former name is irrelevant. | Both native `rollback` and the lock-aware `putContent` path check immediately before the write, after the backup required by the effective copy policy. |
| `files_copy` of a file | `Modules/Files/Reorganization/CopyMove` checks the final destination, allowing a source extension to change. | `ListedTreeCopy` invokes the current policy immediately before native `Node::copy`. Normal single-file receipts retain their existing fields. |
| `files_copy` of a folder | `FileTypePreflight` walks visible, readable descendants with one snapshot and bounds nodes, projected work and copy bytes. | `ListedTreeCopy` copies only listed identities, resolving path, ID and kind again at each sink. Each file rechecks its destination with the current policy. |
| `files_move` of a file or folder | `CopyMove` preflights projected destinations and descendants. | `MoveExecution::locked` repeats bounded preflight inside the lock callback, then invokes native `Node::move`. |
| `files_move_batch` | `MovePlanner` checks every projected step using a shared snapshot and bounded alias tracking, before any requested directory creation. Existing native planning still refuses unsupported chained sources. | Each `BatchMover` step delegates to `MoveExecution::locked`, preserving partial receipts when conditions change. |
| `files_undo_batch` | `BatchUndo` preflights the reversed recorded sequence under current policy, including intermediate projected names and aliases. | Each recorded inverse move delegates to `MoveExecution::locked`. A blocked preflight keeps data and receipt intact; a mid-run refusal retains the remaining moves for retry. |
| `notes_create` | `Modules/Notes/Creation/NoteCreator` checks the sanitized `.md` name, including collision suffixes, before creating a missing category. | It recomputes the free name and checks it immediately before `Folder::newFile`. |
| `notes_edit` content and rename | `NoteEditor` uses one snapshot for current-content and projected-rename names, before any content effect or confirmation. | Each `putContent` and `move` callback rechecks the current or final name independently. A failed later rename still reports an already saved content change. |
| `notes_edit` append/replace | `PartialEditPlan` checks current-content and projected-rename names using one snapshot. `NoteEditor` rechecks when confirming. | `PartialEditConfirmation::writer` recomputes the approved content, then checks immediately before `putContent`. The subsequent rename has its own check. |
| `notes_move` | `Modules/Notes/Moving/NoteMover` checks the resolved note name before category creation and in the preview. | The lock callback checks again immediately before `Node::move`. |

`Platform/FileAccess/Policy/FileTypeGuard` is a required injected collaborator.
There is no optional/null policy interpretation, container fallback in a tool,
legacy owner alias, public exemption argument, or extension-based backup bypass.
The existing administration bundle composes focused state, view and controller
functions under `src/admin/protection/`; it adds no third production entry.

## Mandatory internal writes

These exemptions belong to the internal owners, without a client-supplied flag.

| Owner | Native sink | Why it is exempt |
| --- | --- | --- |
| `Modules/Files/Editing/FileBackup` | `Folder::newFile`, then `File::putContent` | A verified recovery copy is mandatory before guarded edits, checkout replacement and version restoration. Ordinary public writes receive no internal policy exemption; existing tools retain their backup-path access checks. |
| `Modules/Contacts/Deletion/ContactBackup` | `Folder::newFile` | Contact deletion requires its internal `.vcf` recovery copy. Public uploads named `.vcf` or `.bak` follow the ordinary policy. |
| Directory-only helpers in `Platform/FileAccess/Nodes/NodeAccess` and Files/Notes storage | `Folder::newFolder` | Creating a directory writes no file basename. A guarded file or whole-tree preflight still runs before directories created as part of that operation. |

Reading, resource/checkout downloads, share changes and Notes trash-bin deletion
do not create or overwrite a covered public file name. They retain their own
access, recovery and deletion contracts. In particular, the connector does not
remove copied partial data. Batch undo's existing deletion allowance remains
limited to empty directories that the recorded batch created.

## Bounded behavior and remaining races

An active tree preflight bounds aggregate inspected/projected work at 2,000 nodes
and folder-copy content at 1 GiB. It refuses hidden or unreadable descendants
before returning their paths or counts. Folder copies use the validated manifest,
omit later additions, recheck identity and policy per entry, and report vanished
items, failures, unattempted items and retained partial output. If current
visibility, exclusions or permissions change, it stops with a generic warning
that the copy may be incomplete; it withholds stale child names and counts and
retains existing output. The warning does not promise rollback or a safe replay. If the policy
becomes active after an off preflight, a recursive native copy cannot fall through
unchecked: a manifest is prepared or the final native-folder boundary refuses.

A folder move remains one native Nextcloud operation after bounded preflight.
A file inserted after that last check can race into the move. Neither the
configuration reader nor the manifest makes filesystem identity, policy updates,
permissions or tree mutations atomic. Filename checks do not inspect MIME or
content, sanitize documents, or eliminate prompt injection. Stronger transactional
or content-scanning requirements require a separate design.

## Regression ownership

`Architecture/FileWritePolicySinksTest` tokenizes every Files, Notes, Contacts and
shared file-access source and rejects an unlisted `newFile`, `putContent`, `copy`,
`move` or `rollback` call. It distinguishes delegated tool calls and the two
mandatory internal backup owners. Its explicit list complements behavioral tests;
it does not infer runtime safety from a guard's name appearing in source.

Local behavioral coverage includes configuration/recovery/controller contracts,
`FileWritePolicyBoundariesTest`, `FileWritePolicyTreesTest`,
`NotesFileWritePolicyTest`, checkout controller tests and the existing Files/Notes
suites. They cover policy changes between preview and confirmation, after empty
file creation, after backup, during listed copying, vanished identities, projected
aliases, bounded work, unchanged file-copy receipts and blocked undo retries.
Browser cases use the existing production bundle in Chromium, Firefox and WebKit,
including uncertain saves, field errors, retries, late responses/disposal and
independent protection warnings.

Native cases are `Policy/FileWritePolicyRecoveryTest`,
`FileWritePolicyAdminHttpTest`, `FileWriteCheckoutTest`, `FileTypeEditRestoreTest`,
`FileWriteTreePolicyTest` and `NotesFileWritePolicyTest`. The focused
`Contacts/ContactBackupLockTest::testFilenamePolicyPreservesMandatoryVcardBackupAndRefusesPublicVcardUpload`
case checks an exempt vCard backup alongside a refused public vCard upload.
The Notes case requires the
pinned Notes app. Execute these through the existing disposable native harness;
see [testing](testing.md). Local doubles and API-surface fixtures are separate
from native runtime evidence. F3 and human review remain separate acceptance work.
