// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { command } from './command.mjs';
import { emptyCronTablesCommand } from './fixture.mjs';
import { here } from './inputs.mjs';
import { checkedFile } from '../app-archive.mjs';
import { verifyInstalled, verifyKit } from '../../../../scripts/release/integrity.mjs';
export const nativePhp = ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'php'];
export const occ = (c, ...args) => c.exec(c.app, [...nativePhp, '/app/www/public/occ', ...args]);
export async function state(c, mode) {
  const output = await c.exec(c.app, [...nativePhp, '/fixture-probes/l3-state.php', mode]);
  const lines = output.split('\n').filter(line => line.startsWith('MCP_L3_STATE='));
  if (lines.length !== 1) throw new Error('L3 state probe incomplete');
  await c.record(`candidate-${mode}`, JSON.parse(lines[0].slice('MCP_L3_STATE='.length)));
}
export async function restartClosed(c, recreate = false) {
  await c.close();
  if (recreate) await c.recreateApp(); else await c.checked(['restart', c.app]);
  await c.wait('closed candidate Nginx', async () => {
    const r = await c.rawExec(c.client, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
    return !r.code && r.out === '503';
  });
  await c.exec(c.app, ['s6-rc', '-d', 'change', 'svc-cron']);
  await c.exec(c.app, ['sh', '-c', '! pgrep -x crond && ! pgrep -x cron']);
  await c.exec(c.app, ['sh', '-c', emptyCronTablesCommand]);
  if (recreate) await c.record('recreated-writer-gate', { effectiveCronTables: 'empty before startup', cron: 'stopped', admission: 'closed' });
}
export async function identity(c, options, name) {
  const installed = path.join(c.root, name);
  await c.checked(['cp', `${c.app}:/config/www/nextcloud/apps/mcp`, installed]);
  await verifyInstalled(options.kit, options['manifest-sha256'], installed);
  const changed = path.join(installed, 'appinfo/info.xml');
  const original = await fs.readFile(changed);
  await fs.appendFile(changed, '\n<!-- intentional snapshot tamper -->\n');
  let refused = false;
  try { await verifyInstalled(options.kit, options['manifest-sha256'], installed); } catch { refused = true; }
  await fs.writeFile(changed, original);
  if (!refused) throw new Error('Changed installed snapshot was accepted');
  await verifyInstalled(options.kit, options['manifest-sha256'], installed);
  await fs.rm(installed, { recursive: true });
  const version = (await occ(c, 'config:app:get', 'mcp', 'installed_version')).trim();
  if (version !== '2.0.0') throw new Error('Candidate version mismatch');
  const native = await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', 'integrity:check-app', 'mcp']);
  // An unsigned fork has no publisher signature. This never waives a file mismatch.
  if (native.code !== 0 || !(native.out + native.err).includes('mcp: App signature not found, skipping app integrity check')) throw new Error('Unexpected native integrity result');
  await c.record(name, { installedBytes: 'verified', changedSnapshotRejected: true, version, nativeIntegrity: { code: native.code, output: (native.out + native.err).trim() } });
}
export async function replace(c, options) {
  await verifyKit(options.kit, options['manifest-sha256']);
  const stage = path.join(c.root, 'candidate-stage');
  const archive = path.join(c.root, 'source', `mcp-${c.manifest.version}.tar.gz`);
  const extracted = await command('python3', [path.join(here, 'extract.py'), archive, stage, 'mcp']);
  if (extracted.code) throw new Error('Candidate extraction failed');
  await verifyInstalled(options.kit, options['manifest-sha256'], path.join(stage, 'mcp'));
  await c.checked(['cp', path.join(stage, 'mcp'), `${c.app}:/config/mcp-l3-candidate`]);
  await c.exec(c.app, ['chown', '-R', 'abc:abc', '/config/mcp-l3-candidate']);
  await c.exec(c.app, ['sh', '-c', 'test -f /config/mcp-l1-closed && test ! -e /config/mcp-l3-baseline && mv /config/www/nextcloud/apps/mcp /config/mcp-l3-baseline && mv /config/mcp-l3-candidate /config/www/nextcloud/apps/mcp']);
  const output = await occ(c, 'upgrade', '--no-interaction');
  const status = JSON.parse(await occ(c, 'status', '--output=json'));
  if (status.needsDbUpgrade || status.versionstring !== c.profile.nextcloudVersion || (await occ(c, 'config:app:get', 'mcp', 'installed_version')).trim() !== '2.0.0') throw new Error('Native upgrade incomplete');
  c.markUpgrade();
  await c.exec(c.app, ['rm', '-rf', '/config/mcp-l3-baseline']);
  await c.record('native-upgrade', { output: output.trim(), core: status.versionstring, mcp: '2.0.0' });
  await restartClosed(c);
  await identity(c, options, 'installed-candidate');
}
export async function prepareTestInput(c, options) {
  const phar = path.join(options.inputs, 'phpunit.phar');
  await checkedFile(phar, path.join(c.root, 'phpunit.phar'), 'ce85745a2ec7d8e536621ebed69dfa36a8d504daf73be6750e93bb8c5b088835', (await fs.stat(phar)).size);
  const tokenizer = path.join(options.inputs, 'php85-tokenizer-8.5.12-r0.apk');
  await checkedFile(tokenizer, path.join(c.root, 'tokenizer.apk'), '5020af709d77e877db0bd5ed4b3fa3fa8eed037bfbcc4e5852aa5a72390accd1', (await fs.stat(tokenizer)).size);
}
export async function prepareSuite(c) {
  await c.exec(c.app, ['mkdir', '-p', '/mcp-it-tools/config', '/mcp-it-tools/tokenizer']);
  await c.checked(['cp', path.join(c.root, 'tokenizer.apk'), `${c.app}:/mcp-it-tools/tokenizer.apk`]);
  await c.exec(c.app, ['apk', '--no-network', 'verify', '/mcp-it-tools/tokenizer.apk']);
  await c.exec(c.app, ['apk', '--no-network', 'extract', '--destination', '/mcp-it-tools/tokenizer', '/mcp-it-tools/tokenizer.apk']);
  const ini = path.join(c.root, 'test-tokenizer.ini');
  await fs.writeFile(ini, 'extension=/mcp-it-tools/tokenizer/usr/lib/php85/modules/tokenizer.so\n');
  await c.checked(['cp', ini, `${c.app}:/mcp-it-tools/config/tokenizer.ini`]);
  await c.exec(c.app, ['chmod', '644', '/mcp-it-tools/config/tokenizer.ini']);
  await c.exec(c.app, [...nativePhp, '-d', 'extension=/mcp-it-tools/tokenizer/usr/lib/php85/modules/tokenizer.so', '-r', 'if (!extension_loaded("tokenizer") || PHP_VERSION !== "8.5.12") exit(1);']);
  await c.record('native-test-tokenizer', { version: '8.5.12-r0', sha256: '5020af709d77e877db0bd5ed4b3fa3fa8eed037bfbcc4e5852aa5a72390accd1', alpineSignature: 'verified', scope: 'test CLI only; no apk installation or FPM change' });
  await c.checked(['cp', path.join(c.root, 'phpunit.phar'), `${c.app}:/mcp-it-tools/phpunit.phar`]);
  await c.exec(c.app, ['chmod', '644', '/mcp-it-tools/phpunit.phar']);
  await c.exec(c.app, ['mkdir', '-p', '/fixture-tests/mcp/tests/Support/Access']);
  await c.checked(['cp', path.resolve(here, '../..'), `${c.app}:/fixture-tests/mcp/tests/Integration`]);
  await c.checked(['cp', path.resolve(here, '../../../Support/Access/Policy'), `${c.app}:/fixture-tests/mcp/tests/Support/Access/Policy`]);
}
