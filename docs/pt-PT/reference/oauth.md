<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/reference/oauth.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../../en/reference/oauth.md).

# OAuth

Os metadados do recurso protegido ficam em `/apps/mcp/.well-known/oauth-protected-resource`. O desafio `401` identifica esse documento. Os metadados do servidor de autorização ficam em `/apps/mcp/.well-known/openid-configuration` e `/apps/mcp/.well-known/oauth-authorization-server`, com emissor `https://<instance>/apps/mcp`.

`oauth/authorize` oferece consentimento no navegador; `oauth/token` troca códigos de autorização com PKCE S256 e substitui os tokens de renovação. Tokens de acesso duram uma hora e os de renovação, 30 dias. A base de dados guarda hashes de credenciais, não os valores em texto simples. É autenticação de cliente público (`none`); um ID de cliente é um identificador, não um segredo nem uma concessão de permissões.

Ao ligar um cliente, o ecrã de consentimento começa sem ferramentas de dados selecionadas. Expanda um catálogo para escolher ferramentas e ações individuais, ou selecione e limpe ferramentas de um catálogo ou de todos os catálogos. Selecionar tudo inclui todas as ações permitidas pelo administrador. Também pode autorizar uma ligação com zero ferramentas de dados. As escolhas aplicam-se apenas a esta ligação.

## Clientes publicados, nativos e registados

Administradores gerem clientes em **Administração → Nextcloud MCP → Clientes e ligações**. Guardar exige administrador, proteção CSRF do Nextcloud e a revisão atual da política.

| Tipo de cliente | Identidade e regras de redirecionamento |
| --- | --- |
| Documento de identidade de cliente publicado (CIMD) | Documento HTTPS num servidor com nome exato permitido. Os valores predefinidos são `claude.ai,chatgpt.com`. Redirecionamentos devem corresponder ao documento validado. |
| Cliente nativo incluído | `nextcloud-mcp-native`, desativado por predefinição. Os seus callbacks HTTP de loopback registados permitem portas variáveis para programas locais. |
| Cliente personalizado registado pelo administrador | ID gerado, nome apresentado, estado ativado e lista explícita de URIs de redirecionamento. A correspondência é exata, incluindo a porta em HTTP de loopback. |

A lista permitida aceita hosts sem esquema, porta, caminho nem carateres universais; pelo menos um deve permanecer. Guardar substitui a lista. Remover um host bloqueia a admissão mesmo com o seu documento em cache. Os documentos são descarregados por HTTPS sem redirecionamentos, com limite de 64 KiB e cache de uma hora.

São permitidos no máximo 20 clientes personalizados, nomes de até 64 carateres e até 20 URIs de redirecionamento por cliente. Nomes não podem estar vazios e devem ser únicos sem diferenciar maiúsculas. Cada URI admite até 2000 bytes, usa HTTPS ou HTTP em loopback e não contém informações de utilizador, fragmento, caráter universal, espaços em branco, carateres de controlo ASCII nem barra invertida. Uma lista de redirecionamentos vazia não pode autorizar um cliente. Copie o ID gerado para a configuração e registe o callback exato; clientes personalizados não herdam a exceção de porta variável do cliente nativo.

Após a migração, a tabela de clientes geridos é a fonte autoritativa. Desativar/remover um cliente gerido ou remover um dos seus URIs de redirecionamento retira as concessões existentes e avança a sua geração de cliente. Reativá-lo não reativa uma página de consentimento, código ou token anterior. Uma falha na leitura dessa tabela recusa temporariamente a autorização em vez de mostrar consentimento supondo permissão.

## Ligar através do ChatGPT e Gemini CLI (OAuth)

Consulte os exemplos no [guia de clientes](../guides/clients.md). ChatGPT usa a sua identidade publicada em `chatgpt.com`. Gemini CLI pode usar o cliente nativo incluído após um administrador ativá-lo. Os callbacks nativos são `http://localhost/oauth/callback`, `http://127.0.0.1/oauth/callback` e `http://[::1]/oauth/callback`; a porta de loopback selecionada pode variar.

Todo redirecionamento validado inclui o parâmetro de emissor `iss`, inclusive recusas e erros redirecionáveis. Erros anteriores à validação do redirecionamento ficam numa página local. Interfaces e compatibilidade com clientes alojados ainda exigem revisão com clientes reais; um teste local do protocolo não certifica a sua interface atual.

## Revogação e incerteza

Cada pedido verifica novamente identidade, serviço, elegibilidade, ligação pessoal, aplicação/função de registos, permissão de operação, restrição de recursos, etiquetas de exclusão e ACLs nativas quando aplicável. Uma permissão de grupo não ignora essas verificações. Política desconhecida significa indisponibilidade temporária, não permissão implícita nem revogação destrutiva.

Desligar a ligação pessoal, perda de elegibilidade, desativação do serviço e desativação/eliminação da conta invalidam credenciais OAuth por gerações por utilizador ou uma época global. A invalidação é confirmada com a alteração da política; uma limpeza persistente remove depois as linhas obsoletas e deve preservar credenciais posteriores. Restituir acesso ou reativar um cliente não revive credenciais anteriores. A deteção de reutilização de tokens de renovação continua aplicável à linhagem do proprietário/cliente.

Alterar o âmbito de recursos não revoga OAuth: a próxima operação avalia a nova restrição. Palavras-passe de aplicação HTTP Basic pertencem ao Nextcloud, não a este armazenamento OAuth. Desligar MCP bloqueia a sua utilização em MCP, mas eliminá-las exige os controlos de Segurança do Nextcloud. Desligar o serviço preserva a preferência de ligação pessoal; a recuperação também pode exigir desligação explícita e revisão de palavras-passe de aplicação. Consulte [instalação e recuperação](../installation.md).

## Propriedades de segurança

- O consentimento tem proteção CSRF e não pode ser exibido em frames. Início de sessão, elegibilidade, ligação, permissões, consentimento e PKCE continuam obrigatórios para clientes nativos/personalizados.
- Verificações de geração e invalidação impedem que credenciais retiradas recuperem validade após restituir acesso ou recriar identidade/cliente. Estado incerto da base de dados ou da política produz uma falha que permite nova tentativa, sem suposições.
- Alterações administrativas de clientes validam todo o pedido antes de gravar. Conflitos recarregam o estado em vez de repetir silenciosamente uma mutação.
- Erros inesperados apresentados ao cliente são genéricos. O diagnóstico da aplicação não regista credenciais em texto simples nem conteúdo de ficheiros.
- Comunique vulnerabilidades aos responsáveis pela manutenção em particular. Não desative a proteção contra força bruta nem permita um intervalo de rede não verificado para contornar uma ligação que falhou; investigue a recusa e os registos do servidor.

[Guias de operação](../guides/README.md).

[English](../../en/reference/oauth.md) · [Español](../../es/reference/oauth.md) · [Català](../../ca/reference/oauth.md) · [Português (Brasil)](../../pt-BR/reference/oauth.md) · Português (Portugal)
