<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts-tasks.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/contacts-tasks.md).

# Contactos e Tarefas

| Módulo | Tools | Dependência |
|---|---|---|
| Contactos | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | app Contactos ativado; core CardDAV |
| Tarefas | `tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task` | aplicação Tasks ativa e DAV do núcleo |

Os catálogos de Contactos são próprios/partilhados, filtrando explicitamente qualquer principal de sistema. Leituras usam adapters lazy do backend nativo, depois do filtro de acesso; escritas passam pelo Sabre do core (dispatcher CardDAV separado e o `EmbeddedDavDispatcher` existente para VTODO). A sessão precisa corresponder ao ator e o principal é fixado na execução. As consultas de calendário usam VEVENT como padrão; Tarefas seleciona VTODO.

`GrantPolicy`/`GrantMatrix` incluem `contacts` e `tasks` com read/create/edit/delete, escritas desligadas por predefinição. Concluir tarefa usa edit. `PreviewsWrites` produz o plano sem efeitos secundários; `WriteGate` exige confirm:true; shares exigem confirm_shared; ETag fornecido é conferido e If-Match usa o relido. Edição de vCard preserva campos desconhecidos, parâmetros, grupos e versão; o plano inclui todas as propriedades antes/depois e o vCard completo.

**Eliminar o contacto é PERMANENTE no Nextcloud 33, sem lixo.** Só depois de confirmar, `ContactBackup` guarda o vCard original completo em `Contacts/<catalog>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf` dentro da pasta de cópias de segurança configurada na raiz pessoal (predefinição `/MCP backups`), no fuso da conta e na pasta do ator. Usa mutex da aplicação, sufixo aleatório e verificação de colisões para não selecionar ficheiros existentes, e confere a cópia relendo os bytes. Falha impede DELETE CardDAV; o resultado informa backupPath. Reimporte pela interface Contactos para recuperar. Não se cria uma cópia na pré-visualização. Esta cópia verificada continua obrigatória mesmo com as cópias de Files suspensas.

Tarefas usam VTODO, incluem tarefas sem data, ocultam PRIVATE/CONFIDENTIAL de outro dono e excluem objetos em lixo. Ler recorrência é permitido; escritas em séries ou tarefas com participantes são recusadas. Campos não alterados permanecem no iCalendar; completar grava STATUS/PERCENT-COMPLETE/COMPLETED, e reduzir progresso reabre a tarefa. Excluir usa o lixo CalDAV, recusa retenção zero e verifica deleted-at após o DELETE. Listagens usam limit/offset/nextOffset; concluídas exigem include_completed. As categorias (`CATEGORIES`) são texto livre: ler e listar devolvem `categories`, reunidas de todas as linhas `CATEGORIES` (vírgulas escapadas respeitadas), aparadas e sem repetição ignorando maiúsculas Unicode, com a primeira grafia mantida; criar e editar aceitam `categories` (até 50 itens de 1 a 255 caracteres depois de aparar, sem caracteres de controlo, validados antes de qualquer escrita): omitir deixa todas as linhas intactas, `[]` as remove e uma lista as substitui por uma linha de vários valores. `tasks_list_tasks` filtra por `category` (exata, sem diferenciar maiúsculas) antes de `offset`/`limit`, junto com `include_completed`; o plano mostra cada nome entre aspas, antes e depois. As categorias de tarefas ocultas por CLASS nunca são lidas, filtradas nem mostradas.

A [hierarquia de tarefas](tasks.md) documenta UIDs ascendentes, filtragem por filhos diretos, bloqueio e limites de prevenção de ciclos entre clientes.

Uma pesquisa visível concluída sem categoria correspondente acrescenta uma nota a explicar o filtro literal e se foram pesquisadas tarefas concluídas; uma página vazia causada apenas pelo deslocamento não o faz. Categorias ocultas e a existência de tarefas nunca são enumeradas.

Não há migration, approval_id, registo de aprovação, requisito de occ, serviço extra ou base de dados auxiliar. Descrições/ToolGuideNotes ficam em inglês; títulos e mensagens usam Translator com paridade de/pt_BR/es. Testes cobrem planos, backup, ACL, grants, ETag, preservação e dispatch real Sabre; não provam os plugins internos numa instalação Nextcloud real.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/contacts-tasks.md) · [Español](../../../es/reference/tools/contacts-tasks.md) · [Català](../../../ca/reference/tools/contacts-tasks.md) · [Português (Brasil)](../../../pt-BR/reference/tools/contacts-tasks.md) · Português (Portugal)
