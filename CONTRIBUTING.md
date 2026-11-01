<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Start with the [development setup](docs/en/development/setup.md), then use the
[testing guide](docs/en/development/testing.md) for focused checks and full verification.
The [architecture specification](docs/en/development/architecture-specification.md)
and [tool-authoring guide](docs/en/development/adding-a-tool.md) explain ownership,
registration and the contracts that changes must preserve.

## Fork development workflow

Use `main` as the base for topic branches. Open pull requests against
`marcjoancr/mcp-for-nextcloud:main`. The fork maintains its own architecture
and release schedule. Keep upstream attribution and review relevant upstream
changes by URL before selecting them for the fork.

When using the GitHub CLI, run `gh repo set-default --view` to confirm the
destination. See the [development baseline record](docs/en/development/fork-development-baseline.md)
for the history of the default branch.

Keep publication, default-branch changes and deployment explicitly authorized.
The [fork continuation plan](docs/en/development/fork-continuation-plan.md) owns
baseline references, release proposals, update safeguards and pending decisions.
The Nextcloud MCP identity, 2.0.0 target and proposed platform scope are confirmed.
F3 qualification and human reviews remain pending; publishing a development branch
does not certify a release or qualify a production server.

## Contribution requirements

Write code, identifiers, comments, PHPDoc and canonical documentation in English.
Preserve localized output and meaningful multilingual fixtures. Update English
guides with the change; reviewed translations can follow independently. Link only
to translations that exist and label English fallbacks explicitly.

Run `composer --working-dir=mcp check` before submitting a change. This includes
syntax, both static-analysis scopes, all tests and a verified production package.
REUSE compliance and the supported PHP matrix also run in CI. Keep dependency
updates deliberate and preserve original attribution. Do not weaken a failing
check or regenerate compatibility captures merely to make a refactor pass.

New tooling requires no devbox installation. Environment-specific task catalogs
may delegate to these Composer commands; keep their metadata outside the repository.

See [documentation authoring](docs/en/development/documentation.md) and
[translation maintenance](docs/en/development/translations.md) for documentation contributions.
