<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: a24d67927e4a1a55c6da5d64a09cb0bf3c870590 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/deck.md).

# Deck

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Deck** | quadros, listas, cards e **acompanhamento** dos quadros que gere: cards por responsável, com prazo, indicação de atraso (no seu fuso) e link do card | criar, editar, mover e excluir cards, e atribuir ou remover responsáveis; criar um quadro com suas listas e primeiros cards, ou uma lista em um quadro que gere; excluir um quadro ou uma lista somente quando estiver vazio, direto para o lixo do Deck |

## Deck: cartões, quadros e listas

As escritas do Deck usam os grants `deck.create`, `deck.edit`, `deck.move` e `deck.delete`, todas desligadas por predefinição, e `confirm_shared: true` em quadro de outro dono. O app sempre confirma com o Deck se a escrita foi gravada, mesmo depois de um erro do Deck.

| Tool de escrita | Grant | Comportamento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Cria um quadro do próprio utilizador com suas listas e primeiros cards (até 20 listas e 100 cards), com cor opcional. Num quadro novo os responsáveis são o próprio utilizador, porque mais ninguém tem acesso. O plano mostra a árvore inteira; se um passo falhar no meio, nada é desfeito e o resultado traz `created`, `failed` (com motivo seguro) e `warnings`, para a repetição não duplicar nada. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Acrescenta uma lista a um quadro que o utilizador gerencia, no fim ou numa posição. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Exclui uma lista **somente quando está vazia**: qualquer card, ativo ou arquivado, recusa a chamada dizendo quantos restam, e a contagem é refeita no confirm. Vai para o lixo do Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual para o quadro, e só o dono pode excluir. Se um card aparecer entre a contagem e a exclusão, o quadro volta com o próprio undo do Deck e a chamada é recusada dizendo que nada foi excluído. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` e a atribuição de responsáveis usam os mesmos grants e a mesma confirmação; os responsáveis são membros do quadro.

Depois de um erro vindo do Deck, toda escrita relê o estado real antes de responder: se o item foi gravado, a resposta é sucesso com o aviso "Saved; Deck reported an error afterwards (notification or activity)"; se não foi, é o erro de sempre; e se o estado não pode ser lido, a resposta não é erro e manda ler com `deck_list_cards`, `deck_read_card`, `deck_list_stacks` ou `deck_list_boards`. Um item reencontrado só por semelhança (mesmo dono, mesmo título, criado há pouco) vem como `confirmed: "probable"`, com aviso para conferir, e nada mais é escrito nele. Só as recusas que a própria aplicação faz antes de chamar o Deck (regras da tool, sessão, conflitos, argumentos) pulam essa releitura.

A exclusão "somente quando vazia" não é atômica: o Deck não olha o `deleted_at` da lista ao criar um card, então um card criado por outra requisição logo após a segunda contagem pode ir para o lixo junto com a lista ou o quadro, de onde é recuperável. Isso está na descrição da tool e no gateway.

## Comentários dos cartões

`deck_list_comments` consulta comentários visíveis com `deck.read_comments`. `deck_add_comment` requer `deck.comment`. Mostre o quadro, o cartão e o texto antes da publicação. Após a aprovação, repita a chamada com `confirm: true` e o `plan_state` recebido. Em quadros partilhados, inclua também `confirm_shared: true`.

## Concluir e reabrir cartões

Em `deck_edit_card`, use `completed: true` para concluir um cartão e `completed: false` para o reabrir. Omita o campo para manter o estado atual. Concluir o cartão novamente preserva a data de conclusão original. O cartão permanece na mesma lista, mesmo que o quadro tenha uma lista de cartões concluídos. A operação exige `deck.edit`, as permissões do quadro e a confirmação habitual.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · [Español](../../../es/reference/tools/deck.md) · [Català](../../../ca/reference/tools/deck.md) · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · Português (Portugal)
