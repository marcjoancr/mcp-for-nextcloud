<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../../en/reference/tools/forms.md).

# Forms

As ferramentas de Forms exigem Forms 5.3.0 ou superior, disponibilidade para a conta autenticada, atribuições explícitas de ferramentas e seleção na conexão. As permissões nativas de Forms são verificadas novamente em cada solicitação.

`forms_list` lista formulários acessíveis; `forms_read` lê perguntas e opções. Ambas usam `forms.read`. `forms_read_responses` exige a operação separada `forms.read_responses` e a permissão nativa para visualizar resultados. Poder enviar um formulário ou visualizar apenas a própria resposta não concede acesso ao conjunto completo de resultados.

## Resumir respostas

`forms_summarize_responses` exige `forms.summarize_responses`. Informe `id`, com `offset` e `limit` opcionais (padrão 50, máximo 100 envios). Retorna contagens determinísticas de respostas por pergunta para essa página, com `submissionsAnalyzed`, `partial`, `hasMore` e `nextOffset`. Siga o deslocamento retornado para continuar; o resumo de uma página não resume o formulário inteiro. Considera no máximo 50 registros de metadados de perguntas e 200 respostas por envio, com limite de 8.000 respostas examinadas e 4 MiB de texto de respostas. Os limites de texto e varredura também podem tornar os resultados parciais.

O resumo não contém identificadores dos respondentes. Respostas com upload de arquivos são omitidas; nenhum arquivo é aberto ou baixado. A permissão nativa de resultados é obrigatória mesmo quando o solicitante já pode ler as perguntas do formulário.

## Exportar respostas para Files

`forms_export_responses` exige `forms.export_responses` **e** `files.create`, além de acesso aos resultados do formulário e à pasta de destino. Informe `id`, um destino `.csv` novo em `path` e `offset` e `limit` opcionais (padrão 50, máximo 100 envios).

A primeira chamada prepara uma prévia sem gravar. Mostre ao usuário a página de origem, o destino e o aviso de resultado parcial. Após aprovação, repita os mesmos argumentos com `confirm: true` e o estado do plano retornado. Uma mudança na página de origem ou no destino exige nova prévia. Um destino compartilhado também exige a confirmação existente de recurso compartilhado de Files. Arquivos existentes não são sobrescritos.

O CSV contém uma linha por resposta, com ID do envio, data/hora, ID da pergunta, pergunta e resposta. Identidades de respondentes e respostas com upload de arquivos ficam excluídas. Células que poderiam ser fórmulas de planilha são neutralizadas. A exportação é limitada a 1 MiB e 8.000 linhas; resultados parciais são indicados explicitamente. Células de texto de pergunta e resposta são limitadas a 1.024 bytes, com detalhes de truncamento e linhas omitidas na prévia. Restrições de escopo de Files e permissões nativas da pasta continuam valendo.

[Todas as ferramentas](../tools.md) · [Permissões](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · [Español](../../../es/reference/tools/forms.md) · [Català](../../../ca/reference/tools/forms.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
