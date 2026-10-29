// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import path from 'node:path';
import { completeProbe, rejectedProbe } from './safety.mjs';
const service = ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'php'];
const occ = (c, ...args) => c.exec(c.app, [...service, '/app/www/public/occ', ...args]);
const probe = async (c, mode) => {
  const output = await c.exec(c.app, [...service, '/fixture-probes/probe.php', mode]);
  const result = completeProbe(output);
  await c.record(mode, { ...result, output: output.trim().split('\n').filter(line => !line.startsWith('MCP_L1_COMPLETE=')) });
};
const curl = (c, id, url, args = []) => c.rawExec(id, ['curl', '--silent', '--show-error', '--max-time', '20', '--cacert', '/tmp/ca.crt', ...args, url]);
const status = async (c, id, expected, url = 'https://nc.fixture.test/status.php', extra = []) => {
  const r = await curl(c, id, url, ['-o', '/dev/null', '-w', '%{http_code}', ...extra]);
  if (r.code || r.out !== String(expected)) throw new Error(`Unexpected private HTTP response (expected ${expected}, received ${r.code}/${r.out})`);
};
const paused = async c => {
  await c.exec(c.app, ['s6-rc', '-d', 'change', 'svc-cron']);
  await c.exec(c.app, ['sh', '-c', 'test -z "$(crontab -l -u abc)" && test -z "$(crontab -l -u root)" && ! pgrep -x crond && ! pgrep -x cron || { echo "Background jobs are not paused"; exit 1; }']);
};
const open = async c => { await occ(c, 'maintenance:mode', '--off'); await c.exec(c.app, ['rm', '/config/mcp-l1-closed']); };
const closed = async c => { await c.close(); await occ(c, 'maintenance:mode', '--on'); };

export async function bootstrap(c) {
  await c.wait('PostgreSQL', async () => (await c.rawExec(c.postgres, ['pg_isready', '-U', 'nextcloud', '-d', 'nextcloud'])).code === 0);
  const sql = (await c.exec(c.postgres, ['psql', '-U', 'nextcloud', '-d', 'nextcloud', '-Atc', 'SHOW server_version'])).trim();
  if (!sql.startsWith('16.15')) throw new Error('SQL server version mismatch');
  await c.record('postgres-ready', sql);
  await c.wait('LinuxServer initialization', async () => (await c.rawExec(c.app, ['sh', '-c', 'test -f /app/www/public/occ && test -d /run/service/svc-php-fpm && test -f /run/s6/container_environment/MCP_S3_SECRET'])).code === 0);
  await c.wait('closed Nginx', async () => {
    const r = await curl(c, c.client, 'https://nc.fixture.test/status.php', ['-o', '/dev/null', '-w', '%{http_code}']);
    return r.code === 0 && r.out === '503';
  });
  await paused(c);
  await c.record('closed-admission-and-paused-cron');
  await c.wait('authenticated S3', async () => (await c.rawExec(c.client, ['curl', '--silent', '--max-time', '3', '-o', '/dev/null', '-w', '%{http_code}', 'http://s3:8333/'])).out === '403');
  await probe(c, 's3-create');
  const topology = JSON.parse(await c.exec(c.client, ['curl', '--fail', '--silent', '--max-time', '10', 'http://s3:9333/dir/status'])).Topology;
  const volumes = topology.DataCenters.flatMap(dc => dc.Racks).flatMap(rack => rack.DataNodes).reduce((n, node) => n + node.Volumes, 0);
  if (topology.Max !== c.profile.objectStore.volumeCount || volumes !== c.profile.objectStore.volumeGrowthCount) throw new Error('S3 effective volume limits mismatch');
  const deniedS3 = await c.rawExec(c.client, ['curl', '--silent', '--max-time', '10', '--aws-sigv4', 'aws:amz:us-east-1:s3', '--user', 'invalid:invalid', '-o', '/dev/null', '-w', '%{http_code}', 'http://s3:8333/mcp-l1/']);
  if (deniedS3.code || deniedS3.out !== '403') throw new Error('S3 accepted invalid signing credentials');
  await c.record('s3-limits-and-auth', { maxVolumes: topology.Max, allocatedVolumes: volumes, configuredVolumeMiB: c.profile.objectStore.volumeSizeLimitMiB, anonymousDenied: true, invalidSignatureDenied: true });
  await c.checked(['restart', c.s3]);
  await c.wait('S3 restart', async () => (await c.rawExec(c.client, ['curl', '--silent', '--max-time', '3', '-o', '/dev/null', '-w', '%{http_code}', 'http://s3:8333/'])).out === '403');
  await c.wait('persisted S3 object after restart', async () => {
    const reply = await c.rawExec(c.app, [...service, '/fixture-probes/probe.php', 's3-read']);
    if (reply.code) return false;
    completeProbe(reply.out); return true;
  });
  await probe(c, 's3-read');
  await probe(c, 's3-delete');
  // On a fresh volume this image creates config.php after its recursive chown.
  await c.exec(c.app, ['chown', 'abc:abc', '/config/www/nextcloud/config/config.php']);
  await c.exec(c.app, [...service, '-r', 'if (!is_writable("/app/www/public/config/config.php")) { exit(1); }']);
  // Passwords expand only inside the synthetic container; never enter host argv/logs.
  const installed = await c.exec(c.app, ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'sh', '-c',
    'php /app/www/public/occ maintenance:install --database pgsql --database-host postgres --database-name nextcloud --database-user nextcloud --database-pass "$MCP_DB_PASSWORD" --admin-user l1-admin --admin-pass "$MCP_ADMIN_PASSWORD" --data-dir /data']);
  if (!installed.includes('Nextcloud was successfully installed')) throw new Error('Native installer did not acknowledge completion');
  for (const id of ['mcp', ...c.profile.apps.map(app => app.id)]) {
    await c.checked(['cp', path.join(c.root, 'apps', id), `${c.app}:/config/www/nextcloud/apps/${id}`]);
    await c.exec(c.app, ['chown', '-R', 'abc:abc', `/config/www/nextcloud/apps/${id}`]);
    await occ(c, 'app:enable', id);
  }
  await occ(c, 'background:cron');
  await c.checked(['cp', path.join(c.root, 'probes/health.php'), `${c.app}:/app/www/public/mcp-l1-health.php`]);
  const state = JSON.parse(await occ(c, 'status', '--output=json'));
  if (!state.installed || state.versionstring !== c.profile.nextcloudVersion || state.needsDbUpgrade) throw new Error('Nextcloud status mismatch');
  await c.record('nextcloud-status', state);
  await probe(c, 'context');
  await c.record('baseline-installed', { mcp: '1.2.0', apps: c.profile.apps.map(({ id, version }) => ({ id, version })), candidateInstalled: false });
}

export async function validateBaseline(c) {
  await open(c);
  await status(c, c.client, 200);
  await status(c, c.client, 200, 'https://nc.fixture.test/login');
  await status(c, c.client, 401, 'https://nc.fixture.test/index.php/apps/mcp/', ['-X', 'POST', '-H', 'Content-Type: application/json', '--data', '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}']);
  await status(c, c.denied, 403);
  const clientRow = JSON.parse(await c.checked(['inspect', c.client]))[0];
  const admittedIp = Object.values(clientRow.NetworkSettings.Networks)[0].IPAddress;
  await status(c, c.denied, 403, 'https://nc.fixture.test/status.php', ['-H', `X-Forwarded-For: ${admittedIp}`]);
  const health = await curl(c, c.client, 'https://nc.fixture.test/mcp-l1-health.php');
  const identity = JSON.parse(health.out);
  if (health.code || identity.ok !== true || identity.sapi !== 'fpm-fcgi' || identity.uid !== 1000) throw new Error('FPM identity failed');
  const cliVersion = (await c.exec(c.app, [...service, '-r', 'echo PHP_VERSION;'])).trim();
  if (cliVersion !== identity.phpFpm) throw new Error('CLI/FPM version mismatch');
  await c.record('private-tls-fpm', identity);
  const noTrust = await c.rawExec(c.client, ['curl', '--silent', '--show-error', '--max-time', '20', 'https://nc.fixture.test/status.php']);
  if (noTrust.code !== 60) throw new Error('Untrusted certificate was not rejected as a trust error');
  const appRow = JSON.parse(await c.checked(['inspect', c.app]))[0];
  if (Object.keys(appRow.HostConfig.PortBindings ?? {}).length || appRow.Mounts.some(m => m.Destination.includes('docker.sock'))) throw new Error('Unsafe fixture exposure');
  const ip = Object.values(appRow.NetworkSettings.Networks)[0].IPAddress;
  const wrongHost = await curl(c, c.client, 'https://wrong.fixture.test/status.php', ['--resolve', `wrong.fixture.test:443:${ip}`]);
  if (wrongHost.code !== 60) throw new Error('Wrong certificate hostname was not rejected');
  await c.record('tls-and-admission-negatives', ['untrusted-ca', 'wrong-hostname', 'non-admitted-client', 'spoofed-forwarded-ip']);
  for (const [name, pin] of Object.entries({ 'manifest.json': { sha256: c.profile.candidate.manifestSha256 }, ...c.manifest.files })) {
    const download = await curl(c, c.client, `https://nc.fixture.test/source/${name}`, ['--fail', '-o', '/tmp/source-download']);
    if (download.code) throw new Error(`Private source download failed: ${name}`);
    const digest = (await c.exec(c.client, ['sha256sum', '/tmp/source-download'])).split(/\s/)[0];
    if (digest !== pin.sha256) throw new Error('Private source digest mismatch');
  }
  await status(c, c.client, 403, 'https://nc.fixture.test/source/manifest.json', ['-X', 'PUT', '--data', 'forbidden']);
  if (!appRow.Mounts.find(m => m.Destination === '/fixture-source' && m.RW === false)) throw new Error('Source mount is writable');
  await c.record('private-source-kit', { files: Object.keys(c.manifest.files).length + 1, advertisedByBaseline: false, readOnly: true });
  for (const mode of ['missing-env', 'wrong-path', 'incomplete']) {
    const reply = await c.rawExec(c.app, [...service, '/fixture-probes/probe.php', mode]);
    rejectedProbe(reply, mode);
    await c.record(`negative-${mode}`);
  }
  await closed(c);
  await status(c, c.client, 503);
  await c.checked(['stop', '-t', '10', c.postgres]);
  let reply = await c.rawExec(c.app, [...service, '/fixture-probes/probe.php', 'unavailable-db']);
  rejectedProbe(reply, 'unavailable-db');
  await c.checked(['start', c.postgres]);
  await c.wait('DB recovery', async () => (await c.rawExec(c.postgres, ['pg_isready', '-U', 'nextcloud'])).code === 0);
  await c.record('negative-unavailable-db');
  await c.checked(['stop', '-t', '10', c.s3]);
  reply = await c.rawExec(c.app, [...service, '/fixture-probes/probe.php', 'unavailable-s3']);
  rejectedProbe(reply, 'unavailable-s3');
  await c.checked(['start', c.s3]);
  await c.wait('S3 recovery', async () => (await c.rawExec(c.client, ['curl', '--silent', '--max-time', '3', '-o', '/dev/null', '-w', '%{http_code}', 'http://s3:8333/'])).out === '403');
  await c.wait('primary S3 object after recovery', async () => {
    const reply = await c.rawExec(c.app, [...service, '/fixture-probes/probe.php', 'verify']);
    if (reply.code) return false;
    completeProbe(reply.out); return true;
  });
  await probe(c, 'verify');
  await c.record('negative-unavailable-s3');
  await c.checked(['restart', c.app]);
  await c.wait('persistent closed gate', async () => {
    const r = await curl(c, c.client, 'https://nc.fixture.test/status.php', ['-o', '/dev/null', '-w', '%{http_code}']);
    return r.code === 0 && r.out === '503';
  });
  await paused(c);
  await occ(c, 'maintenance:mode', '--off');
  await status(c, c.client, 503);
  await probe(c, 'verify');
  await c.record('restart-persistence-and-pre-php-gate');
  await c.exec(c.app, ['rm', '/config/mcp-l1-closed']);
  await status(c, c.client, 200);
  await status(c, c.denied, 403);
  await closed(c);
  await status(c, c.client, 503);
  await c.record('final-closed-state');
}
