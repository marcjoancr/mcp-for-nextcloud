<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/reference/tools.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../en/reference/tools.md).

# Ferramentas

Os catálogos dos módulos contêm no máximo **110 ferramentas de módulos**, mais `mcp_status` e `mcp_guide` (112 no total). Três ferramentas de dependência do Deck dependem de APIs nativas compatíveis no NC34/35; sem elas, há no máximo 107 ferramentas de módulos (109 no total). A disponibilidade dos aplicativos e o acesso de cada conta reduzem ainda mais esses números.

Cada ferramenta só aparece em `tools/list` e só pode ser chamada quando está atribuída ao usuário, selecionada para a conexão e seus aplicativos e pré-requisitos de operação estão habilitados para ele. Grant e app são verificados de novo a cada chamada. Tudo roda como o usuário autenticado, pela pasta dele no Nextcloud, e as permissões e compartilhamentos do Nextcloud continuam valendo.

### Guia das ferramentas (`mcp_guide`)

`mcp_guide` (título **Guia das ferramentas**) descreve as ferramentas ao próprio modelo, para que ele não precise adivinhar o que cada uma faz:

- **sem argumento** — uma linha por módulo com os títulos das ferramentas, apenas dos módulos disponíveis **para aquele usuário** (o mesmo filtro de grant e app do `tools/list`: módulo desabilitado ou sem grant não aparece);
- **com `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`, `mail`, `forms`) — cada ferramenta do módulo: descrição, se lê ou escreve, parâmetros com tipo, obrigatoriedade, descrição, `enum` e limites, e as confirmações que exige;
- **com `tool`** — uma só ferramenta, com o módulo a que pertence.

O guia é escrito em inglês e o modelo repassa no idioma do usuário.

O texto vem das definições reais (`ToolModule::definitions()`, `inputSchema`, `annotations` e `ToolPresentation`), então mudar o schema de uma ferramenta muda o guia: não existe uma segunda cópia da documentação para manter em dia. O que o schema não carrega — como encontrar as coisas, com quais caminhos e IDs, e onde as escritas param — entra em `ToolGuideNotes::guideNotes()`, uma nota curta por módulo, escrita ao lado do módulo. A resposta traz o mesmo conteúdo em markdown e em `structuredContent`, e a tool é read-only: ela lê definições, nunca executa uma ferramenta.

Módulo ou ferramenta que o usuário não pode ver é recusado com uma mensagem que lista o que ele pode usar. Não há aprovação gravada no servidor: a regra de confirmar antes de escrever está nas `instructions` do `initialize` e vale para toda tool que altera algo.

E também:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao usuário e só repetir a chamada com `confirm: true` após aprovação explícita. Nenhum estado ou token de aprovação é gravado no servidor.
- **OCR pelo Workflow OCR**: com o app Workflow OCR instalado, os PDFs escaneados ganham uma camada de texto que o `files_read` lê normalmente. Sem ele, um PDF ou imagem sem texto volta com `text_layer: false` e um aviso, e a página de admin mostra o status do OCR com o link para instalar. Nada fica bloqueado.
- **Nomes amigáveis das ferramentas** no cliente ("Buscar arquivos", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas compartilhadas, pastas de time, quadros do Deck e calendários de outro dono. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente precisa perguntar ao usuário antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Arquivos e Notas desde a 0.7.0)*
- **Apps opcionais respeitados**: as tools e as colunas da matriz de admin de um app desativado (para todos ou para um usuário) ficam ocultas.
- **Interface traduzida**: a matriz de admin, a página pessoal, a tela de consentimento e toda mensagem mostrada por uma tool seguem o idioma da conta Nextcloud do usuário. Todos os módulos estão cobertos (Arquivos, Notas, Calendário, Contatos, Tarefas, Deck, Talk, as mensagens compartilhadas, as páginas de checkout e o selftest do calendário). Inclui o texto-fonte em inglês e catálogos de interface em alemão, português do Brasil e espanhol. Algumas mensagens novas ainda exigem revisão humana do idioma, inclusive a interface administrativa alemã renderizada. A documentação operacional também mantém rascunhos em catalão e português europeu com sua própria proveniência pendente; as descrições das ferramentas ficam em inglês, porque quem as lê é o modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

- [Arquivos](tools/files.md)
- [Notas](tools/notes.md)
- [Calendário](tools/calendar.md)
- [Contatos](tools/contacts.md)
- [Tarefas](tools/tasks.md)
- [Deck](tools/deck.md)
- [Logs](tools/logs.md)
- [Talk](tools/talk.md)
- [Pessoas](tools/people.md)
- [Mail](tools/mail.md)
- [Forms](tools/forms.md) — `forms_list` lista formulários acessíveis. `forms_read` lê perguntas e opções de um formulário. Exige Forms 5.3.0 ou superior e a permissão separada `forms.read`. `forms_read_responses` lê páginas de envios com a permissão separada `forms.read_responses` e permissão nativa de resultados. Use os deslocamentos retornados para obter perguntas e opções seguintes. Campos de texto maiores que 1 KiB incluem uma indicação de truncamento.

Para `forms_read_responses`, informe o `id` do formulário, `limit` (padrão 50, máximo 100) e `offset`. Siga `nextOffset` enquanto `hasMore` for verdadeiro. Cada envio inclui no máximo 200 respostas, com `answersTruncated` indicando respostas omitidas. Texto maior que 1 KiB inclui `textTruncated: true`. Respostas com upload de arquivos são omitidas e informadas por `fileAnswersOmitted`. Envios anônimos omitem IDs de respondentes mesmo quando a configuração de anonimato do formulário muda depois. Poder visualizar as próprias respostas não concede ao respondente acesso ao conjunto completo de resultados por essa ferramenta.

`mcp_status` fornece diagnóstico sem ler dados do usuário. A identidade do servidor nos metadados de protocolo, ferramentas, prompts e recursos informa a versão instalada do aplicativo obtida do Nextcloud, em vez de um número de versão copiado do projeto original.

[Guias de operação](../guides/README.md).

[English](../../en/reference/tools.md) · [Español](../../es/reference/tools.md) · [Català](../../ca/reference/tools.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/reference/tools.md)
