<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Development guide

Start with [setup](setup.md), then run the commands in [testing](testing.md).
Code, identifiers, comments and technical source documentation use English;
user-facing messages use Nextcloud localization.

| Task | Reference |
| --- | --- |
| Understand architecture | [Architecture](architecture-specification.md) |
| Add or change an MCP capability | [Adding a tool](adding-a-tool.md) |
| Change administration or personal settings | [Administration JavaScript](admin-javascript.md) |
| Understand analyzer scope and host doubles | [Static analysis](static-analysis.md) |
| Build the installable artifact and source kit | [Packaging](packaging.md), [source delivery and integrity](source-delivery.md), [delivery controls](fork-f3-delivery.md) |
| Edit guides or translations | [Documentation](documentation.md), [translations](translations.md) |
| Change filename write enforcement | [Filename write policy sink inventory](file-write-policy-sinks.md) |
| Change file visibility rules | [Exclusion and folder exposure contract](contracts/exclusion-and-folder-exposure.md) |
| Review upstream source history | [1.2.0 inventory](upstream-1.2.0-inventory.md), [1.2.0 source ledger](upstream-1.2.0-source-ledger.md), [1.2.1 source ledger](upstream-1.2.1-source-ledger.md) |

For current contribution and branch policy, see [Contributing](../../../CONTRIBUTING.md).
Use [operational guides](../guides/README.md) for installation and administration.
Keep test fixtures with their tests and preserve their attribution and source
history. Record candidate-specific validation and review requests in the pull
request; maintained guides describe reproducible workflows.
