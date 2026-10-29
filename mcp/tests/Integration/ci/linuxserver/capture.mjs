// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { verifyKit } from '../../../../scripts/release/integrity.mjs';
import { verifyPolicyDump } from './policy-dump.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { sealCheckpoint, verifyCheckpoint, sha256 } from './checkpoint.mjs';
const service = ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'php'];
const occ = (c, ...args) => c.exec(c.app, [...service, '/app/www/public/occ', ...args]);
const native = async (c, file, mode, marker) => {
  const output = await c.exec(c.app, [...service, `/fixture-probes/${file}`, mode]);
  if (!output.includes(marker)) throw new Error(`Incomplete capture probe: ${mode}`);
};
export async function capture(c, name, expected, oracle) {
  if (!['B0', 'B1'].includes(name)) throw new Error('Unknown checkpoint');
  const generation = randomUUID(), b0 = path.join(c.root, name);
  await fs.mkdir(b0, { mode: 0o700 });
  const { users, pendingPlans, policySnapshot, adminPassword, current, ...semantic } = expected;
  await fs.writeFile(path.join(b0, 'semantic.json'), JSON.stringify(semantic, null, 2));
  for (const [source, target] of [['app.env', 'app.env'], ['pg.env', 'pg.env'], ['s3/s3.json', 's3.json']]) await fs.copyFile(path.join(c.root, source), path.join(oracle, target));
  await occ(c, 'maintenance:mode', '--on');
  const gate = await c.rawExec(c.client, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '10', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
  if (gate.code || gate.out !== '503') throw new Error(`${name} admission not closed`);
  await c.exec(c.app, ['s6-rc', '-d', 'change', 'svc-nginx', 'svc-php-fpm', 'svc-cron']);
  const quiescent = async () => {
    await c.exec(c.app, ['sh', '-c', '! pgrep -x nginx && ! pgrep -f "^php-fpm" && ! pgrep -x crond && ! pgrep -x cron']);
    const writers = (await c.exec(c.postgres, ['psql', '-U', 'nextcloud', '-d', 'nextcloud', '-Atc', "SELECT count(*) FROM pg_stat_activity WHERE datname = 'nextcloud' AND pid <> pg_backend_pid() AND state <> 'idle'"])).trim();
    if (writers !== '0') throw new Error('Unexpected database writer');
  };
  await quiescent();
  const startedAt = new Date().toISOString();
  await c.exec(c.postgres, ['pg_dump', '-U', 'nextcloud', '-d', 'nextcloud', '-Fc', '-f', `/tmp/${name}-database.dump`]);
  const listing = await c.exec(c.postgres, ['pg_restore', '--list', `/tmp/${name}-database.dump`]);
  if (!listing.includes('mcp_oauth_tokens') || !listing.includes('deck_cards')) throw new Error('Database dump inventory incomplete');
  await c.checked(['cp', `${c.postgres}:/tmp/${name}-database.dump`, path.join(b0, 'database.dump')]);
  const dumpDigest = (await c.exec(c.postgres, ['sha256sum', `/tmp/${name}-database.dump`])).split(/\s/)[0];
  if (await sha256(path.join(b0, 'database.dump')) !== dumpDigest) throw new Error('Database dump transfer mismatch');
  const policyTables = Object.keys(policySnapshot);
  if (policyTables.some(name => !/^[a-z_]+$/.test(name))) throw new Error('Invalid policy table');
  const sql = await c.exec(c.postgres, ['pg_restore', '--data-only', ...policyTables.map(name => `--table=oc_${name}`), '--file=-', `/tmp/${name}-database.dump`]);
  await c.record(`${name}-policy-oracle-matches-dump`, { tables: verifyPolicyDump(sql, policySnapshot) });
  const owners = {};
  for (const component of ['config', 'data']) {
    await c.exec(c.app, ['tar', '-cpf', `/tmp/l2-${component}.tar`, '-C', `/${component}`, '.']);
    await c.checked(['cp', `${c.app}:/tmp/l2-${component}.tar`, path.join(b0, `${component}.tar`)]);
    const validation = await command('python3', ['-B', path.join(here, 'volume_archive.py'), path.join(b0, `${component}.tar`)]);
    if (validation.code) throw new Error(`Unsafe ${component} archive: ${validation.err}`);
    owners[component] = JSON.parse(validation.out);
  }
  await native(c, 'l2-s3.php', name === 'B0' ? 'export' : 'export-b1', 'MCP_L2_S3_COMPLETE');
  await c.checked(['cp', `${c.app}:/tmp/${name === 'B0' ? 'l2-export' : 'l3-export'}/.`, b0]);
  const s3 = JSON.parse(await fs.readFile(path.join(b0, 's3.json'), 'utf8'));
  const objects = Object.values(s3.objects);
  if (objects.reduce((sum, row) => sum + row.bytes, 0) > c.profile.limits.seedObjectMiB * 2**20) throw new Error('Synthetic object budget exceeded');
  if (!objects.length || !objects.some(row => row.bytes === 0) || !objects.some(row => row.bytes > 5 * 2**20 && row.etag.includes('-'))) throw new Error('S3 edge fixtures missing');
  for (const object of objects) {
    if (!/^objects\/[a-f0-9]{64}$/.test(object.payload) || await sha256(path.join(b0, object.payload)) !== object.sha256) throw new Error('S3 export integrity failure');
  }
  for (const [filename, file] of Object.entries(expected.files)) {
    const object = s3.objects[`urn:oid:${file.id}`];
    if (!object || object.sha256 !== file.sha256 || object.bytes !== file.bytes) throw new Error(`${name} primary S3 semantic mismatch: ${filename}`);
  }
  await quiescent();
  const finishedAt = new Date().toISOString();
  if (Object.entries(users).some(([uid, user]) => uid !== 'l2-denied' && (!Number.isSafeInteger(user.codeExpiresAt) || Date.parse(finishedAt) / 1000 >= user.codeExpiresAt))) throw new Error(`Pending authorization code expired before ${name} completed`);
  await fs.writeFile(path.join(b0, 'bootstrap.json'), JSON.stringify({ postgres: '16.15', database: 'nextcloud', owner: 'nextcloud', requiredRole: 'LOGIN SUPERUSER', credentials: `${path.relative(c.root, oracle)}/pg.env`, objectStoreCredentials: `${path.relative(c.root, oracle)}/s3.json`, appEnvironment: `${path.relative(c.root, oracle)}/app.env`, ports: [], admission: 'closed', maintenance: true, backgroundWriters: 'stopped', pgDataDirectoryCopied: false }, null, 2));
  if (name === 'B1') {
    await fs.cp(path.join(c.root, 'source'), path.join(b0, 'source'), { recursive: true, errorOnExist: true });
    await verifyKit(path.join(b0, 'source'), c.profile.candidate.manifestSha256);
  }
  const privateInputs = {};
  for (const name of ['expected.json', 'app.env', 'pg.env', 's3.json']) privateInputs[name] = await sha256(path.join(oracle, name));
  const metadata = { ...(name === 'B1' ? { sourceKit: c.profile.candidate } : {}), privateInputs, privateInputDirectory: path.relative(c.root, oracle), generation, startedAt, finishedAt, baseline: c.profile.baseline, installedMcp: name === 'B0' ? c.profile.baseline.version : c.profile.candidate.version, core: c.profile.nextcloudVersion, apps: c.profile.apps, profileSha256: c.profileSha256, images: c.profile.images, ownership: owners, oracleSha256: await sha256(path.join(oracle, 'expected.json')) };
  const { digest, verifiedAt } = await finalizeCapture(c.root, name, metadata, users);
  await c.record(`${name}-verified`, { generation, manifestSha256: digest, startedAt, finishedAt, verifiedAt, objects: objects.length, bytes: objects.reduce((sum, row) => sum + row.bytes, 0), ownership: owners, pendingCodeLiveAtCapture: true });
  return { generation, manifestSha256: digest };
}

export async function finalizeCapture(root, name, metadata, users, now = Date.now) {
  const directory = path.join(root, name);
  const digest = await sealCheckpoint(directory, metadata);
  await verifyCheckpoint(directory, metadata.generation, digest);
  const verifiedAt = new Date(now()).toISOString();
  if (Object.entries(users).some(([uid, user]) => uid !== 'l2-denied' && (!Number.isSafeInteger(user.codeExpiresAt) || Date.parse(verifiedAt) / 1000 >= user.codeExpiresAt))) throw new Error(`Pending authorization code expired before ${name} verification completed`);
  await fs.writeFile(path.join(root, `${name}-trust.json`), JSON.stringify({ generation: metadata.generation, manifestSha256: digest }, null, 2), { mode: 0o600 });
  return { digest, verifiedAt };
}
