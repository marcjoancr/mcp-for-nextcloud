// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

test('native source acceptance refuses malformed or untrusted input before invoking Docker', t => {
  const parent = path.resolve('build/release-tests'); fs.mkdirSync(parent, { recursive: true });
  const fixture = fs.mkdtempSync(path.join(parent, 'acceptance-'));
  t.after(() => fs.rmSync(fixture, { recursive: true, force: true }));
  const marker = path.join(fixture, 'docker-called');
  fs.writeFileSync(path.join(fixture, 'docker'), '#!/bin/sh\n: > "$SOURCE_TEST_MARKER"\nexit 99\n', { mode: 0o700 });
  const kit = path.join(fixture, 'kit'); fs.mkdirSync(kit); fs.writeFileSync(path.join(kit, 'manifest.json'), '{}');
  for (const args of [[], ['relative', 'a'.repeat(64)], [kit, 'a'.repeat(64)], [kit, 'a'.repeat(64), 'extra']]) {
    const result = spawnSync(process.execPath, [path.resolve('scripts/release/accept.mjs'), ...args], {
      encoding: 'utf8', env: { ...process.env, PATH: fixture + path.delimiter + process.env.PATH, SOURCE_TEST_MARKER: marker },
    });
    assert.equal(result.status, 1, result.stderr);
    assert.match(result.stderr, /Source acceptance failed/);
    assert.equal(fs.existsSync(marker), false);
  }
});
