<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Start with [development setup](docs/en/development/setup.md), then use [testing and verification](docs/en/development/testing.md) for focused checks and the full gate. The [architecture](docs/en/development/architecture-specification.md) and [tool-authoring guide](docs/en/development/adding-a-tool.md) explain module ownership and compatibility contracts.

## Development workflow

Base topic branches on `main`. Open pull requests against `marcjoancr/mcp-for-nextcloud:main`. This fork maintains its own architecture and release schedule. Review upstream changes selectively and preserve attribution and source provenance.

When using the GitHub CLI, run `gh repo set-default --view` to confirm the destination. Keep publication, default-branch changes and deployment explicitly authorized. A passing development check does not qualify a production deployment; release evidence must identify the exact source, package and tested environments.

## Contribution requirements

- Write code, identifiers, comments, PHPDoc and canonical documentation in English. Preserve localized output and meaningful multilingual fixtures.
- Update the maintained English guide when behavior changes. Translations may follow independently with visible review notices and real source provenance.
- Run `composer --working-dir=mcp check` before submitting a change. It validates the manifest, documentation, syntax, both analysis scopes, PHP/browser tests and production packaging. REUSE and the PHP version matrix run separately in CI.
- Preserve existing compatibility captures and original credits. Do not weaken a failing check or regenerate fixtures merely to make a refactor pass.
- Keep credentials, generated output, environment profiles and development-server metadata outside committed source. New tooling must work without a devbox installation.

## Documentation contributions

Root READMEs introduce the product; app READMEs introduce the installed package. Operational guides own user procedures, references own contracts and development guides own contributor workflows. Avoid duplicating those procedures across entry points.

Keep campaign plans, raw execution evidence and host-specific handoffs in external working records or pull requests. Git history preserves retired integration and deployment records. See [documentation authoring](docs/en/development/documentation.md) and [translation maintenance](docs/en/development/translations.md) for checks and provenance rules.
