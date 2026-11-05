<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<!-- translation-source: README.md -->
<!-- translation-source-revision: b93ec95263f3db1d7b208b7cb3c17cef2e1f3f20 -->
<!-- translation-review: pending -->

> **Revisão pendente:** esta tradução aguarda revisão humana. Consulte [English](README.md).

# Nextcloud MCP

[English](README.md) · [Español](README.es.md) · [Català](README.ca.md) · **Português (Brasil)** · [Português (Portugal)](README.pt-PT.md)

[![CI](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml?query=branch%3Amain) · **Nextcloud 33–35** · **PHP 8.4+** · [AGPL-3.0-or-later](LICENSE)

Conecte um cliente MCP ao seu Nextcloud. Busque arquivos, leia notas, acompanhe conversas no Talk ou gerencie tarefas por um único ponto de acesso. Cada solicitação respeita as permissões de quem iniciou a sessão.

**Experimente:** no ChatGPT ou no Claude, pergunte “Quais conversas do Talk têm mensagens não lidas?”. O guia mostra quais ferramentas sua conta pode usar. O administrador pode limitar tanto as ferramentas quanto os recursos que elas podem acessar.

O Nextcloud MCP é um fork independente de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud), de Sintax (Jhonatan Jaworski). O desenvolvimento acontece em [`main`](https://github.com/marcjoancr/mcp-for-nextcloud/tree/main).

A **versão 2.6.2** mantém o identificador `mcp`. A loja de aplicativos do Nextcloud distribui o projeto original. Consulte o guia de instalação antes de substituir uma instalação existente.

## Primeiros passos

URL do servidor · `https://cloud.example.com/apps/mcp/`

- [Instalar o fork](docs/pt-BR/installation.md)
- [Conectar um cliente](docs/pt-BR/guides/clients.md)
- [Explorar as ferramentas](docs/pt-BR/reference/tools.md)
- [Configurar as permissões](docs/pt-BR/guides/permissions.md)

[Documentação](docs/pt-BR/README.md) · [Como contribuir](CONTRIBUTING.md) · [Histórico de versões](CHANGELOG.md)

## Desenvolvimento

Crie uma branch a partir de `main` e envie o pull request para este fork. A [documentação de arquitetura (em inglês)](docs/en/development/architecture-specification.md) explica como as ferramentas de Files, Deck, Talk e dos demais módulos se organizam e compartilham o transporte MCP.

## Licença

AGPL-3.0-or-later, com o termo adicional de atribuição da seção 7(b) no [NOTICE](NOTICE). O texto integral da licença está em [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve o crédito à Sintax na administração, na documentação e nos avisos de autoria ao redistribuir ou oferecer versões modificadas pela rede.

## Créditos

O pacote inclui Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte versões, licenças, autoria e dependências de desenvolvimento em [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contribuidores.** Agradecimento a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) pela checagem de visibilidade da raiz e a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) pelo relato que motivou a auditoria da ocultação por tags. Agradecemos também a @matt-ek pela tradução alemã da interface ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) e a @Zegorax pelos clientes OAuth registrados por administradores ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Hierarquia de tarefas: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

Sintax / Jhonatan Jaworski · [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw).

Developed by Sintax — https://sintax.tech
