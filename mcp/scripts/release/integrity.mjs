// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import { constants } from 'node:fs';
import path from 'node:path';
import { createHash } from 'node:crypto';

const digestPattern = /^[a-f0-9]{64}$/;
function safePath(name) {
  if (!name || name.includes('\\') || /[\x00-\x1f\x7f]/.test(name) || path.posix.isAbsolute(name)
    || name.split('/').some(part => !part || part === '.' || part === '..')) {
    throw new Error(`Unsafe inventory path: ${JSON.stringify(name)}`);
  }
}
export async function hashFile(file) {
  const handle = await fs.open(file, constants.O_RDONLY | constants.O_NOFOLLOW);
  try {
    if (!(await handle.stat()).isFile()) throw new Error(`Expected regular file: ${file}`);
    const hash = createHash('sha256');
    for await (const chunk of handle.createReadStream({ autoClose: false })) hash.update(chunk);
    return hash.digest('hex');
  } finally { await handle.close(); }
}

export async function inventory(directory) {
  const result = Object.create(null);
  async function walk(current, prefix) {
    if (!(await fs.lstat(current)).isDirectory()) throw new Error(`Expected regular directory, not symlink: ${current}`);
    for (const entry of (await fs.readdir(current)).sort()) {
      const name = prefix ? `${prefix}/${entry}` : entry;
      safePath(name);
      const file = path.join(current, entry), stat = await fs.lstat(file);
      if (stat.isDirectory()) await walk(file, name);
      else if (stat.isFile()) result[name] = { sha256: await hashFile(file), bytes: stat.size };
      else throw new Error(`Expected regular file/directory, not symlink or special file: ${name}`);
    }
  }
  await walk(directory, '');
  return Object.fromEntries(Object.entries(result).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0));
}
function validateInventory(files) {
  if (!files || typeof files !== 'object' || Array.isArray(files) || !Object.keys(files).length) throw new Error('Empty or invalid inventory');
  for (const [name, value] of Object.entries(files)) {
    safePath(name);
    if (!value || !digestPattern.test(value.sha256) || !Number.isSafeInteger(value.bytes) || value.bytes < 0) {
      throw new Error(`Invalid inventory record: ${name}`);
    }
  }
}
function validateManifest(manifest) {
  if (manifest.schemaVersion !== 1 || !/^[a-f0-9]{40}$/.test(manifest.sourceCommit)
    || !/^\d+\.\d+\.\d+$/.test(manifest.version)) throw new Error('Invalid source manifest metadata');
  validateInventory(manifest.files);
  validateInventory(manifest.runtimeFiles);
}
export async function sealKit(directory, metadata) {
  const manifest = { ...metadata, schemaVersion: 1, files: await inventory(directory) };
  if ('manifest.json' in manifest.files) throw new Error('Refusing to replace an existing manifest');
  validateManifest(manifest);
  await fs.writeFile(path.join(directory, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, { flag: 'wx' });
  return hashFile(path.join(directory, 'manifest.json'));
}
function compare(actual, expected) {
  if (JSON.stringify(Object.keys(actual).sort()) !== JSON.stringify(Object.keys(expected).sort())) throw new Error('File inventory differs (missing or extra files)');
  for (const [name, value] of Object.entries(expected)) {
    if (actual[name].sha256 !== value.sha256 || actual[name].bytes !== value.bytes) throw new Error(`File integrity differs: ${name}`);
  }
}
export async function verifyKit(directory, trustedDigest) {
  if (!digestPattern.test(trustedDigest ?? '')) throw new Error('A trusted manifest digest (64 lowercase hex characters) is required');
  if (!(await fs.lstat(directory)).isDirectory()) throw new Error('Expected regular kit directory, not symlink');
  const file = path.join(directory, 'manifest.json'), stat = await fs.lstat(file);
  if (!stat.isFile() || stat.size > 1024 * 1024) throw new Error('Manifest must be a regular file of at most 1 MiB');
  // Bind the bytes that are parsed, rather than reopening the file after hashing it.
  const bytes = await fs.readFile(file);
  if (createHash('sha256').update(bytes).digest('hex') !== trustedDigest) throw new Error('Trusted manifest digest does not match');
  const manifest = JSON.parse(bytes);
  validateManifest(manifest);
  const actual = await inventory(directory);
  delete actual['manifest.json'];
  compare(actual, manifest.files);
  return manifest;
}
export async function verifyInstalled(directory, trustedDigest, installed) {
  const manifest = await verifyKit(directory, trustedDigest);
  compare(await inventory(installed), manifest.runtimeFiles);
  return manifest;
}
