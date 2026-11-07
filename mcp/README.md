<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Nextcloud MCP

**Your Nextcloud, in the conversation.** Search files, work with notes, calendars, contacts and tasks, manage Deck cards and catch up on Talk from an MCP client. Mail reads owned messages and attachments and manages local unsent drafts without sending; Forms reads permitted results and exports bounded CSV pages. Authorized operators can inspect server logs.

**English** · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)

## Get started

Requires **Nextcloud 33–35** and **PHP 8.4+**. This independently maintained fork of [MCP for Cloud](https://github.com/sintax-tech/mcp-for-nextcloud) declares version **2.7.0** and retains app ID `mcp`. The Nextcloud App Store edition is the original project; use this fork's reviewed package for installation or replacement.

1. [Install the application](../docs/en/installation.md).
2. [Enable the service, user eligibility and personal connection](../docs/en/guides/permissions.md#enable-access).
3. [Connect a client](../docs/en/guides/clients.md) to `https://cloud.example.com/apps/mcp/` using OAuth or an app password, and select its permitted tools.
4. Ask for the **Tool guide**, then try “Which Talk conversations have unread messages?” or “Find the project proposal and summarize it.” These are example requests, not recorded transcripts.

## Access stays under your control

Up to **112 tools** across eleven modules, including guide/status tools, are filtered by available apps, tool assignments and connection selections. Native Nextcloud permissions apply on every request. Administrators can narrow access to selected folders, calendars and task lists and exclude tagged files. Write tools return a preview unless explicitly confirmed; the assistant must obtain the user's approval. There is no file deletion tool. Mail, Forms and log tools require explicit grants. Mail drafts, attachment saving and Forms exports require confirmation; logs remain read-only and Mail never sends messages.

Tool results are sent to the chosen AI client and are subject to its processing and retention policies.

[Tools](../docs/en/reference/tools.md) · [Permissions](../docs/en/guides/permissions.md) · [File protection](../docs/en/guides/file-protection.md) · [Troubleshooting](../docs/en/guides/troubleshooting.md)

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
