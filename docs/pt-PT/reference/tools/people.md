<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/people.md -->
<!-- translation-source-revision: c5ff622fac628b942d9945fe50aa21a4b7a49262 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/people.md).

# People

Use `mcp_guide` com `module: "people"`.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

| Módulo | Tools | Dependência |
| --- | --- | --- |
| People | `users_search` | pesquisa de contas — e, com `include_groups: true`, de grupos, devolvendo `type` `user` ou `group` — pela partilha do Nextcloud (respeita as restrições da administração); sem aplicação opcional |

[Guias de operação](../../guides/README.md).

## Identificação de pessoas e equipas

Use `users_resolve` para consultar um lote de nomes, endereços de e-mail ou IDs de conta. O resultado separa correspondências exatas nativas de sugestões e assinala resultados ambíguos ou incompletos. Os campos de perfil seguem a visibilidade do Nextcloud.

`users_list_teams` e `users_list_team_members` requerem o Circles. A associação às equipas é consultada como o utilizador com sessão iniciada. O resultado distingue contas locais de outros tipos de membro. Use o UID de um membro local ao convidá-lo para um evento.

## Encontrar perfis e gerir membros

`users_filter_team_members` filtra membros locais visíveis de uma equipa por função profissional ou organização. A função profissional e o papel de membro da equipa são campos distintos. O resultado assinala quando a pesquisa foi truncada.

`users_add_team_member` e `users_remove_team_member` atuam sobre uma conta local e aplicam as permissões nativas do Circles. A pré-visualização identifica a conta, a equipa e a possível alteração de acesso a recursos partilhados. Repita a chamada aprovada com `confirm: true` e o `plan_state` da pré-visualização. As novas associações usam o papel de membro.

[English](../../../en/reference/tools/people.md) · [Español](../../../es/reference/tools/people.md) · [Català](../../../ca/reference/tools/people.md) · [Português (Brasil)](../../../pt-BR/reference/tools/people.md) · Português (Portugal)
