// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { isIPv4 } from 'node:net';
import { capture } from './capture.mjs';
import { verifyCheckpoint, sha256 } from './checkpoint.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { nativePhp, occ, state, restartClosed, identity, replace, prepareSuite } from './l3-lifecycle.mjs';
import { verifyKit } from '../../../../scripts/release/integrity.mjs';

const open = async c => {
  await occ(c, 'maintenance:mode', '--off');
  await c.exec(c.app, ['rm', '/config/mcp-l1-closed']);
};
async function appPrivate(c, file) {
  await c.checked(['cp', file, `${c.app}:/tmp/l3-private.json`]);
  await c.exec(c.app, ['chown', 'abc:abc', '/tmp/l3-private.json']);
}
async function http(c, mode, file) {
  await c.checked(['cp', file, `${c.client}:/tmp/l3-private.json`]);
  const output = await c.exec(c.client, ['php', '/tmp/l3-http.php', mode]);
  const lines = output.split('\n').filter(line => line.startsWith('MCP_L3_HTTP='));
  if (lines.length !== 1) throw new Error(`Incomplete L3 HTTP probe: ${mode}`);
  const result = JSON.parse(lines[0].slice('MCP_L3_HTTP='.length));
  if (result.mode !== mode || !Array.isArray(result.checks) || !result.checks.length) throw new Error('Empty L3 HTTP assertions');
  await c.checked(['cp', `${c.client}:/tmp/l3-http-output.json`, file]);
  if (await sha256(file) !== result.oracleSha256) throw new Error('L3 HTTP oracle transfer mismatch');
  await appPrivate(c, file);
  await c.record(`http-${mode}`, result);
}
async function downloadKit(c, options, name) {
  const directory = path.join(c.root, name);
  await fs.mkdir(directory, { mode: 0o700 });
  for (const file of ['manifest.json', ...Object.keys(c.manifest.files)]) {
    // Names have already passed the release manifest's allowlist, without user-controlled URLs.
    await c.exec(c.client, ['curl', '--fail', '--silent', '--show-error', '--cacert', '/tmp/ca.crt', '--max-time', '120', '-o', '/tmp/l3-download', `https://nc.fixture.test/source/${file}`]);
    await c.checked(['cp', `${c.client}:/tmp/l3-download`, path.join(directory, file)]);
  }
  await verifyKit(directory, options['manifest-sha256']);
  const target = path.join(directory, 'BUILDING.md');
  const original = await fs.readFile(target);
  await fs.appendFile(target, '\nintentional integrity negative\n');
  let refused = false;
  try { await verifyKit(directory, options['manifest-sha256']); } catch { refused = true; }
  await fs.writeFile(target, original);
  if (!refused) throw new Error('Changed source download was accepted');
  await verifyKit(directory, options['manifest-sha256']);
  await c.record(name, { artifacts: 1 + Object.keys(c.manifest.files).length, credentials: 'none', manifestSha256: options['manifest-sha256'], changedDownloadRejected: true });
  await fs.rm(directory, { recursive: true });
}
async function nativeSuite(c, options) {
  await prepareSuite(c, options);
  const config = '/config/nginx/site-confs/default.conf';
  const original = await c.exec(c.app, ['cat', config]);
  const row = JSON.parse(await c.checked(['inspect', c.app]))[0];
  const ip = Object.values(row.NetworkSettings.Networks)[0].IPAddress;
  if (!isIPv4(ip) || !/^map \$remote_addr \$mcp_admitted \{ default 0; [\d.]+ 1; \}\n/.test(original)) throw new Error('Unexpected fixture ACL');
  const extended = original.replace(' 1; }', ` 1; ${ip} 1; }`);
  const temporary = path.join(c.root, 'native-test-nginx.conf');
  await c.close();
  await fs.writeFile(temporary, extended, { mode: 0o644 });
  await c.checked(['cp', temporary, `${c.app}:${config}`]);
  await c.exec(c.app, ['nginx', '-t']);
  await c.exec(c.app, ['nginx', '-s', 'reload']);
  await open(c);
  const output = await c.rawExec(c.app, ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'env',
    'PHP_INI_SCAN_DIR=:/config/php:/mcp-it-tools/config', 'NEXTCLOUD_ROOT=/app/www/public', 'MCP_IT_NC=35', 'MCP_IT_DB=pgsql', 'MCP_IT_PROFILE=linuxserver', 'MCP_LINUXSERVER_TESTS=1',
    'php', '-d', 'curl.cainfo=/config/tls/ca.crt', '-d', 'memory_limit=1G', '/mcp-it-tools/phpunit.phar', '-c', '/fixture-probes/l3-tests.xml', '--fail-on-empty-test-suite', '--log-junit', '/tmp/l3-junit.xml']);
  await fs.writeFile(path.join(c.root, 'native-tests.log'), output.out + output.err);
  await c.close();
  await fs.writeFile(temporary, original, { mode: 0o644 });
  await c.checked(['cp', temporary, `${c.app}:${config}`]);
  await c.exec(c.app, ['nginx', '-t']);
  await c.exec(c.app, ['nginx', '-s', 'reload']);
  if (output.code) throw new Error(`Native candidate suite failed (${output.code}); see private native-tests.log`);
  await c.checked(['cp', `${c.app}:/tmp/l3-junit.xml`, path.join(c.root, 'native-junit.xml')]);
  await fs.chmod(path.join(c.root, 'native-junit.xml'), 0o600);
  const parsed = await command('python3', ['-B', path.join(here, 'junit.py'), path.join(c.root, 'native-junit.xml')]);
  if (parsed.code) throw new Error('Native JUnit incomplete, failed or skipped');
  await c.record('native-candidate-suite', JSON.parse(parsed.out));
  await open(c);
}
export async function executeL3(c, options) {
  const oracle = path.join(c.root, 'private/B1');
  await fs.mkdir(oracle, { mode: 0o700 });
  const privateFile = path.join(oracle, 'expected.json');
  await state(c, 'before');
  await c.checked(['cp', `${c.app}:/tmp/l3-before.json`, path.join(oracle, 'pre-upgrade.json')]);
  await replace(c, options);
  await state(c, 'after');
  await c.checked(['cp', `${c.app}:/tmp/l2-private.json`, privateFile]);
  for (const name of ['l3-http.php', 'l3-probe-support.php']) await c.checked(['cp', path.join(c.root, 'probes', name), `${c.client}:/tmp/${name}`]);
  await open(c);
  await http(c, 'preserved', privateFile);
  // Fixture creation must keep the HTTP-updated pending-plan history.
  await c.checked(['cp', privateFile, `${c.app}:/tmp/l2-private.json`]);
  await c.exec(c.app, ['chown', 'abc:abc', '/tmp/l2-private.json']);
  await state(c, 'fixtures');
  await c.checked(['cp', `${c.app}:/tmp/l3-private.json`, privateFile]);
  await restartClosed(c); await open(c);
  await http(c, 'source', privateFile);
  await downloadKit(c, options, 'source-kit-download');
  // The frozen admin suite owns the default backup policy and deletes it in teardown.
  const seededPolicy = c.profile.baseline.version === '1.2.1';
  if (seededPolicy) await state(c, 'suite-backup-save');
  try { await nativeSuite(c, options); }
  finally { if (seededPolicy) await state(c, 'suite-backup-restore'); }
  await state(c, 'reset-visibility');
  await http(c, 'backup-configure', privateFile);
  for (const mode of ['permissions-denied', 'permissions-allowed']) {
    await state(c, mode); await http(c, mode, privateFile);
  }
  for (const mode of ['offer-missing', 'offer-mismatch']) {
    await state(c, mode); await restartClosed(c); await open(c); await http(c, mode, privateFile);
  }
  await state(c, 'offer-valid'); await restartClosed(c); await open(c);
  await state(c, 'disconnect'); await http(c, 'disconnected', privateFile);
  await state(c, 'reconnect');
  await c.close(); await occ(c, 'maintenance:mode', '--on');
  await state(c, 'persist-before');
  const persisted = path.join(oracle, 'pre-recreation.json');
  await c.checked(['cp', `${c.app}:/tmp/l3-persist.json`, persisted]);
  await restartClosed(c, true);
  await identity(c, options, 'recreated-candidate');
  await c.checked(['cp', persisted, `${c.app}:/tmp/l3-persist.json`]);
  await c.exec(c.app, ['chown', 'abc:abc', '/tmp/l3-persist.json']);
  await appPrivate(c, privateFile);
  await state(c, 'persist-after');
  await open(c);
  await http(c, 'final', privateFile);
  await downloadKit(c, options, 'recreated-source-kit-download');
  await c.close();
  await state(c, 'final');
  await c.checked(['cp', `${c.app}:/tmp/l3-private.json`, privateFile]);
  const expected = JSON.parse(await fs.readFile(privateFile, 'utf8'));
  const checkpoint = await capture(c, 'B1', expected, oracle);
  const trust = JSON.parse(await fs.readFile(path.join(c.root, 'B0-trust.json'), 'utf8'));
  await verifyCheckpoint(path.join(c.root, 'B0'), trust.generation, trust.manifestSha256);
  await c.record('B0-unchanged-after-upgrade');
  return checkpoint;
}
