<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/notes.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../../en/reference/tools/notes.md).

# Notes

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Notas** | listar, ler, pesquisar (por título e conteúdo Markdown) | criar, editar, mover entre categorias, excluir (só quando o lixo permite recuperar) |

| Ferramenta | Permissão | Notas |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta da aplicação Notes (preferência `notesPath`, predefinição `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Pesquisa por título e conteúdo Markdown (até 1 MiB), com um excerto contextual da correspondência. Opcionalmente restrita a uma categoria. |
| `notes_read` `{id}` | notes.read | Nota com conteúdo; limite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca substitui ficheiros existentes; em colisão, usa `Title (2)`. |
| `notes_edit` `{id, content?, append?, old?, new?, title?, etag?, plan_state?}` | notes.edit | Conteúdo completo, acrescento ou substituição exata de um excerto; os três modos de conteúdo são mutuamente exclusivos. Edições apenas de título continuam suportadas. A confirmação parcial exige o ETag e o estado opaco devolvidos pelo plano. |
| `notes_move` `{id, category, etag?}` | notes.move | Muda de categoria (subpasta), sem substituir ficheiros existentes. |
| `notes_delete` `{id, etag?}` | notes.delete | Exige `confirm: true`, a aplicação de ficheiros eliminados (`files_trashbin`) ativa e o armazenamento da nota coberto por ela; em armazenamento externo sem reciclagem, a eliminação é bloqueada. |

## Edições parciais

Use `append` para acrescentar texto numa nova linha preservando o estilo de finais de linha da nota, ou o par `old`/`new` para substituir uma passagem. Não os combine com `content`. A passagem antiga deve ser não vazia, UTF-8 válido e ter no máximo 64 KiB em bytes; ocorrências ausentes ou múltiplas, incluindo sobrepostas, são recusadas sem revelar uma contagem. O resultado mantém-se dentro do limite de 1 MiB da nota.

Leia a nota, peça um plano, mostre-o ao utilizador e repita os mesmos argumentos de efeito com `confirm: true`, `etag` e `plan_state`. Uma nota alterada ou plano desatualizado é recusado, incluindo conteúdo alterado sem um novo ETag. O conteúdo é relido no ponto de escrita, mas o armazenamento nativo não dispõe aqui de comparação e troca atómica: uma escrita concorrente após essa leitura final continua possível. Mantêm-se os bloqueios de ficheiros, permissões, visibilidade, confirmação de escrita partilhada e política opcional de nomes de ficheiro.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

As ferramentas Notes exigem a aplicação Notes ativada para o utilizador. A leitura começa permitida; a criação, edição, movimentação e eliminação começam recusadas até o administrador as permitir. Não há um tempo limite próprio: a leitura é local ao PHP e o limite de bytes protege contra ficheiros grandes. O armazenamento externo lento é limitado pelo `max_execution_time` do PHP.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/notes.md) · [Español](../../../es/reference/tools/notes.md) · [Català](../../../ca/reference/tools/notes.md) · [Português (Brasil)](../../../pt-BR/reference/tools/notes.md) · Português (Portugal)
