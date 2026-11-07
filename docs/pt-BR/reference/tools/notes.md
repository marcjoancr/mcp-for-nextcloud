<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools/notes.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/notes.md).

# Notas

| Módulo | Leitura | Escrita |
| --- | --- | --- |
| **Notas** | listar, ler, buscar (por título e conteúdo Markdown) | criar, editar, mover entre categorias, excluir (só quando a lixeira permite recuperar) |

| Ferramenta | Permissão | Observações |
| --- | --- | --- |
| `notes_list` `{}` | notes.read | Notas `.md`/`.txt` da pasta do app Notes (preferência `notesPath`, padrão `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Busca por título e conteúdo Markdown (até 1 MiB), com trecho contextual da ocorrência. Opcionalmente restrita a uma categoria. |
| `notes_read` `{id}` | notes.read | Nota com conteúdo; limite de 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Nunca sobrescreve; em colisão, usa `Title (2)`. |
| `notes_edit` `{id, content?, append?, old?, new?, title?, etag?, plan_state?}` | notes.edit | Conteúdo completo, acréscimo ou substituição de um único trecho exato; os três modos de conteúdo são mutuamente exclusivos. Edição apenas do título continua disponível. A confirmação parcial exige o ETag e o estado opaco retornados pelo plano. |
| `notes_move` `{id, category, etag?}` | notes.move | Troca de categoria (subpasta), sem sobrescrever. |
| `notes_delete` `{id, etag?}` | notes.delete | Exige `confirm: true`, a lixeira (`files_trashbin`) ativa e o storage da nota coberto por ela; em storage externo sem lixeira, a exclusão é bloqueada. |


## Edições parciais

Use `append` para adicionar texto em uma nova linha preservando o estilo de quebra de linha da nota, ou o par `old`/`new` para substituir um trecho. Não combine com `content`. O trecho antigo deve ser não vazio, UTF-8 válido e ter no máximo 64 KiB em bytes; trechos ausentes ou com múltiplas ocorrências, inclusive sobrepostas, são recusados sem informar a quantidade. O resultado continua sujeito ao limite de 1 MiB por nota.

Leia a nota, solicite um plano, mostre-o ao usuário e repita os mesmos argumentos de efeito com `confirm: true`, `etag` e `plan_state`. Uma nota alterada ou plano desatualizado é recusado, inclusive se o conteúdo mudou sem novo ETag. O conteúdo é relido no ponto de escrita, mas o armazenamento nativo não oferece comparação e troca atômica aqui: outra escrita após essa leitura final continua possível. Travas, permissões, visibilidade, confirmação de escrita compartilhada e a política opcional de nomes de arquivo continuam valendo.

Consulte `mcp_guide` para o esquema e as operações disponíveis.

Tools de Notes exigem o app Notes habilitado para o usuário. Leitura começa permitida; criação, edição, movimentação e exclusão começam negadas até o administrador liberar. Não há timeout próprio: a leitura é local ao PHP e o limite de bytes protege contra arquivos grandes. Storage externo lento fica limitado ao `max_execution_time` do PHP.

[Guias de operação](../../guides/README.md).

[English](../../../en/reference/tools/notes.md) · [Español](../../../es/reference/tools/notes.md) · [Català](../../../ca/reference/tools/notes.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/notes.md)
