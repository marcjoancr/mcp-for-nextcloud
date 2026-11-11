<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: 5beb2248a554cea171e7420ce93034571be15fec -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução aguarda revisão humana. Consulte [English](../en/installation.md).

# Instalação

O Nextcloud MCP funciona como um aplicativo nativo do Nextcloud. Usa o PHP e o armazenamento do servidor existente; não precisa de um daemon MCP separado nem de um runtime Node.js.

## Requisitos

| Componente | Requisito |
| --- | --- |
| Nextcloud | **33–35**, conforme o manifesto do aplicativo |
| PHP | **8.4 ou superior**, também compatível com a versão escolhida do Nextcloud |
| Extensões PHP | `zip`, `mbstring` e `dom`, além dos requisitos do próprio Nextcloud |
| Acesso do cliente | Uma URL HTTPS acessível ao cliente MCP escolhido |
| Aplicativos opcionais | Habilite os aplicativos correspondentes para Notes, Tasks, Calendar, Contacts, Deck, Talk, Mail e Forms |

As ferramentas também dependem das atribuições individuais, da seleção da conexão e das permissões nativas sobre recursos. Mail exige uma versão conhecida de pelo menos 5.0.0; Forms exige versão conhecida de pelo menos 5.3.0. Suas ferramentas exigem atribuições e seleção explícitas; esses mínimos de API não certificam todas as combinações de aplicativo/servidor. As ferramentas de dependência do Deck exigem APIs compatíveis no NC34/35. Consulte o [catálogo de ferramentas](reference/tools.md).

As ferramentas de desenvolvimento também testam PHP 8.2/8.3; esses ambientes de teste não reduzem o requisito PHP 8.4 para instalação. A matriz de testes nativos cobre Linux x86-64/Apache com NC33/34/35 e MariaDB 10.11, mais NC35 com PostgreSQL 16 ou SQLite. Um ambiente separado LinuxServer/Nginx exercita NC35/PostgreSQL/S3. São perfis de teste, não uma garantia exaustiva de suporte a implantações.

## Escolha o pacote do fork

O fork mantém o identificador **`mcp`** e o manifesto declara **2.7.0**. Substitui uma instalação existente do MCP for Cloud com esse mesmo identificador. A loja do Nextcloud distribui o projeto original: **`occ app:install mcp` instala a edição da loja**.

Use `mcp-2.7.0.tar.gz` gerado a partir da revisão verificada do fork, com o kit de código-fonte correspondente e um hash de manifesto confiável obtido separadamente. Um arquivo de código-fonte do GitHub ou uma cópia do diretório `mcp/` sem compilar não é um pacote de instalação. O checkout completo contém as instruções de empacotamento, em inglês, em `docs/en/development/packaging.md` e `docs/en/development/source-delivery.md`.

## Nova instalação

Para uma instância sem `mcp` instalado:

1. Verifique o pacote e o kit de código-fonte correspondente com o hash confiável do manifesto.
2. Extraia o único diretório `mcp/` do arquivo para uma raiz de aplicativos configurada em `apps_paths` do Nextcloud. Defina propriedade e acesso de leitura conforme os papéis de execução e manutenção da implantação.
3. Como usuário de manutenção do Nextcloud, execute `php /absolute/path/to/nextcloud/occ app:enable mcp` usando o binário PHP selecionado para essa instância. Em contêineres, execute o comando equivalente no ambiente de manutenção.
4. Abra **Administração → Nextcloud MCP**. Ative o serviço, permita a conexão dos usuários pretendidos e atribua as ferramentas individuais que eles podem usar.
5. Cada usuário ativa sua conexão em **Configurações pessoais → Nextcloud MCP**, seleciona as ferramentas para o cliente e segue a [configuração de clientes](guides/clients.md).

Use o endpoint mostrado na Visão geral da administração, normalmente:

```text
https://cloud.example.com/apps/mcp/
```

Inclua o subdiretório da instalação do Nextcloud, se houver. Serviço, elegibilidade do usuário e conexão pessoal começam desativados, inclusive para administradores. Escritas começam negadas; o acesso a Mail, Forms e logs também exige permissões explícitas. Ferramentas novas começam sem atribuição e sem seleção. Consulte [permissões e administração](guides/permissions.md).

As respostas das ferramentas chegam ao cliente de IA conectado. Escolha permissões e restrições de recursos de acordo com os dados que esse cliente deve receber; hospedar o conector não determina a política de processamento ou retenção do próprio cliente.

## Atualização e recuperação

Antes de atualizar, prepare um backup consistente do aplicativo, banco de dados e configuração/dados necessários do Nextcloud, seguindo o procedimento do operador. Ensaie substituição e recuperação em um perfil isolado equivalente e garanta que atualizações automáticas da loja não substituam o fork.

O modo de manutenção impede tráfego durante a substituição sem alterar a política do serviço MCP nem revogar credenciais apenas para uma atualização rotineira. Substitua todo o diretório do aplicativo, execute o `occ upgrade` nativo, limpe/reinicie caches PHP quando necessário e verifique histórico de migrações, recursos da interface, permissões e conexões existentes antes de reabrir o tráfego. Mantenha os metadados de entrega do código-fonte alinhados com o kit instalado.

Proteja a raiz efetiva do aplicativo, seus diretórios ancestrais graváveis e a configuração que seleciona `apps_paths`. Montar apenas `mcp/` como somente leitura não impede um processo de selecionar código substituto quando o diretório pai ou a configuração continuam graváveis. Separe o acesso de execução da manutenção pelo operador quando a implantação exigir essa proteção.

Prefira corrigir avançando após uma atualização que falhou. Reverter apenas os arquivos do aplicativo para 1.0.8 é inseguro: esse código não aplica as novas revogações por época/geração nem as restrições de recursos. Um backup do banco de dados também pode conter credenciais revogadas depois de sua criação.

Para recuperação coordenada, mantenha o Nextcloud em manutenção enquanto restaura estados correspondentes do aplicativo e banco de dados e a configuração/dados necessários. Antes de reabrir MCP, invalide códigos/tokens OAuth restaurados pelos comandos de política suportados, desconecte explicitamente contas que devam se reconectar e revise permissões efetivas e restrições de recursos. Revise/revogue separadamente as senhas de aplicativo do Nextcloud em Segurança. Reabilitar o serviço não substitui essa revisão. Uma versão antiga que não aplique as restrições necessárias não pode ser reaberta para esses usuários.

A suíte nativa descartável ensaia a restauração de aplicativo/SQLite da mesma versão, a recusa de credenciais antigas e a reconexão explícita. Não certifica as ferramentas de backup do operador, uma reversão apenas de arquivos nem todas as configurações de armazenamento de produção.

[Guias de operação](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · [Català](../ca/installation.md) · Português (Brasil) · [Português (Portugal)](../pt-PT/installation.md)
