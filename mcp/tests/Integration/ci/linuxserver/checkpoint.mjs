// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
const required = ['database.dump', 'config.tar', 'data.tar', 's3.json', 'semantic.json', 'bootstrap.json'];
export async function sha256(file) {
  const handle = await fs.open(file, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
  try {
    const before = await handle.stat();
    if (!before.isFile()) throw new Error('Checkpoint payload must be a regular file');
    const hash = createHash('sha256');
    for await (const chunk of handle.createReadStream({ autoClose: false })) hash.update(chunk);
    const after = await handle.stat();
    if (before.size !== after.size || before.mtimeMs !== after.mtimeMs || before.ctimeMs !== after.ctimeMs) throw new Error('Checkpoint payload changed during hashing');
    return hash.digest('hex');
  } finally { await handle.close(); }
}
async function inventory(root, prefix = '', restrict = false) {
  const directory = path.join(root, prefix);
  if (!(await fs.lstat(directory)).isDirectory()) throw new Error('Checkpoint directory must not be a link');
  if (restrict) await fs.chmod(directory, 0o700);
  const entries = {};
  for (const name of (await fs.readdir(path.join(root, prefix))).sort()) {
    const relative = prefix + name;
    if (relative === 'manifest.json') continue;
    const file = path.join(root, relative), stat = await fs.lstat(file);
    if (stat.isDirectory()) Object.assign(entries, await inventory(root, relative + '/', restrict));
    else {
      if (!stat.isFile()) throw new Error('Checkpoint payload must be a regular file');
      if (restrict) await fs.chmod(file, 0o600);
      entries[relative] = { bytes: stat.size, sha256: await sha256(file) };
    }
  }
  return entries;
}
export async function sealCheckpoint(root, metadata) {
  // Docker copies preserve source modes; the host umask alone does not protect them.
  const files = await inventory(root, '', true);
  for (const name of required) if (!files[name]) throw new Error(`Missing checkpoint component: ${name}`);
  const manifest = { schemaVersion: 1, ...metadata, files };
  await fs.writeFile(path.join(root, 'manifest.json'), JSON.stringify(manifest, null, 2), { mode: 0o600 });
  return sha256(path.join(root, 'manifest.json'));
}
export async function verifyCheckpoint(root, generation, trustedDigest) {
  const file = path.join(root, 'manifest.json');
  const stat = await fs.lstat(file);
  if (!stat.isFile() || stat.size > 2 * 2**20 || !/^[a-f0-9]{64}$/.test(trustedDigest) || await sha256(file) !== trustedDigest) throw new Error('Checkpoint manifest digest mismatch');
  const manifest = JSON.parse(await fs.readFile(file, 'utf8'));
  if (manifest.schemaVersion !== 1 || manifest.generation !== generation) throw new Error('Checkpoint generation mismatch');
  const actual = await inventory(root);
  if (required.some(name => !manifest.files?.[name]) || JSON.stringify(Object.keys(actual).sort()) !== JSON.stringify(Object.keys(manifest.files).sort())) throw new Error('Checkpoint inventory mismatch');
  for (const [name, entry] of Object.entries(actual)) if (entry.bytes !== manifest.files[name].bytes || entry.sha256 !== manifest.files[name].sha256) throw new Error('Checkpoint payload mismatch');
  return manifest;
}
