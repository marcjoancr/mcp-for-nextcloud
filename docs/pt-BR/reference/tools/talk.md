<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: a527ea9ff481a7a1f060b3162496bcfaadd2d928 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir o link de um card do Deck ou evento que você pode ver), mensagem direta para um usuário, lote de mensagens, compartilhar ou citar arquivo, criar conversa em grupo (permissão própria, desligada por padrão). **Nada é enviado antes de o usuário aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou compartilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registro no servidor.** |


## Ler mensagens anteriores

`talk_read_messages` retorna primeiro a página mais recente. Em cada página, os itens aparecem do mais antigo para o mais recente. Para voltar uma página, passe o `next_before_message_id` recebido como `before_message_id` com o mesmo `conversation_token`. A página exclui a mensagem do cursor. Um cursor nulo indica o início do histórico disponível. Cada página verifica o acesso novamente e preserva as mensagens não lidas e as notificações.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)

`forms_read_responses` consulta respostas com `forms.read_responses` e permissão nativa para ver resultados. `talk_publish_summary` publica o texto `message` em uma conversa existente com a permissão independente `talk.publish_summary`. Mostre o texto completo e o destino antes de repetir a chamada com `confirm: true` e o `plan_state` recebido. Se mudarem, é necessária outra prévia.
