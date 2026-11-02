<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Nextcloud MCP

**English** · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

Connect an MCP client to your own Nextcloud. Search files, read notes, follow Talk conversations or manage tasks through one endpoint. Each request runs with the permissions of the signed-in user.

Nextcloud MCP is an independently maintained fork of [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) by Sintax (Jhonatan Jaworski). Development takes place on [`main`](https://github.com/marcjoancr/mcp-for-nextcloud/tree/main).

**Version 2.3.2** retains the app ID `mcp`. The Nextcloud App Store provides the original project. Review the installation guide before replacing an existing instance.

## Start here

Server URL · `https://cloud.example.com/apps/mcp/`

- [Install the fork](../docs/en/installation.md)
- [Connect a client](../docs/en/guides/clients.md)
- [Explore the tools](../docs/en/reference/tools.md)
- [Configure permissions](../docs/en/guides/permissions.md)

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). See [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt) for the complete license. Preserve the attribution in the administration page, documentation and copyright notices when redistributing or providing modified versions over a network.

## Credits

Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader are bundled. See [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) for versions, copyright, licenses and separate development credits.

**Contributors.** Thanks to @NightDragon1 ([#5](https://github.com/sintax-tech/mcp-for-nextcloud/issues/5)) for the root visibility check and to @matt-ek ([#4](https://github.com/sintax-tech/mcp-for-nextcloud/issues/4)) for the report behind the hidden-tags audit. Thanks also to @matt-ek for the German UI translation ([#10](https://github.com/sintax-tech/mcp-for-nextcloud/issues/10)) and @Zegorax for administrator-registered OAuth clients ([#8](https://github.com/sintax-tech/mcp-for-nextcloud/issues/8), [#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7)).

The upstream documentation also credits Sintax/Jhonatan Jaworski's contribution
to [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). This historical
credit is retained without implying that XERJ reviewed this fork.


Tasks hierarchy: [@mturgeonferland](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18).

Developed by Sintax — https://sintax.tech
