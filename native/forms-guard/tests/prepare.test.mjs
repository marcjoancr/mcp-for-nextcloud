import test from 'node:test';
import assert from 'node:assert/strict';
import { existsSync, mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
const script = resolve(import.meta.dirname, '../prepare.mjs');
test('preparation exists and rejects unknown source before creating an output', () => {
  assert.ok(existsSync(script), 'Pinned preparation tool must exist');
  const source = mkdtempSync(join(tmpdir(), 'forms-unknown-'));
  const output = source + '-output';
  writeFileSync(join(source, 'marker'), 'untrusted');
  const result = spawnSync(process.execPath, [script, source, output], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.equal(existsSync(output), false);
  rmSync(source, {recursive: true});
});
