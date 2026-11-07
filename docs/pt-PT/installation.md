<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: 6cbeb0b5675be9b99f3a27c755cf92069f776fb8 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../en/installation.md).

# Instalação

O Nextcloud MCP funciona como aplicação nativa do Nextcloud. Utiliza o PHP e o armazenamento do servidor existente; não exige um processo MCP separado ou Node.js.

## Requisitos

| Componente | Requisito |
| --- | --- |
| Nextcloud | **33–35**, conforme o manifesto da aplicação |
| PHP | **8.4 ou posterior**, também suportado pela versão do Nextcloud escolhida |
| Extensões PHP | `zip`, `mbstring` e `dom`, além dos requisitos do próprio Nextcloud |
| Acesso do cliente | Um URL HTTPS acessível pelo cliente MCP escolhido |
| Aplicações opcionais | Ative as aplicações correspondentes às funcionalidades Notes, Deck, Talk e Mail |

As ferramentas dependem também das permissões concedidas ao utilizador e das permissões nativas dos recursos. Mail exige uma versão conhecida de pelo menos 5.0.0 e uma permissão explícita de leitura; este mínimo da API não certifica todas as combinações de Mail/servidor. As ferramentas de dependências do Deck exigem APIs compatíveis no NC34/35. Consulte o [catálogo de ferramentas](reference/tools.md).

As ferramentas para contribuidores também testam PHP 8.2/8.3; esses ambientes de teste não reduzem o requisito de instalação PHP 8.4. A matriz nativa de testes abrange Linux x86-64/Apache com NC33/34/35 e MariaDB 10.11, mais NC35 com PostgreSQL 16 ou SQLite. Um perfil separado LinuxServer/Nginx exercita NC35/PostgreSQL/S3. São perfis de teste, não uma garantia exaustiva de suporte a instalações.

## Escolher o pacote do fork

O fork mantém o identificador **`mcp`** e o manifesto declara **2.0.0**. Substitui uma instalação existente de MCP for Cloud com o mesmo identificador. A Nextcloud App Store distribui o projeto original: **`occ app:install mcp` instala a edição da loja**.

Use `mcp-2.0.0.tar.gz` construído a partir da revisão revista do fork, com o kit de código-fonte correspondente e o resumo criptográfico do manifesto obtido por uma via de confiança separada. Um arquivo de código-fonte do GitHub ou uma cópia do diretório `mcp/` sem preparação não é um pacote de instalação. A cópia completa do repositório contém as instruções de empacotamento em `docs/en/development/packaging.md` e `docs/en/development/source-delivery.md` (em inglês).

## Nova instalação

Numa instância sem `mcp` instalado:

1. Verifique o pacote e o kit de código-fonte correspondente face ao resumo criptográfico de confiança do manifesto.
2. Extraia o único diretório `mcp/` do arquivo para uma raiz de aplicações configurada em `apps_paths` do Nextcloud. Defina o proprietário e o acesso de leitura segundo os papéis de serviço e manutenção da instalação.
3. Como utilizador de manutenção do Nextcloud, execute `php /absolute/path/to/nextcloud/occ app:enable mcp` com o binário PHP escolhido para essa instância. Em contentores, execute o comando equivalente no respetivo ambiente de manutenção.
4. Abra **Administração → Nextcloud MCP**. Ative o serviço, permita que os utilizadores pretendidos se liguem e escolha as permissões de operação.
5. Cada utilizador ativa a sua ligação em **Definições pessoais → Nextcloud MCP** e segue a [configuração de clientes](guides/clients.md).

Use o ponto de acesso apresentado na Visão geral da administração, normalmente:

```text
https://cloud.example.com/apps/mcp/
```

Inclua o subdiretório de instalação do Nextcloud, se existir. O serviço, a elegibilidade do utilizador e a ligação pessoal começam desativados, incluindo para administradores. As escritas começam recusadas; a leitura de Mail e dos registos do servidor também exige permissões explícitas. Consulte [permissões e administração](guides/permissions.md).

As respostas das ferramentas chegam ao cliente de IA ligado. Escolha permissões e restrições de recursos para os dados que esse cliente deve receber; alojar o conector no seu servidor não determina as políticas de tratamento ou retenção do cliente.

## Atualização e recuperação

Antes de atualizar, prepare uma cópia de segurança consistente da aplicação, base de dados e configuração/dados necessários do Nextcloud, seguindo o procedimento do operador. Ensaie a substituição e a recuperação num perfil isolado equivalente e garanta que as atualizações automáticas da App Store não podem substituir o fork.

O modo de manutenção impede tráfego durante a substituição sem alterar a política de serviço MCP nem revogar credenciais apenas para uma atualização de rotina. Substitua todo o diretório da aplicação, execute o `occ upgrade` nativo, limpe/reinicie caches PHP quando necessário e verifique histórico de migrações, recursos da interface, permissões e ligações existentes antes de reabrir o tráfego. Mantenha os metadados de entrega do código-fonte alinhados com o kit instalado.

Proteja a raiz efetiva da aplicação, os seus diretórios ascendentes com acesso de escrita e a configuração que seleciona `apps_paths`. Uma montagem apenas de leitura em `mcp/` não impede um processo de selecionar código de substituição se o diretório pai ou a configuração continuar gravável. Separe o acesso de serviço da manutenção pelo operador quando a sua instalação exigir essa proteção.

Prefira corrigir avançando após uma atualização que falhou. Reverter apenas os ficheiros da aplicação para 1.0.8 é inseguro: esse código não aplica as novas revogações por época/geração nem as restrições de recursos. Uma cópia de segurança da base de dados também pode conter credenciais revogadas depois da sua criação.

Para recuperação coordenada, mantenha Nextcloud em manutenção enquanto restaura estados correspondentes da aplicação e base de dados e a configuração/dados necessários. Antes de reabrir MCP, invalide códigos/tokens OAuth restaurados pelos comandos de política suportados, desligue explicitamente contas que devam voltar a ligar-se e reveja permissões efetivas e restrições de recursos. Reveja/revogue separadamente as palavras-passe de aplicação do Nextcloud em Segurança. Reativar o serviço não substitui essa revisão. Uma versão antiga que não aplique as restrições necessárias não pode ser reaberta para esses utilizadores.

A suite nativa descartável ensaia a restauração de aplicação/SQLite da mesma versão, a recusa de credenciais antigas e a nova ligação explícita. Não certifica as ferramentas de cópia de segurança do operador, uma reversão apenas de ficheiros nem todas as configurações de armazenamento de produção.

[Guias de operação](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · Português (Portugal)
