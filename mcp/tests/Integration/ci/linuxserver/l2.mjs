// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { capture } from './capture.mjs';
import { httpState } from './http-state.mjs';
import { verifyCheckpoint, sha256 } from './checkpoint.mjs';
const service = ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'php'];
const occ = (c, ...args) => c.exec(c.app, [...service, '/app/www/public/occ', ...args]);
const native = async (c, file, mode, marker) => {
  const output = await c.exec(c.app, [...service, `/fixture-probes/${file}`, mode]);
  if (!output.includes(marker)) throw new Error(`Incomplete L2 probe: ${mode}`);
};
export async function executeL2(c) {
  const b0 = path.join(c.root, 'B0'), oracle = path.join(c.root, 'private');
  await fs.mkdir(oracle, { mode: 0o700 });
  await occ(c, 'maintenance:mode', '--off');
  await native(c, 'l2-seed.php', 'seed', 'MCP_L2_COMPLETE=seed');
  await native(c, 'l2-s3.php', 'seed', 'MCP_L2_S3_COMPLETE');
  await c.checked(['cp', `${c.app}:/tmp/l2-private.json`, path.join(oracle, 'expected.json')]);
  await c.checked(['cp', path.join(oracle, 'expected.json'), `${c.client}:/tmp/l2-private.json`]);
  for (const name of ['l2-http.php', 'ProbeCompletion.php', 'tool-response.php', 'http-client.php']) await c.checked(['cp', path.join(c.root, 'probes', name), `${c.client}:/tmp/${name}`]);
  await c.exec(c.app, ['rm', '/config/mcp-l1-closed']);
  const http = await c.exec(c.client, ['php', '/tmp/l2-http.php']);
  const line = http.split('\n').find(line => line.startsWith('MCP_L2_HTTP='));
  if (!line) throw new Error('Incomplete L2 HTTP probe');
  const httpResult = JSON.parse(line.slice('MCP_L2_HTTP='.length));
  await c.close();
  await c.checked(['cp', `${c.client}:/tmp/l2-http-private.json`, path.join(oracle, 'expected.json')]);
  if (await sha256(path.join(oracle, 'expected.json')) !== httpResult.oracleSha256) throw new Error('HTTP oracle transfer mismatch');
  const retainedHttpState = JSON.stringify(httpState(JSON.parse(await fs.readFile(path.join(oracle, 'expected.json'), 'utf8'))));
  await c.record('baseline-capabilities-and-http-policy', httpResult);
  await c.checked(['cp', path.join(oracle, 'expected.json'), `${c.app}:/tmp/l2-private.json`]);
  await c.exec(c.app, ['chown', 'abc:abc', '/tmp/l2-private.json']);
  await native(c, 'l2-seed.php', 'credentials', 'MCP_L2_COMPLETE=credentials');
  await c.checked(['cp', `${c.app}:/tmp/l2-private.json`, path.join(oracle, 'expected.json')]);
  const expected = JSON.parse(await fs.readFile(path.join(oracle, 'expected.json'), 'utf8'));
  if (JSON.stringify(httpState(expected)) !== retainedHttpState) throw new Error('HTTP oracle changed during credential refresh');
  const checkpoint = await capture(c, 'B0', expected, oracle);
  const { generation, manifestSha256: digest } = checkpoint;
  // CLI mutation after the checkpoint proves the oracle stays independent; no HTTP admission is reopened.
  await native(c, 'l2-seed.php', 'post-b0', 'MCP_L2_COMPLETE=post-b0');
  await c.checked(['cp', `${c.app}:/tmp/l2-private.json`, path.join(oracle, 'post-b0.json')]);
  await verifyCheckpoint(b0, generation, digest);
  await c.record('B0-unchanged-after-file-change-and-deletion');
  return { generation, manifestSha256: digest };
}
