#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# L0 only: no execution path, resource creation, networking or lifecycle cleanup.
set -eu
L0_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
exec node "$L0_DIR/plan.mjs" "$@"
