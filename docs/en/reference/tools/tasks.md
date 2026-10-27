<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Tasks

| Module | Read | Write |
| --- | --- | --- |
| **Tasks** | list task calendars and VTODO tasks, including undated tasks, optionally filtered by category or direct parent; read tasks with categories and parent UID | create, edit, complete and delete simple tasks through CalDAV, including categories and parent links; deletion uses calendar trash and is refused when retention is zero |

## Parent and subtask behavior

`tasks_create_task` and `tasks_edit_task` accept `parent`: a UID sets the parent,
`null` makes the task top-level, and omission preserves the link. The parent must
be a live, readable VTODO in the same calendar. Read/list/write results include
`parent` (UID or null). `tasks_list_tasks` accepts a parent UID to list only direct
children, applying this filter before pagination and alongside category/completed
filters. It does not recursively list descendants.

The link uses `RELATED-TO;RELTYPE=PARENT`; a relation without RELTYPE is also read
as a parent. Changing or clearing a parent preserves CHILD, SIBLING, custom
relations and unrelated properties. Plans show readable titles with UIDs to
separate parents with identical names. Private/confidential parents in a shared
calendar are indistinguishable from missing ones.

Preview and confirmation reject cycles and nesting beyond 64 levels, including
the descendants of a moved task. Confirmed MCP writes that set a parent are
serialized per calendar. With server file locking disabled they are refused;
clearing the parent remains available to repair a hierarchy. Other CalDAV clients
can still change related tasks during a write. If read-back finds a loop through
the saved task, the response reports the successful save with a repair warning.
This is not an atomic hierarchy transaction across clients.

Completing or deleting a parent does not complete, delete or move its subtasks.
Recurring tasks can be read and used as parents, but editing a recurring series
or a task with participants remains refused. Existing ETag, scope, sharing and
confirmation checks still apply.

[Contacts and Tasks behavior](contacts-tasks.md).

See the live `mcp_guide` for the authoritative schema and available operations.

[Operational guides](../../guides/README.md).

English · [Español](../../../es/reference/tools/tasks.md) · [Català](../../../ca/reference/tools/tasks.md) · [Português (Brasil)](../../../pt-BR/reference/tools/tasks.md) · [Português (Portugal)](../../../pt-PT/reference/tools/tasks.md)
