<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Notes

| Module | Read | Write |
| --- | --- | --- |
| **Notes** | list, read, search (by title and Markdown content) | create, edit, move between categories, delete (only when the trash bin can recover it) |

| Tool | Grant | Notes |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | `.md`/`.txt` notes in the Notes app folder (`notesPath` preference, default `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Search by title and Markdown content (up to 1 MiB), with a contextual snippet of the match. Optionally restricted to a category. |
| `notes_read` `{id}` | notes.read | Note with content; limit of 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Never overwrites; on collision, uses `Title (2)`. |
| `notes_edit` `{id, content?, append?, old?, new?, title?, etag?, plan_state?}` | notes.edit | Complete content, append, or one exact snippet replacement; the three content modes are mutually exclusive. Title-only edits remain supported. Partial confirmation requires the ETag and opaque plan state returned by the plan. |
| `notes_move` `{id, category, etag?}` | notes.move | Changes category (subfolder), without overwriting. |
| `notes_delete` `{id, etag?}` | notes.delete | Requires `confirm: true`, the trash bin (`files_trashbin`) active and the note's storage covered by it; on external storage without a trash bin, deletion is blocked. |


## Partial edits

Use `append` to add text on a new line while preserving the note's line-ending
style, or paired `old`/`new` to replace one passage. Do not combine them with
`content`. The old passage must be nonempty, valid UTF-8 and at most 64 KiB in
bytes; absent or overlapping multiple matches are refused without a match count.
The result still fits the 1 MiB note limit.

Read the note, request a plan, show it to the user, then repeat the same effect
arguments with `confirm: true`, `etag` and `plan_state`. A changed note or stale
plan is refused, including content changed without a new ETag. Content is reread
at the write boundary, but native storage has no atomic compare-and-swap here:
a competing write after that final read remains possible. Existing file locks,
permissions, visibility, shared-write confirmation and the optional filename policy
still apply.

See the live `mcp_guide` for the authoritative schema and available operations.

Notes tools require the Notes app enabled for the user. Reading starts allowed; creating, editing, moving and deleting start denied until the administrator grants them. There is no timeout of its own: reading is local to PHP and the byte limit protects against large files. Slow external storage is limited to PHP's `max_execution_time`.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/notes.md) · [Català](../../../ca/reference/tools/notes.md) · [Português (Brasil)](../../../pt-BR/reference/tools/notes.md) · [Português (Portugal)](../../../pt-PT/reference/tools/notes.md)
