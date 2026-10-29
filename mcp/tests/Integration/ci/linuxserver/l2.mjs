// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { httpState } from './http-state.mjs';
import { verifyPolicyDump } from './policy-dump.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { sealCheckpoint, verifyCheckpoint, sha256 } from './checkpoint.mjs';
const service = ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'php'];
const occ = (c, ...args) => c.exec(c.app, [...service, '/app/www/public/occ', ...args]);
const native = async (c, file, mode, marker) => {
  const output = await c.exec(c.app, [...service, `/fixture-probes/${file}`, mode]);
  if (!output.includes(marker)) throw new Error(`Incomplete L2 probe: ${mode}`);
};
export async function executeL2(c) {
  const generation = randomUUID();
  const b0 = path.join(c.root, 'B0'), oracle = path.join(c.root, 'private');
  await fs.mkdir(b0, { mode: 0o700 }); await fs.mkdir(oracle, { mode: 0o700 });
  await occ(c, 'maintenance:mode', '--off');
  await native(c, 'l2-seed.php', 'seed', 'MCP_L2_COMPLETE=seed');
  await native(c, 'l2-s3.php', 'seed', 'MCP_L2_S3_COMPLETE');
  await c.checked(['cp', `${c.app}:/tmp/l2-private.json`, path.join(oracle, 'expected.json')]);
  await c.checked(['cp', path.join(oracle, 'expected.json'), `${c.client}:/tmp/l2-private.json`]);
  for (const name of ['l2-http.php', 'ProbeCompletion.php', 'tool-response.php']) await c.checked(['cp', path.join(c.root, 'probes', name), `${c.client}:/tmp/${name}`]);
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
  // The oracle and raw credentials are outside B0; the backup carries only its semantic digest and non-secret inventory.
  const { users, pendingPlans, policySnapshot, ...semantic } = expected;
  await fs.writeFile(path.join(b0, 'semantic.json'), JSON.stringify(semantic, null, 2));
  await fs.copyFile(path.join(c.root, 'app.env'), path.join(oracle, 'app.env'));
  await fs.copyFile(path.join(c.root, 'pg.env'), path.join(oracle, 'pg.env'));
  await fs.copyFile(path.join(c.root, 's3/s3.json'), path.join(oracle, 's3.json'));
  await occ(c, 'maintenance:mode', '--on');
  const gate = await c.rawExec(c.client, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '10', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
  if (gate.code || gate.out !== '503') throw new Error('B0 admission not closed');
  await c.exec(c.app, ['s6-rc', '-d', 'change', 'svc-nginx', 'svc-php-fpm', 'svc-cron']);
  const quiescent = async () => {
    await c.exec(c.app, ['sh', '-c', '! pgrep -x nginx && ! pgrep -f "^php-fpm" && ! pgrep -x crond && ! pgrep -x cron']);
    const writers = (await c.exec(c.postgres, ['psql', '-U', 'nextcloud', '-d', 'nextcloud', '-Atc', "SELECT count(*) FROM pg_stat_activity WHERE datname = 'nextcloud' AND pid <> pg_backend_pid() AND state <> 'idle'"])).trim();
    if (writers !== '0') throw new Error('Unexpected database writer');
  };
  await quiescent();
  const startedAt = new Date().toISOString();
  await c.exec(c.postgres, ['pg_dump', '-U', 'nextcloud', '-d', 'nextcloud', '-Fc', '-f', '/tmp/l2-database.dump']);
  const listing = await c.exec(c.postgres, ['pg_restore', '--list', '/tmp/l2-database.dump']);
  if (!listing.includes('mcp_oauth_tokens') || !listing.includes('deck_cards')) throw new Error('Database dump inventory incomplete');
  await c.checked(['cp', `${c.postgres}:/tmp/l2-database.dump`, path.join(b0, 'database.dump')]);
  const dumpDigest = (await c.exec(c.postgres, ['sha256sum', '/tmp/l2-database.dump'])).split(/\s/)[0];
  if (await sha256(path.join(b0, 'database.dump')) !== dumpDigest) throw new Error('Database dump transfer mismatch');
  const policyTables = Object.keys(policySnapshot);
  if (policyTables.some(name => !/^[a-z_]+$/.test(name))) throw new Error('Invalid policy table');
  const sql = await c.exec(c.postgres, ['pg_restore', '--data-only', ...policyTables.map(name => `--table=oc_${name}`), '--file=-', '/tmp/l2-database.dump']);
  await c.record('B0-policy-oracle-matches-dump', { tables: verifyPolicyDump(sql, policySnapshot) });
  const owners = {};
  for (const component of ['config', 'data']) {
    await c.exec(c.app, ['tar', '-cpf', `/tmp/l2-${component}.tar`, '-C', `/${component}`, '.']);
    await c.checked(['cp', `${c.app}:/tmp/l2-${component}.tar`, path.join(b0, `${component}.tar`)]);
    const validation = await command('python3', ['-B', path.join(here, 'volume_archive.py'), path.join(b0, `${component}.tar`)]);
    if (validation.code) throw new Error(`Unsafe ${component} archive: ${validation.err}`);
    owners[component] = JSON.parse(validation.out);
  }
  await native(c, 'l2-s3.php', 'export', 'MCP_L2_S3_COMPLETE');
  await c.checked(['cp', `${c.app}:/tmp/l2-export/.`, b0]);
  const s3 = JSON.parse(await fs.readFile(path.join(b0, 's3.json'), 'utf8'));
  const objects = Object.values(s3.objects);
  if (objects.reduce((sum, row) => sum + row.bytes, 0) > c.profile.limits.seedObjectMiB * 2**20) throw new Error('Synthetic object budget exceeded');
  if (!objects.length || !objects.some(row => row.bytes === 0) || !objects.some(row => row.bytes > 5 * 2**20 && row.etag.includes('-'))) throw new Error('S3 edge fixtures missing');
  for (const object of objects) {
    if (!/^objects\/[a-f0-9]{64}$/.test(object.payload) || await sha256(path.join(b0, object.payload)) !== object.sha256) throw new Error('S3 export integrity failure');
  }
  for (const [name, file] of Object.entries(expected.files)) {
    const object = s3.objects[`urn:oid:${file.id}`];
    if (!object || object.sha256 !== file.sha256 || object.bytes !== file.bytes) throw new Error(`B0 primary S3 semantic mismatch: ${name}`);
  }
  await quiescent();
  const finishedAt = new Date().toISOString();
  if (Object.entries(users).some(([uid, user]) => uid !== 'l2-denied' && (!Number.isSafeInteger(user.codeExpiresAt) || Date.parse(finishedAt) / 1000 >= user.codeExpiresAt))) throw new Error('Pending authorization code expired before B0 completed');
  await fs.writeFile(path.join(b0, 'bootstrap.json'), JSON.stringify({ postgres: '16.15', database: 'nextcloud', owner: 'nextcloud', requiredRole: 'LOGIN SUPERUSER', credentials: 'separate private/pg.env', objectStoreCredentials: 'separate private/s3.json', appEnvironment: 'separate private/app.env', ports: [], admission: 'closed', maintenance: true, backgroundWriters: 'stopped', pgDataDirectoryCopied: false }, null, 2));
  const privateInputs = {};
  for (const name of ['expected.json', 'app.env', 'pg.env', 's3.json']) privateInputs[name] = await sha256(path.join(oracle, name));
  const metadata = { privateInputs, generation, startedAt, finishedAt, baseline: c.profile.baseline, core: c.profile.nextcloudVersion, apps: c.profile.apps, profileSha256: c.profileSha256, images: c.profile.images, ownership: owners, oracleSha256: await sha256(path.join(oracle, 'expected.json')) };
  const digest = await sealCheckpoint(b0, metadata);
  await verifyCheckpoint(b0, generation, digest);
  await fs.writeFile(path.join(c.root, 'B0-trust.json'), JSON.stringify({ generation, manifestSha256: digest }, null, 2), { mode: 0o600 });
  await c.record('B0-verified', { generation, manifestSha256: digest, startedAt, finishedAt, objects: objects.length, bytes: objects.reduce((sum, row) => sum + row.bytes, 0), ownership: owners, pendingCodeLiveAtCapture: true });
  // CLI mutation after the checkpoint proves the oracle stays independent; no HTTP admission is reopened.
  await native(c, 'l2-seed.php', 'post-b0', 'MCP_L2_COMPLETE=post-b0');
  await c.checked(['cp', `${c.app}:/tmp/l2-private.json`, path.join(oracle, 'post-b0.json')]);
  await verifyCheckpoint(b0, generation, digest);
  await c.record('B0-unchanged-after-file-change-and-deletion');
  return { generation, manifestSha256: digest };
}
