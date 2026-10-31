// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { inventory } from '../../../scripts/release/integrity.mjs';
import { command } from './linuxserver/command.mjs';

const extractApp = fileURLToPath(new URL('./linuxserver/extract.py', import.meta.url));

export async function checkedFile(source, target, hash, bytes) {
  if (!(await fs.lstat(source)).isFile()) throw new Error('Input must be a regular file');
  const handle = await fs.open(source, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
  try {
    const stat = await handle.stat();
    if (!stat.isFile() || stat.size !== bytes || stat.size > 256 * 2**20) throw new Error('Input size/type mismatch');
    const data = await handle.readFile();
    if (createHash('sha256').update(data).digest('hex') !== hash) throw new Error('Input hash mismatch');
    await fs.writeFile(target, data, { mode: 0o600, flag: 'wx' });
  } finally { await handle.close(); }
}
export async function stageApp(root, source, id, version, digest, bytes) {
  const name = `${id}-${version}.tar.gz`;
  const archive = path.join(root, name);
  await checkedFile(source, archive, digest, bytes);
  const result = await command('python3', [extractApp, archive, path.join(root, 'apps'), id]);
  if (result.code) throw new Error(`Archive extraction failed: ${id}`);
}

export async function stageBaselineArchive(root, source, baseline) {
  await fs.mkdir(path.join(root, 'apps'));
  await stageApp(root, source, 'mcp', baseline.version, baseline.archiveSha256, baseline.archiveBytes);
  const files = await inventory(path.join(root, 'apps/mcp'));
  const entries = Object.entries(files).map(([name, metadata]) => [`mcp/${name}`, metadata]);
  const serialized = JSON.stringify(Object.fromEntries(entries), null, 2) + '\n';
  const inventorySha256 = createHash('sha256').update(serialized).digest('hex');
  if (entries.length !== baseline.runtimeFileCount || inventorySha256 !== baseline.inventorySha256) {
    throw new Error('Extracted baseline inventory mismatch');
  }
  return { sha256: inventorySha256, files: entries.length, bytes: Buffer.byteLength(serialized) };
}
