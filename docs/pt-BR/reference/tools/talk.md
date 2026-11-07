<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/talk.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/talk.md).

# Talk

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir o link de um card do Deck ou evento que você pode ver), mensagem direta para um usuário, lote de mensagens, compartilhar ou citar arquivo, criar conversa em grupo (permissão própria, desligada por padrão). **Nada é enviado antes de o usuário aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou compartilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registro no servidor.** |


Consulte `mcp_guide` para o esquema e as operações disponíveis.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/talk.md) · [Español](../../../es/reference/tools/talk.md) · [Català](../../../ca/reference/tools/talk.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/talk.md)
