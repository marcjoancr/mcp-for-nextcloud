#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Explicit L1 boundary. The offline planner and executor share strict argument validation.
set -eu
L1_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
exec node "$L1_DIR/plan.mjs" --phase L1 "$@"
