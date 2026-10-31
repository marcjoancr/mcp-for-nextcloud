// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { readProfile, validateProfile } from './profile.mjs';
import * as inputs from './inputs.mjs';

const sha = bytes => createHash('sha256').update(bytes).digest('hex');

test('LinuxServer rejects swapped origins and self-declared baseline pins', async () => {
  const older = (await readProfile(new URL('./profile.json', import.meta.url))).profile;
  const newer = (await readProfile(new URL('./profile-121.json', import.meta.url))).profile;
  assert.equal(newer.baseline.version, '1.2.1');
  for (const [profile, other] of [[older, newer], [newer, older]]) {
    for (const key of Object.keys(profile.baseline)) {
      assert.throws(() => validateProfile({ ...profile, baseline: { ...profile.baseline, [key]: other.baseline[key] } }));
    }
    assert.throws(() => validateProfile({ ...profile, id: other.id }));
  }
});

test('baseline admission measures extracted file contents and checks the supplied inventory', async t => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-baseline-test-'));
  t.after(() => fs.rm(root, { recursive: true, force: true }));
  await fs.mkdir(path.join(root, 'mcp'));
  const content = 'installed app';
  await fs.writeFile(path.join(root, 'mcp/version'), content);
  const archive = path.join(root, 'mcp-1.2.1.tar.gz');
  execFileSync('tar', ['-czf', archive, '-C', root, 'mcp']);
  const bytes = await fs.readFile(archive);
  const inventory = JSON.stringify({ 'mcp/version': { sha256: sha(content), bytes: content.length } }, null, 2) + '\n';
  const baseline = { version: '1.2.1', archiveSha256: sha(bytes), archiveBytes: bytes.length, inventorySha256: sha(inventory), runtimeFileCount: 1 };
  const check = async pins => {
    const stage = await fs.mkdtemp(path.join(root, 'stage-'));
    return inputs.stageBaseline(stage, root, pins);
  };
  const supplied = path.join(root, 'baseline-inventory.json');
  await fs.writeFile(supplied, inventory);
  await check(baseline);
  await fs.writeFile(supplied, inventory.replace(sha(content), '0'.repeat(64)));
  await assert.rejects(check(baseline));
  await assert.rejects(check({ ...baseline, inventorySha256: sha(await fs.readFile(supplied)) }));
  await fs.unlink(supplied);
  await assert.rejects(check(baseline));
});
