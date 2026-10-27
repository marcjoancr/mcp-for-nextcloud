<!-- SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech> -->
<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

<div align="center">

# Nextcloud MCP

Independent continuation of MCP for Cloud by Sintax, maintained in this fork.
Not an official Nextcloud product or the upstream App Store package.

**A native Nextcloud app that turns your Nextcloud into an MCP server.**
Let Claude and other MCP clients work with your files, notes, calendars, contacts, tasks, Deck boards, Talk conversations and cached Mail folders, subject to MCP policy, resource restrictions and each user's native Nextcloud permissions.

[![Tests](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml/badge.svg)](https://github.com/marcjoancr/mcp-for-nextcloud/actions/workflows/tests.yml)
[![Nextcloud 33–35](https://img.shields.io/badge/Nextcloud-33--35-0082c9?logo=nextcloud&logoColor=white)](https://nextcloud.com)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777bb4?logo=php&logoColor=white)](https://www.php.net)
[![MCP](https://img.shields.io/badge/MCP-2025--06--18%20%7C%202026--07--28-111)](https://modelcontextprotocol.io)
[![License: AGPL-3.0](https://img.shields.io/badge/License-AGPL--3.0-blue)](#license)


</div>

---

## Why

One endpoint inside Nextcloud, with each user’s permissions. No Node, Docker or extra service is required at runtime.

## Selected upstream integration (development)

This branch adds Notes append/replace, Deck labels and visible file linking/unlinking,
conditional NC34/35 Deck dependencies, precise Deck deadlines and an optional
filename allowlist, alongside privacy and compatibility fixes. See the
[tools](docs/en/reference/tools.md) and
[configuration](docs/en/reference/configuration.md) guides for limits and defaults.
The independent **Nextcloud MCP 2.0.0** development candidate retains app ID `mcp`;
release and host qualification and human reviews remain pending. Native file linking
requires explicit approval of its current and future audience and retains native
sharing limitations. The intended LinuxServer/Nginx upgrade and recovery remain
pending; disposable Apache results do not certify every client/platform combination.

## What's new in 1.1.2

Guests restrictions now apply to app-password and OAuth requests and to checkout links: allowed applications, hidden external storage and group-only sharing. Accounts that are not guests are unaffected; no database migration is added. See [release details](CHANGELOG.md#112).


## What's new in 1.1.1

Permission screens treat unreadable save replies as unconfirmed and reload stored permissions without repeating the write. No database migration is added. See [release details](CHANGELOG.md#111).

## What's new in 1.1.0

Group grants, per-user resource restrictions, access inspection, registered OAuth clients and stronger revocation. See the [changelog](CHANGELOG.md) and [permissions guide](docs/en/guides/permissions.md).

## Features

[Features](docs/en/reference/tools.md).

## Contacts and tasks

[Contacts and tasks](docs/en/reference/tools/contacts-tasks.md).

## Requirements

[Requirements](docs/en/installation.md).

## Installation

This independent fork is a development candidate, not the App Store release. Read the [installation requirements](docs/en/installation.md) and pending release gates before using a built artifact.

[Installation](docs/en/installation.md).

## Connecting a client

[Connecting a client](docs/en/guides/clients.md).

### Claude (claude.ai, Claude Desktop, Cowork and Claude Code — recommended)

[Claude (claude.ai, Claude Desktop, Cowork and Claude Code — recommended)](docs/en/guides/clients.md).

### ChatGPT

[ChatGPT](docs/en/guides/clients.md).

### Gemini CLI

[Gemini CLI](docs/en/guides/clients.md).

### Administrators: OAuth clients

[Administrators: OAuth clients](docs/en/guides/clients.md).

### Other MCP clients (app password)

[Other MCP clients (app password)](docs/en/guides/clients.md).

### Revoking access

[Revoking access](docs/en/guides/clients.md).

## Security

[Security](docs/en/guides/permissions.md).

### Excluded from MCP access

[Excluded from MCP access](docs/en/guides/permissions.md).

## Development

[Development](docs/en/development/setup.md).

## Selected 1.2.1 capabilities

Configurable [backup policy](docs/en/reference/configuration.md#mcp-backup-copies),
[Tasks hierarchy](docs/en/reference/tools/tasks.md) and optional
[Mail account/folder reads](docs/en/reference/tools/mail.md) are integrated into
the capability architecture. Mail requires an explicit read grant. See the
[reconciliation and acceptance limits](docs/en/development/upstream-1.2.1-u5-integration.md);
2.0.0 remains a development candidate.

## Changelog

[Changelog](CHANGELOG.md).

## Roadmap

- Native review of the Spanish translation

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). The complete license is in [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt). Preserve the Sintax attribution in the administration page, documentation and copyright notices when redistributing or making modified versions available over a network.

## Credits

The package includes Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader. See versions, licenses, copyright and development dependencies in [THIRD-PARTY-NOTICES.md](mcp/THIRD-PARTY-NOTICES.md).

**Contributors.** Thanks to @NightDragon1 (#5) for the root visibility check and to @matt-ek (#4) for the report behind the hidden-tags audit. Thanks also to @matt-ek for the German UI translation (#10) and @Zegorax for administrator-registered OAuth clients (#8, #7), and @mturgeonferland for the original Tasks
subtask contribution ([#18](https://github.com/sintax-tech/mcp-for-nextcloud/pull/18)).

The upstream documentation also credits Sintax/Jhonatan Jaworski's contribution
to [XERJ Corpus Hub](https://hub.xerj.org/contributors/jhowjaw). This historical
credit is retained without implying that XERJ reviewed this fork.

## About Sintax

Developed by [Sintax](https://sintax.tech) · sintax.tech.

- Website: https://sintax.tech
- GitHub: https://github.com/sintax-tech
- Contact: contato@sintax.tech
- Other Nextcloud apps by Sintax: [MCP for Cloud](https://apps.nextcloud.com/apps/mcp) · [Audiolog](https://apps.nextcloud.com/apps/audiolog)

English · [Español](README.es.md) · [Català](README.ca.md) · [Português (Brasil)](README.pt-BR.md) · [Português (Portugal)](README.pt-PT.md)
