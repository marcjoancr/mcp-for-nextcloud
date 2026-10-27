<!-- SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Contributing

Start with the [development setup](docs/en/development/setup.md), then use the
[testing guide](docs/en/development/testing.md) for focused checks and full verification.
The [architecture specification](docs/en/development/architecture-specification.md)
and [tool-authoring guide](docs/en/development/adding-a-tool.md) explain ownership,
registration and the contracts that changes must preserve.

## Fork development workflow

This fork develops its capability architecture on `fork/main`, the published
GitHub default branch. Track its promotion
in the [development baseline record](docs/en/development/fork-development-baseline.md).
Base new topic branches on `fork/main`, not the older `main` or the frozen
`refactor/complete-upstream-pr` reference. Verify the starting revision when using
a worktree manager or task runner whose configured default may still be `main`.

The contribution destination is `marcjoancr/mcp-for-nextcloud`. Preserve original
attribution, but do not open new PRs against the original repository or update
PR #14 as part of fork development. The local `upstream` remote was removed;
read-only upstream research and explicitly selected ports remain possible by URL.
For GitHub CLI, check `gh repo set-default --view` before an authorized remote
action; a fork relationship alone does not guarantee the correct default target.

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
