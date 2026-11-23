<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/people.md -->
<!-- translation-source-revision: 2e4144ffeb13c74a1d9da098c262b8424cb24439 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/people.md).

# Pessoas

Use `mcp_guide` com `module: "people"`.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

| Módulo | Ferramentas | Dependência |
| --- | --- | --- |
| Pessoas | `users_search` | busca de contas — e, com `include_groups: true`, de grupos, devolvendo `type` `user` ou `group` — pelo compartilhamento do Nextcloud (respeita as restrições do admin); sem app opcional |

[Guias de operação](../../guides/README.md).

## Identificação de pessoas e equipes

Use `users_resolve` para consultar um lote de nomes, endereços de e-mail ou IDs de conta. O resultado separa correspondências exatas nativas de sugestões e sinaliza resultados ambíguos ou incompletos. Os campos de perfil seguem a visibilidade do Nextcloud.

`users_list_teams` e `users_list_team_members` exigem Circles. A associação a equipes é consultada como a pessoa conectada. O resultado diferencia contas locais de outros tipos de membro. Use o UID de um membro local ao convidá-lo para um evento.

## Encontrar perfis e gerenciar membros

`users_filter_team_members` filtra membros locais visíveis de uma equipe por cargo profissional ou organização. O cargo profissional e a função de membro na equipe são campos distintos. O resultado indica quando a consulta foi truncada.

`users_add_team_member` e `users_remove_team_member` atuam sobre uma conta local e aplicam as permissões nativas do Circles. A prévia identifica a conta, a equipe e possíveis alterações de acesso a recursos compartilhados. Repita a chamada aprovada com `confirm: true` e o `plan_state` da prévia. Novas associações usam a função de membro.

[English](../../../en/reference/tools/people.md) · [Español](../../../es/reference/tools/people.md) · [Català](../../../ca/reference/tools/people.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/people.md)
