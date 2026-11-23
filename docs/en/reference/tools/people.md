<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# People

Use `mcp_guide` with `module: "people"`.

See the live `mcp_guide` for the authoritative schema and available operations.

| Module | Tools | Dependency |
| --- | --- | --- |
| People | `users_search` | account search — and, with `include_groups: true`, group search, returning `type` `user` or `group` — through Nextcloud sharing (honors the admin restrictions); no optional app |

## Resolving people and teams

Use `users_resolve` for a batch of names, email addresses or account IDs. The result separates native exact matches from suggestions and marks ambiguous or incomplete results. Profile fields follow Nextcloud visibility.

`users_list_teams` and `users_list_team_members` require Circles. Team membership is read as the signed-in user. The result distinguishes local accounts and other member types. Use a local member's UID when inviting them to an event.


[Operational guides](../../guides/README.md).

## Find profiles and manage team members

`users_filter_team_members` filters visible local members of a team by professional role or organisation. The professional role and team membership role are separate fields. The result flags a truncated scan.

`users_add_team_member` and `users_remove_team_member` address one local account and enforce native Circles permissions. The preview identifies the account, team and possible access change on shared resources. Repeat the approved call with `confirm: true` and the preview's `plan_state`. New memberships use the member role.

English · [Español](../../../es/reference/tools/people.md) · [Català](../../../ca/reference/tools/people.md) · [Português (Brasil)](../../../pt-BR/reference/tools/people.md) · [Português (Portugal)](../../../pt-PT/reference/tools/people.md)
