// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { prepareKit } from './source.mjs';
import { verifyKit, verifyInstalled } from './integrity.mjs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
try {
  const [command, ...args] = process.argv.slice(2);
  const counts = { prepare: 1, verify: 2, installed: 3, test: 0 };
  if (!Object.hasOwn(counts, command) || args.length !== counts[command]) {
    throw new Error('Usage: prepare FULL_COMMIT | verify KIT TRUSTED_MANIFEST_SHA256 | installed KIT TRUSTED_MANIFEST_SHA256 APP_DIRECTORY | test');
  }
  if (command === 'prepare') console.log(JSON.stringify(await prepareKit(root, args[0]), null, 2));
  else if (command === 'verify' || command === 'installed') {
    const manifest = await (command === 'verify' ? verifyKit(...args) : verifyInstalled(...args));
    console.log(`Verified ${command}: ${manifest.sourceCommit} / ${manifest.version}`);
  } else {
    if (process.env.NODE_TEST_CONTEXT) throw new Error('Cannot run release gate inside a nested Node test context');
    const directory = path.join(root, 'mcp/tests/release');
    const tests = fs.readdirSync(directory).filter(file => file.endsWith('.test.mjs')).sort().map(file => path.join(directory, file));
    if (!tests.length) throw new Error('No release tests discovered');
    const result = spawnSync(process.execPath, ['--test', ...tests], { cwd: path.join(root, 'mcp'), stdio: 'inherit' });
    if (result.error) throw result.error;
    if (result.status !== 0) throw new Error(`Release tests failed (${result.status ?? result.signal})`);
  }
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
