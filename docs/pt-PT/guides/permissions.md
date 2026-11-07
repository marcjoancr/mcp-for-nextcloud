<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/guides/permissions.md).

# Permissões e administração

Abra **Administração → Nextcloud MCP**. Os cinco separadores são **Visão geral**, **Acesso e permissões**, **Clientes e ligações**, **Proteção de dados** e **Avançado**. Os endereços (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) e a navegação pelo teclado abrem a secção correspondente. Em ecrãs estreitos, use o seletor Secções para alternar entre as mesmas cinco vistas. Clientes e ligações abre com a lista de ligações ativas. Em telemóveis, cada ligação tem linhas e ações identificadas próprias.

## Ativar o acesso

O serviço, a elegibilidade do utilizador (**Pode ligar-se**) e a ligação pessoal começam desativados, incluindo para administradores. Ative o serviço, permita que o utilizador se ligue e deixe esse utilizador ativar a sua ligação em **Definições pessoais → Nextcloud MCP**. A autenticação por si só não concede acesso: a conta, o serviço, a elegibilidade, a ligação, a aplicação opcional, a permissão de operação e as permissões nativas do Nextcloud sobre o recurso devem permitir o pedido.

As ferramentas de leitura originais de 2.0.0 mantêm as suas predefinições, exceto Mail e registos do servidor, que começam recusados. Cada ferramenta nova exige atribuição explícita por utilizador ou grupo e seleção na ligação. Atribuir uma ferramenta nova não concede outra: por exemplo, ler uma mensagem de correio não permite ler uma conversa e ler perguntas de um formulário não permite ler respostas. Na matriz, atribua as ferramentas individuais para pesquisa Mail e leitura de mensagens e conversas; listagem, perguntas e respostas Forms; leitura e publicação de comentários Deck; e publicação de resumos Talk.

As definições das ferramentas mantêm chaves de operação para classificação: `mail.read` abrange contas e pastas, `mail.search` pesquisas de cabeçalhos e `mail.read_message` conteúdo de mensagens e conversas. Forms usa `forms.read` para a estrutura e `forms.read_responses` para respostas; os resultados também exigem permissão nativa de resultados Forms. Comentários Deck usam `deck.read_comments` e `deck.comment`; resumos Talk usam `talk.publish_summary`, independentemente das respostas. São separadas a leitura de anexos Mail (`mail.read_attachment`), gravação (`mail.save_attachment`), leitura de rascunhos (`mail.read_drafts`), escrita (`mail.write_drafts`) e eliminação (`mail.delete_drafts`). Guardar um anexo exige ainda `files.create`, permissões nativas do destino e âmbito Files. A pesquisa de mensagens Talk usa `talk.search`; a leitura contextual usa `talk.read`, com atribuição independente da ferramenta. Na atualização, novas ferramentas continuam sem atribuição nem seleção. Mantêm-se o acesso nativo a recursos e quaisquer precondições adicionais de operação. Partilhas Files com pessoas/grupos (`files.share`) e ligações públicas (`files.link`) continuam a ser permissões independentes. Listar partilhas é uma operação de leitura.

Ao estabelecer a ligação, MCP lista as ferramentas permitidas para o utilizador e selecionadas para essa ligação. Cada ferramenta também exige que a aplicação do seu módulo e quaisquer aplicações adicionais que utilize estejam instaladas e ativas para esse utilizador. Por exemplo, listar, criar e remover partilhas de ficheiros exige `files_sharing`. Editar, substituir e efetuar checkout de um ficheiro existente exige `files_versions`. As leituras comuns de ficheiros funcionam com qualquer uma destas aplicações desativada. O servidor volta a verificar os mesmos requisitos numa chamada direta.

Visão geral mostra o endpoint, a versão, o número de ligações e o limite de carregamento de checkout. Esse limite é editado em MiB e não pode exceder `post_max_size` do PHP. Avançado contém as funções e a privacidade dos registos e OCR. Proteção de dados contém as etiquetas de exclusão; consulte [proteção de ficheiros](file-protection.md).

## Restrições da aplicação Guests

Desde 1.1.2, o MCP reaplica as restrições de Guests após autenticar uma palavra-passe de aplicação ou token OAuth. Um convidado cuja lista de aplicações permitidas exclua MCP é recusado. MCP devolve HTTP 403; Guests pode rejeitar o início de sessão com palavra-passe de aplicação antes, no Nextcloud (a recusa é apresentada como HTTP 500 no Nextcloud 35). Ferramentas, recursos Notes e referências Talk de aplicações não permitidas são ocultados ou recusados; as ferramentas de versões de ficheiros também exigem que Guests permita `files_versions`. Files usa a aplicação `files`, sempre permitida. Tasks exige a aplicação `tasks` na lista de aplicações permitidas do convidado e usa `dav` para armazenamento.

O armazenamento externo oculto continua inacessível, incluindo montagens Nextcloud 35 resolvidas diretamente por caminho. Referências estruturadas a ficheiros em Talk e Tasks, incluindo a publicação de anexos Talk, usam a mesma verificação de armazenamento oculto. Quando Guests oculta outras contas, a partilha continua limitada aos grupos do convidado. Um estado ilegível de Guests recusa o acesso.

As ligações de checkout e carregamento aplicam as mesmas restrições relevantes ao proprietário antes de resolver um ficheiro. Um convidado recusado recebe a resposta de ligação desconhecida (404), sem a gastar. Versões de Guests anteriores a 4.8.0 não conseguem aplicar estas restrições sem sessão, pelo que ligações pertencentes a convidados são recusadas. Contas não convidadas e instalações sem Guests mantêm o comportamento existente.

## Escolhas individuais e herança de grupos

Em **Administração → Nextcloud MCP → Acesso e permissões**, a matriz abre com o primeiro grupo do diretório. Clique no cabeçalho da coluna para escolher outro grupo ou uma pessoa no menu. Ambas as listas estão disponíveis antes de escrever. Use **Adicionar coluna de comparação** para manter a coluna atual e comparar outra entrada. Um grupo mostra as regras atribuídas. Uma pessoa mostra o acesso efetivo, a origem e eventuais exceções individuais. A linha do serviço fica no topo. Abra um catálogo, pesquise uma ferramenta ou filtre diferenças ou exceções pessoais.

Escolha **Permitir**, **Bloquear** ou a opção neutra diretamente numa célula. **Não atribuído** limpa uma regra de grupo. **Herdar** limpa uma exceção pessoal. O botão de informação junto ao seletor abre a descrição e regras relacionadas da ferramenta sob essa linha. Para uma pessoa, mostra também os grupos que fundamentam a decisão. Para alterar várias atribuições em conjunto, ative **Editar várias regras**, selecione ferramentas e colunas de destino e escolha a regra uma vez. As edições ficam pendentes até à revisão e gravação. Reveja o impacto de cada coluna e guarde antes de rever a seguinte, pois uma alteração de grupo pode afetar uma pessoa noutra coluna.

Só aparecem ferramentas de aplicações instaladas, ativas e compatíveis. Uma pessoa vê as aplicações ativas para a sua conta. Desativar uma aplicação oculta as suas ferramentas do MCP e dos ecrãs de definições. As permissões e seleções de ligação existentes voltam a estar disponíveis quando a aplicação é reativada.

**Permitir**, **Bloquear** e **Não atribuído** aplicam-se tanto ao serviço como às ferramentas. A decisão explícita individual prevalece. Quando não há atribuição individual, um bloqueio de um grupo de confiança prevalece sobre permissões de grupos. Sem decisão de grupo, aplica-se a predefinição do catálogo. Desativar o serviço global ou uma conta impede todas as ligações de usar MCP. Uma ferramenta continua a precisar da sua aplicação opcional, função, precondições de operação e acesso nativo ao recurso.

A pertença a grupos provém do Nextcloud. Apenas grupos nativos baseados na base de dados podem conceder acesso. Uma falha ao resolver a política é indicada como indisponibilidade. Numa célula de ferramenta, abra **Regras de operações relacionadas** para controlos como partilhar com pessoas ou criar ligações públicas. Alterar uma dessas definições afeta todas as ferramentas que exigem essa operação.

Quando Nextcloud cria uma conta, MCP limpa permissões individuais de ferramentas e seleções de ligação deixadas por uma conta anterior com o mesmo ID. Se essa transação falhar, a criação da conta para. Repita a criação quando o armazenamento de políticas estiver disponível.

### Atualizar a partir de 2.0.0

A atualização preserva as definições individuais e de grupo originais. As 84 ferramentas incluídas em 2.0.0 herdam essas definições até um administrador alterar uma atribuição. Uma operação de grupo antiga não selecionada continua sem atribuição. Repor uma ferramenta individual como **Não atribuído** também limpa a exceção individual de operação herdada para essa ferramenta. A migração verifica as decisões antigas e novas antes da ativação. Uma diferença inesperada ou política ilegível interrompe a atualização.

As ligações existentes recebem uma seleção limitada às permissões de operação anteriores. Novas ferramentas exigem nova atribuição e seleção. Conserve juntas a base de dados e a cópia de segurança da aplicação anteriores à atualização. Após alterações de seleções ou bloqueios no novo editor, a recuperação deve preservar essas decisões. Uma aplicação anterior não consegue aplicá-las.

## Análise Forms, pesquisa Deck e disponibilidade Calendar

Estas adições começam sem atribuição e são selecionadas separadamente em cada ligação:

| Ferramenta | Operação | Acesso nativo adicional |
| --- | --- | --- |
| `forms_summarize_responses` | `forms.summarize_responses` | Permissão de resultados completos do formulário |
| `forms_export_responses` | `forms.export_responses` e `files.create` | Resultados do formulário e criação na pasta Files selecionada |
| `deck_search_cards` | `deck.search` | Permissão de leitura em cada quadro devolvido |
| `calendar_check_availability` | `calendar.availability` | Âmbito Calendar, partilhas nativas e classificação de eventos |

As permissões de leitura existentes não ativam automaticamente estas ferramentas. A exportação de formulários é a única escrita deste conjunto: exige pré-visualização atual e confirmação, mais confirmação de destino partilhado quando aplicável. A atribuição de ferramentas e a seleção da ligação não contornam restrições de acesso nativo de outro utilizador.

## Guardar, conflitos e desfazer

As escritas de política incluem a revisão apresentada na interface. Uma pré-visualização desatualizada ou alteração simultânea produz um conflito e um recarregamento; o navegador não repete silenciosamente a escrita. Uma resposta incerta não é apresentada como escrita bem-sucedida. Rascunhos de grupos, recursos e clientes continuam a ser rascunhos até à respetiva ação Guardar.

Pedidos de reposição/lote limitam-se a 50 utilizadores e 2500 células. A pré-visualização vale 120 segundos; recibos do servidor expiram após 600 segundos. A interface oferece **Desfazer** por 10 segundos enquanto o controlo estiver disponível e pausa o temporizador com o ponteiro sobre ele ou o foco do teclado. Desfazer exige a revisão guardada e um recibo válido: não pode substituir alterações posteriores. Restaurar uma permissão não restaura credenciais revogadas nem inicia sessões novamente.

## Restrições de recursos e inspeção

O administrador pode restringir os recursos de **Files**, **Calendar** e **Tasks** de um utilizador pelo painel de acesso. Cada módulo é independente: **Todos os recursos** significa sem restrição; **Apenas selecionados**, com seleção vazia, não permite nenhum. O limite é de 200 recursos por utilizador e módulo. As restrições apenas reduzem as concessões e permissões nativas existentes; alterá-las não revoga a ligação. Aplicam-se à próxima operação.

Notes, Contacts, Deck e Talk não têm restrição de recursos por módulo. Mail também não tem seleção de recursos por conta. Referências estruturadas a ficheiros e operações com anexos podem exigir verificações do âmbito de Files; consulte [proteção de ficheiros](file-protection.md). Restringir uma pasta não restringe genericamente tudo o que outros módulos guardam sob essa pasta.

O inspetor, exclusivo de administradores, informa **Permitido**, **Bloqueado** ou **Desconhecido**, a verificação que bloqueia e a origem da permissão. Pode explicar um caminho excluído ao administrador; erros MCP mantêm recusas genéricas. Consultas e alterações podem gerar mensagens de auditoria de nível informativo sem nomes nem caminhos de recursos. Dependem do nível de registo do servidor e não garantem uma pista de auditoria.

## Clientes e definições pessoais

Abra **Definições pessoais → Nextcloud MCP** e escolha um cliente ligado para gerir as suas ferramentas. O mesmo seletor aparece ao autorizar uma nova ligação OAuth. Selecione apenas o necessário para esse cliente. Retiradas administrativas aplicam-se no pedido seguinte, incluindo com tokens existentes. Renovar um token OAuth preserva a seleção da ligação. A autenticação Basic e os clientes de sessão partilham o perfil Nextcloud identificado separadamente. O âmbito de recursos continua a limitar as pastas, calendários e listas de tarefas acessíveis ao cliente.

Faça a gestão dos hosts de clientes publicados, o cliente nativo e os clientes personalizados registados em **Clientes e ligações**; consulte [OAuth](../reference/oauth.md). APIs administrativas exigem administrador e proteção CSRF do Nextcloud. APIs pessoais de ligação/âmbito obtêm a identidade da sessão e expõem apenas o estado daquele utilizador. Respostas nunca contêm hashes de tokens.

As definições pessoais mostram o estado da ligação, os clientes ligados, os respetivos controlos de revogação e as restrições de recursos só de leitura do utilizador. Para HTTP Basic, crie uma palavra-passe de aplicação em **Definições pessoais → Segurança**. MCP não pede nem guarda a palavra-passe principal. Desligar MCP não elimina essa palavra-passe de aplicação do Nextcloud; consulte [revogar o acesso](clients.md#revogar-o-acesso).

Permanece um risco residual de escrita: uma recusa genérica pode distinguir um destino indisponível de outro onde a criação teria sucesso, embora não revele o motivo.

[Guias de operação](README.md).

[English](../../en/guides/permissions.md) · [Español](../../es/guides/permissions.md) · [Català](../../ca/guides/permissions.md) · [Português (Brasil)](../../pt-BR/guides/permissions.md) · Português (Portugal)
