<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** consulte a [versão em inglês](../../../en/reference/tools/forms.md).

# Forms

As ferramentas Forms exigem Forms 5.3.0 ou posterior, disponibilidade para a conta autenticada, atribuições explícitas das ferramentas e seleção na ligação. As permissões nativas de Forms são novamente verificadas em cada pedido.

`forms_list` lista formulários acessíveis; `forms_read` lê perguntas e opções. Ambas usam `forms.read`. `forms_read_responses` exige a operação separada `forms.read_responses` e a permissão nativa para consultar resultados. Poder submeter um formulário ou consultar apenas a própria submissão não concede acesso ao conjunto completo de resultados.

## Resumir respostas

`forms_summarize_responses` exige `forms.summarize_responses`. Indique `id` e, opcionalmente, `offset` e `limit` (50 submissões por predefinição, máximo 100). Devolve contagens determinísticas das respostas por pergunta nessa página, com `submissionsAnalyzed`, `partial`, `hasMore` e `nextOffset`. Siga o deslocamento devolvido para continuar; o resumo de uma página não resume o formulário inteiro. São considerados no máximo 50 registos de metadados de perguntas e 200 respostas por submissão, com um limite de 8.000 respostas examinadas e 4 MiB de texto de respostas. Os limites de texto e análise também podem tornar os resultados parciais.

O resumo não contém identificadores dos respondentes. Respostas com ficheiros carregados são omitidas; nenhum ficheiro é aberto ou transferido. A permissão nativa de resultados é obrigatória mesmo que quem chama já possa ler as perguntas do formulário.

## Exportar respostas para Files

`forms_export_responses` exige `forms.export_responses` **e** `files.create`, além de acesso aos resultados do formulário e à pasta de destino. Indique `id`, um novo destino `.csv` em `path` e, opcionalmente, `offset` e `limit` (50 submissões por predefinição, máximo 100).

A primeira chamada prepara uma pré-visualização sem escrever. Mostre ao utilizador a página de origem, o destino e o aviso de resultados parciais. Após aprovação, repita os mesmos argumentos com `confirm: true` e o estado do plano devolvido. Uma página de origem ou destino alterados exigem nova pré-visualização. Um destino partilhado também exige a confirmação existente de recurso partilhado de Files. Ficheiros existentes não são substituídos.

O CSV contém uma linha por resposta, com ID de submissão, data/hora, ID da pergunta, pergunta e resposta. As identidades dos respondentes e respostas com ficheiros carregados são excluídas. Células que possam ser fórmulas de folha de cálculo são neutralizadas. A exportação está limitada a 1 MiB e 8.000 linhas; resultados parciais são explicitamente indicados. As células de texto de perguntas e respostas estão limitadas a 1.024 bytes, com detalhes de truncagem e linhas omitidas na pré-visualização. As restrições de âmbito Files e as permissões nativas da pasta continuam a aplicar-se.

[Todas as ferramentas](../tools.md) · [Permissões](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · [Español](../../../es/reference/tools/forms.md) · [Català](../../../ca/reference/tools/forms.md) · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · Português (Portugal)
