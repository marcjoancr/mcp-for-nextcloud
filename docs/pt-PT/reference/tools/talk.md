<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: a24d67927e4a1a55c6da5d64a09cb0bf3c870590 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir a ligação de um card do Deck ou evento que pode ver), mensagem direta para um utilizador, lote de mensagens, partilhar ou citar ficheiro, criar conversa em grupo (permissão própria, desligada por predefinição). **Nada é enviado antes de o utilizador aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou partilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registo no servidor.** |

## Ler mensagens anteriores

`talk_read_messages` devolve primeiro a página mais recente. Em cada página, as mensagens aparecem da mais antiga para a mais recente. Para recuar, passe o `next_before_message_id` recebido como `before_message_id` com o mesmo `conversation_token`. A página exclui a mensagem do cursor. Um cursor nulo indica o início do histórico disponível. Cada página volta a verificar o acesso e preserva as mensagens não lidas e as notificações.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · Português (Portugal)
