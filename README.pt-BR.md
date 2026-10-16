<div align="center">

# MCP for Cloud

**App nativo do Nextcloud que transforma o seu Nextcloud em um servidor MCP.**
Deixe o Claude e outros clientes MCP trabalharem com seus arquivos, notas, calendários, contatos, tarefas, quadros do Deck e conversas do Talk. Cada usuário usa as próprias permissões, e nada sai do servidor sem liberação do admin.

[![Tests](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/sintax-tech/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 32–35](https://img.shields.io/badge/Nextcloud-32--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![App Store do Nextcloud](https://img.shields.io/badge/App%20Store-mcp-0082c9?logo=nextcloud&logoColor=white)](https://apps.nextcloud.com/apps/mcp)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![Licença: AGPL-3.0](https://img.shields.io/badge/Licen%C3%A7a-AGPL--3.0-blue)](#licença)

[English](README.md) · Português (Brasil)

</div>

---

## Por quê

Assistentes de IA rendem mais quando alcançam as ferramentas que o time já usa. Ligar esses assistentes ao Nextcloud costuma exigir scripts com senha compartilhada, tokens amplos ou dados copiados para fora. O **MCP for Cloud** roda **dentro** do seu Nextcloud:

- **Uma URL por instância.** `https://cloud.example.com/apps/mcp/`. Sem subdomínio, sem serviço extra, sem Node/Docker/AppAPI.
- **Login pelo Nextcloud.** Clientes como o claude.ai usam OAuth ("Entrar agora"): a pessoa faz login no próprio Nextcloud e clica em **Permitir**. Senha de app (HTTP Basic) continua valendo para outros clientes.
- **O admin no controle.** Liga e desliga o serviço, define quem pode conectar, mantém uma **matriz usuário × permissão** e permite **liberações por grupo nativo do Nextcloud**. Leitura vem ligada para usuários autorizados. Escrever, mover, excluir, transferir, restaurar e compartilhar ficam **desligados até o admin liberar**, e compartilhar um arquivo e criar um link público são duas permissões separadas. Grupos apenas concedem permissões; ajustes individuais por usuário (`Permitir` ou `Negar`) sempre prevalecem.
- **As permissões do Nextcloud sempre valem.** O app nunca amplia o que o usuário já pode fazer no Nextcloud. Uma restrição opcional por recurso permite ao administrador restringir o acesso MCP de um usuário a calendários e listas de tarefas específicos na 1.1.0 (*TODO: a parte de Arquivos (B5) ainda não está ativa, o editor de admin (B7) está em andamento e, até ele existir, a restrição é definida pelos endpoints de admin*).
- **Exclusão de arquivos sensíveis do acesso MCP por etiqueta.** O administrador pode escolher etiquetas de sistema (preferencialmente restritas ou invisíveis) para deixar arquivos e pastas completamente invisíveis às ferramentas e aos recursos deste conector MCP, incluindo tudo o que estiver dentro de uma pasta etiquetada (as ferramentas do log do servidor, apenas com "Esconder o texto das mensagens do log" ligada). Enquanto houver etiqueta de exclusão configurada, compartilhar pasta, criar link público de pasta, copiar pasta e mover pasta para outro diretório são recusados no MCP para impedir a exposição indireta de um descendente oculto. Em caso de dúvida, oculta: se a consulta da etiqueta falhar, o item continua oculto. Os backups feitos pelo app recebem a etiqueta antes de receber qualquer conteúdo.

## Novidades da 1.1 (em andamento)

- **1.1.0: permissões por grupo, restrição por recurso e exclusão por etiqueta reforçada.**
  - **Permissões por grupo nativo do Nextcloud:** administradores podem conceder *Pode conectar* e operações por módulo a grupos nativos do Nextcloud (backend único `'Database'`) em *Acesso e permissões → Permissões de grupo*, com prévia antes de salvar (permissões acrescentadas e removidas, membros atuais, titulares de credencial que podem ser desconectados). Grupos apenas concedem; nunca negam nem restringem, e ajustes individuais por usuário (`Permitir` ou `Negar`) prevalecem sobre o grupo. Grupos não nativos (LDAP, SAML, apps de terceiros ou múltiplos backends) aparecem desabilitados com o motivo; conceda acesso aos membros individualmente. Veja [Permissões por grupo, garantias e limites](#permissões-por-grupo-garantias-e-limites). Os overrides individuais podem voltar a herdar dos grupos pela tabela de permissões, com prévia de antes e depois e desfazer, e um **Inspetor de acesso** explica o acesso efetivo de um usuário; veja [Overrides individuais, redefinição e o inspetor de acesso](#overrides-individuais-redefinição-e-o-inspetor-de-acesso).
  - **Restrição por recurso (Calendário e Tarefas; Arquivos em andamento):** administradores podem restringir um usuário de "Todos os recursos que o usuário tem no Nextcloud" para uma lista explícita de calendários de eventos (`VEVENT`) ou listas de tarefas (`VTODO`), mantidas de forma independente mesmo quando um calendário do Nextcloud guarda os dois, até 200 itens por usuário e módulo, sem revogar conexões OAuth ativas. Só estreita o acesso, é definida por usuário e apenas por administradores, nunca por grupo. Um calendário ou lista de tarefas fora da restrição responde "não encontrado", e uma restrição que não pode ser lida responde 503. A precedência é sempre `Exclusão por etiqueta de sistema > Restrição por recurso > ACL nativa do Nextcloud`. O módulo Notas fica fora do escopo de pastas de Arquivos. Veja [Restrição por recurso](#restrição-por-recurso). (*TODO: Arquivos (B5) não está ativo, então ainda não dá para salvar uma restrição de Arquivos; o editor de admin é o B7; a limpeza da restrição de uma conta excluída é o B3.*)
  - **Excluídos do acesso MCP — proteção de pastas e filtragem de referências:** enquanto houver ao menos uma etiqueta de exclusão configurada, o MCP recusa compartilhar, criar/alterar link público, copiar ou mover para outro diretório qualquer **pasta** (e oculta a `url` de links públicos de pastas existentes em `files_list_shares`), mantendo o funcionamento normal para arquivos individuais e para renomeação de pasta no mesmo diretório pai. Referências estruturadas a arquivos passam pela verificação de exclusão por etiqueta: anexos do Talk (`talk_read_messages` omite o `attachmentId`; `talk_attach_file` e `talk_quote_file` recusam um arquivo excluído) e links `ATTACH` de Tarefas (em qualquer host, IP, loopback ou alias) são omitidos da resposta quando o arquivo está excluído, sem alterar os dados guardados (*TODO: o mesmo para arquivos fora de uma restrição por recurso, depois do B5*). A resolução do diretório pai real acima da raiz de montagem corrige falsos "não encontrado" em storages externos e em arquivos de pastas de equipe compartilhados avulsos quando há etiquetas ativas ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).

Quem atualiza a partir da 1.0.8 executa as migrações nativas de esquema automaticamente (tabelas `mcp_group_grants`, `mcp_policy_state`, `mcp_auth_users`, `mcp_policy_events`, `mcp_auth_recheck`, `mcp_group_life`, `mcp_scope_modes` e `mcp_scope_items`, além das colunas de geração nas tabelas OAuth): sem passo manual, sem `occ`, e todas as permissões e conexões existentes continuam funcionando. **Importante:** após adotar permissões por grupo ou restrição por recurso, não faça downgrade do app para a 1.0.8; veja [Atualização, incidentes e rollback](#atualização-incidentes-e-rollback).

## Novidades da 1.0

Desde a 1.0.0 o app está publicado na [App Store do Nextcloud](https://apps.nextcloud.com/apps/mcp), assinado com o certificado da loja. A lista completa, incluindo as correções de segurança, está no [`CHANGELOG.md`](CHANGELOG.md).

- **1.0.0 e 1.0.1: a App Store.** Primeira versão assinada na loja (sem mudança funcional desde a 0.11.1); a 1.0.1 acrescenta a categoria `ai`.
- **1.0.2: log do servidor.** Um módulo somente leitura para a equipe de TI, `logs_list` e `logs_analyze`, sobre o `nextcloud.log` pelo leitor do próprio core. Só administradores e os grupos que eles escolherem veem as ferramentas, a permissão nasce desligada, IPs são mascarados, a saída é marcada como não confiável e toda leitura é auditada. Veja [Funcionalidades](#funcionalidades). Também corrige o `files_image_search`, que falhava no Nextcloud 33.
- **1.0.3: Nextcloud 32–35.** O app declara suporte ao Nextcloud 32 a 35, conferido por testes de contrato de API com Deck 1.16–1.19 e Talk 22–25. Correções: editar um card do Deck na 1.18/1.19 não apaga mais a data de início nem a cor, e o Talk respeita o lobby na leitura e na escrita.
- **1.0.4: descrição da loja.** A descrição na loja de apps (inglês, português e espanhol) passa a listar o módulo de log do servidor da 1.0.2. Sem mudança funcional.
- **1.0.5: arquivos travados e licenciamento.** Uma nota ou arquivo aberto no Text ou no Nextcloud Office, travado por outra pessoa ou por um cliente WebDAV deixa de dar erro genérico: o plano avisa, e a escrita é recusada antes de qualquer alteração, dizendo qual app ou pessoa trava, desde quando, até quando e o que fazer. A sua própria trava manual continua deixando você escrever, o app nunca destrava nem assume uma trava, a restauração de versão não grava mais por cima de um editor aberto e o envio do checkout responde 423. O app recusa as travas que o Nextcloud informa no momento da checagem; travas de pessoas e de apps também são aplicadas pelo Nextcloud na escrita (com uma exceção do Text na exclusão e na renomeação), enquanto a trava de token WebDAV é recusada só pela checagem do app, que não é atômica com a escrita e pode não ver uma trava criada durante a mesma requisição (veja as limitações conhecidas no [`CHANGELOG.md`](CHANGELOG.md)). O repositório passa a seguir o REUSE, com `NOTICE`, avisos de terceiros e o crédito "Desenvolvido pela Sintax" na página de administração.
- **1.0.6: ocultação por tags auditada.**
  - **Correção:** com uma tag de sistema de ocultação configurada, arquivos e pastas comuns voltam a aparecer; desde a 0.8.0 todos eram dados como não encontrados. Backups de arquivos ocultos continuam ocultos. Agradecimento a @NightDragon1 (#5).
  - **Segurança:** a ocultação por tags foi auditada em todos os caminhos de acesso e as lacunas foram fechadas: desfazer lote, citações do Talk, categorias do Notes, recursos MCP, arquivos alcançados por compartilhamentos locais, configurações que não podem ser lidas, decisões em cache e pastas chamadas "0". Na dúvida, o app agora recusa. Agradecimento a @matt-ek (#4) pelo relato que motivou a auditoria.
  - **Log do servidor:** uma nova opção de admin, ligada por padrão, esconde o texto das mensagens do log enquanto houver arquivos ocultos por tags; ela pode ser desligada quando a equipe de TI precisar dos detalhes completos. O log de erros do próprio app passa a guardar só o tipo e o código do erro.
  - **Documentação e CI:** o README técnico passa a existir em inglês ([`mcp/README.md`](mcp/README.md)) e em português ([`mcp/README.pt-BR.md`](mcp/README.pt-BR.md)), e o GitHub Actions roda a suíte de testes no PHP 8.2, 8.3, 8.4 e 8.5 e confere o pacote em todo push e pull request.
- **1.0.7: categorias de tarefa e uma tela de admin mais clara.**
  - **Tarefas:** as ferramentas de Tarefas leem, gravam e filtram as categorias das tarefas (a propriedade `CATEGORIES` do VTODO): `categories` em `tasks_read_task` e `tasks_list_tasks`, `categories` ao criar e editar (omitir mantém, `[]` limpa, uma lista substitui) e o filtro `category` na listagem. O plano de confirmação mostra cada nome antes e depois da mudança ([#6](https://github.com/sintax-tech/mcp-for-nextcloud/issues/6)).
  - **Tela de admin:** cinco abas com endereço próprio, seletores com busca para os grupos do log do servidor e as etiquetas de exclusão, e uma matriz de permissões que mostra um aplicativo por vez e mantém os nomes à vista. Cada célula diz Permitido, Negado ou Indisponível (com o motivo), o menu "Todos" pede confirmação contando quem muda, e "Desfazer" devolve os valores por 10 segundos ([#2](https://github.com/sintax-tech/mcp-for-nextcloud/issues/2)).
  - **Excluídos do acesso MCP:** a seção de etiquetas diz o que faz e o que não faz: esconde arquivos só deste conector, quem pode atribuir cada tipo de etiqueta e que as ferramentas de log do servidor respeitam a exclusão só enquanto "Esconder o texto das mensagens do log" estiver ligado ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)).
- **1.0.8: novo nome.** O app passa a se chamar **MCP for Cloud**: as regras da App Store do Nextcloud não permitem o nome Nextcloud no nome de um app. Nada muda para você: o ID do app, as configurações, as permissões e as conexões são os mesmos, e não há nada a fazer.

Quem vem da 0.7.0 ou de uma versão posterior: as migrações de esquema rodam sozinhas (tabelas `mcp_file_batches`, `mcp_oauth_spent` e `mcp_logs_groups`), sem passo manual, sem `occ` e sem mudar o `config.php`.

## Funcionalidades

| Módulo | Leitura | Escrita (cada uma exige liberação do admin) |
|---|---|---|
| **Arquivos** | listar, buscar (por nome ou conteúdo com trechos via fulltextsearch), buscar imagens por tag/data, ver imagens e prévias em lote (JPEG, PNG, WebP, GIF, TIFF, PDF), ler texto (TXT/MD, PDF, DOCX, ODT; um escaneado sem texto retorna `text_layer: false` e, com o app opcional Workflow OCR ativo, ganha texto em segundo plano), listar e ler versões do arquivo | editar texto com diff e substituição de trecho, com backup conferido em `/MCP backups` antes de cada gravação (o versionamento do Nextcloud vale como em qualquer edição); restaurar uma versão guardada; retirar qualquer arquivo (DOCX, XLSX, PDF, imagens, até o limite de envio) para um agente com terminal por um link de uso único; reorganizar pastas: varrer a árvore, criar pasta, copiar, mover, mover um lote e desfazer. **Nunca exclui o arquivo.** Também listar os compartilhamentos que você criou, compartilhar um arquivo ou pasta seus com uma pessoa, um grupo ou um link público (com senha gerada quando pedida) e remover um compartilhamento — o que sai é o acesso, não o arquivo. *(Enquanto houver etiqueta de exclusão configurada, compartilhar, criar link, copiar ou mover pasta para outro diretório é recusado no MCP; arquivos individuais e renomeação de pasta no mesmo diretório pai continuam funcionando.)* |
| **Notas** | listar, ler, buscar (por título e conteúdo Markdown) | criar, editar, mover entre categorias, excluir (só quando a lixeira permite recuperar) |
| **Calendário** | listar calendários e eventos (recorrência, fusos, dia inteiro) | criar, editar, mover, excluir e transferir eventos pelo pipeline CalDAV de verdade, com participantes. As escritas ficam desligadas até o administrador liberá-las na matriz de permissões (sem passo no terminal), e cada uma devolve antes um plano: **nada é gravado antes de o usuário aprovar.** |
| **Contatos** | listar catálogos pessoais/compartilhados, buscar e ler contatos, incluindo o catálogo somente leitura de contas da instância (colegas, com `accountId` para convites) conforme as regras de enumeração do admin | criar e editar preservando campos desconhecidos do vCard; excluir permanentemente só após backup `.vcf` verificado em `/MCP backups/Contacts` |
| **Tarefas** | listar agendas de tarefas e VTODO, incluindo tarefas sem data, opcionalmente só as de uma categoria; ler tarefas, com as suas categorias | criar, editar, concluir e excluir tarefas simples pelo CalDAV, inclusive as categorias; exclusão usa a lixeira do calendário e recusa retenção zero |
| **Deck** | quadros, listas, cards e **acompanhamento** dos quadros que você gerencia: cards por responsável, com prazo, indicação de atraso (no seu fuso) e link do card | criar, editar, mover e excluir cards, e atribuir ou remover responsáveis; criar um quadro com suas listas e primeiros cards, ou uma lista em um quadro que você gerencia; excluir um quadro ou uma lista somente quando estiver vazio, direto para a lixeira do Deck |
| **Log do servidor** | para administradores e os grupos que eles escolherem: listar as entradas do `nextcloud.log` com filtros e resumi-las (totais, assinaturas frequentes, URLs, user agents, entradas por hora). Desligado por padrão para todos; IPs mascarados, saída marcada como não confiável, toda leitura auditada. Com etiquetas de exclusão configuradas, a opção de admin "Esconder o texto das mensagens do log" (ligada por padrão) esconde o texto das mensagens e das exceções, o user agent e o detalhe da URL e recusa o filtro `contains`; desligada, o log volta completo para diagnóstico | — (somente leitura) |
| **Talk** | conversas e mensagens (nunca marca como lido) | responder (podendo citar uma mensagem ou incluir o link de um card do Deck ou evento que você pode ver), mensagem direta para um usuário, lote de mensagens, compartilhar ou citar arquivo, criar conversa em grupo (permissão própria, desligada por padrão). **Nada é enviado antes de o usuário aprovar: chamada sem `confirm: true` devolve só o plano (destinatário, texto final, anexos, o que será criado ou compartilhado) e não executa nada; com `confirm: true` revalida as permissões e envia. Não há approval_id, token nem registro no servidor.** |

E também:

- **Confirmação conversacional antes de qualquer escrita (`confirm: true`)**: qualquer ferramenta que altera dados (`operation != 'read'`) só executa quando chamada com `confirm: true`. Sem isso, o servidor apenas devolve o plano (`requiresConfirmation: true`) sem modificar nada. O assistente deve apresentar o plano ao usuário e só repetir a chamada com `confirm: true` após aprovação explícita. Nenhum estado ou token de aprovação é gravado no servidor.
- **Recursos MCP (MCP Resources)**: suporte nas duas gerações do protocolo (`resources: {}`). Clientes podem listar `mcp://guide` para ler o guia das ferramentas diretamente, descobrir templates de recursos (`nc://files/{path}` e `nc://notes/{id}`) filtrados pelos grants e apps do usuário, e ler arquivos de texto (com extração automática de texto), notas e pequenos binários em base64 (<= 512 KiB). Recursos sem permissão, inexistentes ou ocultos falham com código padrão.
- **OCR pelo Workflow OCR**: com o app Workflow OCR instalado, os PDFs escaneados ganham uma camada de texto que o `files_read` lê normalmente. Sem ele, um PDF ou imagem sem texto volta com `text_layer: false` e um aviso, e a página de admin mostra o status do OCR com o link para instalar. Nada fica bloqueado.
- **Guia das ferramentas** (`mcp_guide`): o modelo pergunta ao servidor o que cada ferramenta faz, seus parâmetros, limites e se exige confirmação. O guia é montado das mesmas definições que o `tools/list` entrega, filtrado pelas permissões e apps do usuário. Ele é escrito em inglês e o modelo repassa no idioma do usuário.
- **Nomes amigáveis das ferramentas** no cliente ("Buscar arquivos", "Listar calendários") e anotações MCP (`readOnlyHint`, `destructiveHint`), para o cliente pedir confirmação em ações de risco.
- **Confirmação de segurança** em recursos de outras pessoas: pastas compartilhadas, pastas de time, quadros do Deck e calendários de outro dono. O servidor recusa a primeira chamada e devolve uma mensagem pronta. O assistente precisa perguntar ao usuário antes de repetir com `confirm_shared: true`. *(Deck e Calendário desde a 0.6.6; Arquivos e Notas desde a 0.7.0)*
- **Apps opcionais respeitados**: as tools e as colunas da matriz de admin de um app desativado (para todos ou para um usuário) ficam ocultas.
- **Interface traduzida**: a matriz de admin, a página pessoal, a tela de consentimento e toda mensagem mostrada por uma tool seguem o idioma da conta Nextcloud do usuário. Todos os módulos estão cobertos (Arquivos, Notas, Calendário, Contatos, Tarefas, Deck, Talk, as mensagens compartilhadas, as páginas de checkout e o selftest do calendário). Inglês, português do Brasil, espanhol e alemão vêm incluídos (o alemão é de @matt-ek; o espanhol é uma primeira tradução e ainda precisa de revisão de um nativo); as descrições das tools ficam em inglês, porque quem as lê é o modelo.
- **As duas gerações do MCP** no mesmo endpoint: `2026-07-28`, sem estado (`server/discover`), e o fluxo clássico com `initialize` (`2025-06-18` e anteriores).

## Contatos e tarefas

Contatos usam o CardDAV do core e apenas os catálogos próprios e compartilhados com você. A edição altera os campos fornecidos e preserva propriedades desconhecidas, parâmetros, grupos e a versão do vCard. O app Contacts precisa estar habilitado para o usuário. Tarefas usam VTODO nas agendas que suportam esse componente, sem depender do app opcional Tasks nem da interface Calendar. As categorias das tarefas (a propriedade `CATEGORIES`) são texto livre, como no app Tasks: `categories` é devolvido ao ler e listar, e ao criar e editar uma lista substitui todas as categorias, `[]` limpa e omitir mantém; `tasks_list_tasks` pode filtrar por uma `category`, sem diferenciar maiúsculas.

Os dois módulos têm grants próprios de `read`, `create`, `edit` e `delete`; toda escrita começa desligada. Sem `confirm: true`, a chamada devolve o plano real de antes/depois e não cria backups nem faz escritas DAV. Coleções de outro dono exigem `confirm_shared: true`. O ETag é opcional; quando enviado, precisa conferir, e edições/exclusões confirmadas sempre usam o ETag atual em `If-Match`.

**Excluir contato é permanente: o Nextcloud não tem lixeira de contatos.** O plano mostra todos os campos do vCard e esse aviso. Após a confirmação, o vCard original completo é salvo e relido na pasta do usuário que executa a ação: `/MCP backups/Contacts/<catálogo>/<nome-ou-uid>.<AAAAmmdd-HHMMSS>-<sufixo-único>.vcf`. Arquivos existentes nunca são escolhidos para sobrescrita; falha no backup impede a exclusão. O resultado informa `backupPath`; importe o arquivo pela interface Contacts para recuperar o contato. Essa cópia é uma proteção, não uma lixeira nativa de contatos.

A exclusão de tarefas usa a lixeira nativa do calendário e é recusada com `dav/calendarRetentionObligation` em `0`. Tarefas recorrentes podem ser lidas; alterar tarefas recorrentes ou com participantes é recusado para proteger a série e o agendamento. Tarefas concluídas ficam fora da listagem por padrão; `include_completed` permite incluí-las. Busca de contatos e listagem de tarefas aceitam `limit` (até 200) e `offset`, devolvendo `nextOffset` quando há mais resultados.

## Requisitos

- Nextcloud **32 a 35**, cada versão conferida pelos testes de contrato de API contra o primeiro build da major (com o Deck e o Talk correspondentes); Deck 1.16+ e Talk 22+ no 32, até Deck 1.19 e Talk 25 no 35
- PHP **8.2+** com `zip`, `mbstring` e `dom`
- Apps opcionais, só para as próprias ferramentas (ocultas enquanto o app estiver desativado): Notes, Calendar, Contacts, Deck, Talk (`spreed`), Versões (`files_versions`, obrigatório para editar), Arquivos excluídos (`files_trashbin`, obrigatório para excluir)
- Deck e Talk são opcionais: as ferramentas deles só aparecem com o app ativo numa versão suportada; uma versão anterior conta como desativada, as ferramentas dela ficam ocultas e a página de administração diz qual versão as traz de volta

## Instalação

**Pela App Store (recomendado).** Como administrador, abra *Apps*, busque **MCP for Cloud** e clique em **Baixar e ativar**. Pelo terminal, o equivalente é `sudo -u www-data php /var/www/nextcloud/occ app:install mcp`. O pacote da loja é assinado, e as atualizações chegam pelo atualizador normal de apps do Nextcloud.

**Build manual (alternativa, para desenvolvimento).**

1. **Gere o pacote** em qualquer máquina com PHP e Composer:

   ```bash
   cd mcp
   ./scripts/package.sh          # gera build/mcp-<versão>.tar.gz
   ```

   O script empacota só arquivos de produção e confere o arquivo final: metadados do macOS (`._*`), pastas extras na raiz e assets faltando.

2. **Copie e habilite no servidor:**

   ```bash
   scp build/mcp-<versão>.tar.gz usuario@servidor:/tmp/
   ssh usuario@servidor
   sudo tar -xzf /tmp/mcp-<versão>.tar.gz -C /var/www/nextcloud/apps/
   sudo chown -R www-data:www-data /var/www/nextcloud/apps/mcp
   sudo -u www-data php /var/www/nextcloud/occ app:enable mcp
   ```

   Ajuste os caminhos e o usuário do servidor web ao seu ambiente. Com Docker, rode o `occ` dentro do container.

Depois, **configure** em *Configurações de administração → MCP for Cloud* (o app tem entrada própria, com ícone, no menu de configurações): ligue o serviço, marque quem pode conectar (individualmente ou por grupo nativo do Nextcloud), libere as permissões de escrita necessárias e selecione as etiquetas de sistema que excluem arquivos sensíveis do acesso MCP (restringir usuários a calendários e listas de tarefas específicos está descrito em [Restrição por recurso](#restrição-por-recurso); o editor dela nesta página é *TODO: B7*). A página tem cinco abas, cada uma com endereço próprio: **Visão geral** (endpoint para copiar, serviço, versão do app, usuários habilitados/conectados, conexões ativas e o limite de envio do checkout editável em MiB, ao lado do teto `post_max_size` do PHP), **Acesso e permissões** (a matriz de **Permissões** — com busca, grupo, filtros "quem pode conectar / conectados" e por aplicativo, coluna de nomes fixa, Permitido, Negado ou Indisponível em cada célula, menu "Todos" por módulo para os usuários da página que pede confirmação e oferece "Desfazer" por 10 segundos, e paginação em cima e embaixo — e o editor de **Permissões de grupo** para conceder *Pode conectar* e operações por módulo a grupos nativos do Nextcloud com prévia antes de salvar; com a redefinição de overrides individuais para a herança dos grupos (prévia, desfazer) e um **Inspetor de acesso**, veja [Overrides individuais, redefinição e o inspetor de acesso](#overrides-individuais-redefinição-e-o-inspetor-de-acesso); *TODO: editor de restrição por recurso B7*), **Clientes e conexões** (clientes OAuth e conexões ativas), **Proteção de dados** (**Excluídos do acesso MCP**, as etiquetas de sistema que excluem arquivos) e **Avançado** (**Log do servidor**: os grupos cujos membros podem lê-lo, além dos administradores, **Privacidade do log**: o interruptor que esconde o texto das mensagens do log enquanto houver arquivos excluídos por etiquetas, e **OCR**).

Os grupos com acesso ao log do servidor, as concessões por grupo, as revisões de política e os escopos por recurso ficam nas tabelas versionadas do próprio app, criadas automaticamente pelas migrações nativas sem nenhum comando no terminal. Os grupos do log que já estavam no appconfig são importados na primeira leitura. As APIs de admin devolvem `version`/`revision` no GET e exigem o campo na gravação; gravações desatualizadas recebem 409 sem sobrescrever alterações concorrentes.

## Conectando um cliente

Todos os clientes falam com a mesma URL, `https://cloud.example.com/apps/mcp/`, e se autenticam como quem está usando, com as permissões dessa pessoa. Só a etapa de autenticação muda:

| Cliente | Autenticação | O que o administrador precisa fazer antes |
|---|---|---|
| **Claude** — claude.ai, Claude Desktop, Cowork, Claude Code | **Entrar agora** (OAuth), identidade publicada do Claude (CIMD) | Nada: o `claude.ai` é permitido por padrão |
| **ChatGPT** | **Entrar agora** (OAuth), identidade publicada da OpenAI (CIMD) | Nada: o `chatgpt.com` é permitido por padrão |
| **Gemini CLI** | OAuth com o cliente nativo embutido no app | Ligar **Permitir programas locais** e copiar o `client_id` |
| **Qualquer outro cliente MCP** | Senha de app (HTTP Basic) | Nada |

### Claude (claude.ai, Claude Desktop, Cowork e Claude Code — recomendado)

1. *Configurações → Conectores → Adicionar conector personalizado*.
2. URL: `https://cloud.example.com/apps/mcp/`.
3. Autenticação: **Entrar agora** (OAuth). Cliente: **identidade publicada do Claude** (CIMD). Não precisa de cabeçalho.
4. **Vincular**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

O `claude.ai` já está na lista de hosts de clientes permitidos, então nada precisa ser mudado no servidor. Se um administrador restringiu essa lista, o Claude é recusado com `invalid_client` antes mesmo de a tela de login abrir: peça para ele recolocar o `claude.ai` em *Configurações de administração → MCP for Cloud → Clientes OAuth → Hosts de clientes permitidos*.

### ChatGPT

1. No ChatGPT, adicione um novo conector para servidor MCP e cole `https://cloud.example.com/apps/mcp/` como URL.
2. Autenticação: **Sign in** (OAuth). O ChatGPT se identifica com o documento de identidade de cliente publicado em `chatgpt.com`; o app baixa esse documento para descobrir os redirect URIs registrados. Não precisa de cabeçalho nem de senha de app.
3. **Conectar**: o seu Nextcloud abre, você faz login e clica em **Permitir**.

O `chatgpt.com` é permitido por padrão, então uma instalação nunca configurada já aceita o ChatGPT. Se um administrador restringiu a lista para outros hosts, o ChatGPT é recusado com `invalid_client` antes da tela de login — peça para ele recolocar o `chatgpt.com` em *Configurações de administração → MCP for Cloud → Clientes OAuth → Hosts de clientes permitidos*.

### Gemini CLI

Dois passos, um do administrador e um do usuário, sem nenhum passo no terminal do servidor.

**Administrador** — *Configurações de administração → MCP for Cloud → Clientes OAuth*:

1. Marque **Permitir programas locais (cliente nativo)**. Por padrão está desligado.
2. A seção passa a mostrar o `client_id` para copiar — `nextcloud-mcp-native` — e os redirect URIs aceitos.

**Usuário** — crie ou edite `~/.gemini/settings.json` na sua própria máquina:

```json
{
  "mcpServers": {
    "nextcloud": {
      "url": "https://cloud.example.com/apps/mcp/",
      "oauth": {
        "clientId": "nextcloud-mcp-native"
      }
    }
  }
}
```

3. No Gemini CLI, rode `/mcp auth` (ou `/mcp auth nextcloud`).
4. O seu Nextcloud abre no navegador: faça login e clique em **Permitir**. O Gemini CLI está ouvindo numa porta livre escolhida por ele mesmo, então o navegador retorna para `http://localhost:<porta>/oauth/callback`. O app aceita `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` e `http://[::1]/oauth/callback`, e ignora a porta para endereços loopback (RFC 8252 7.3), então a porta aleatória ainda casa.

Por que um cliente embutido: o Gemini CLI não publica documento de identidade de cliente, então o app traz um cliente público fixo para programas locais. O id é o mesmo para todo mundo na instância, e isso é seguro porque ele não concede nada sozinho — o login, a elegibilidade, a conexão pessoal, as liberações do administrador, a tela de consentimento e o PKCE continuam valendo. Desligar a opção também impede o cliente de trocar um código de autorização e de renovar token.

### Administradores: Clientes OAuth

*Configurações de administração → MCP for Cloud → Clientes OAuth* decide quais clientes podem conectar, sem nenhum passo no terminal:

- **Hosts de clientes permitidos** — os hosts cujo documento de identidade de cliente publicado o app aceita baixar, separados por vírgula; `claude.ai` e `chatgpt.com` por padrão. Só nome de host exato: sem `https://`, sem porta, sem caminho, sem `*`, e é preciso deixar pelo menos um. O host é o que está na URL que o cliente usa como `client_id`. Salvar substitui a lista inteira, e remover um host recusa aquele cliente na hora, mesmo que o documento dele já esteja em cache.
- **Permitir programas locais (cliente nativo)** — liga o cliente `nextcloud-mcp-native` descrito acima e mostra o `client_id` e os redirect URIs aceitos para copiar.

Os dois controles são exclusivos de administrador, têm proteção CSRF e avisam quando uma gravação é recusada. Uma configuração que você mesmo gravou não é sobrescrita pelos padrões: um valor de `oauth_client_hosts` salvo antes da 0.8.0 continua valendo como está.

### Outros clientes MCP (senha de app)

Crie uma senha de app em *Configurações pessoais → Segurança* e ative a conexão em *Configurações pessoais → MCP for Cloud*. Depois configure o cliente com a URL acima e autenticação HTTP Basic (`usuario:senha-de-app`). Teste rápido:

```bash
curl -u 'alice:SENHA-DE-APP' \
  -H 'Content-Type: application/json' -H 'Accept: application/json, text/event-stream' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"0"}}}' \
  https://cloud.example.com/apps/mcp/
```

### Revogando acesso

- **Usuário**: *Configurações pessoais → MCP for Cloud → Desconectar* bloqueia a próxima requisição e revoga os tokens OAuth. A mesma página lista os próprios clientes OAuth (cliente, conectado em, expira em) com **Revogar** em cada um. Senhas de app se revogam em *Segurança*.
- **Admin**: remova a permissão de conectar (individualmente ou removendo a permissão do grupo / participação no grupo) ou desligue o serviço. Vale a partir da próxima requisição. *Configurações de administração → MCP for Cloud → Conexões ativas* lista toda conexão OAuth (usuário, cliente, conectado em, expira em) e revoga uma, ou todas de um usuário, na hora; o usuário pode entrar de novo enquanto tiver permissão para conectar.

## Segurança

- Toda chamada reconfere a identidade autenticada, o serviço ligado, a elegibilidade do usuário (um override individual ou uma concessão de um grupo nativo dele), a conexão pessoal e a liberação do admin para aquela operação e, então, toda checagem do recurso precisa passar; quando várias recusam, a resposta segue a precedência
  `Exclusão por etiqueta de sistema > Restrição por recurso > ACL nativa do Nextcloud`. Um recurso excluído responde "não encontrado" mesmo onde a ACL nativa sozinha responderia "proibido"; uma recusa só da ACL nativa mantém a resposta do Nextcloud. A restrição por recurso vale hoje para calendários e listas de tarefas (Arquivos: *TODO: B5*).
  A exclusão e a restrição por recurso apenas estreitam o acesso; nunca concedem nem ampliam o que as ACLs do Nextcloud permitem. Um recurso excluído devolve exatamente a mesma resposta de "não encontrado" de um inexistente e fica de fora de listagens, buscas e prévias; um calendário ou lista de tarefas fora do escopo se comporta do mesmo modo, e uma restrição que não pode ser lida responde 503 em vez de listar tudo (Arquivos: *TODO: B5*).
- OAuth: PKCE S256, redirect URI idêntico (um redirect URI loopback pode usar qualquer porta, RFC 8252 7.3), documentos de identidade de cliente aceitos só dos hosts permitidos que o administrador edita na tela de admin (padrão `claude.ai` e `chatgpt.com`), o método público `none` como único método de autenticação de token aceito, tokens guardados só como hash HMAC, refresh de uso único com rotação.
- Um documento de identidade de cliente é baixado por HTTPS, só de host permitido, sem seguir redirecionamentos, limitado a 64 KiB e mantido em cache por uma hora. Tirar um host da lista bloqueia o cliente na hora, com cache ou sem cache.
- Todo redirecionamento de volta a um cliente carrega o `iss` com o identificador de emissor deste servidor (RFC 9207) — no código de autorização, numa negação e em erros redirecionáveis. Erros que ocorrem antes de o redirect URI ser validado aparecem numa página local, em vez de serem redirecionados.
- O cliente nativo é desligado por padrão. Uma vez ligado, ele é um cliente público, com `client_id` fixo e não secreto, que só pode voltar para um callback loopback, e não concede acesso por si só: login, elegibilidade, conexão pessoal, liberações do administrador, tela de consentimento e PKCE continuam valendo. Desligá-lo também impede a troca de código de autorização e a renovação de token.
- A seção "Clientes OAuth" do admin é exclusiva de administrador, tem proteção CSRF, recusa uma lista de hosts inválida em vez de gravá-la, e mostra o estado efetivo e o erro de gravação.
- Nada de senha, token ou conteúdo de arquivo em log. Os erros devolvidos ao cliente são genéricos.
- A tela de consentimento tem proteção CSRF e não pode ser embutida em frame.
- Encontrou uma vulnerabilidade? Reporte em privado aos mantenedores, e não numa issue pública.

### Permissões por grupo, garantias e limites

Em *Configurações de administração → MCP for Cloud → Acesso e permissões → Permissões de grupo*, administradores podem conceder *Pode conectar* e operações de módulos a grupos do Nextcloud, com prévia antes de salvar que mostra as permissões acrescentadas e removidas, os membros atuais e os titulares de credencial que podem ser desconectados (uma estimativa; não mostra o acesso efetivo de cada membro depois da mudança, *TODO*).

- **Como as permissões de grupo se combinam com overrides individuais:** grupos apenas **concedem** (`Permitir`); nunca negam nem estreitam. Se um usuário tiver um ajuste individual (`Permitir` ou `Negar`) em *Pode conectar* ou numa operação específica, esse ajuste individual sempre vence. Sem ajuste individual, o usuário herda `Permitir` se algum de seus grupos confiáveis conceder a permissão; caso contrário, vale o padrão do módulo (`Permitir` para `read`, exceto `logs.read`; `Negar` para todas as escritas e para *Pode conectar*).
- **Por que apenas grupos nativos do Nextcloud (backend `'Database'`) concedem permissões:** um grupo só pode conceder permissões MCP quando o Nextcloud o atende exclusivamente por um único backend cujo nome informado é `'Database'`. Grupos vindos de LDAP, SAML, apps de terceiros, backend sem identificação ou múltiplos backends ao mesmo tempo aparecem desabilitados na lista com o motivo. Backends externos não entregam eventos de participação ou exclusão de forma confiável ao Nextcloud e podem compartilhar o mesmo ID de grupo entre backends; **para liberar acesso MCP a membros de um grupo externo, conceda as permissões individualmente a esses usuários na matriz de *Permissões*.**
- **O que significa "aguardando confirmação" (`needs_confirm`) e como resolver:** se a criação ou exclusão de um grupo sofrer rollback ou for observada enquanto outra transação de banco estiver aberta, ou se a classificação de backend do grupo mudar, o app marca o grupo como **aguardando confirmação**. Nesse estado, o grupo não concede nada, mas suas permissões salvas são preservadas em vez de apagadas automaticamente. Para reativar o grupo, abra *Acesso e permissões → Permissões de grupo*, revise as permissões do grupo e clique em **Confirmar política atual**.
  - Um grupo aguardando confirmação só torna o acesso de um usuário temporariamente indisponível (HTTP 503, sem revogar suas conexões OAuth) quando a elegibilidade ou a permissão da ferramenta daquele usuário dependia **exclusivamente** desse grupo. Se o usuário tiver um override individual `Permitir` ou outro grupo válido que conceda a permissão, ele continua operando normalmente. Em `tools/list`, uma ferramenta cuja concessão esteja temporariamente incerta é omitida da lista em vez de derrubar a listagem inteira (e, quando uma ferramenta aceita concessões alternativas, basta uma concessão conhecida e permitida).
  - Ao retirar *Pode conectar* de um grupo (ou excluir um grupo), se o app não puder confirmar a elegibilidade remanescente de algum membro com credenciais ativas, o salvamento é recusado (503) sem alterar nada para que o administrador possa tentar novamente.
  - Ao excluir um grupo, o app primeiro o suspende (aguardando confirmação) e só então levanta os membros que têm credencial; o grupo só é marcado como em exclusão depois que todos os cortes foram registrados. Se algo falhar no meio, ou o processo cair, o grupo fica suspenso e nada é apagado; enquanto isso, o que ele concedia responde "indisponível". Toda nova conexão que dependia do grupo renova o estado dele e é recusada se a suspensão vencer. Limite residual: se a suspensão perder a corrida três vezes, uma conexão feita nesse intervalo só volta a valer se o administrador confirmar explicitamente a política do grupo.
  - Quando alguém sai de um grupo, a saída é registrada mesmo que a pessoa tenha um *Permitir* individual; um reset em andamento nesse momento é recusado (conflito) e, repetido, corta.
  - Voltar um *Permitir* individual para *Herdar* só derruba a conexão se o usuário perder a elegibilidade de fato; se um grupo ainda o mantém elegível, nada é cortado, e na dúvida a mudança é recusada sem corte.
- **Garantia e limites declarados:** o app garante a aplicação **por eventos entregues** — ele aplica todo sinal de usuário e de grupo que o Nextcloud entrega fora de uma transação alheia de banco de dados. Como o Nextcloud não expõe ganchos transacionais nem identidade autenticada de backends, os seguintes limites se aplicam por construção:
  1. **Grupo excluído e recriado com o mesmo ID (`GID`) quando sinais se perdem:** se o processo PHP morrer antes do processamento ao final da requisição, durante a curta janela entre o commit do Nextcloud e esse processamento diferido (incluindo exclusão e recriação na mesma transação), ou se uma transação alheia continuar aberta no encerramento do processo, um novo grupo recriado com o mesmo `GID` pode herdar a política salva do grupo anterior, e requisições concorrentes nessa janela ainda podem avaliar a política antiga.
  2. **Nome de backend `'Database'`:** o Nextcloud não reserva nem autentica nomes de backends de grupo (`INamedBackend`). Qualquer backend de grupo instalado pelo administrador do servidor que informe seu nome como `'Database'` é tratado como o backend nativo de banco de dados.
  3. **Origem de backend que muda e volta entre duas observações:** se o backend de um grupo mudar para uma fonte não confiável e voltar para `'Database'` antes de o app observá-lo, a mudança intermediária não é detectada (qualquer perda de confiança observada suspende o grupo em aguardando confirmação).
  4. **Caches em memória em processos longos (`occ` / workers):** `Database::groupExists` do Nextcloud mantém um cache positivo, o `AppManager` mantém seu próprio cache dos apps habilitados e `IUserManager::get` devolve objetos `IUser` memorizados. Requisições web normais usam um processo PHP novo por requisição e não são afetadas, enquanto processos longos de CLI ou workers podem observar estado antigo até terminarem.
  5. **Exceção decidida na revogação de conexões OAuth em eventos incertos de usuário:** por padrão, na dúvida sobre se um usuário perdeu elegibilidade (ou quando uma conta é excluída), o app revoga as conexões OAuth do usuário e exige novo login. A única exceção intencional ocorre quando um evento de usuário é capturado dentro de uma transação de banco aberta por outro código (onde o app nunca executa SQL, pois um erro SQL no PostgreSQL abortaria a transação do próprio Nextcloud) e, quando o app processa a revalidação enfileirada sem carimbo de tempo do evento, o usuário continua elegível: nesse caso, nem credenciais antigas nem novas são revogadas, evitando desconectar desnecessariamente quem permaneceu elegível.

### Overrides individuais, redefinição e o inspetor de acesso

- **Voltar a herdar dos grupos:** em *Acesso e permissões → Permissões*, uma célula com definição individual mostra o estado (permitido, negado ou valor salvo inválido) e um botão ↺ que a remove, de modo que grupos e padrões voltam a decidir. Cada linha tem *Voltar ao grupo*, e os usuários selecionados na página (há *Selecionar todos os usuários desta página*) podem ser redefinidos juntos com *Voltar a herdar dos grupos para os usuários selecionados*. Uma redefinição aceita no máximo 50 usuários e 2.500 células.
- **Prévia, antes e depois:** nada é salvo antes de uma prévia. Ela lista as definições a remover (permitidas, negadas, inválidas) e, célula por célula, o resultado antes → depois (uma definição individual, os grupos que concedem, o padrão ou *Desconhecido*), com o número de resultados que mudam, ficam iguais ou são desconhecidos. Para os usuários que perdem *Pode conectar* ela avisa, com o número deles: “N usuários perdem “Pode conectar” e o acesso que ele dá. As conexões MCP deles são revogadas, e desfazer isto não os reconecta.” Avisa também quando um grupo mantém um usuário conectado e que remover um Negar individual de *Pode conectar* devolve a decisão aos grupos, o que pode dar acesso. Se o que um usuário herda não puder ser lido, o resultado aparece como desconhecido, uma redefinição que removeria *Pode conectar* é recusada até que os grupos possam ser lidos, e tentar de novo é sempre um clique seu.
- **Recusada quando a tabela mudou:** a redefinição é enviada com a revisão da política em que a prévia foi lida. Se as permissões mudaram desde então (nesta página, por outro administrador ou por um grupo), nada é gravado: a prévia se fecha ou avisa que a tabela está mais antiga que a prévia, e a tabela precisa ser recarregada para revisar a redefinição de novo.
- **Desfazer:** depois de uma redefinição, a página oferece *Desfazer* por 10 segundos, restaurando as definições removidas a partir do recibo do servidor. Ele não reconecta quem foi desconectado.
- **Limite: fixar um Permitir que já é herdado.** Uma célula permitida só por um grupo não tem valor próprio, e a tabela não tem um controle direto para gravar um Permitir individual sobre ela. Para manter esse Permitir caso o grupo mude, desmarque a caixa (Negar) e marque de novo (Permitir), como diz o texto de ajuda da página. Não faça isso em *Pode conectar*: negá-lo desconecta o usuário.
- **Inspetor de acesso:** *Acesso e permissões → Inspetor de acesso* é só para administradores e só lê; nada é alterado. Informe um ID de usuário para ver cada operação com seu resultado (*Permitido*, *Bloqueado* ou *Desconhecido*), a primeira verificação que bloqueia (a política MCP, as permissões do Nextcloud, as etiquetas de exclusão, a restrição por recurso, uma concessão de grupo ou a elegibilidade por grupo), de onde vem a concessão (um valor individual, grupos nomeados, o padrão ou um grupo aguardando confirmação) e, por módulo, se o usuário está restrito a alguns recursos (*Restrito a N*). A mesma página explica um caminho nos arquivos do usuário e um calendário ou lista de tarefas. Para um caminho excluído, dá o motivo e nomeia a pasta excluída quando a exclusão é herdada dela; esses detalhes aparecem só para administradores, e as ferramentas continuam respondendo "não encontrado". Quando uma verificação não pode ser confirmada, a resposta é *Desconhecido* (*Não confirmado: … então o acesso é desconhecido*), nunca *Permitido*. O inspetor usa a mesma resolução das ferramentas, não uma regra paralela.
- **Auditoria do inspetor:** toda pergunta é gravada no log do Nextcloud em nível *info* (administrador, usuário inspecionado, tipo de pergunta, módulo e um código de resultado; nunca um caminho, um calendário ou um nome), então só aparece com o `loglevel` do servidor em 1 ou menos; o padrão (2) não grava linhas *info*, e isto não é uma trilha de auditoria garantida. Uma inspeção que falha é registrada em nível de erro apenas com a classe da falha.

### Excluídos do acesso MCP

Em *Configurações de administração → MCP for Cloud → Proteção de dados → Excluídos do acesso MCP*, o administrador escolhe as etiquetas de sistema (recomendadas: restritas ou invisíveis) que ocultam arquivos e pastas sensíveis deste conector MCP:

- **Herança e cobertura de armazenamento:** a etiqueta em um arquivo ou pasta cobre o próprio item e todos os arquivos e pastas abaixo dele em qualquer profundidade, criados via MCP, WebDAV ou interface web do Nextcloud. Um item alcançado por um compartilhamento local herda a exclusão da cadeia da pasta de origem do dono. Pastas de equipe (`groupfolders`) e storage externo (`files_external`) são verificados ao longo do caminho montado (a pasta acima da raiz de uma montagem é resolvida de novo pelo caminho, para que storages externos e arquivos de pastas de equipe compartilhados avulsos não sejam bloqueados indevidamente). Compartilhamentos federados são verificados só pelas etiquetas locais (veja os limites abaixo).
- **Superfície coberta:** todas as ferramentas `files_*` (listagem, árvore, leitura, busca por nome e texto completo, prévias e busca de imagens, edição/substituição de texto, criação, upload, `mkdir`, cópia, movimentação, lote e desfazer, versões, compartilhamentos e links de uso único de download/upload do `files_checkout`), `notes_*`, recursos MCP (`nc://files/{path}` e `nc://notes/{id}`), Talk (`talk_attach_file` e `talk_quote_file` verificam a visão do autor da chamada sobre o arquivo e recusam arquivos excluídos como não encontrados; `talk_read_messages` devolve `attachmentId: null` quando o arquivo de um anexo da conversa está excluído), Tarefas (propriedades `ATTACH` apontando para URLs locais do Nextcloud `/f/`, `/s/` ou `/remote.php/...` em qualquer host, IP, loopback ou alias, bem como parâmetros `X-NC-FILE-ID`/`FILENAME` do Calendar, são omitidos de `tasks_read_task` e dos planos e resultados das ferramentas de escrita de Tarefas quando o arquivo alvo está excluído, e o objeto guardado no servidor os mantém ao editar; links públicos de pastas são omitidos enquanto houver etiquetas de exclusão ativas), Calendário (saídas de eventos não expõem `ATTACH`/URLs de arquivos), Deck (saídas de cartões expõem apenas `attachmentCount`) e Log do servidor (enquanto "Esconder o texto das mensagens do log" estiver ligado).
- **Operações em pastas enquanto houver etiquetas de exclusão configuradas:** sempre que ao menos uma etiqueta de exclusão estiver configurada, o conector MCP recusa **compartilhar** (`files_share`), **criar ou atualizar link público** (`files_share` com `with: "link"`), **copiar** (`files_copy`) ou **mover para outro diretório** (`files_move`, `files_move_batch`, `files_undo_batch`) qualquer **pasta**, e oculta a `url` (`url: null`) em links públicos existentes de pastas em `files_list_shares`.
  - **Por quê:** a API de armazenamento do Nextcloud não garante visibilidade completa de todos os descendentes dentro de uma pasta (subpastas sem permissão de leitura retornam vazias, montagens indisponíveis somem da listagem e listar pode ter efeitos colaterais). Compartilhar, criar link, copiar ou mover uma pasta para dentro de um diretório compartilhado poderia expor um arquivo excluído dentro dela.
  - **O que continua funcionando e como operar pastas:** todas as operações em **arquivos individuais**, renomear uma pasta dentro do **mesmo diretório pai** (a pasta não entra em outro compartilhamento, e as verificações de trava existentes continuam valendo) e remover um compartilhamento existente de pasta (`files_unshare`) continuam funcionando normalmente no MCP. A visibilidade é conferida primeiro (uma pasta excluída ou configuração de etiquetas ilegível responde sempre "não encontrado"); apenas uma pasta visível recebe a mensagem de recusa explicando que compartilhar, copiar e mover pastas para outro diretório devem ser feitos pela interface web do Nextcloud Files enquanto houver etiquetas de exclusão ativas.
- **Na dúvida, recusa (fail-closed):** uma configuração que não pode ser validada ou uma consulta de etiqueta que falha mantém o item oculto, e a página de admin explica o estado e oferece o botão de reparo (o *Inspetor de acesso* explica por que um caminho é alcançado ou não, incluindo o motivo da exclusão; veja [Overrides individuais, redefinição e o inspetor de acesso](#overrides-individuais-redefinição-e-o-inspetor-de-acesso)).
- **Limites declarados:**
  - **Apenas neste conector:** a proteção vale somente para este conector MCP. Não cobre outras ferramentas de IA, outros conectores que leiam o mesmo Nextcloud, a interface web do Nextcloud nem arquivos que alguém envie manualmente para uma IA.
  - **Compartilhamentos federados:** verificados apenas pelas etiquetas de sistema locais desta instância Nextcloud; etiquetas do servidor Nextcloud remoto não são legíveis.
  - **Log do servidor:** nomes e caminhos de arquivos dentro das mensagens de log só ficam ocultos enquanto **Esconder o texto das mensagens do log** estiver ligado (padrão); um nome de arquivo solto gravado por outro app em um campo estruturado do log não é redigido.
  - **Texto livre:** menções existentes em texto livre ao nome ou caminho de um arquivo em mensagens do Talk, descrições de eventos, descrições de tarefas ou cartões do Deck não são redigidas.
  - **Storage externo montado duas vezes com raízes diferentes:** montar o *mesmo* diretório externo duas vezes com raízes diferentes (por exemplo `/ext` e `/ext/Open`, onde a pasta pai etiquetada fica fora da montagem mais estreita) cria dois storages distintos no Nextcloud com IDs de arquivo diferentes; uma etiqueta aplicada em uma montagem não cobre leituras feitas pela outra. Etiquete o arquivo na montagem por onde ele é lido. Duas montagens da *mesma* raiz (alias) compartilham IDs de arquivo e ficam protegidas juntas.
  - **Janela de cache da lista de etiquetas (~3 s entre processos):** alterações salvas na interface de administração valem imediatamente naquele nó web. Se a lista de etiquetas de exclusão for alterada via `occ` ou em outro nó web num ambiente com múltiplos nós, o Nextcloud pode servir a lista anterior por cerca de 3 segundos (ele guarda a configuração do app em cache local por 3 s; medido entre 2,7 e 3,9 s no Nextcloud 32–35).
  - **Sem selo visual no Nextcloud Files:** a interface do Nextcloud Files exibe o nome da etiqueta de sistema normalmente, sem um indicador separado de exclusão MCP.

### Restrição por recurso

O administrador pode restringir um usuário específico para que, em vez de acessar todos os recursos que sua conta Nextcloud enxerga, ele acesse no MCP apenas uma lista explícita de recursos permitidos. A restrição está aberta para Calendário e Tarefas: toda ferramenta de calendário e de tarefas é estreitada por ela. Para Arquivos continua fechada: gravá-la é recusado até que o B5 ligue todas as ferramentas de Arquivos a ela. *TODO: B5 (Arquivos) e B7 (o editor de admin da restrição de um usuário, que ainda não existe: hoje a restrição é definida pelos endpoints de admin `GET`/`PUT /apps/mcp/api/scope/{uid}/{module}` e `GET /apps/mcp/api/scope/{uid}/{module}/candidates`).*

- **Módulos suportados:**
  - **Arquivos** (*TODO: B5, não ativo*): pastas permitidas, cobrindo as pastas selecionadas e tudo o que há abaixo delas.
  - **Calendário:** calendários de eventos (`VEVENT`) permitidos. Listar, ler, criar, editar, excluir, mover e transferir eventos passam todos pela restrição: um calendário fora dela está ausente, com a mesma recusa de um calendário que não existe, e um calendário da restrição que o usuário só pode ler continua somente leitura. Mover ou transferir um evento exige que tanto o calendário de origem quanto o de destino estejam na lista permitida. As referências a eventos no Talk seguem a mesma regra.
  - **Tarefas:** listas de tarefas (`VTODO`) permitidas, configuradas de forma **independente** de Calendário mesmo quando eventos e tarefas compartilham o mesmo calendário subjacente no Nextcloud: as ferramentas de eventos respondem à restrição de Calendário e as de tarefas à de Tarefas. Um calendário é identificado pelo id dele no Nextcloud, que um compartilhamento mantém e um renomear não muda. Ler um objeto pelo UID olha só o componente da ferramenta, então um evento e uma tarefa com o mesmo UID nunca respondem um pelo outro.
- **Notas fica fora do escopo de recursos de Arquivos:** o módulo Notas tem modelo de armazenamento próprio e não é restringido pela lista de pastas permitidas de Arquivos do usuário (a exclusão por etiqueta de sistema continua valendo para Notas).
- **Precedência e resposta:** `Exclusão por etiqueta de sistema > Restrição por recurso > ACL nativa do Nextcloud`. O escopo por recurso apenas estreita o acesso; nunca concede acesso a pasta, calendário ou lista de tarefas negados pelas ACLs do Nextcloud, e um arquivo com etiqueta de exclusão dentro de uma pasta permitida continua oculto. Um calendário ou lista de tarefas fora do escopo devolve exatamente a mesma mensagem de "não encontrado" de um inexistente e fica de fora das listagens. Uma restrição que não pode ser lida responde 503 (temporariamente indisponível) para qualquer calendário ou lista de tarefas, exista ou não, em vez de listar tudo. Referências estruturadas a arquivos fora de uma restrição de Arquivos devem ser omitidas da mesma forma que arquivos excluídos (*TODO: depende do B5*). As páginas de admin e a lista de candidatos dos endpoints de restrição continuam mostrando todos os calendários do usuário, então uma restrição nunca esconde um calendário de quem a configura.
- **Quem configura e comportamento da sessão:** somente administradores editam o escopo por recurso de um usuário (até 200 itens por usuário e módulo; toda consulta e alteração administrativa é gravada no log do Nextcloud em nível *info*, sem nomes de recursos; só aparece com o `loglevel` do servidor em 1 ou menos, pois o padrão (2) não grava linhas *info*, então isto não é uma trilha de auditoria garantida). O escopo do próprio usuário pode ser lido por um endpoint somente leitura (*TODO: as configurações pessoais ainda não o mostram*). O efeito de uma restrição sobre um usuário pode ser conferido no **Inspetor de acesso**. Alterar o escopo de um usuário **não revoga** suas conexões OAuth ativas; o novo escopo vale na próxima requisição.
- **Escopo vazio, recursos excluídos e contas recriadas:** um módulo restrito a uma lista vazia não dá acesso a nenhum recurso daquele módulo. Se uma conta de usuário do Nextcloud for excluída e outra conta for recriada com o mesmo UID, a nova conta herda a restrição anterior em vez de abrir para acesso irrestrito. *TODO (B3): a limpeza que remove a restrição de uma conta excluída ainda não está nesta versão, então até lá a restrição permanece.*
- **O que não é suportado na 1.1.0:** escopo por recurso para Notas, Contatos, Deck e Talk; escopo por recurso por grupo (grupos apenas concedem permissões de módulo/operação; um usuário com escopo por recurso permanece restrito mesmo quando recebe permissão de módulo via grupo); e redação automática de texto livre.

### Atualização, incidentes e rollback

- **Atualização sem passos manuais a partir da 1.0.8:** atualizar o app executa automaticamente as migrações nativas de banco e inicializa o estado de política sem `occ` nem SQL manual. Permissões individuais existentes mantêm seus valores, os grupos do log do servidor são importados na primeira leitura e as conexões OAuth ativas continuam funcionando.
- **Voltar para a 1.0.8 não é seguro, nem antes de adotar grupos ou restrições:** as novas tabelas ficam vazias e as permissões individuais mantêm seus valores, mas a 1.1.0 revoga credenciais OAuth de forma lógica (por geração e época) e só as remove fisicamente depois. Uma credencial revogada na 1.1.0 e ainda não removida pode voltar a valer na 1.0.8. Se mesmo assim voltar, revogue todas as conexões em torno do rollback, de preferência de novo depois de voltar para a 1.0.8 (*Conexões ativas*).
- **Após adotar permissões por grupo ou restrição por recurso — não faça downgrade para a 1.0.8:** voltar o código para a 1.0.8 sobre o mesmo banco **não é suportado e não é seguro**:
  1. a 1.0.8 não lê `mcp_group_grants`, fazendo com que toda permissão concedida por grupo deixe de valer;
  2. a 1.0.8 não verifica as colunas de geração e época do OAuth (`auth_gen` e `auth_global_epoch`), de modo que tokens OAuth cortados na 1.1.0 apenas pelo avanço de geração do usuário ou da época global podem voltar a ser aceitos;
  3. a 1.0.8 não lê `mcp_scope_modes` nem `mcp_scope_items`, de modo que usuários restritos a recursos específicos voltariam a ter acesso MCP a todos os seus recursos no Nextcloud.
- **Recuperação após a adoção:** restaure a **instalação inteira do Nextcloud** (código, `config`, banco de dados e diretório de dados) a partir do mesmo backup coordenado feito antes da atualização, em modo de manutenção e seguindo o procedimento de restauração do Nextcloud, ou avance para uma versão corrigida da série 1.1.x mantendo o esquema e as épocas da 1.1.0. *TODO (ticket 10): o procedimento de incidente e rollback ainda está sendo finalizado; esta é a orientação atual.* Uma restauração perde tudo o que foi feito depois do backup, inclusive revogações: depois dela, revogue todas as conexões MCP (*Conexões ativas*) e reaplique qualquer elegibilidade ou permissão retirada desde o backup antes de religar o serviço.

## Desenvolvimento

```bash
cd mcp
composer install
vendor/bin/phpunit          # testes unitários com mocks do OCP, sem precisar de Nextcloud
```

Organização: `lib/Tools/<Módulo>` (um `ToolModule` por app), `lib/OAuth` (servidor de autorização), `lib/Service` (protocolo MCP, política de permissões), `lib/Controller`, `templates`, `js`, `css` e `l10n`. As notas técnicas detalhadas ficam em [`mcp/README.pt-BR.md`](mcp/README.pt-BR.md).

O protótipo original em Node.js via stdio (só leitura) foi removido; continua no histórico do git até o commit 27739f0 (tag v0.11.0).

## Histórico de versões

Veja o [`CHANGELOG.md`](CHANGELOG.md) (em inglês).

## Roadmap

- Revisão nativa da tradução para o espanhol

## Licença

AGPL-3.0-or-later, com o termo adicional de atribuição da seção 7(b) no [NOTICE](NOTICE). O texto integral da licença está em [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve o crédito à Sintax na administração, na documentação e nos avisos de autoria ao redistribuir ou oferecer versões modificadas pela rede.

## Créditos

O pacote inclui Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte versões, licenças, autoria e dependências de desenvolvimento em [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contribuidores.** Agradecimento a @NightDragon1 (#5) pela checagem de visibilidade da raiz e a @matt-ek (#4) pelo relato que motivou a auditoria da ocultação por tags e (#10) pela tradução para o alemão.

## Sobre a Sintax

Desenvolvido pela [Sintax](https://sintax.tech) · sintax.tech.

- Site: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contato: contato@sintax.tech
- Outros apps da Sintax para Nextcloud: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)
