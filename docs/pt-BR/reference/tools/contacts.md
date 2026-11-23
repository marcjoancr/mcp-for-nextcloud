<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/contacts.md -->
<!-- translation-source-revision: c5ff622fac628b942d9945fe50aa21a4b7a49262 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/contacts.md).

# Contatos

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Contatos** | listar catálogos pessoais/compartilhados, buscar e ler contatos, incluindo o catálogo somente leitura de contas da instância (colegas, com `accountId` para convites) conforme as regras de enumeração do admin | criar e editar preservando campos desconhecidos do vCard; excluir permanentemente só após backup `.vcf` verificado em `Contacts` na pasta de backup configurada (padrão `/MCP backups/Contacts`; obrigatório mesmo com as cópias de Files suspensas) |

[Comportamento de Contatos e Tarefas](contacts-tasks.md).

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

## Grupos, catálogos e vCard

`contacts_list_groups` lista categorias em um catálogo visível. Informe `group` em `contacts_search_contacts` para uma correspondência exata de categoria. A criação e a edição aceitam nomes e endereços estruturados, categorias, aniversário e e-mail preferencial. Os campos seguem a versão vCard armazenada.

`contacts_export_vcf` retorna os cartões selecionados como um documento vCard; salve o conteúdo como arquivo `.vcf`. Use `contacts_import_vcf` com um arquivo que você possa ler no Files ou com o caminho de backup retornado após excluir um contato. O plano lista quais cartões serão criados ou ignorados. Uma nova importação com um plano atualizado ignora UIDs já existentes no catálogo.

`contacts_find_duplicates` encontra possíveis duplicatas por e-mails ou telefones normalizados coincidentes. Nome e organização em comum são indícios mais fracos. Revise as referências e os ETags antes de decidir quais cartões consolidar.

Use `contacts_merge_contacts` com até cinco grupos sem cartões em comum. Cada grupo contém de dois a dez cartões de um catálogo editável. Escolha qual manter e revise os valores propostos para cada conflito. Após aprovar, repita com os ETags e o `plan_state` da prévia. A ferramenta verifica os backups originais e o cartão consolidado antes de excluir as fontes. Ela informa os grupos que ainda precisam de revisão e seus caminhos de backup.

## Catálogos e transferência de contatos

Use `contacts_create_addressbook` para criar um catálogo pessoal e `contacts_list_addressbook_shares` para consultar compartilhamentos em um catálogo seu. `contacts_share_addressbook` gerencia o compartilhamento direto com usuários locais. Defina acesso de leitura ou escrita, ou remova um compartilhamento atual; revise a alteração na prévia.

`contacts_copy_contact` cria um contato distinto no destino editável e mantém a origem. `contacts_move_contact` preserva o UID, verifica o destino e salva um backup antes de excluir a origem. Confirme a transferência com o `plan_state` do plano. Inclua o ETag da origem ao mover e confira o estado dos dois cartões se uma etapa falhar. Catálogos compartilhados exigem aprovação separada para cada coleção afetada.

## Manutenção em lote

`contacts_update_categories` adiciona, remove ou renomeia uma categoria nos cartões selecionados de um catálogo editável. Informe os ETags dos cartões. A prévia mostra as categorias antes e depois, e o resultado informa o estado de cada cartão.

`contacts_reconcile_vcf` atualiza cartões com correspondência de UID exata e única em um VCF visível no Files. Revise os valores atuais, propostos e preservados. Cada atualização exige um backup completo verificado. Cartões sem correspondência única ficam para revisão. Confirme cada operação com o `plan_state` e reconheça separadamente alterações em catálogos compartilhados.

[English](../../../en/reference/tools/contacts.md) · [Español](../../../es/reference/tools/contacts.md) · [Català](../../../ca/reference/tools/contacts.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/contacts.md)
