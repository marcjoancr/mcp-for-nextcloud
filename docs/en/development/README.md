<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Development guide

Nextcloud MCP is a native Nextcloud app maintained on `main` in [this fork](https://github.com/marcjoancr/mcp-for-nextcloud). Target this repository for contributions. Code, identifiers, comments and canonical technical documentation use English; user-facing messages use Nextcloud localization.

## Start here

1. Follow [development setup](setup.md) to install the locked toolchain and dependencies.
2. Read the [architecture](architecture-specification.md) for module ownership and compatibility boundaries.
3. Use [testing and verification](testing.md) for focused checks, then the complete `composer --working-dir=mcp check` gate.

| Task | Reference |
| --- | --- |
| Add or change an MCP capability | [Adding a tool](adding-a-tool.md) |
| Change administration or personal settings | [Administration JavaScript](admin-javascript.md) |
| Understand static analysis and host doubles | [Static analysis](static-analysis.md) |
| Build the installation archive | [Packaging](packaging.md) |
| Deliver matching source and verify an installed tree | [Source delivery and integrity](source-delivery.md) |
| Rehearse LinuxServer upgrade, recovery and update controls | [LinuxServer testing](linuxserver-testing.md) |
| Change filename write enforcement | [Write-policy sink inventory](file-write-policy-sinks.md) |
| Change file visibility or folder exposure | [Exclusion and folder exposure contract](contracts/exclusion-and-folder-exposure.md) |
| Edit guides or translations | [Documentation authoring](documentation.md), [translation maintenance](translations.md) |

## Documentation scope

Maintained guides describe current behavior and reproducible procedures. Historical integration plans, candidate ledgers and deployment campaigns remain available in Git history: use `git log --all -- docs/en/development/` and `git show COMMIT:PATH` to inspect an earlier record. Keep new run-specific evidence, operator handoffs and review verdicts in their working records or pull request.

The manifest, tool catalogs, dependency locks and executable fixture profiles own current versions and supported inputs. Tests against a frozen kit qualify that kit and profile; they do not certify a later checkout, every Nextcloud installation or an external AI client. Record exact source/artifact hashes and actual check results with each proposed release.

For installation and administration, use the [operational guides](../guides/README.md).
