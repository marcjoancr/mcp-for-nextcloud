// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { sha256 } from './checkpoint.mjs';
import { capture } from './capture.mjs';
import { credentialWindow, validateRestore } from './restore-inputs.mjs';
import { nativePhp, occ, restartClosed } from './l3-lifecycle.mjs';
import { verifyKit, verifyInstalled } from '../../../../scripts/release/integrity.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';

const transfer = async (c, file, container, destination) => {
  await c.checked(['cp', file, `${container}:${destination}`]);
  if (container === c.app) await c.exec(c.app, ['chown', 'abc:abc', destination]);
};
async function native(c, mode) {
  const output = await c.exec(c.app, [...nativePhp, '/fixture-probes/l4-state.php', mode]);
  const lines = output.split('\n').filter(line => line.startsWith('MCP_L4_STATE='));
  if (lines.length !== 1) throw new Error('L4 state probe incomplete');
  await c.record(`recovery-${mode}`, JSON.parse(lines[0].slice('MCP_L4_STATE='.length)));
}
async function readPrivate(c, destination) {
  await c.checked(['cp', `${c.app}:/tmp/l4-private.json`, destination]);
  await fs.chmod(destination, 0o600);
  return JSON.parse(await fs.readFile(destination, 'utf8'));
}
async function http(c, mode, oracle) {
  await transfer(c, oracle, c.client, '/tmp/l4-private.json');
  const output = await c.exec(c.client, ['php', '/tmp/l4-http.php', mode]);
  const lines = output.split('\n').filter(line => line.startsWith('MCP_L4_HTTP='));
  if (lines.length !== 1) throw new Error('L4 HTTP probe incomplete');
  const result = JSON.parse(lines[0].slice('MCP_L4_HTTP='.length));
  await c.record(`recovery-http-${mode}`, result);
  return result;
}
async function failureBoundary(c, options, afterUpgrade) {
  await verifyKit(options.kit, options['manifest-sha256']);
  const stage = path.join(c.root, `failure-stage-${afterUpgrade ? 'upgraded' : 'replaced'}`);
  const extracted = await command('python3', [path.join(here, 'extract.py'), path.join(c.root, 'source', 'mcp-2.0.0.tar.gz'), stage, 'mcp']);
  if (extracted.code) throw new Error('Failure boundary candidate extraction failed');
  await verifyInstalled(options.kit, options['manifest-sha256'], path.join(stage, 'mcp'));
  await c.checked(['cp', path.join(stage, 'mcp'), `${c.app}:/config/mcp-l4-candidate`]);
  await c.exec(c.app, ['chown', '-R', 'abc:abc', '/config/mcp-l4-candidate']);
  await c.exec(c.app, ['sh', '-c', 'test -f /config/mcp-l1-closed && test ! -e /config/mcp-l4-baseline && mv /config/www/nextcloud/apps/mcp /config/mcp-l4-baseline && mv /config/mcp-l4-candidate /config/www/nextcloud/apps/mcp']);
  if (afterUpgrade) {
    await occ(c, 'upgrade', '--no-interaction');
    if ((await occ(c, 'config:app:get', 'mcp', 'installed_version')).trim() !== '2.0.0') throw new Error('Failure point did not reach completed upgrade');
    c.markUpgrade();
  } else {
    const version = (await c.exec(c.postgres, ['psql', '-U', 'nextcloud', '-d', 'nextcloud', '-Atc', "SELECT configvalue FROM oc_appconfig WHERE appid='mcp' AND configkey='installed_version'"])).trim();
    if (version !== '1.2.0') throw new Error('Replacement boundary database changed');
  }
  const gate = await c.rawExec(c.client, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
  if (gate.code || gate.out !== '503') throw new Error('Failure boundary admitted HTTP');
  await c.record('injected-upgrade-stop', { boundary: afterUpgrade ? 'after-occ-upgrade-before-reopening' : 'after-app-replacement', databaseVersion: afterUpgrade ? '2.0.0' : '1.2.0', appVersion: '2.0.0', http: 503, sqlMigrationInterruption: false });
}

export async function executeL4(c, options, originals) {
  const cases = [];
  for (const name of ['l4-http.php', 'ProbeCompletion.php', 'tool-response.php', 'http-client.php', 'l3-probe-support.php']) await c.checked(['cp', path.join(c.root, 'probes', name), `${c.client}:/tmp/${name}`]);
  for (const [label, base] of [['r1', 'B1'], ['r0-replaced', 'B0'], ['r0-upgraded', 'B0']]) {
    const initial = await c.restore(path.join(c.root, base), originals[base]);
    const privateDirectory = path.join(c.root, 'private', label);
    await fs.mkdir(privateDirectory, { mode: 0o700 });
    const expectedFile = path.join(privateDirectory, 'expected.json');
    await transfer(c, path.join(initial.oracle, 'expected.json'), c.app, '/tmp/l4-private.json');
    await native(c, 'verify');
    await occ(c, 'maintenance:mode', '--off');
    // Only synthetic OAuth issuance is admitted; HTTP remains gated while refreshing.
    await native(c, 'refresh');
    let expected = await readPrivate(c, expectedFile);
    credentialWindow(expected.users);
    const name = `${base}-${label}`;
    const trust = await capture(c, name, expected, privateDirectory);
    c.retainCheckpoint(name, trust);
    await restartClosed(c);
    await native(c, 'mutate');
    const postFile = path.join(privateDirectory, 'post.json');
    const post = await readPrivate(c, postFile);
    if (base === 'B0') await failureBoundary(c, options, label === 'r0-upgraded');
    credentialWindow(expected.users); credentialWindow(post.users);
    const restored = await c.restore(path.join(c.root, name), trust);
    await transfer(c, expectedFile, c.app, '/tmp/l4-private.json');
    await native(c, 'verify');
    credentialWindow(expected.users);
    await native(c, 'cut');
    await occ(c, 'maintenance:data-fingerprint');
    await native(c, 'fingerprint');
    await occ(c, 'maintenance:mode', '--off');
    await c.exec(c.app, ['rm', '/config/mcp-l1-closed']);
    const working = path.join(privateDirectory, 'working.json');
    await readPrivate(c, working);
    await http(c, 'closed', working);
    await native(c, 'enable');
    await http(c, 'disconnected', working);
    await native(c, 'reconnect');
    expected = await readPrivate(c, working);
    expected.postSnapshotUsers = post.postSnapshotUsers;
    await fs.writeFile(working, JSON.stringify(expected), { mode: 0o600 });
    const proof = await http(c, 'reconnected', working);
    const denied = await c.rawExec(c.denied, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
    if (denied.code || denied.out !== '403') throw new Error('Unadmitted client reached recovered fixture');
    if (base === 'B1') {
      for (const file of ['manifest.json', ...Object.keys(c.manifest.files)]) {
        await c.exec(c.client, ['curl', '--fail', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '30', '-o', '/tmp/l4-source-download', `https://nc.fixture.test/source/${file}`]);
        if ((await c.exec(c.client, ['sha256sum', '/tmp/l4-source-download'])).split(/\s/)[0] !== await sha256(path.join(c.root, 'source', file))) throw new Error('Restored source offer differs');
      }
      await c.exec(c.client, ['rm', '/tmp/l4-source-download']);
      await c.record('restored-source-offer', { files: 5, hashes: 'matched', tls: 'private CA verified' });
    }
    await c.close();
    await native(c, 'close');
    await occ(c, 'maintenance:mode', '--on');
    await validateRestore(c, path.join(c.root, name), trust);
    cases.push({ name, ...trust, ...restored.details, httpAssertions: proof.checks.length, minimumCredentialMarginSeconds: proof.minimumCredentialMarginSeconds });
    await c.record(`${name}-recovered-and-closed`, cases.at(-1));
  }
  // Failed imports never start the normal app process; no partial database may serve HTTP.
  for (const fault of ['partial-database', 'missing-s3', 'unavailable-storage']) {
    let rejected = false;
    try { await c.restore(path.join(c.root, 'B1'), originals.B1, fault); } catch (error) {
      if (c.restoreProgress !== `${fault}-validation`) throw error;
      rejected = true; await c.record(`negative-${fault}`, { rejected: true, reason: error.message }); }
    if (!rejected) throw new Error(`Restore fault accepted: ${fault}`);
    const check = await c.rawExec(c.app, ['sh', '-c', 'test -f /config/mcp-l1-closed && ! pgrep -x nginx && ! pgrep -f "^php-fpm" && ! pgrep -x crond']);
    if (check.code) throw new Error('Partial restore started a serving process');
    const http = await c.rawExec(c.client, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
    if (http.code !== 7 || http.out !== '000') throw new Error('Partial restore is not closed');
    await c.record(`negative-${fault}-closed`, { nginx: 'not started', http: 'connection refused', exit: 7 });
  }
  for (const name of ['B0', 'B1']) {
    await validateRestore(c, path.join(c.root, name), originals[name]);
    await validateRestore({ ...c, root: options.checkpoints }, path.join(options.checkpoints, name), originals[name]);
  }
  await c.record('original-checkpoints-unchanged');
  return { cases, negatives: ['partial-database', 'missing-s3', 'unavailable-storage'], L5: 'not started' };
}
