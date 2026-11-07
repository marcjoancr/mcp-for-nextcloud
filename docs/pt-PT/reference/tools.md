<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/tools.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/reference/tools.md).

# Ferramentas

Os catálogos dos módulos contêm no máximo **110 ferramentas de módulo**, mais `mcp_status` e `mcp_guide` (112 no total). Três ferramentas de dependências Deck dependem de APIs nativas NC34/35 compatíveis, deixando no máximo 107 ferramentas de módulo (109 no total) sem elas. A disponibilidade das aplicações e o acesso de cada conta reduzem ainda estas contagens.

Cada ferramenta só aparece em `tools/list` e só pode ser chamada quando está atribuída ao utilizador, selecionada para a ligação, e as aplicações necessárias e precondições da operação estão ativas para esse utilizador. A permissão e a aplicação são verificadas novamente em cada chamada. Tudo é executado como o utilizador autenticado, através da sua pasta no Nextcloud, mantendo as permissões e partilhas do Nextcloud.

### Guia das ferramentas (`mcp_guide`)

`mcp_guide` (título **Guia das ferramentas**) descreve as ferramentas ao próprio modelo, para que não tenha de adivinhar o que cada uma faz:

- **sem argumento** — uma linha por módulo com os títulos das ferramentas, apenas dos módulos disponíveis **para aquele utilizador** (o mesmo filtro de grant e app do `tools/list`: módulo desativado ou sem grant não aparece);
- **com `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`, `mail`, `forms`) — cada ferramenta do módulo: descrição, se lê ou escreve, parâmetros com tipo, indicação de obrigatoriedade, descrição, `enum` e limites, e confirmações exigidas;
- **com `tool`** — uma única ferramenta e o módulo a que pertence.

O guia é escrito em inglês; o modelo transmite a informação no idioma do utilizador.

O texto provém das definições reais (`ToolModule::definitions()`, `inputSchema`, `annotations` e `ToolPresentation`); por isso, alterar o esquema de uma ferramenta atualiza o guia e não é necessário manter uma segunda cópia da documentação. A informação que não consta do esquema — como encontrar itens, que caminhos e IDs usar e onde terminam as operações de escrita — fica em `ToolGuideNotes::guideNotes()`, uma nota curta por módulo, junto do próprio módulo. A resposta contém o mesmo conteúdo em Markdown e em `structuredContent`. A ferramenta é apenas de leitura: consulta definições, mas nunca executa ferramentas.

Se o utilizador não puder ver um módulo ou uma ferramenta, o pedido é recusado com uma mensagem que indica o que pode usar. O servidor não guarda aprovações: a instrução para confirmar antes de escrever está em `instructions` no `initialize` e aplica-se a todas as ferramentas que alteram dados.

Além disso:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao utilizador e só repetir a chamada com `confirm: true` após aprovação explícita. O servidor não guarda qualquer estado de aprovação nem tokens de aprovação.
- **OCR pelo Workflow OCR**: com a aplicação Workflow OCR instalada, os PDFs digitalizados ganham uma camada de texto que o `files_read` lê normalmente. Sem ele, um PDF ou imagem sem texto devolve com `text_layer: false` e um aviso, e a página de administração mostra o estado do OCR com a ligação para a instalação. Nada fica bloqueado.
- **Nomes amigáveis das ferramentas** no cliente ("Pesquisar ficheiros", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas partilhadas, pastas de equipa, quadros do Deck e calendários de outro proprietário. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente deve perguntar ao utilizador antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Ficheiros e Notas desde a 0.7.0)*
- **Aplicações opcionais respeitadas**: as ferramentas e as colunas da matriz de administração de uma aplicação desativada (para todos ou para um utilizador) ficam ocultas.
- **Interface traduzida**: a matriz de administração, a página pessoal, o ecrã de consentimento e todas as mensagens das ferramentas seguem o idioma da conta Nextcloud. Todos os módulos estão abrangidos (Files, Notes, Calendar, Contacts, Tasks, Deck, Talk, mensagens partilhadas, páginas de checkout e autoteste do calendário). Incluem-se a fonte inglesa e os catálogos de interface em alemão, português do Brasil e espanhol. Algumas mensagens novas ainda exigem revisão linguística humana, incluindo a interface de administração alemã apresentada. A documentação operacional também mantém rascunhos em catalão e português de Portugal com proveniência própria pendente; as descrições das ferramentas continuam em inglês, pois são lidas pelo modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

- [Files](tools/files.md)
- [Notes](tools/notes.md)
- [Calendário](tools/calendar.md)
- [Contactos](tools/contacts.md)
- [Tarefas](tools/tasks.md)
- [Deck](tools/deck.md)
- [Logs](tools/logs.md)
- [Talk](tools/talk.md)
- [People](tools/people.md)
- [Mail](tools/mail.md)
- [Forms](tools/forms.md) — `forms_list` lista formulários acessíveis. `forms_read` lê perguntas e opções. Exige Forms 5.3.0 ou posterior e a permissão separada `forms.read`. `forms_read_responses` lê páginas de submissões com a permissão separada `forms.read_responses` e permissão nativa de resultados. Use os deslocamentos devolvidos para obter perguntas e opções seguintes. Campos de texto maiores que 1 KiB incluem uma indicação de truncagem.

Para `forms_read_responses`, indique o `id` do formulário, `limit` (50 por predefinição, máximo 100) e `offset`. Siga `nextOffset` enquanto `hasMore` for verdadeiro. Cada submissão inclui no máximo 200 respostas, com `answersTruncated` a indicar respostas omitidas. Texto maior que 1 KiB tem `textTruncated: true`. Respostas com ficheiros carregados são omitidas e indicadas por `fileAnswersOmitted`. Submissões anónimas omitem IDs dos respondentes mesmo que a definição de anonimato mude posteriormente. Poder consultar as próprias respostas não concede acesso ao conjunto completo de resultados por esta ferramenta.

`mcp_status` fornece diagnósticos sem ler dados do utilizador. A identidade do servidor nos metadados de protocolo, ferramentas, prompts e recursos indica a versão instalada fornecida pelo Nextcloud, em vez de um número de versão copiado do projeto original.

[Guias de operação](../guides/README.md).

[English](../../en/reference/tools.md) · [Español](../../es/reference/tools.md) · [Català](../../ca/reference/tools.md) · [Português (Brasil)](../../pt-BR/reference/tools.md) · Português (Portugal)
