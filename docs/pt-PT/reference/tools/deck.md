<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/deck.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/deck.md).

# Deck

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Deck** | quadros, listas, cartões e **acompanhamento** dos quadros que gere: cartões por responsável, com prazo, indicação de atraso (no seu fuso horário) e ligação do cartão | criar, editar, mover e excluir cartões, e atribuir ou remover responsáveis; criar um quadro com as suas listas e primeiros cartões, ou uma lista num quadro que gere; excluir um quadro ou uma lista apenas quando estiver vazio, diretamente para a reciclagem do Deck |

## Deck: cartões, quadros e listas

As escritas do Deck usam as permissões `deck.create`, `deck.edit`, `deck.move` e `deck.delete`, todas desligadas por predefinição, e `confirm_shared: true` num quadro de outro proprietário. A aplicação confirma sempre com o Deck se a escrita foi gravada, mesmo depois de um erro do Deck.

| Ferramenta de escrita | Permissão | Comportamento |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Cria um quadro do próprio utilizador com as suas listas e primeiros cartões (até 20 listas e 100 cartões), com cor opcional. Num quadro novo os responsáveis são o próprio utilizador, porque mais ninguém tem acesso. O plano mostra a árvore inteira; se um passo falhar no meio, nada é desfeito e o resultado traz `created`, `failed` (com motivo seguro) e `warnings`, para a repetição não duplicar nada. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Acrescenta uma lista a um quadro que o utilizador gere, no fim ou numa posição. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Elimina uma lista **apenas quando está vazia**: qualquer cartão, ativo ou arquivado, recusa a chamada dizendo quantos restam, e a contagem é refeita no confirm. Vai para o lixo do Deck. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Igual para o quadro, e só o proprietário pode eliminar. Se um cartão aparecer entre a contagem e a exclusão, o quadro volta com o próprio undo do Deck e a chamada é recusada dizendo que nada foi excluído. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` e a atribuição de responsáveis usam os mesmas permissões e a mesma confirmação; os responsáveis são membros do quadro.

Após um erro proveniente do Deck, cada escrita volta a ler o estado real antes de responder: se o item foi gravado, a resposta é sucesso com o aviso "Saved; Deck reported an error afterwards (notification or activity)"; se não foi, é o erro de sempre; e se o estado não pode ser lido, a resposta não é erro e manda ler com `deck_list_cards`, `deck_read_card`, `deck_list_stacks` ou `deck_list_boards`. Um item reencontrado só por semelhança (mesmo dono, mesmo título, criado há pouco) vem como `confirmed: "probable"`, com aviso para verificar, e nada mais é escrito nele. Só as recusas que a própria aplicação faz antes de chamar o Deck (regras da ferramenta, sessão, conflitos, argumentos) dispensam essa releitura.

A exclusão "apenas quando vazia" não é atómica: o Deck não consulta o `deleted_at` da lista ao criar um cartão, então um cartão criado por outro pedido logo após a segunda contagem pode ir para o lixo junto com a lista ou o quadro, de onde é recuperável. Isso está na descrição da ferramenta e no gateway.

## Prazos

A criação e a edição de cartões aceitam `duedate` como `YYYY-MM-DD` ou `YYYY-MM-DDTHH:MM[:SS]`, com `Z` ou desvio numérico opcionais. Sem desvio, usa-se o fuso horário da conta; uma data sem hora corresponde à meia-noite local desse dia. Horas locais explícitas inexistentes numa mudança de fuso horário são recusadas. Uma hora repetida seleciona o primeiro instante, salvo se o desvio fornecido resolver a ambiguidade. A persistência converte para UTC antes de chamar o Deck. As respostas mantêm `duedate` baseado no dia, a indicação de atraso e `dueBefore`, acrescentando `dueAt` com o desvio da conta. Edições não relacionadas preservam o instante existente.

Escritas MCP anteriores afetadas podem ter guardado o dia errado. Reveja esses cartões face à data pretendida e corrija-os explicitamente no Deck ou com uma nova edição confirmada. Não desloque todos os prazos existentes: datas introduzidas por outros clientes podem já estar corretas. Nenhuma data histórica é reescrita automaticamente.

## Etiquetas e ficheiros visíveis dos cartões

| Ferramenta | Permissão | Comportamento |
| --- | --- | --- |
| `deck_list_labels` `{boardId, limit=50, offset=0}` | deck.read | Etiquetas de um quadro legível, até 200 por página. |
| `deck_edit_card_labels` `{cardId, add?, remove?}` | deck.edit | IDs de etiquetas incrementais, no máximo 100 por lista. Preserva etiquetas não relacionadas; duplicados/alterações já satisfeitas não alteram nada; etiquetas de outro quadro e pedidos sobrepostos de adição/remoção são recusados. |
| `deck_list_card_files` `{cardId}` | deck.read e files.read | Apenas associações nativas a ficheiros existentes e visíveis, ordenadas por `attachmentId`. Este ID de partilha/associação difere do `fileId` original. |
| `deck_unlink_card_file` `{cardId, attachmentId}` | deck.edit e files.read | Remove uma associação nativa visível após aprovação, repetindo exatamente o comprovativo `file_state` e o `plan_state` do plano. Preserva o ficheiro original e as partilhas independentes; não exige files.share. |

As escritas de etiquetas e remoção de associação exigem um plano novo, `confirm: true`, `plan_state` e aprovação de quadro partilhado quando aplicável. Leituras/escritas voltam a verificar o acesso nativo. As referências a ficheiros também exigem o âmbito Files, verificações de etiquetas de exclusão, restrições Guests e READ nativo do nó através dos aliases de quem chama. Referências invisíveis são omitidas ou tratadas como inexistentes, sem contagens ou identificadores; a resposta do cartão deixa de expor `attachmentCount` nativo. Carregamentos antigos `deck_file` e pastas não são suportados aqui. Criar uma associação nativa a um ficheiro usa a operação autorizada separadamente abaixo.

Remover a associação revoga-a para todos os membros atuais e futuros do cartão, incluindo quando outro editor a criou. Partilhas independentes podem manter o acesso; pode perder a sua última montagem Deck. Não há recuperação automática; restaurar a associação pode exigir a interface nativa do Deck. Um comprovativo desatualizado ou inválido é recusado. Confirmar uma associação já ausente só é uma operação sem efeito com um comprovativo original válido e o ficheiro original ainda visível.

## Associação nativa de ficheiros

`deck_link_card_file` associa um ficheiro normal existente e visível a um cartão Deck ativo. Exige `deck.edit`, `files.read` e `files.share`, EDIT nativo no cartão e READ/SHARE no ficheiro. A pré-visualização identifica o proprietário do ficheiro, se é uma repartilha, o total atual de destinatários e até dez nomes (80 caracteres Unicode cada). Deve ser aprovada com `confirm: true`, o `plan_state` exato e `confirm_shared` quando as regras existentes de escrita partilhada o exigirem.

A associação nativa dá acesso READ aos membros atuais e futuros do quadro, incluindo membros herdados de grupos/Teams. Não carrega nem copia o ficheiro. Não altera partilhas independentes existentes. Uma alteração no ficheiro, origem, público, política de partilha ou permissão invalida a confirmação antes da escrita nativa. As verificações da política do núcleo e de quem executa precedem a expansão privilegiada de Teams; o privilégio temporário nativo de Circles é removido antes das autorizações e escritas nativas seguintes e nos limites da operação, incluindo em erros. Uma falha de limpeza recusa antes de escrever ou indica um efeito incerto após uma tentativa de escrita.

A operação recusa utilizadores Guests, pastas, origens arquivadas, destinatários não resolvidos ou não suportados, partilha desativada, partilha apenas dentro de grupos, expiração interna obrigatória, repartilha proibida e públicos de grupo/Team quando a partilha de grupos está desativada. A impossibilidade de determinar identidade ou política também recusa a operação. Não sugere desativar essas proteções. Podem ser executados hooks e notificações nativos de partilha. A expiração interna predefinida opcional não se aplica a este tipo de partilha nativa; a expiração interna obrigatória é recusada.

É necessário um público completo: no máximo 128 entradas ACL, 1.000 pessoas locais distintas, 5.000 passos de trabalho cooperativo e dois segundos verificados entre chamadas nativas. Não há sucesso com público parcial. A alocação nativa de membros e E/S bloqueante ficam fora destes limites cooperativos. Uma pré-visualização limitada de nomes não limita a partilha: a associação chega a todo o público nativo e aos seus membros futuros.

Alterações de membros/política após a criação não desencadeiam revogação automática pelo MCP; a validação e a partilha nativa não são uma transação atómica. Estes limites também importam ao interpretar um resultado de escrita incerto. Consulte o estado atual antes de repetir; observar uma partilha após um erro não determina quem a criou. Inversamente, a ausência de associação após uma tentativa de criação não prova que nada aconteceu: um hook ou outro interveniente pode tê-la removido depois de conceder acesso ou desencadear notificações. Tais falhas continuam incertas e não autorizam repetição cega. A remoção elimina apenas a associação nativa e o acesso resultante; o ficheiro e as partilhas independentes permanecem.

A política de nomes rege as escritas de conteúdo e destinos abrangidos de Files/Notes; uma associação Deck nativa não é uma nova escrita de conteúdo. A visibilidade Files, âmbito, exclusões e política nativa de partilha continuam a aplicar-se.

## Dependências nativas condicionais

As três ferramentas de dependências só aparecem e são executadas com APIs nativas compatíveis: NC34 com Deck >=1.18.5 e <1.19.0, ou NC35 com Deck >=1.19.0 e <1.20.0, e os métodos/propriedades nativos exigidos. Os alvos fixados de qualificação são Deck 1.18.5 e 1.19.0; admitir um intervalo não qualifica todas as versões de correção. NC32/33 e formatos de API não suportados omitem as três ferramentas e recusam chamadas diretas.

- `deck_read_card_dependencies` lê o subgrafo completo de saída legível.
- `deck_add_card_dependency` adiciona `cardId` → `targetId`.
- `deck_remove_card_dependency` remove apenas essa relação dirigida.

Em `assignDependentCard(A,B)` nativo, B aparece em `dependentCards` de A; estas relações não bloqueiam automaticamente o progresso de tarefas. Leituras exigem deck.read; escritas exigem deck.edit, EDIT na origem e READ no destino, mesmo para remover. As escritas exigem aprovação e o `plan_state` devolvido. Outras relações permanecem; adicionar um duplicado ou remover algo ausente não tem efeito.

A travessia está limitada a 100 nós legíveis incluindo a raiz, 500 arestas legíveis e profundidade de caminho mais curto de 20. Exceder um limite recusa o resultado em vez de devolver um grafo incompleto. Os mesmos limites de nós/arestas legíveis aplicam-se ao retrato completo da adjacência de origem exigido para adicionar, remover e verificar resultados; origens demasiado grandes devem ser alteradas na interface nativa do Deck. Uma nova relação também tem de caber nestes limites antes de o plano ou escrita ser aprovado. A autorização do destino e as consultas de entidades param no primeiro excesso legível. O Deck ainda materializa cada adjacência nativa antes de filtrar, e filtrar relações ilegíveis pode exigir consultas adicionais; estes limites não limitam o trabalho total ou memória. Cartões ilegíveis não consomem o orçamento legível nem revelam IDs, contagens, identificadores ou indicação de limite oculto. Relações consigo próprio e ciclos detetáveis através de cartões legíveis são recusados; ciclos ocultos e alterações simultâneas entre a última verificação e a escrita nativa não podem ser excluídos. Não há garantia global de ausência de ciclos.

Após um resultado incerto ou parcial de etiquetas/dependências/associação/remoção, consulte o estado atual do Deck antes de agir. Observar o estado pedido após um erro não prova quem o alterou nem que todo o pedido terminou; não repita automaticamente a escrita.

## Comentários dos cartões

`deck_list_comments` lê uma página de comentários de um cartão visível com `deck.read_comments`. Para publicar, chame `deck_add_comment` com o cartão e a mensagem e mostre a pré-visualização ao utilizador. Os leitores do quadro podem incluir pessoas fora da conversa de correio original. Após aprovação, repita a chamada com `confirm: true` e o `plan_state` devolvido. Um quadro partilhado também exige `confirm_shared: true`. Um plano alterado exige nova revisão. Publicar exige `deck.comment` e acesso nativo ao cartão. Deck permite que leitores comentem mesmo sem poderem editar campos do cartão.

## Conclusão de cartões

Defina `completed` como `true` em `deck_edit_card` para concluir um cartão, ou `false` para o reabrir. Omita o campo para preservar o estado atual. Repetir uma conclusão mantém a data/hora original. O cartão permanece na lista atual, incluindo em quadros com uma lista de concluídos designada. A alteração usa `deck.edit`, permissões nativas do quadro e a pré-visualização e confirmação habituais das edições.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/deck.md) · [Español](../../../es/reference/tools/deck.md) · [Català](../../../ca/reference/tools/deck.md) · [Português (Brasil)](../../../pt-BR/reference/tools/deck.md) · Português (Portugal)

## Pesquisar cartões

`deck_search_cards` exige a permissão independente `deck.search` e seleção explícita da ferramenta. Pesquisa cartões ativos em quadros que o utilizador autenticado pode ler, incluindo quadros partilhados apenas de leitura. Não altera cartões nem os marca como vistos.

Os filtros opcionais são `text` (título e descrição), `boardId`, `assignee` (ID de utilizador), `labelIds`, `labelMatch` (`any` ou `all`), `dueAfter` e `dueBefore`. Os extremos das datas são inclusivos no fuso horário do utilizador. Combine filtros para restringir o resultado. A paginação usa `limit` (50 por predefinição, máximo 100) e `offset`.

A pesquisa examina no máximo 100 quadros, 500 listas e 2.000 cartões. Um resultado `truncated` significa que um limite de análise ou resultados impediu uma resposta completa; restrinja os filtros ou use um quadro específico antes de tirar conclusões sobre resultados ausentes. As ACLs nativas Deck são verificadas para quem chama; não se pode consultar o quadro privado de outra pessoa adivinhando o ID.
