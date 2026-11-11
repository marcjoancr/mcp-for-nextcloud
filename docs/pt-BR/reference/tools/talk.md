<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Talk** | conversas, busca de mensagens e contexto (nunca marca nada como lido) | responder (podendo citar uma mensagem ou incluir o link de um card do Deck ou evento que você pode ver), mensagem direta para um usuário, lote de mensagens, compartilhar ou citar arquivo, criar conversa em grupo (permissão própria, desligada por padrão). **Nada é enviado antes de o usuário aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou compartilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registro no servidor.** |


## Publicar um resumo preparado

`talk_publish_summary` publica o texto `message` fornecido pelo solicitante em um `conversation_token` existente. Exige a permissão independente `talk.publish_summary`. Mostre a prévia completa e o destino ao usuário, depois repita com `confirm: true` e o `plan_state` retornado. O usuário autenticado ainda deve poder escrever na conversa. Alterações no texto aprovado, destino, conjunto de participantes ou mensagem mais recente exigem outra prévia. Conversas com mais de 1.000 participantes e conversas individuais incompletas são recusadas. As verificações finais e o envio nativo são operações separadas; podem ocorrer mudanças concorrentes entre elas. Um erro de entrega pode deixar resultado incerto. Confira a conversa antes de tentar novamente.

Para respostas de formulários, use primeiro `forms_read_responses` com `forms.read_responses` e permissão nativa de resultados. O assistente prepara um resumo das respostas acessíveis. Revise informações pessoais e quem pode ler no destino antes de aprovar a publicação.

## Ler mensagens antigas

`talk_read_messages` retorna a página mais recente primeiro, ordenada da mais antiga para a mais nova dentro de cada página. Para ler a página anterior, informe o `next_before_message_id` retornado como `before_message_id` com o mesmo `conversation_token`. A mensagem do cursor é excluída. Cursor nulo significa que chegou ao início do histórico disponível. Cada página verifica novamente o acesso e preserva mensagens não lidas e notificações.

## Buscar e ler contexto

`talk_search_messages` busca texto comum de mensagens em um `conversation_token`. Exige a operação independente `talk.search`. Os resultados são limitados e paginados; mantenha conversa e consulta iguais ao seguir `next_offset`. `has_more` indica correspondências restantes; `pagination_limited` distingue o teto de segurança de deslocamento do fim dos resultados. A página padrão contém 50 correspondências, máximo 200. A busca exclui eventos de sistema, envelopes de anexos, reações e respostas privadas.

Use o ID de uma mensagem encontrada com `talk_read_message_context` para ler mensagens anteriores e posteriores ao redor dela na mesma conversa. Informe `message_id`, com contagens opcionais `before_limit` e `after_limit` (5 cada por padrão, máximo 100 cada). `has_more_before` e `has_more_after` indicam vizinhos adicionais fora da janela solicitada. É uma atribuição separada de ferramenta, classificada como `talk.read`; conceder busca não concede leitura de contexto. O contexto é cronológico e a mensagem âncora deve pertencer à conversa solicitada. Verificações nativas de membros e sala de espera se aplicam novamente a cada solicitação. Referências a arquivos são filtradas pelas mesmas verificações de visibilidade da leitura comum de histórico.

Ambas as ferramentas preservam contadores de não lidos, notificações e marcadores de leitura. Novos resultados de busca e contexto limitam texto de mensagens a 4.000 caracteres e indicam texto truncado. Não têm efeitos de envio.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
