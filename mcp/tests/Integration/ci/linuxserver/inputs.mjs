// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { command } from './command.mjs';
export const here = fileURLToPath(new URL('.', import.meta.url));
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
export async function prepareInputs(root, options, profile, manifest) {
  const apps = [{ id: 'mcp', version: profile.baseline.version, sha256: profile.baseline.archiveSha256, bytes: profile.baseline.archiveBytes }, ...profile.apps];
  await fs.mkdir(path.join(root, 'apps'));
  await fs.mkdir(path.join(root, 'source'), { mode: 0o755 });
  for (const app of apps) {
    const name = `${app.id}-${app.version}.tar.gz`, dest = path.join(root, name);
    await checkedFile(path.join(options.inputs, name), dest, app.sha256, app.bytes);
    const result = await command('python3', [path.join(here, 'extract.py'), dest, path.join(root, 'apps'), app.id]);
    if (result.code) throw new Error(`Archive extraction failed: ${app.id}`);
  }
  const sourceManifest = path.join(options.kit, 'manifest.json');
  const targetManifest = path.join(root, 'source/manifest.json');
  await checkedFile(sourceManifest, targetManifest, profile.candidate.manifestSha256, (await fs.lstat(sourceManifest)).size);
  await fs.chmod(targetManifest, 0o644);
  for (const [name, file] of Object.entries(manifest.files)) {
    const dest = path.join(root, 'source', name);
    await checkedFile(path.join(options.kit, name), dest, file.sha256, file.bytes);
    await fs.chmod(dest, 0o644);
  }
}
