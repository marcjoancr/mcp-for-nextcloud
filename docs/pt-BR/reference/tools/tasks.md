<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/tasks.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/tasks.md).

# Tarefas

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Tarefas** | listar calendários de tarefas e tarefas VTODO, inclusive sem data, com filtros opcionais por categoria ou pai direto; ler tarefas com categorias e UID do pai | criar, editar, concluir e excluir tarefas simples pelo CalDAV, inclusive categorias e relações com pais; exclusão usa a lixeira do calendário e é recusada quando a retenção é zero |

## Comportamento de tarefas pai e subtarefas

`tasks_create_task` e `tasks_edit_task` aceitam `parent`: um UID define o pai, `null` torna a tarefa de nível superior e omitir preserva a relação. O pai deve ser um VTODO ativo e legível no mesmo calendário. Resultados de leitura/listagem/escrita incluem `parent` (UID ou null). `tasks_list_tasks` aceita o UID de um pai para listar apenas filhos diretos, aplicando esse filtro antes da paginação junto aos filtros de categoria/conclusão. Não lista descendentes recursivamente.

A relação usa `RELATED-TO;RELTYPE=PARENT`; uma relação sem RELTYPE também é lida como pai. Alterar ou remover um pai preserva CHILD, SIBLING, relações personalizadas e propriedades não relacionadas. Planos mostram títulos legíveis com UIDs para distinguir pais de nomes iguais. Pais privados/confidenciais em um calendário compartilhado são indistinguíveis dos ausentes.

Prévia e confirmação recusam ciclos e aninhamento além de 64 níveis, incluindo os descendentes de uma tarefa movida. Escritas MCP confirmadas que definem pai são serializadas por calendário. Com o bloqueio de arquivos do servidor desativado, são recusadas; remover o pai continua disponível para reparar uma hierarquia. Outros clientes CalDAV ainda podem alterar tarefas relacionadas durante a escrita. Se a releitura encontrar um ciclo pela tarefa salva, a resposta informa o salvamento bem-sucedido com um aviso de reparo. Não é uma transação atômica de hierarquia entre clientes.

Concluir ou excluir um pai não conclui, exclui nem move suas subtarefas. Tarefas recorrentes podem ser lidas e usadas como pais, mas editar uma série recorrente ou tarefa com participantes continua recusado. As verificações existentes de ETag, escopo, compartilhamento e confirmação continuam valendo.

[Comportamento de Contatos e Tarefas](contacts-tasks.md).

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/tasks.md) · [Español](../../../es/reference/tools/tasks.md) · [Català](../../../ca/reference/tools/tasks.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/tasks.md)
