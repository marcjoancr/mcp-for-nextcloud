<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Talk** | conversas, pesquisa de mensagens e contexto (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir a ligação de um cartão do Deck ou evento que pode ver), mensagem direta para um utilizador, lote de mensagens, partilhar ou citar ficheiro, criar conversa em grupo (permissão própria, desligada por predefinição). **Nada é enviado antes de o utilizador aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou partilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registo no servidor.** |

## Publicar um resumo preparado

`talk_publish_summary` publica o texto `message` fornecido por quem chama numa `conversation_token` existente. Exige a permissão independente `talk.publish_summary`. Mostre a pré-visualização completa e o destino ao utilizador, depois repita com `confirm: true` e o `plan_state` devolvido. O utilizador autenticado deve continuar a poder escrever na conversa. Alterações ao texto aprovado, destino, conjunto de participantes ou última mensagem exigem outra pré-visualização. Conversas com mais de 1.000 participantes e conversas individuais incompletas são recusadas. As verificações finais e o envio nativo são operações separadas, podendo ocorrer alterações simultâneas entre elas. Um erro de entrega pode deixar um resultado incerto. Consulte a conversa antes de tentar novamente.

Para respostas a formulários, use primeiro `forms_read_responses` com `forms.read_responses` e permissão nativa de resultados. O assistente prepara um resumo das respostas acessíveis. Reveja informações pessoais e os leitores do destino antes de aprovar a publicação.

## Ler mensagens anteriores

`talk_read_messages` devolve primeiro a página mais recente, ordenada da mensagem mais antiga para a mais recente dentro de cada página. Para ler a página anterior, indique o `next_before_message_id` devolvido como `before_message_id` com a mesma `conversation_token`. A mensagem do cursor é excluída. Um cursor null significa que se chegou ao início do histórico disponível. Cada página volta a verificar o acesso e preserva mensagens não lidas e notificações.

## Pesquisar e ler contexto

`talk_search_messages` pesquisa texto de mensagens comuns numa `conversation_token`. Exige a operação independente `talk.search`. Os resultados são limitados e paginados; mantenha a conversa e a pesquisa ao seguir `next_offset`. `has_more` indica correspondências restantes; `pagination_limited` distingue o limite de segurança do deslocamento do fim dos resultados. A página predefinida contém 50 correspondências, no máximo 200. A pesquisa exclui eventos do sistema, estruturas de anexos, reações e respostas privadas.

Use o ID de uma mensagem do resultado em `talk_read_message_context` para ler mensagens anteriores e posteriores na mesma conversa. Indique `message_id`, com contagens opcionais `before_limit` e `after_limit` (5 cada por predefinição, no máximo 100 cada). `has_more_before` e `has_more_after` indicam mensagens vizinhas adicionais fora da janela pedida. Esta ferramenta tem atribuição separada e é classificada como `talk.read`; conceder pesquisa não concede leitura de contexto. O contexto é cronológico e a mensagem de referência tem de pertencer à conversa pedida. As verificações nativas de pertença e sala de espera voltam a aplicar-se em cada pedido. Referências a ficheiros são filtradas pelas mesmas verificações de visibilidade da leitura normal do histórico.

Ambas as ferramentas preservam contadores de não lidos, notificações e marcadores de leitura. Novos resultados de pesquisa e contexto limitam o texto das mensagens a 4.000 caracteres e indicam texto truncado. Não têm efeitos de envio.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · Português (Portugal)
