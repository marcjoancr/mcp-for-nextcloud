<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Deck

| Module | Read | Write |
| --- | --- | --- |
| **Deck** | boards, stacks, cards, and a **follow-up** of the boards you manage: cards grouped by assignee, with due date, overdue flag (in your own time zone) and a link to the card | create, edit, move and delete cards, and assign or unassign the board members; create a board with its lists and first cards, or a list in a board you manage; delete a board or a list only when it is empty, straight to the Deck trash |

## Deck: cards, boards and lists


The Deck writes use the grants `deck.create`, `deck.edit`, `deck.move` and `deck.delete`, all off by default, and `confirm_shared: true` on a board of another owner. The app always confirms with Deck whether the write was saved, even after a Deck error.

| Write tool | Grant | Behavior |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Creates a board of the user themselves with its lists and first cards (up to 20 lists and 100 cards), with an optional color. On a new board the assignees are the user themselves, because no one else has access. The plan shows the whole tree; if a step fails midway, nothing is undone and the result carries `created`, `failed` (with a safe reason) and `warnings`, so a retry does not duplicate anything. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Adds a list to a board the user manages, at the end or at a position. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Deletes a list **only when it is empty**: any card, active or archived, refuses the call saying how many remain, and the count is redone at the confirm. It goes to the Deck trash bin. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Same for the board, and only the owner can delete. If a card appears between the count and the deletion, the board comes back with Deck's own undo and the call is refused saying that nothing was deleted. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` and the assignment of assignees use the same grants and the same confirmation; the assignees are board members.

After an error coming from Deck, every write re-reads the real state before answering: if the item was saved, the response is a success with the notice "Saved; Deck reported an error afterwards (notification or activity)"; if not, it is the usual error; and if the state cannot be read, the response is not an error and tells the assistant to read with `deck_list_cards`, `deck_read_card`, `deck_list_stacks` or `deck_list_boards`. An item found again only by similarity (same owner, same title, created a moment ago) comes as `confirmed: "probable"`, with a notice to check, and nothing else is written to it. Only the refusals the app itself makes before calling Deck (tool rules, session, conflicts, arguments) skip this re-read.

The "only when empty" deletion is not atomic: Deck does not look at the list's `deleted_at` when creating a card, so a card created by another request right after the second count may go to the trash bin together with the list or the board, from where it is recoverable. This is in the tool description and in the gateway.


## Deadlines

Card creation and editing accept `duedate` as `YYYY-MM-DD`, or
`YYYY-MM-DDTHH:MM[:SS]` with an optional `Z` or numeric offset. Input without an
offset uses the account timezone; date-only input means that day's local midnight.
Explicit local times skipped by a timezone transition are refused. A repeated time
selects the earliest instant unless the supplied offset disambiguates it.
Persistence converts to UTC before calling Deck. Responses retain day-based
`duedate`, overdue and `dueBefore`, and add `dueAt` with the account offset.
Unrelated edits preserve the existing instant.

Earlier affected MCP writes may have stored the wrong day. Review those cards
against the intended date and correct them explicitly in Deck or through a new
confirmed edit. Do not shift every existing deadline: dates entered by other
clients may already be correct. No historical dates are rewritten automatically.

## Labels and visible card files

| Tool | Grant | Behavior |
| --- | --- | --- |
| `deck_list_labels` `{boardId, limit=50, offset=0}` | deck.read | Labels of a readable board, up to 200 per page. |
| `deck_edit_card_labels` `{cardId, add?, remove?}` | deck.edit | Incremental label IDs, at most 100 per list. Preserves unrelated labels; duplicates/already satisfied changes are unchanged, foreign-board labels and overlapping add/remove requests are refused. |
| `deck_list_card_files` `{cardId}` | deck.read and files.read | Visible existing native file associations only, sorted by `attachmentId`. This share/association ID differs from the original `fileId`. |
| `deck_unlink_card_file` `{cardId, attachmentId}` | deck.edit and files.read | Removes one visible native association after approval, repeating the exact `file_state` receipt and `plan_state` from the plan. Preserves the original file and independent shares; no files.share grant is required. |

Label and unlink writes require a fresh plan, `confirm: true`, `plan_state` and
shared-board approval where applicable. Reads/writes recheck native access. File
references also require Files scope, exclusion-tag checks, Guests restrictions
and native node READ across the caller's aliases. Invisible references are omitted
or answered like missing ones, without counts or handles; the card response no
longer exposes native `attachmentCount`. Legacy `deck_file` uploads and folders
are unsupported here. Creating a new native file association is deferred.

Unlinking revokes that association for all current and future card members,
including when another editor created it. Independent shares may retain access;
you may lose your own last Deck mount. There is no automatic recovery; restoring
the association may require Deck's native interface. A stale or invalid receipt
is refused. A confirmed already-absent association is a no-op only with a valid
original receipt and the original file still visible.

## Conditional native dependencies

The three dependency tools appear and execute only on compatible native APIs:
NC34 with Deck >=1.18.5 and <1.19.0, or NC35 with Deck >=1.19.0 and <1.20.0,
and the required native methods/properties. The pinned qualification targets are
Deck 1.18.5 and 1.19.0; admitting a range does not qualify every patch. NC32/33
and unsupported API shapes omit all three tools and refuse direct calls.

- `deck_read_card_dependencies` reads the complete outgoing readable subgraph.
- `deck_add_card_dependency` adds `cardId` → `targetId`.
- `deck_remove_card_dependency` removes only that directed relation.

For native `assignDependentCard(A,B)`, B appears in A's `dependentCards`; these
relations do not automatically block task progress. Reads require deck.read;
writes require deck.edit, origin EDIT and target READ, even for removal. Writes
need approval and the returned `plan_state`. Other relations remain unchanged;
a duplicate addition or missing removal is a no-op.

Traversal is bounded to 100 readable nodes including the root, 500 readable edges
and shortest-path depth 20. Exceeding a bound refuses the result instead of
returning an incomplete graph. The same readable node/edge limits apply to the
complete origin adjacency snapshot required for additions, removals and result
verification; oversized origins must be changed through Deck's native interface.
A new relation must also fit these bounds before its plan or write is approved.
Target authorization and entity lookups stop at the first readable overflow.
Deck still materializes each native adjacency before filtering, and filtering
unreadable relations can require additional lookups; these limits do not cap
total work or memory. Unreadable cards consume no readable budget and disclose
no IDs, counts, handles or hidden-limit indicator. Self-relations and cycles detectable through readable
cards are refused; hidden cycles and a concurrent change between the last check
and native write cannot be excluded. This is no global acyclicity guarantee.

After an uncertain or partial label/dependency/unlink result, inspect the current
Deck state before taking further action. Observing the requested state after an
error does not prove which actor changed it or that the whole request completed;
do not automatically repeat the write.

See the live `mcp_guide` for the authoritative schema and available operations.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/deck.md) · [Català](../../../ca/reference/tools/deck.md) · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)
