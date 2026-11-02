<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<!-- translation-source: README.md -->
<!-- translation-source-revision: b93ec95263f3db1d7b208b7cb3c17cef2e1f3f20 -->
<!-- translation-review: pending -->

> **Revisão humana pendente:** esta tradução ainda precisa de revisão humana. Consulte [English](README.md).

# Nextcloud MCP

[English](README.md) · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · **Português (Portugal)**

[![CI](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml?query=branch%3Amain) · **Nextcloud 33–35** · **PHP 8.4+** · [AGPL-3.0-or-later](LICENSE)

Ligue um cliente MCP ao seu Nextcloud. Pesquise ficheiros, leia notas, acompanhe conversas no Talk ou faça a gestão de tarefas através de um único ponto de acesso. Cada pedido respeita as permissões de quem iniciou sessão.

**Experimente:** no ChatGPT ou no Claude, pergunte «Que conversas do Talk têm mensagens por ler?». O guia mostra as ferramentas que a sua conta pode utilizar. O administrador pode limitar as ferramentas e os recursos a que estas têm acesso.

O Nextcloud MCP é um fork independente de [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud), de Sintax (Jhonatan Jaworski). O desenvolvimento decorre em [`main`](https://github.com/marcjoancr/mcp-for-nextcloud/tree/main).

A **versão 2.2.0** mantém o identificador `mcp`. A loja de aplicações do Nextcloud distribui o projeto original. Consulte o guia de instalação antes de substituir uma instalação existente.

## Primeiros passos

URL do servidor · `https://cloud.example.com/apps/mcp/`

- [Instalar o fork](docs/pt-PT/installation.md)
- [Ligar um cliente](docs/pt-PT/guides/clients.md)
- [Explorar as ferramentas](docs/pt-PT/reference/tools.md)
- [Configurar as permissões](docs/pt-PT/guides/permissions.md)

[Documentação](docs/pt-PT/README.md) · [Como contribuir](CONTRIBUTING.md) · [Registo de alterações](CHANGELOG.md)

## Desenvolvimento

Crie um ramo a partir de `main` e envie o pedido de integração para este fork. A [documentação de arquitetura (em inglês)](docs/en/development/architecture-specification.md) explica como se organizam as ferramentas de Files, Deck, Talk e dos restantes módulos e como partilham o transporte MCP.

## Licença

AGPL-3.0-or-later, com a cláusula adicional de atribuição da secção 7(b) em [NOTICE](NOTICE). A licença completa encontra-se em [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve a atribuição à Sintax na página de administração, na documentação e nos avisos de direitos de autor ao redistribuir ou disponibilizar versões modificadas numa rede.

## Créditos

O pacote inclui Smalot PdfParser, Symfony Polyfill Mbstring e o autoloader do Composer. Consulte as versões, licenças, direitos de autor e dependências de desenvolvimento em [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contribuidores.** Agradecemos a @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) pela verificação da visibilidade da raiz e a @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) pelo relatório que esteve na origem da auditoria de etiquetas ocultas. Agradecemos também a @matt-ek pela tradução alemã da interface ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) e a @Zegorax pelos clientes OAuth registados por administradores ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).


Hierarquia de tarefas: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

Sintax / Jhonatan Jaworski · [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw).

Developed by Sintax — https://sintax.tech
