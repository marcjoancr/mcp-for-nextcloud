<!--
  SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# D7 folder exposure contract (issue #4, A3)

The operation order is **node visibility (D6), D7, recursive helpers, mutation**.
The coordinator confirmed this precedence on 2026-10-15: an excluded node or
unreadable/invalid exclusion configuration gives the generic not-found refusal,
never a folder-specific message that would reveal the node's existence. A visible
folder with exclusions configured gets the D7 refusal. No subtree enumeration is
needed to make that decision.

| Path | Gate location | Protected downstream work |
| --- | --- | --- |
| Copy plan and confirm | `Reorganization::planCopy/copy`, after `writable` | `measure`, destination checks, copy |
| Move plan and confirm | `Reorganization::planMove/move`, immediately after `inspect` | `planNotice`, `assertUnlocked`, `locked/run` |
| Batch plan and confirm | `MovePlanner::plan` first calls `Reorganization::assertFolderMoves` for the entire batch | per-item lock evaluation, `planDirs`, `createFolders`, mutation |
| Undo plan and confirm | `Reorganization::planUndoBatch/undoBatch`, after batch identity/lifetime/undone checks | `assertUndoVisible`, conflicts/locks, `emptyDirs`, folder recreation and mutation |
| Share/link create and update | `ShareWriter::prepare/prepareLink`, after `ShareAccess::ownNode` | all IShareManager queries and writes, including policy getters |
| Share list by path and by page | `ShareLister`, using the resolved visible node | `ShareFormatter` returns `url: null` for folder links and never reads their token; visible file URLs stay unchanged |

Renames compare the positive canonical file IDs of the actual source and target
parents. A missing/unresolved parent cannot prove a rename. A requested virtual
parent from batch `mkdirs` cannot stand in for the actual target parent.

`FolderExposureTest` instruments directory listing (including undo `emptyDirs`),
lock-manager queries, sharing-policy queries, share reads/writes and tree mutations.
The refused paths have zero subtree listing, lock queries and mutations. It covers
clean folders and folders with protected descendants, invalid/unreadable config,
mixed batches, existing shares, activation between preview and confirmation, and
undo before any empty-folder scan. Directory listing is deliberately retained on
permitted paths. Rename, batch rename and undo retain descendant lock refusals,
including locks appearing after a plan; removing a folder share remains permitted.

Request boundaries in these doubles are modeled by `VisibilityGuard::clearCache`.
Production requests resolve a fresh request-scoped guard; no visibility/config
cache is stored across requests. Real core behavior and event delivery are proven by A4
(`WritesTest` against the real share manager, copy and move; see `EXCLUSION-MATRIX.md`).
The existing privacy-race batch test now moves an individual file first, preserving
its after-mutation tag assertion; moving a folder across parents is blocked by D7.

One new source string is defined by `FilesMessages::folderExposureRefused`:

> Folders cannot be shared, copied or moved to another directory through this connector while files are excluded from MCP access. Use the Nextcloud Files interface for these folder operations.

Portuguese and Spanish entries are added together in their JSON and JS catalogs.
German intentionally uses the English fallback during development, as approved;
a reviewed German translation belongs to B2/C ticket 16. The local translation
contract checks Portuguese, Spanish and German fallback. There is no new tool or
argument schema. The approved output change is the nullable `url` on folder link
list items when exclusions are configured; no share is revoked or modified by listing.

Non-link shares retain the absence of the `url` key. Undo also reapplies D7 per
item immediately before its lock/write guard, preserving remaining items for retry.
