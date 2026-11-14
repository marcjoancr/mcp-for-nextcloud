<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: ad44b6fd690c2e0063884da06ef5e1117cec64b6 -->
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

## Fluxos nativos qualificados

O catálogo define 65 ferramentas de Forms. A disponibilidade depende da extensão
nativa instalada, das permissões atuais e da seleção na conexão.
Use `mcp_guide` com `module: "forms"` para consultar esquemas e permissões,
e `forms_capabilities` para verificar compatibilidade e limites.
As cinco ferramentas anteriores mantêm o requisito mínimo de Forms 5.3.
Os fluxos ampliados exigem a extensão independente de Forms 5.4.2 e uma
combinação qualificada de Nextcloud, banco de dados, armazenamento e escritores
nativos. A versão, sozinha, não habilita essas funções. Consulte o
[guia de instalação e recuperação](../../../../native/forms-guard/README.md).

A ampliação inclui pesquisa e configurações, criação e clonagem, estados e
bloqueios, perguntas e opções, compartilhamento e links, respostas próprias,
resultados e arquivos, exportações, comentários, administração, transferência,
exclusão e recuperação. Clonar reinicia respostas, compartilhamentos e vínculo
de exportação. Permanecem as regras nativas de propriedade e bloqueio permanente.
Há 12 tipos de pergunta disponíveis para criação. O tipo legado `datetime`
continua disponível para leitura e resposta; o tipo de uma pergunta existente
não pode ser alterado. Grades exigem `radio`, `checkbox` ou `number`.
A [referência completa em inglês](../../../en/reference/tools/forms.md) lista
os tipos e as famílias de ferramentas.

## Permissões e confirmação

Operações novas começam negadas e ferramentas novas sem atribuição nem seleção,
inclusive após uma atualização. Ler a estrutura não concede acesso a
configurações, links, resultados, participantes ou arquivos. Acesso às próprias
respostas não permite ler as respostas de outras pessoas. Aplicam-se os papéis
nativos, escopos de recursos, exclusões e restrições de convidados.

Audiência e links públicos exigem `forms.public_link`; configurações de
e-mail de confirmação acrescentam `forms.configure_notifications`.
Compartilhar arquivos pode acrescentar `files.share`. Participantes e arquivos
têm permissões independentes. Exportações exigem as operações de Files
declaradas e acesso ao destino. Continuar, cancelar ou consultar evidências
revalida os privilégios e a seleção atuais da ação original.

Revise a prévia e repita os mesmos argumentos com `confirm: true`,
`plan_state` e `operation_id`; acrescente `confirm_shared: true` quando
solicitado. Mudanças na origem, nas permissões, na conexão ou no destino exigem
uma nova prévia. A prévia não adquire o bloqueio nativo de quinze minutos.
Gerar tokens e preparar uploads também são efeitos confirmados.

## Resultados completos e exportações

Trabalhos completos processam uma cópia estável em etapas limitadas. Continue a
operação original até a conclusão verificada; um resultado pendente não é uma
exportação completa. Há suporte a CSV, ODS e XLSX, preservando anonimato e
permissões adicionais para participantes e arquivos. Fórmulas são neutralizadas.
Downloads são de uso único, vinculados ao ator e à conexão, e duram no máximo
cinco minutos. O cache privado expira após sete dias; evidências e cópias de
recuperação têm ciclos independentes.

Substituir um arquivo exige `overwrite`, o `etag` aprovado, permissão de
edição e recuperação verificada. Uma exportação contínua precisa de conexão
OAuth persistente e aprovação das gravações iniciais e futuras. Mudanças de
audiência, origem, destino ou autoridade podem pausá-la. Desvincular preserva
a saída existente.

## Exclusão e recuperação

Inspecione todas as coleções com `forms_read_operation_impact` antes de
confirmar. As contagens da cópia de uma pergunta descrevem a recuperação de todo
o formulário; não significam excluir todas as respostas. Evidências
`authoring_effects_v1` de título e compartilhamento não são restauráveis.

Excluir uma pergunta a oculta e preserva respostas e opções históricas. Somente
arquivos exatos gerenciados por Forms, aprovados e com recuperação verificada,
são removidos. Excluir o formulário também remove seus comentários nativos e
registros de compartilhamento gerenciados. Outros formulários, arquivos fora do
manifesto e saídas vinculadas são preservados. A transferência conserva
originais e evidências, informando separadamente compartilhamento e exportações.

`forms_restore` cria um formulário novo, privado e fechado, sem republicar
comentários nem recriar tokens ou trabalhos. Exige o ator original e autoridade
nativa atual para recuperar. Possuir uma cópia obtida apenas com permissão de
edição não concede acesso aos resultados. `forms_read_recovered_comments`
exige permissão atual de comentários e a autoridade original de recuperação;
identidades históricas não verificadas são identificadas.

`bulk_v1:` identifica o formato original. Uma cópia posterior com arquivos
históricos já ausentes usa `bulk_history_v2:` e
`whole_form_with_unavailable_history`: informa a contagem e a recuperação
incompleta de bytes históricos, sem inventar arquivos nem reutilizar IDs
pendentes. A cópia anterior à limpeza conserva seus bytes verificados.

Após uma resposta perdida, consulte `forms_get_operation` com o ID exato.
Não repita efeitos incertos nem libere a reserva para tentar novamente.
Cancelar pode preservar cópias e evidências e não garante desfazer mudanças
confirmadas. Banco de dados, Files e eventos têm evidências separadas; uma
tentativa de notificação não comprova entrega.

[Todas as ferramentas](../tools.md) · [Permissões](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · [Español](../../../es/reference/tools/forms.md) · [Català](../../../ca/reference/tools/forms.md) · Português (Brasil) · [Português (Portugal)](../../../pt-PT/reference/tools/forms.md)
