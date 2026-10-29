#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Offline L0 planning or explicitly selected L1-L4 isolated qualification; L5 is rejected.
set -eu
L0_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
exec node "$L0_DIR/plan.mjs" "$@"
