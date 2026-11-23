<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/contacts.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/contacts.md).

# Contactos

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Contactos** | listar catálogos pessoais/partilhados, pesquisar e ler contactos, incluindo o catálogo somente leitura de contas da instância (colegas, com `accountId` para convites) conforme as regras de enumeração da administração | criar e editar preservando campos desconhecidos do vCard; eliminar permanentemente apenas após cópia `.vcf` verificada em `Contacts` na pasta de cópias de segurança configurada (predefinição `/MCP backups/Contacts`; obrigatória mesmo com as cópias de Files suspensas) |

[Comportamento de Contactos e Tarefas](contacts-tasks.md).

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

## Grupos, livros de endereços e vCard

`contacts_list_groups` lista as categorias de um livro de endereços visível. Indique `group` em `contacts_search_contacts` para uma correspondência exata da categoria. A criação e a edição aceitam nomes e moradas estruturados, categorias, aniversário e e-mail preferido. Os campos seguem a versão vCard armazenada.

`contacts_export_vcf` devolve os cartões selecionados como documento vCard; guarde o conteúdo como ficheiro `.vcf`. Use `contacts_import_vcf` com um ficheiro que possa ler em Files ou com o caminho da cópia de segurança devolvido após eliminar um contacto. O plano indica que cartões serão criados ou ignorados. Uma nova importação com um plano atualizado ignora os UIDs já existentes no livro.

`contacts_find_duplicates` encontra possíveis duplicados quando coincidem e-mails ou números de telefone normalizados. Um nome e uma organização comuns são indícios menos fortes. Reveja as referências e os ETags antes de decidir que cartões consolidar.

Use `contacts_merge_contacts` com até cinco grupos sem cartões em comum. Cada grupo contém entre dois e dez cartões de um livro de endereços editável. Escolha o cartão a manter e reveja os valores propostos para cada conflito. Após a aprovação, repita com os ETags e o `plan_state` da pré-visualização. A ferramenta verifica as cópias de segurança originais e o cartão consolidado antes de eliminar as origens. Indica os grupos que ainda precisam de revisão e os respetivos caminhos das cópias.

## Livros de endereços e transferência de contactos

Use `contacts_create_addressbook` para criar um livro pessoal e `contacts_list_addressbook_shares` para consultar a partilha de um livro que lhe pertence. `contacts_share_addressbook` gere a partilha direta com utilizadores locais. Defina acesso de leitura ou escrita, ou remova uma partilha atual, e reveja a alteração na pré-visualização.

`contacts_copy_contact` cria um contacto distinto num destino editável e mantém a origem. `contacts_move_contact` mantém o UID, verifica o destino e guarda uma cópia de segurança antes de eliminar a origem. Confirme a transferência com o `plan_state` do plano. Inclua o ETag da origem ao mover e verifique o estado de ambos os cartões se algum passo falhar. Livros partilhados exigem aprovação separada para cada coleção afetada.

## Manutenção em lote

`contacts_update_categories` adiciona, remove ou muda o nome de uma categoria nos cartões selecionados de um livro editável. Indique os ETags dos cartões. A pré-visualização mostra as categorias antes e depois, e o resultado indica o estado de cada cartão.

`contacts_reconcile_vcf` atualiza cartões com uma correspondência UID exata e única num VCF visível em Files. Reveja os valores atuais, propostos e preservados. Cada atualização exige uma cópia de segurança completa verificada. Os cartões sem correspondência única ficam para revisão. Confirme cada operação com o `plan_state` e reconheça separadamente as escritas em livros partilhados.

[English](../../../en/reference/tools/contacts.md) · [Español](../../../es/reference/tools/contacts.md) · [Català](../../../ca/reference/tools/contacts.md) · [Português (Brasil)](../../../pt-BR/reference/tools/contacts.md) · Português (Portugal)
