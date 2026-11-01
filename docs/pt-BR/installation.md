<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: docs/en/installation.md -->
<!-- translation-source-revision: 1c9c678262c1113dbadc1839d428a58193851301 -->
<!-- translation-review: pending -->

> **Revisão pendente:** conteúdo preservado da documentação existente; a tradução e as diferenças em relação à reorganização precisam de revisão humana. Consulte [English](../en/installation.md).

# Instalação

## Requisitos

O Nextcloud MCP 2.0.0 usa o identificador `mcp` e substitui uma instalação existente do MCP for Cloud. A loja do Nextcloud distribui o aplicativo original. Para instalar o fork, use o pacote gerado a partir da revisão verificada do código-fonte.

O ambiente de referência é Linux x86-64 com Apache. Use versões que recebam atualizações de segurança e as extensões PHP `zip`, `mbstring` e `dom`. O SQLite é usado em testes locais e ensaios de recuperação.

| Nextcloud | PHP | Banco de dados |
| --- | --- | --- |
| 35 | 8.5 | MariaDB 10.11 ou PostgreSQL 16 |
| 34 | 8.5 | MariaDB 10.11 |
| 33 | 8.4 | MariaDB 10.11 |

As ferramentas disponíveis dependem dos aplicativos do Nextcloud e das permissões da conta. As operações de dependência do Deck exigem APIs compatíveis no NC34/35. Consulte o [catálogo de ferramentas](reference/tools.md).

### Instalar via SSH

Verifique `mcp-2.0.0.tar.gz` e o kit de código-fonte correspondente com o hash de confiança do manifesto. Teste a atualização e a recuperação em um ambiente equivalente ao do servidor e confirme que as atualizações automáticas preservam o fork. O comando `occ app:install mcp` instala o pacote original da loja.

O MinicoLab atualizou da versão 1.2.1 para a 2.0.0 no Nextcloud 35.0.2 com LinuxServer/Nginx e PostgreSQL. Foram verificados o acesso por HTTPS público, a autorização e revogação com o MCP Inspector e uma consulta ao guia de ferramentas pelo ChatGPT. As revisões humanas continuam pendentes.

## Atualização e recuperação

Antes de atualizar, prepare um backup consistente do aplicativo, banco de dados e configuração/dados necessários do Nextcloud, seguindo o procedimento do operador. O modo de manutenção impede tráfego durante a substituição sem alterar a política de serviço MCP nem revogar credenciais apenas para uma atualização rotineira. Substitua todo o diretório do aplicativo, execute a atualização nativa necessária, limpe/reinicie caches PHP quando necessário e verifique histórico de migrações, recursos da interface, permissões e conexões existentes antes de reabrir o tráfego.

Prefira corrigir avançando após uma atualização que falhou. Reverter apenas os arquivos do aplicativo para 1.0.8 é inseguro: esse código não aplica as novas revogações por época/geração nem as restrições de recursos. Um backup do banco de dados também pode conter credenciais revogadas depois de sua criação.

Para recuperação coordenada, mantenha Nextcloud em manutenção enquanto restaura estados correspondentes do aplicativo e banco de dados e a configuração/dados necessários. Antes de reabrir MCP, invalide códigos/tokens OAuth restaurados pelos comandos de política suportados, desconecte explicitamente contas que devam se reconectar e revise permissões efetivas e restrições de recursos. Revise/revogue separadamente as senhas de aplicativo do Nextcloud em Segurança. Reabilitar o serviço não substitui essa revisão. Uma versão antiga que não aplique as restrições necessárias não pode ser reaberta para esses usuários.

A suíte nativa descartável ensaia a restauração de aplicativo/SQLite da mesma versão, a recusa de credenciais antigas e a reconexão explícita. Não certifica as ferramentas de backup do operador, uma reversão apenas de arquivos nem todas as configurações de armazenamento de produção.

[Guias de operação](guides/README.md).

[English](../en/installation.md) · [Español](../es/installation.md) · [Català](../ca/installation.md) · Português (Brasil) · [Português (Portugal)](../pt-PT/installation.md)
