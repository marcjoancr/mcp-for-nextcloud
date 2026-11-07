<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/guides/permissions.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/guides/permissions.md).

# Permissões e administração

Abra **Administração → Nextcloud MCP**. Os cinco separadores são **Visão geral**, **Acesso e permissões**, **Clientes e ligações**, **Proteção de dados** e **Avançado**. Os endereços (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`) e a navegação pelo teclado abrem a secção correspondente.

## Ativar o acesso

O serviço, a elegibilidade do utilizador (**Pode ligar-se**) e a ligação pessoal começam desativados, incluindo para administradores. Ative o serviço, permita que o utilizador se ligue e deixe esse utilizador ativar a sua ligação em **Definições pessoais → Nextcloud MCP**. A autenticação por si só não concede acesso: a conta, o serviço, a elegibilidade, a ligação, a aplicação opcional, a permissão de operação e as permissões nativas do Nextcloud sobre o recurso devem permitir o pedido.

A leitura começa permitida, exceto para os registos do servidor e Mail. Mail exige uma permissão explícita de leitura individual ou de grupo, incluindo após uma atualização. As restantes operações começam recusadas. Partilhar ficheiros com pessoas/grupos (`files.share`) e criar ligações públicas (`files.link`) são permissões independentes. Listar partilhas é uma operação de leitura.

Visão geral mostra o endpoint, a versão, o número de ligações e o limite de carregamento de checkout. Esse limite é editado em MiB e não pode exceder `post_max_size` do PHP. Avançado contém as funções e a privacidade dos registos e OCR. Proteção de dados contém as etiquetas de exclusão; consulte [proteção de ficheiros](file-protection.md).

## Restrições da aplicação Guests

Desde 1.1.2, o MCP reaplica as restrições de Guests após autenticar uma palavra-passe de aplicação ou token OAuth. Um convidado cuja lista de aplicações permitidas exclua MCP é recusado. MCP devolve HTTP 403; Guests pode rejeitar o início de sessão com palavra-passe de aplicação antes, no Nextcloud (a recusa é apresentada como HTTP 500 no Nextcloud 35). Ferramentas, recursos Notes e referências Talk de aplicações não permitidas são ocultados ou recusados; as ferramentas de versões de ficheiros também exigem que Guests permita `files_versions`. Files e Tasks usam `files` e `dav`, sempre permitidos.

O armazenamento externo oculto continua inacessível, incluindo montagens Nextcloud 35 resolvidas diretamente por caminho. Referências estruturadas a ficheiros em Talk e Tasks, incluindo a publicação de anexos Talk, usam a mesma verificação de armazenamento oculto. Quando Guests oculta outras contas, a partilha continua limitada aos grupos do convidado. Um estado ilegível de Guests recusa o acesso.

As ligações de checkout e carregamento aplicam as mesmas restrições relevantes ao proprietário antes de resolver um ficheiro. Um convidado recusado recebe a resposta de ligação desconhecida (404), sem a gastar. Versões de Guests anteriores a 4.8.0 não conseguem aplicar estas restrições sem sessão, pelo que ligações pertencentes a convidados são recusadas. Contas não convidadas e instalações sem Guests mantêm o comportamento existente.

## Escolhas individuais e herança de grupos

Acesso e permissões oferece níveis por módulo e uma visualização **Operações** para permissões individuais. A pesquisa usa nome, ID ou correio eletrónico; o correio eletrónico não é apresentado. Os filtros de grupo e elegibilidade/ligação são aplicados no servidor, com 50 utilizadores por página. Contas desativadas não podem ser editadas, e aplicações ou funções de registos indisponíveis continuam indisponíveis sem apagar as escolhas guardadas.

A resolução segue esta ordem:

1. Prevalece **Permitir** ou **Negar** explícito do indivíduo.
2. Sem escolha individual, qualquer concessão de um grupo de confiança permite a operação.
3. Caso contrário, aplica-se a predefinição. Grupos concedem acesso; não o negam nem restringem.

Apenas grupos do backend de base de dados próprio do Nextcloud podem conceder permissões. LDAP, SAML, outros backends de aplicações e identidades de backend ambíguas não podem fornecer herança segura. Conceda permissões individuais aos seus utilizadores. Um grupo recriado ou incerto não recupera silenciosamente a sua autoridade anterior: o editor explica o estado e oferece confirmação da política atual quando aplicável.

Em **Permissões de grupo**, selecione um grupo, edite as operações e visualize antes de guardar. Contagens de membros e estimativas de encerramento de sessão são informações de planeamento, não uma decisão de autorização por membro. Remover **Pode ligar-se** encerra as sessões dos membros que realmente perdem a elegibilidade. Grupos grandes podem levar tempo para processar; esta integração não promete uma nova garantia de desempenho.

**Herdar** e as ações de repor linha/seleção removem as exceções individuais. A pré-visualização identifica escolhas individuais, grupos que concedem permissões, predefinições e resultados desconhecidos. Um grupo ou política ilegível é **Desconhecido**, nunca uma permissão implícita nem um motivo para apagar credenciais. Pedidos que dependem dessa incerteza falham temporariamente; ferramentas com autorização desconhecida são omitidas da descoberta, enquanto as permitidas independentemente permanecem disponíveis.

## Guardar, conflitos e desfazer

As escritas de política incluem a revisão apresentada na interface. Uma pré-visualização desatualizada ou alteração simultânea produz um conflito e um recarregamento; o navegador não repete silenciosamente a escrita. Uma resposta incerta não é apresentada como escrita bem-sucedida. Rascunhos de grupos, recursos e clientes continuam a ser rascunhos até à respetiva ação Guardar.

Pedidos de reposição/lote limitam-se a 50 utilizadores e 2500 células. A pré-visualização vale 120 segundos; recibos do servidor expiram após 600 segundos. A interface oferece **Desfazer** por 10 segundos enquanto o controlo estiver disponível e pausa o temporizador com o ponteiro sobre ele ou o foco do teclado. Desfazer exige a revisão guardada e um recibo válido: não pode substituir alterações posteriores. Restaurar uma permissão não restaura credenciais revogadas nem inicia sessões novamente.

## Restrições de recursos e inspeção

O administrador pode restringir os recursos de **Files**, **Calendar** e **Tasks** de um utilizador pelo painel de acesso. Cada módulo é independente: **Todos os recursos** significa sem restrição; **Apenas selecionados**, com seleção vazia, não permite nenhum. O limite é de 200 recursos por utilizador e módulo. As restrições apenas reduzem as concessões e permissões nativas existentes; alterá-las não revoga a ligação. Aplicam-se à próxima operação.

Notes, Contacts, Deck e Talk não têm restrição de recursos por módulo. Mail também não tem seleção de recursos por conta. Referências estruturadas a ficheiros e operações com anexos podem exigir verificações do âmbito de Files; consulte [proteção de ficheiros](file-protection.md). Restringir uma pasta não restringe genericamente tudo o que outros módulos guardam sob essa pasta.

O inspetor, exclusivo de administradores, informa **Permitido**, **Bloqueado** ou **Desconhecido**, a verificação que bloqueia e a origem da permissão. Pode explicar um caminho excluído ao administrador; erros MCP mantêm recusas genéricas. Consultas e alterações podem gerar mensagens de auditoria de nível informativo sem nomes nem caminhos de recursos. Dependem do nível de registo do servidor e não garantem uma pista de auditoria.

## Clientes e definições pessoais

Faça a gestão dos hosts de clientes publicados, o cliente nativo e os clientes personalizados registados em **Clientes e ligações**; consulte [OAuth](../reference/oauth.md). APIs administrativas exigem administrador e proteção CSRF do Nextcloud. APIs pessoais de ligação/âmbito obtêm a identidade da sessão e expõem apenas o estado daquele utilizador. Respostas nunca contêm hashes de tokens.

As definições pessoais mostram o estado da ligação, os clientes ligados, os respetivos controlos de revogação e as restrições de recursos só de leitura do utilizador. Para HTTP Basic, crie uma palavra-passe de aplicação em **Definições pessoais → Segurança**. MCP não pede nem guarda a palavra-passe principal. Desligar MCP não elimina essa palavra-passe de aplicação do Nextcloud; consulte [revogar o acesso](clients.md).

Permanece um risco residual de escrita: uma recusa genérica pode distinguir um destino indisponível de outro onde a criação teria sucesso, embora não revele o motivo.

[Guias de operação](README.md).

[English](../../en/guides/permissions.md) · [Español](../../es/guides/permissions.md) · [Català](../../ca/guides/permissions.md) · [Português (Brasil)](../../pt-BR/guides/permissions.md) · Português (Portugal)
