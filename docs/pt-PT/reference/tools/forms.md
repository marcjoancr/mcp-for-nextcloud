<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools/forms.md -->
<!-- translation-source-revision: ad44b6fd690c2e0063884da06ef5e1117cec64b6 -->
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

## Fluxos nativos qualificados

O catálogo define 65 ferramentas de Forms. A disponibilidade depende da extensão
nativa instalada, das permissões atuais e da seleção na ligação.
Use `mcp_guide` com `module: "forms"` para consultar esquemas e permissões,
e `forms_capabilities` para verificar compatibilidade e limites.
As cinco ferramentas anteriores mantêm o mínimo de Forms 5.3. Os fluxos
alargados exigem a extensão independente de Forms 5.4.2 e uma combinação
qualificada de Nextcloud, base de dados, armazenamento e escritores nativos.
A versão, por si só, não ativa estas funções. Consulte o
[guia de instalação e recuperação](../../../../native/forms-guard/README.md).

A extensão inclui pesquisa e definições, criação e clonagem, estados e
bloqueios, perguntas e opções, partilha e ligações públicas, respostas próprias,
resultados e ficheiros, exportações, comentários, administração, transferência,
eliminação e recuperação. Clonar reinicia respostas, partilhas e ligação de
exportação. Mantêm-se as regras nativas de propriedade e bloqueio permanente.
Podem criar-se 12 tipos de pergunta. O tipo legado `datetime` continua
disponível para leitura e resposta; não se pode alterar o tipo de uma pergunta
existente. As grelhas exigem `radio`, `checkbox` ou `number`.
A [referência completa em inglês](../../../en/reference/tools/forms.md) enumera
os tipos e as famílias de ferramentas.

## Permissões e confirmação

As operações novas começam recusadas e as ferramentas novas sem atribuição nem
seleção, incluindo após uma atualização. Ler a estrutura não concede acesso a
definições, ligações, resultados, participantes ou ficheiros. Acesso às próprias
respostas não permite ler as respostas de outras pessoas. Aplicam-se os papéis
nativos, âmbitos de recursos, exclusões e restrições de convidados.

A audiência e as ligações públicas exigem `forms.public_link`; as definições
de correio de confirmação acrescentam `forms.configure_notifications`.
Partilhar ficheiros pode acrescentar `files.share`. Participantes e ficheiros
têm permissões independentes. As exportações exigem as operações de Files
declaradas e acesso ao destino. Continuar, cancelar ou consultar evidências
revalida os privilégios e a seleção atuais da ação original.

Reveja a pré-visualização e repita os mesmos argumentos com `confirm: true`,
`plan_state` e `operation_id`; acrescente `confirm_shared: true` quando
solicitado. Alterações à origem, permissões, ligação ou destino exigem uma nova
pré-visualização. A pré-visualização não adquire o bloqueio nativo de quinze
minutos. Gerar tokens e preparar carregamentos também são efeitos confirmados.

## Resultados completos e exportações

Os trabalhos completos processam uma imagem estável em passos limitados.
Continue a operação original até à conclusão verificada; um resultado pendente
não é uma exportação completa. São suportados CSV, ODS e XLSX, preservando
anonimato e permissões adicionais para participantes e ficheiros. As fórmulas
são neutralizadas. As transferências para o cliente são de utilização única,
associadas ao ator e à ligação, e duram no máximo cinco minutos. A cache privada
caduca após sete dias; evidências e cópias de recuperação têm ciclos separados.

Substituir um ficheiro exige `overwrite`, o `etag` aprovado, permissão de
edição e recuperação verificada. Uma exportação contínua necessita de ligação
OAuth persistente e aprovação das escritas iniciais e futuras. Alterações à
audiência, origem, destino ou autoridade podem pausá-la. Desvincular preserva
a saída existente.

## Eliminação e recuperação

Inspecione todas as coleções com `forms_read_operation_impact` antes de
confirmar. As contagens da cópia de uma pergunta descrevem a recuperação de todo
o formulário, não a eliminação de todas as respostas. As evidências
`authoring_effects_v1` de título e partilha não são restauráveis.

Eliminar uma pergunta oculta-a e conserva respostas e opções históricas.
Apenas são removidos ficheiros exatos geridos por Forms, aprovados e com
recuperação verificada. Eliminar o formulário também remove os seus comentários
nativos e registos de partilha geridos. Outros formulários, ficheiros fora do
manifesto e saídas vinculadas são preservados. A transferência conserva originais
e evidências e informa separadamente os efeitos de partilha e exportação.

`forms_restore` cria um formulário novo, privado e fechado, sem republicar
comentários nem recriar tokens ou trabalhos. Exige o ator original e autoridade
nativa atual para recuperar. Possuir uma cópia obtida apenas com permissão de
edição não concede acesso aos resultados. `forms_read_recovered_comments`
exige permissão atual de comentários e a autoridade original de recuperação;
as identidades históricas não verificadas são identificadas.

`bulk_v1:` identifica o formato original. Uma cópia posterior com ficheiros
históricos já ausentes usa `bulk_history_v2:` e
`whole_form_with_unavailable_history`: declara a contagem e a recuperação
incompleta dos bytes históricos, sem inventar ficheiros nem reutilizar IDs
pendentes. A cópia anterior à limpeza conserva os seus bytes verificados.

Após uma resposta perdida, consulte `forms_get_operation` com o ID exato.
Não repita efeitos incertos nem liberte a reserva para tentar novamente.
Cancelar pode conservar cópias e evidências e não garante desfazer alterações
confirmadas. Base de dados, Files e eventos têm evidências separadas; uma
tentativa de notificação não comprova a entrega.

[Todas as ferramentas](../tools.md) · [Permissões](../../guides/permissions.md)

[English](../../../en/reference/tools/forms.md) · [Español](../../../es/reference/tools/forms.md) · [Català](../../../ca/reference/tools/forms.md) · [Português (Brasil)](../../../pt-BR/reference/tools/forms.md) · Português (Portugal)
