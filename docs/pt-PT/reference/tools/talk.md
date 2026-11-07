<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir a ligação de um cartão do Deck ou evento que pode ver), mensagem direta para um utilizador, lote de mensagens, partilhar ou citar ficheiro, criar conversa em grupo (permissão própria, desligada por predefinição). **Nada é enviado antes de o utilizador aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou partilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registo no servidor.** |

Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · [Português (Brasil)](../../../pt-BR/reference/tools/talk.md) · Português (Portugal)
