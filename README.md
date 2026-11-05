<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Nextcloud MCP

**English** · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

[![CI](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg?branch=main)](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml?query=branch%3Amain) · **Nextcloud 33–35** · **PHP 8.4+** · [AGPL-3.0-or-later](LICENSE)

Connect an MCP client to your own Nextcloud. Search files, read notes, follow Talk conversations or manage tasks through one endpoint. Each request runs with the permissions of the signed-in user.

**Try it:** In ChatGPT or Claude, ask “Which Talk conversations have unread messages?” The tool guide shows what your account can use. An administrator can limit both the available tools and the resources they can access.

Nextcloud MCP is an independently maintained fork of [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) by Sintax (Jhonatan Jaworski). Development takes place on [`main`](https://github.com/marcjoancr/mcp-for-nextcloud/tree/main).

**Version 2.6.1** retains the app ID `mcp`. The Nextcloud App Store provides the original project. Review the installation guide before replacing an existing instance.

## Start here

Server URL · `https://cloud.example.com/apps/mcp/`

- [Install the fork](docs/en/installation.md)
- [Connect a client](docs/en/guides/clients.md)
- [Explore the tools](docs/en/reference/tools.md)
- [Configure permissions](docs/en/guides/permissions.md)

[Documentation](docs/en/README.md) · [Contributing](CONTRIBUTING.md) · [Changelog](CHANGELOG.md)

## Development

Start a topic branch from `main` and send your pull request to this fork. The [architecture guide](docs/en/development/architecture-specification.md) explains how Files, Deck, Talk and the other modules own their tools and share the MCP transport.

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). The complete license is in [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve the Sintax attribution in the administration page, documentation and copyright notices when redistributing or making modified versions available over a network.

## Credits

The package includes Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader. See versions, licenses, copyright and development dependencies in [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contributors.** Thanks to @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) for the root visibility check and to @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) for the report behind the hidden-tags audit. Thanks also to @matt-ek for the German UI translation ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) and @Zegorax for administrator-registered OAuth clients ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)), and @mturgeonferland for the original Tasks
subtask contribution ([#18](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18)).

The upstream documentation also credits Sintax/Jhonatan Jaworski's contribution
to [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). This historical
credit is retained without implying that XERJ reviewed this fork.

Developed by Sintax — https://sintax.tech
