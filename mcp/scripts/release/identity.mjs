// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export function writeIdentity(root, stagedApp) {
  const output = path.join(stagedApp, 'appinfo/source.json');
  if (fs.existsSync(output)) throw new Error('Source identity must be generated, not supplied in the source tree');
  const git = (...args) => execFileSync('git', ['-C', root, ...args], { encoding: 'utf8' }).trim();
  if (git('status', '--porcelain', '--untracked-files=normal') !== '') return false;
  const sourceCommit = git('rev-parse', 'HEAD');
  const version = fs.readFileSync(path.join(root, 'mcp/appinfo/info.xml'), 'utf8').match(/<version>(\d+\.\d+\.\d+)<\/version>/)?.[1];
  if (!/^[a-f0-9]{40}$/.test(sourceCommit) || !version) throw new Error('Invalid committed source identity');
  fs.writeFileSync(output, `${JSON.stringify({ schemaVersion: 1, version, sourceCommit }, null, 2)}\n`, { flag: 'wx' });
  return true;
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv.length !== 3) throw new Error('Usage: identity.mjs STAGED_APP');
    const root = fileURLToPath(new URL('../../../', import.meta.url));
    console.log(writeIdentity(root, process.argv[2]) ? 'Packaged committed source identity.' : 'Dirty development build: source offer unavailable.');
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
