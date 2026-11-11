<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/tasks.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/tasks.md).

# Tarefas

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Tarefas** | listar calendários e tarefas VTODO, incluindo tarefas sem data, opcionalmente filtradas por categoria ou ascendente direto; ler tarefas com categorias e UID do ascendente | criar, editar, concluir e eliminar tarefas simples por CalDAV, incluindo categorias e relações de ascendência; a eliminação usa a reciclagem do calendário e é recusada com retenção zero |

## Comportamento de tarefas e subtarefas

`tasks_create_task` e `tasks_edit_task` aceitam `parent`: um UID define o ascendente, `null` coloca a tarefa no nível superior e a omissão preserva a relação. O ascendente deve ser um VTODO ativo e legível no mesmo calendário. Resultados de leitura/listagem/escrita incluem `parent` (UID ou null). `tasks_list_tasks` aceita um UID ascendente para listar apenas filhos diretos, aplicando o filtro antes da paginação e juntamente com os filtros de categoria/conclusão. Não lista descendentes recursivamente.

A relação usa `RELATED-TO;RELTYPE=PARENT`; uma relação sem RELTYPE também é lida como ascendente. Alterar ou remover um ascendente preserva CHILD, SIBLING, relações personalizadas e propriedades não relacionadas. Os planos mostram títulos legíveis com UIDs para distinguir ascendentes com nomes iguais. Ascendentes privados/confidenciais num calendário partilhado são indistinguíveis dos inexistentes.

A pré-visualização e a confirmação rejeitam ciclos e hierarquias com mais de 64 níveis, incluindo os descendentes de uma tarefa movida. As escritas MCP confirmadas que definem um ascendente são serializadas por calendário. Com o bloqueio de ficheiros do servidor desativado são recusadas; remover o ascendente continua disponível para reparar uma hierarquia. Outros clientes CalDAV podem ainda alterar tarefas relacionadas durante uma escrita. Se a releitura detetar um ciclo que passe pela tarefa guardada, a resposta indica a gravação bem-sucedida com um aviso de reparação. Não é uma transação atómica de hierarquia entre clientes.

Concluir ou eliminar um ascendente não conclui, elimina ou move as suas subtarefas. Tarefas recorrentes podem ser lidas e usadas como ascendentes, mas editar uma série recorrente ou tarefa com participantes continua a ser recusado. Mantêm-se as verificações de ETag, âmbito, partilha e confirmação.

[Comportamento de Contactos e Tarefas](contacts-tasks.md).

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/tasks.md) · [Español](../../../es/reference/tools/tasks.md) · [Català](../../../ca/reference/tools/tasks.md) · [Português (Brasil)](../../../pt-BR/reference/tools/tasks.md) · Português (Portugal)
