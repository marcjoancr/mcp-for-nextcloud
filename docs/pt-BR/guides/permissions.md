<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../../en/guides/permissions.md).

# Permissões e administração

Abra **Administração → Nextcloud MCP**. As cinco abas são **Visão geral**, **Acesso e permissões**, **Clientes e conexões**, **Proteção de dados** e **Avançado**. Os endereços (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) e a navegação pelo teclado abrem a seção correspondente. Em telas estreitas, use o seletor Seções para alternar entre as mesmas cinco visualizações. Clientes e conexões abre com a lista de conexões ativas. Em celulares, cada conexão tem suas próprias linhas identificadas e ações.

## Habilitar o acesso

O serviço, a elegibilidade do usuário (**Pode conectar**) e a conexão pessoal começam desativados, inclusive para administradores. Ative o serviço, permita que o usuário se conecte e deixe que ele ative sua conexão em **Configurações pessoais → Nextcloud MCP**. A autenticação sozinha não concede acesso: a conta, o serviço, a elegibilidade, a conexão, o aplicativo opcional, a permissão de operação e as permissões nativas do Nextcloud sobre o recurso devem permitir a solicitação.

As ferramentas de leitura originais de 2.0.0 mantêm seus padrões, exceto Mail e logs do servidor, que começam negados. Toda ferramenta nova exige atribuição explícita de usuário ou grupo e seleção na conexão. Atribuir uma ferramenta nova não concede outra: por exemplo, ler uma mensagem de e-mail não permite ler uma conversa, e ler perguntas de formulários não permite ler respostas. Na matriz, conceda as ferramentas individuais de busca Mail, leitura de mensagens e conversas; listagem, perguntas e respostas Forms; leitura e publicação de comentários Deck; e publicação de resumos Talk.

As definições mantêm chaves de operação para classificação: `mail.read` cobre contas e pastas, `mail.search` busca em cabeçalhos, e `mail.read_message` conteúdo de mensagens e conversas. Forms usa `forms.read` para estrutura e `forms.read_responses` para respostas; resultados também exigem permissão nativa de resultados Forms. Comentários Deck usam `deck.read_comments` e `deck.comment`; resumos Talk usam `talk.publish_summary`, independentemente de respostas. Leitura de anexos Mail (`mail.read_attachment`), salvamento (`mail.save_attachment`), leitura de rascunhos (`mail.read_drafts`), escrita de rascunhos (`mail.write_drafts`) e exclusão de rascunhos (`mail.delete_drafts`) são separadas. Salvar anexo exige também `files.create`, permissões nativas do destino e escopo de Files. Busca de mensagens Talk usa `talk.search`; leitura contextual usa `talk.read`, com atribuição independente da ferramenta. Ferramentas novas continuam sem atribuição e sem seleção ao atualizar. Acesso nativo a recursos e demais pré-requisitos de operação continuam valendo. Compartilhar arquivos com pessoas/grupos (`files.share`) e links públicos (`files.link`) continuam permissões independentes. Listar compartilhamentos é operação de leitura.

Ao conectar, MCP lista as ferramentas permitidas ao usuário e selecionadas para essa conexão. Cada ferramenta também exige que o aplicativo do módulo e qualquer aplicativo adicional usado estejam instalados e habilitados para esse usuário. Por exemplo, listar, criar e remover compartilhamentos de arquivos exigem `files_sharing`. Editar, substituir e fazer checkout de arquivo existente exigem `files_versions`. Leituras comuns de arquivos funcionam com qualquer um deles desativado. O servidor verifica novamente os mesmos requisitos em uma chamada direta.

Visão geral mostra o endpoint, a versão, o número de conexões e o limite de upload de checkout. Esse limite é editado em MiB e não pode exceder `post_max_size` do PHP. Avançado contém os papéis e a privacidade dos logs e OCR. Proteção de dados contém as etiquetas de exclusão; consulte [proteção de arquivos](file-protection.md).

## Restrições do aplicativo Guests

Desde a versão 1.1.2, MCP reaplica as restrições do Guests após autenticar uma senha de aplicativo ou token OAuth. Um convidado cuja lista de aplicativos permitidos exclua MCP é recusado. MCP retorna HTTP 403; Guests pode recusar o login com senha de aplicativo antes, no Nextcloud (a recusa aparece como HTTP 500 no Nextcloud 35). Ferramentas, recursos Notes e referências Talk de aplicativos não permitidos são ocultados ou recusados; ferramentas de versões de arquivos também exigem que Guests permita `files_versions`. Files usa o aplicativo sempre permitido `files`. Tasks exige `tasks` na lista de aplicativos permitidos do convidado e usa `dav` para armazenamento.

Armazenamento externo oculto continua inacessível, inclusive montagens do Nextcloud 35 resolvidas diretamente pelo caminho. Referências estruturadas a arquivos em Talk e Tasks, inclusive a publicação de anexos Talk, usam a mesma verificação de armazenamento oculto. Quando Guests oculta outras contas, o compartilhamento continua limitado aos grupos do convidado. Um estado ilegível de Guests resulta em recusa segura.

Links de checkout e upload aplicam as mesmas restrições pertinentes ao proprietário antes de resolver um arquivo. Um convidado recusado recebe a resposta de link desconhecido (404), sem consumi-lo. Versões de Guests anteriores a 4.8.0 não conseguem aplicar essas restrições sem sessão; por isso, links de convidados são recusados. Contas que não são convidadas e instalações sem Guests mantêm seu comportamento.

## Escolhas individuais e herança de grupos

Em **Administração → Nextcloud MCP → Acesso e permissões**, a matriz abre com o primeiro grupo do diretório. Clique no cabeçalho da coluna para escolher outro grupo ou uma pessoa na lista suspensa. Ambas as listas estão disponíveis antes de digitar. Use **Adicionar coluna de comparação** para manter a coluna atual e comparar outra entrada. Um grupo mostra suas regras atribuídas. Uma pessoa mostra o acesso efetivo, sua origem e exceções individuais. A linha do serviço fica no topo. Abra um catálogo, busque uma ferramenta ou filtre diferenças ou exceções pessoais.

Escolha **Permitir**, **Bloquear** ou a opção neutra diretamente em uma célula. **Não atribuído** limpa uma regra de grupo. **Herdar** limpa uma exceção pessoal. O botão de informação ao lado do seletor abre a descrição da ferramenta e regras relacionadas abaixo dessa linha. Para uma pessoa, também mostra os grupos que fundamentam a decisão. Para alterar várias atribuições juntas, ative **Editar várias regras**, marque ferramentas e colunas de destino e escolha a regra uma vez. As edições ficam pendentes até serem revisadas e salvas. Revise o impacto e salve cada coluna antes de revisar a próxima, pois mudar um grupo pode afetar uma pessoa em outra coluna.

Só aparecem ferramentas de aplicativos instalados, habilitados e compatíveis. Uma pessoa vê os aplicativos habilitados para sua conta. Desativar um aplicativo oculta suas ferramentas no MCP e nas configurações. Permissões e seleções de conexão existentes voltam a ficar disponíveis quando o aplicativo é reativado.

**Permitir**, **Bloquear** e **Não atribuído** se aplicam ao serviço e às ferramentas. A decisão explícita do indivíduo prevalece. Quando não há atribuição individual, o bloqueio de um grupo confiável prevalece sobre permissões de grupos. Sem decisão de grupo, vale o padrão do catálogo. Desativar o serviço global ou uma conta impede todas as conexões de usar MCP. Uma ferramenta ainda exige seu aplicativo opcional, papel, pré-requisitos de operação e acesso nativo ao recurso.

A associação a grupos vem do Nextcloud. Apenas grupos nativos do banco de dados podem conceder acesso. Falha ao resolver uma política é informada como indisponibilidade. Na célula de uma ferramenta, abra **Regras de operação relacionadas** para controles como compartilhar com pessoas ou criar links públicos. Alterar uma dessas configurações afeta todas as ferramentas que exigem essa operação.

Quando o Nextcloud cria uma conta, MCP limpa permissões individuais de ferramentas e seleções de conexão deixadas por uma conta anterior com o mesmo ID de usuário. Se essa transação falhar, a criação da conta para. Tente criar novamente quando o armazenamento de políticas estiver disponível.

### Atualização de 2.0.0

A atualização preserva configurações individuais e de grupo originais. As 84 ferramentas incluídas em 2.0.0 herdam essas configurações até o administrador alterar uma atribuição. Uma operação de grupo antiga desmarcada continua não atribuída. Redefinir uma ferramenta individual para **Não atribuído** também limpa a exceção de operação individual herdada por essa ferramenta. A migração verifica decisões antigas e novas antes da ativação. Diferença inesperada ou política ilegível interrompe a atualização.

Conexões existentes recebem uma seleção limitada às permissões de operação anteriores. Novas ferramentas exigem nova atribuição e seleção. Mantenha juntos os backups de banco de dados e aplicativo anteriores à atualização. Depois que alguém alterar seleções ou bloquear acesso no novo editor, a recuperação deve manter essas decisões. Um aplicativo antigo não consegue aplicá-las.

## Análise Forms, busca Deck e disponibilidade Calendar

Estas adições começam sem atribuição e são selecionadas separadamente em cada conexão:

| Ferramenta | Operação | Acesso nativo adicional |
| --- | --- | --- |
| `forms_summarize_responses` | `forms.summarize_responses` | Permissão de resultados completos do formulário |
| `forms_export_responses` | `forms.export_responses` e `files.create` | Resultados do formulário e criação na pasta Files selecionada |
| `deck_search_cards` | `deck.search` | Permissão de leitura em cada quadro retornado |
| `calendar_check_availability` | `calendar.availability` | Escopo Calendar, compartilhamentos nativos e classificação dos eventos |

Permissões de leitura existentes não habilitam essas ferramentas automaticamente. Exportar formulário é a única escrita nesse conjunto: exige prévia atual e confirmação, além de confirmação de destino compartilhado quando aplicável. Nem atribuição de ferramenta nem seleção de conexão ignoram restrições nativas de acesso de outro usuário.

## Salvar, conflitos e desfazer

As gravações de política incluem a revisão exibida na interface. Uma prévia desatualizada ou alteração simultânea produz um conflito e uma recarga; o navegador não repete silenciosamente a gravação. Uma resposta incerta não é apresentada como salvamento bem-sucedido. Rascunhos de grupos, recursos e clientes continuam sendo rascunhos até a respectiva ação Salvar.

Solicitações de redefinição/lote se limitam a 50 usuários e 2500 células. A prévia vale 120 segundos; recibos do servidor expiram após 600 segundos. A interface oferece **Desfazer** por 10 segundos enquanto o controle estiver disponível e pausa o temporizador com o ponteiro sobre ele ou o foco do teclado. Desfazer exige a revisão salva e um recibo válido: não pode sobrescrever alterações posteriores. Restaurar uma permissão não restaura credenciais revogadas nem inicia sessões novamente.

## Restrições de recursos e inspeção

O administrador pode restringir os recursos de **Files**, **Calendar** e **Tasks** de um usuário pelo painel de acesso. Cada módulo é independente: **Todos os recursos** significa sem restrição; **Somente selecionados**, com seleção vazia, não permite nenhum. O limite é de 200 recursos por usuário e módulo. As restrições apenas reduzem as concessões e permissões nativas existentes; alterá-las não revoga a conexão. Aplicam-se à próxima operação.

Notes, Contacts, Deck e Talk não têm restrição de recursos por módulo. Mail também não oferece seleção de recursos por conta. Referências estruturadas a arquivos e operações com anexos podem exigir verificações do escopo de Files; consulte [proteção de arquivos](file-protection.md). Restringir uma pasta não restringe genericamente tudo que outros módulos armazenam abaixo dela.

O inspetor, exclusivo de administradores, informa **Permitido**, **Bloqueado** ou **Desconhecido**, a verificação que bloqueia e a origem da permissão. Pode explicar um caminho excluído ao administrador; erros MCP mantêm recusas genéricas. Consultas e alterações podem gerar mensagens de auditoria de nível informativo sem nomes nem caminhos de recursos. Dependem do nível de log do servidor e não garantem uma trilha de auditoria.

## Clientes e configurações pessoais

Abra **Configurações pessoais → Nextcloud MCP** e escolha um cliente conectado para gerenciar suas ferramentas. O mesmo seletor aparece ao autorizar uma nova conexão OAuth. Selecione apenas o necessário para esse cliente. Retiradas administrativas se aplicam à próxima solicitação, inclusive com tokens existentes. Renovar um token OAuth mantém a seleção dessa conexão. Autenticação Basic e clientes de sessão compartilham o perfil Nextcloud identificado separadamente. O escopo de recursos continua limitando quais pastas, calendários e listas de tarefas o cliente pode acessar.

Gerencie os hosts de clientes publicados, o cliente nativo e os clientes personalizados registrados em **Clientes e conexões**; consulte [OAuth](../reference/oauth.md). APIs administrativas exigem administrador e proteção CSRF do Nextcloud. APIs pessoais de conexão/escopo obtêm a identidade da sessão e expõem apenas o estado daquele usuário. Respostas nunca contêm hashes de tokens.

As configurações pessoais mostram o estado da conexão, os clientes conectados, seus controles de revogação e as restrições de recursos somente leitura do usuário. Para HTTP Basic, crie uma senha de aplicativo em **Configurações pessoais → Segurança**. MCP não pede nem armazena a senha principal. Desconectar MCP não exclui essa senha de aplicativo do Nextcloud; consulte [revogar o acesso](clients.md).

Permanece um risco residual de gravação: uma recusa genérica pode distinguir um destino indisponível de outro onde a criação teria sucesso, embora não revele o motivo.

[Guias de operação](README.md).

[English](../../en/guides/permissions.md) · [Español](../../es/guides/permissions.md) · [Català](../../ca/guides/permissions.md) · Português (Brasil) · [Português (Portugal)](../../pt-PT/guides/permissions.md)
