<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: b93ec95263f3db1d7b208b7cb3c17cef2e1f3f20 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](../en/installation.md).

# Instalação

## Requisitos

O Nextcloud MCP 2.6.3 usa o identificador `mcp` e substitui uma instalação existente do MCP for Cloud. A loja do Nextcloud distribui a aplicação original. Para instalar o fork, utilize o pacote compilado a partir da revisão verificada do código-fonte.

O ambiente de referência é Linux x86-64 com Apache. Utilize versões que recebam atualizações de segurança e as extensões PHP `zip`, `mbstring` e `dom`. O SQLite é utilizado em testes locais e ensaios de recuperação.

| Nextcloud | PHP | Base de dados |
| --- | --- | --- |
| 35 | 8.5 | MariaDB 10.11 ou PostgreSQL 16 |
| 34 | 8.5 | MariaDB 10.11 |
| 33 | 8.4 | MariaDB 10.11 |

As ferramentas disponíveis dependem das aplicações do Nextcloud e das permissões da conta. As operações de dependência do Deck exigem APIs compatíveis no NC34/35. Consulte o [catálogo de ferramentas](reference/tools.md).

### Instalar através de SSH

Verifique `mcp-2.6.3.tar.gz` e o kit de código-fonte correspondente com o hash de confiança do manifesto. Ensaie a atualização e a recuperação num ambiente equivalente ao do servidor e confirme que as atualizações automáticas preservam o fork. O comando `occ app:install mcp` instala o pacote original da loja.

Antes da instalação, utilize o MCP Inspector para verificar o acesso HTTPS, a autorização e a revogação do cliente. Depois, confirme que um cliente consegue consultar o guia de ferramentas numa instância de teste descartável.

## Atualização e recuperação

Antes de atualizar, prepare uma cópia de segurança consistente da aplicação, base de dados e configuração/dados necessários do Nextcloud, seguindo o procedimento do operador. O modo de manutenção impede tráfego durante a substituição sem alterar a política de serviço MCP nem revogar credenciais apenas para uma atualização de rotina. Substitua todo o diretório da aplicação, execute a atualização nativa necessária, limpe/reinicie caches PHP quando necessário e verifique histórico de migrações, recursos da interface, permissões e ligações existentes antes de reabrir o tráfego.

Prefira corrigir avançando após uma atualização que falhou. Reverter apenas os ficheiros da aplicação para 1.0.8 é inseguro: esse código não aplica as novas revogações por época/geração nem as restrições de recursos. Uma cópia de segurança da base de dados também pode conter credenciais revogadas depois de sua criação.

Para recuperação coordenada, mantenha Nextcloud em manutenção enquanto restaura estados correspondentes da aplicação e base de dados e a configuração/dados necessários. Antes de reabrir MCP, invalide códigos/tokens OAuth restaurados pelos comandos de política suportados, desligue explicitamente contas que devam voltar a ligar-se e revise permissões efetivas e restrições de recursos. Revise/revogue separadamente as palavras-passe de aplicação do Nextcloud em Segurança. Reativar o serviço não substitui essa revisão. Uma versão antiga que não aplique as restrições necessárias não pode ser reaberta para esses utilizadores.

A suite nativa descartável ensaia a restauração de aplicação/SQLite da mesma versão, a recusa de credenciais antigas e a nova ligação explícita. Não certifica as ferramentas de cópia de segurança do operador, uma reversão apenas de ficheiros nem todas as configurações de armazenamento de produção.

[Guias de operação](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · [Català](../ca/installation.md) · [Português (Brasil)](../pt-BR/installation.md) · Português (Portugal)
