<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/deck.md).

# Deck

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Deck** | quadros, listas, cards e **acompanhamento** dos quadros que você gerencia: cards por responsável, com prazo, indicação de atraso (no seu fuso) e link do card | criar, editar, mover e excluir cards, e atribuir ou remover responsáveis; criar um quadro com suas listas e primeiros cards, ou uma lista em um quadro que você gerencia; excluir um quadro ou uma lista somente quando estiver vazio, direto para a lixeira do Deck |

## Deck: cartões, quadros e listas


As escritas do Deck usam os grants `deck.create`, `deck.edit`, `deck.move` e `deck.delete`, todas desligadas por padrão, e `confirm_shared: true` em quadro de outro dono. O app sempre confirma com o Deck se a escrita foi gravada, mesmo depois de um erro do Deck.

| Ferramenta de escrita | Permissão | Comportamento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Cria um quadro do próprio usuário com suas listas e primeiros cards (até 20 listas e 100 cards), com cor opcional. Num quadro novo os responsáveis são o próprio usuário, porque mais ninguém tem acesso. O plano mostra a árvore inteira; se um passo falhar no meio, nada é desfeito e o resultado traz `created`, `failed` (com motivo seguro) e `warnings`, para a repetição não duplicar nada. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Acrescenta uma lista a um quadro que o usuário gerencia, no fim ou numa posição. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Exclui uma lista **somente quando está vazia**: qualquer card, ativo ou arquivado, recusa a chamada dizendo quantos restam, e a contagem é refeita no confirm. Vai para a lixeira do Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual para o quadro, e só o dono pode excluir. Se um card aparecer entre a contagem e a exclusão, o quadro volta com o próprio undo do Deck e a chamada é recusada dizendo que nada foi excluído. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` e a atribuição de responsáveis usam os mesmos grants e a mesma confirmação; os responsáveis são membros do quadro.

Depois de um erro vindo do Deck, toda escrita relê o estado real antes de responder: se o item foi gravado, a resposta é sucesso com o aviso "Saved; Deck reported an error afterwards (notification or activity)"; se não foi, é o erro de sempre; e se o estado não pode ser lido, a resposta não é erro e manda ler com `deck_list_cards`, `deck_read_card`, `deck_list_stacks` ou `deck_list_boards`. Um item reencontrado só por semelhança (mesmo dono, mesmo título, criado há pouco) vem como `confirmed: "probable"`, com aviso para conferir, e nada mais é escrito nele. Só as recusas que o próprio app faz antes de chamar o Deck (regras da tool, sessão, conflitos, argumentos) pulam essa releitura.

A exclusão "somente quando vazia" não é atômica: o Deck não olha o `deleted_at` da lista ao criar um card, então um card criado por outra requisição logo após a segunda contagem pode ir para a lixeira junto com a lista ou o quadro, de onde é recuperável. Isso está na descrição da tool e no gateway.


## Prazos

Criar e editar cards aceita `duedate` como `YYYY-MM-DD` ou `YYYY-MM-DDTHH:MM[:SS]` com `Z` ou deslocamento numérico opcional. Entradas sem deslocamento usam o fuso da conta; somente a data significa meia-noite local daquele dia. Horários locais explícitos inexistentes por uma transição de fuso são recusados. Um horário repetido seleciona o primeiro instante, salvo se o deslocamento fornecido eliminar a ambiguidade. A persistência converte para UTC antes de chamar Deck. As respostas mantêm `duedate`, indicação de atraso e `dueBefore` baseados no dia e acrescentam `dueAt` com o deslocamento da conta. Edições não relacionadas preservam o instante existente.

Escritas MCP afetadas anteriormente podem ter armazenado o dia errado. Confira esses cards com a data pretendida e corrija-os explicitamente no Deck ou em uma nova edição confirmada. Não desloque todos os prazos existentes: datas inseridas por outros clientes podem estar corretas. Nenhuma data histórica é reescrita automaticamente.

## Etiquetas e arquivos visíveis dos cards

| Ferramenta | Permissão | Comportamento |
| --- | --- | --- |
| `deck_list_labels` `{boardId, limit=50, offset=0}` | deck.read | Etiquetas de um quadro legível, até 200 por página. |
| `deck_edit_card_labels` `{cardId, add?, remove?}` | deck.edit | IDs incrementais de etiquetas, até 100 por lista. Preserva etiquetas não relacionadas; duplicatas/alterações já satisfeitas não mudam nada; etiquetas de outro quadro e solicitações sobrepostas de adicionar/remover são recusadas. |
| `deck_list_card_files` `{cardId}` | deck.read e files.read | Somente associações nativas existentes e visíveis a arquivos, ordenadas por `attachmentId`. Esse ID de compartilhamento/associação difere do `fileId` original. |
| `deck_unlink_card_file` `{cardId, attachmentId}` | deck.edit e files.read | Remove uma associação nativa visível após aprovação, repetindo exatamente o recibo `file_state` e o `plan_state` do plano. Preserva o arquivo original e compartilhamentos independentes; não exige files.share. |

Escritas de etiquetas e desvinculação exigem plano atualizado, `confirm: true`, `plan_state` e aprovação de quadro compartilhado quando aplicável. Leituras/escritas verificam novamente o acesso nativo. Referências a arquivos também exigem escopo de Files, verificação de etiquetas de exclusão, restrições de Guests e READ nativo do nó nos aliases do solicitante. Referências invisíveis são omitidas ou respondidas como ausentes, sem contagens nem identificadores; a resposta do card deixa de expor `attachmentCount` nativo. Uploads legados `deck_file` e pastas não são suportados aqui. Criar uma associação nativa usa a operação de vinculação autorizada separadamente abaixo.

Desvincular revoga essa associação para todos os membros atuais e futuros do card, inclusive quando outro editor a criou. Compartilhamentos independentes podem manter o acesso; você pode perder sua última montagem Deck. Não há recuperação automática; restaurar a associação pode exigir a interface nativa do Deck. Um recibo desatualizado ou inválido é recusado. Uma associação confirmada já ausente só não produz efeito com um recibo original válido e o arquivo original ainda visível.

## Vinculação nativa de arquivos

`deck_link_card_file` vincula um arquivo comum existente e visível a um card Deck ativo. Exige `deck.edit`, `files.read` e `files.share`, EDIT nativo no card e READ/SHARE no arquivo. A prévia identifica o proprietário do arquivo, se é recompartilhamento, o total atual de destinatários e até dez nomes (80 caracteres Unicode cada). Deve ser aprovada com `confirm: true`, o `plan_state` exato e `confirm_shared` quando as regras existentes de escrita compartilhada exigirem.

A associação nativa concede READ aos membros atuais e futuros do quadro, inclusive membros herdados de grupos/Teams. Não envia nem copia o arquivo. Não altera compartilhamentos independentes existentes. Uma mudança no arquivo, origem, público, política de compartilhamento ou permissão invalida a confirmação antes da escrita nativa. Verificações do core sobre política e usuário precedem a expansão privilegiada de Teams; o privilégio temporário de Circles nativo é removido antes das autorizações e escritas seguintes e nos limites de operação, inclusive em caminhos de erro. Falha nessa limpeza recusa antes de escrever ou informa efeito incerto após tentativa de escrita.

A operação recusa convidados, pastas, origens arquivadas, destinatários não resolvidos ou não suportados, compartilhamento desativado, compartilhamento limitado aos próprios grupos, expiração interna obrigatória, recompartilhamento proibido e público de grupo/Team quando o compartilhamento com grupos está desativado. Falhas ao determinar identidade ou política também recusam. Não sugere desativar essas proteções. Hooks e notificações de compartilhamento nativo podem executar. A expiração interna padrão opcional não se aplica a esse tipo nativo de compartilhamento; a expiração interna obrigatória é recusada.

É necessário um público completo: no máximo 128 entradas ACL, 1.000 pessoas locais distintas, 5.000 etapas cooperativas de trabalho e dois segundos verificados entre chamadas nativas. Não existe sucesso com público parcial. Alocação nativa de membros e E/S bloqueante ficam fora desses limites cooperativos. Uma prévia limitada de nomes não limita o compartilhamento: a associação alcança todo o público nativo e seus futuros membros.

Alterações de membros/política após a criação não provocam revogação automática pelo MCP; validação e compartilhamento nativo não são uma transação atômica. Esses limites também importam ao interpretar resultado incerto de escrita. Inspecione o estado atual antes de tentar novamente; observar um compartilhamento após um erro não estabelece qual usuário o criou. Da mesma forma, associação ausente após tentativa de criação não prova que nada aconteceu: um hook ou outro usuário pode tê-la removido após conceder acesso ou disparar notificações. Essas falhas continuam incertas e não autorizam nova tentativa às cegas. Desvincular remove apenas a associação nativa e o acesso resultante; o arquivo e os compartilhamentos independentes permanecem.

A política de nomes de arquivo rege as escritas de conteúdo e destinos cobertos de Files/Notes; uma associação nativa Deck não é uma nova escrita de conteúdo de arquivo. Visibilidade de Files, escopo, exclusões e política nativa de compartilhamento continuam valendo.

## Dependências nativas condicionais

As três ferramentas de dependência só aparecem e executam com APIs nativas compatíveis: NC34 com Deck >=1.18.5 e <1.19.0, ou NC35 com Deck >=1.19.0 e <1.20.0, e os métodos/propriedades nativos exigidos. Os alvos fixados de qualificação são Deck 1.18.5 e 1.19.0; aceitar um intervalo não qualifica cada atualização. NC32/33 e formatos de API não suportados omitem as três ferramentas e recusam chamadas diretas.

- `deck_read_card_dependencies` lê o subgrafo legível completo de saída.
- `deck_add_card_dependency` adiciona `cardId` → `targetId`.
- `deck_remove_card_dependency` remove apenas essa relação direcionada.

Para `assignDependentCard(A,B)` nativo, B aparece em `dependentCards` de A; essas relações não bloqueiam automaticamente o progresso das tarefas. Leituras exigem deck.read; escritas exigem deck.edit, EDIT na origem e READ no destino, inclusive para remover. Escritas precisam de aprovação e do `plan_state` retornado. Outras relações ficam intactas; adicionar duplicata ou remover relação ausente não produz efeito.

A travessia é limitada a 100 nós legíveis incluindo a raiz, 500 arestas legíveis e profundidade de caminho mínimo 20. Exceder um limite recusa o resultado em vez de retornar um grafo incompleto. Os mesmos limites de nós/arestas legíveis se aplicam ao retrato completo de adjacência da origem exigido para adicionar, remover e verificar resultados; origens grandes demais precisam ser alteradas pela interface nativa Deck. Uma nova relação também deve caber nesses limites antes da aprovação de seu plano ou escrita. Autorização do destino e consultas de entidades param no primeiro excesso legível. Deck ainda materializa cada adjacência nativa antes de filtrar, e filtrar relações ilegíveis pode exigir consultas adicionais; esses limites não limitam trabalho total nem memória. Cards ilegíveis não consomem o orçamento legível nem revelam IDs, contagens, identificadores ou indicação de limite oculto. Relações consigo mesmo e ciclos detectáveis por cards legíveis são recusados; não se pode excluir ciclos ocultos nem alteração concorrente entre a última verificação e a escrita nativa. Não é garantia de ausência global de ciclos.

Após resultado incerto ou parcial de etiqueta/dependência/vinculação/desvinculação, inspecione o estado atual do Deck antes de agir novamente. Observar o estado solicitado após um erro não prova quem o alterou nem que a solicitação inteira terminou; não repita a escrita automaticamente.

## Comentários dos cards

`deck_list_comments` lê uma página de comentários de um card visível com `deck.read_comments`. Para publicar, chame `deck_add_comment` com o card de destino e a mensagem e mostre a prévia ao usuário. Leitores do quadro podem incluir pessoas fora da conversa original de e-mail. Após aprovação, repita a chamada com `confirm: true` e o `plan_state` retornado. Um quadro compartilhado também exige `confirm_shared: true`. Plano alterado exige nova revisão. Publicar exige `deck.comment` e acesso nativo ao card. Deck permite que leitores comentem mesmo sem poder editar os campos do card.

## Conclusão de cards

Defina `completed` como `true` em `deck_edit_card` para concluir um card ou como `false` para reabri-lo. Omita o campo para preservar o estado atual. Repetir a conclusão mantém sua data/hora original. O card fica na lista atual, inclusive em quadros com lista designada para concluídos. A alteração usa `deck.edit`, permissões nativas do quadro e a prévia e confirmação comuns de edição.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · [Español](../../../es/reference/tools/deck.md) · [Català](../../../ca/reference/tools/deck.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/deck.md)

## Buscar cards

`deck_search_cards` exige a permissão independente `deck.search` e seleção explícita da ferramenta. Busca cards ativos em quadros que o usuário autenticado pode ler, inclusive quadros compartilhados com acesso somente leitura. Não altera cards nem os marca como vistos.

Filtros opcionais: `text` (título e descrição), `boardId`, `assignee` (ID do usuário), `labelIds`, `labelMatch` (`any` ou `all`), `dueAfter` e `dueBefore`. As datas de início/fim são inclusivas no fuso do usuário. Combine filtros para restringir o resultado. A paginação usa `limit` (padrão 50, máximo 100) e `offset`.

A busca examina no máximo 100 quadros, 500 listas e 2.000 cards. Resultado `truncated` significa que um limite de varredura ou resultados impediu resposta completa; restrinja os filtros ou use um quadro específico antes de tirar conclusões de resultados ausentes. ACLs nativas Deck são verificadas para o solicitante; não é possível consultar o quadro privado de outro usuário adivinhando seu ID.
