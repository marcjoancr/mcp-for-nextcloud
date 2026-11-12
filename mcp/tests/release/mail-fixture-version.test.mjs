// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { spawnSync } from 'node:child_process';
test('guarded fixture accepts installed build version from its generated native manifest', () => {
  const script = fs.readFileSync('tests/Integration/ci/mail-cell.sh', 'utf8');
  const assertion = script.slice(script.indexOf('\tactual=$(occ config:app:get mail installed_version)'), script.indexOf('\n\techo "Mail $actual enabled"'));
  const result = spawnSync('sh', ['-c', `set -eu\nMCP_IT_MAIL_GUARD=1\nMAIL_TAG=v5.12.5\nTOOLS_DIR=unused\nocc() { echo 5.12.5.12; }\nphp() { echo 5.12.5.12; }\n${assertion}`], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
});
