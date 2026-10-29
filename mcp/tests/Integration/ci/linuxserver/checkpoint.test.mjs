// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { sealCheckpoint, verifyCheckpoint, sha256 } from './checkpoint.mjs';

test('B0 requires every component, binds a generation and detects changed or extra bytes', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-b0-test-'));
  try {
    const generation = '00000000-0000-4000-8000-000000000001';
    const metadata = { generation, profileSha256: 'a'.repeat(64), startedAt: new Date().toISOString(), finishedAt: new Date().toISOString(), baseline: '1.2.0', core: '35.0.2' };
    for (const name of ['database.dump', 'config.tar', 'data.tar', 's3.json', 'semantic.json', 'bootstrap.json']) await fs.writeFile(path.join(root, name), name);
    const digest = await sealCheckpoint(root, metadata);
    assert.equal((await verifyCheckpoint(root, generation, digest)).generation, generation);
    await assert.rejects(verifyCheckpoint(root, 'other', digest), /generation/);
    await assert.rejects(verifyCheckpoint(root, generation, 'b'.repeat(64)), /digest/);
    await fs.writeFile(path.join(root, 'data.tar'), 'changed');
    await assert.rejects(verifyCheckpoint(root, generation, digest), /payload/);
    await fs.writeFile(path.join(root, 'data.tar'), 'data.tar');
    await fs.writeFile(path.join(root, 'extra'), 'unlisted');
    await assert.rejects(verifyCheckpoint(root, generation, digest), /inventory/);
    await fs.rm(path.join(root, 'extra'));
    await fs.rm(path.join(root, 'database.dump'));
    await assert.rejects(verifyCheckpoint(root, generation, digest), /inventory/);
    await assert.rejects(sealCheckpoint(root, metadata), /component/);
    await fs.symlink('/etc/passwd', path.join(root, 'database.dump'));
    await assert.rejects(sealCheckpoint(root, metadata), /regular/);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('volume archive validation preserves internal links and rejects extraction hazards', () => {
  const output = execFileSync('python3', ['-B', fileURLToPath(new URL('./archive_test.py', import.meta.url))], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  assert.equal(output, '');
});

test('a private checkpoint cannot contain a directory symlink to external payloads', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-b0-links-'));
  try {
    await fs.symlink(os.tmpdir(), path.join(root, 'objects'));
    await assert.rejects(sealCheckpoint(root, {}), /regular/);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('payload hashing rejects a symlink even when invoked directly', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-hash-links-'));
  try {
    await fs.writeFile(path.join(root, 'file'), 'bytes');
    await fs.symlink(path.join(root, 'file'), path.join(root, 'link'));
    await assert.rejects(sha256(path.join(root, 'link')));
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});
