// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import { allocations, assertOwned, assertImage, assertDisk, completeProbe } from './safety.mjs';
const limits = { cpus: 4, memoryMiB: 8192, diskBudgetGiB: 30, minimumFreeGiB: 40 };
test('aggregate service and client allocations obey the selected limits', () => {
  const roles = allocations(limits);
  assert.ok(Object.values(roles).reduce((n, r) => n + r.cpus, 0) <= 4);
  assert.ok(Object.values(roles).reduce((n, r) => n + r.memoryMiB, 0) <= 8192);
  assert.throws(() => allocations({ ...limits, cpus: 1 }));
});
test('cleanup refuses unknown identity, ownership and malformed inspection', () => {
  const row = { Id: 'abc', Config: { Labels: { 'mcp.linuxserver.run': 'run1' } } };
  assert.doesNotThrow(() => assertOwned('container', 'abc', row, 'run1'));
  for (const value of [null, {}, { ...row, Id: 'other' }, { ...row, Config: { Labels: {} } }]) {
    assert.throws(() => assertOwned('container', 'abc', value, 'run1'));
  }
  assert.throws(() => assertOwned('container', 'abc', row, 'other'));
  assert.doesNotThrow(() => assertOwned('volume', 'v1', { Name: 'v1', Labels: { 'mcp.linuxserver.run': 'run1' } }, 'run1'));
});
test('runtime images require the exact digest and platform', () => {
  const pin = { reference: 'registry/image:v1@sha256:123' };
  const image = { Os: 'linux', Architecture: 'amd64', RepoDigests: ['registry/image@sha256:123'] };
  assert.doesNotThrow(() => assertImage(pin, image));
  assert.throws(() => assertImage(pin, { ...image, Architecture: 'arm64' }));
  assert.throws(() => assertImage(pin, { ...image, RepoDigests: [] }));
});
test('disk pressure and missing probe completion cannot pass', () => {
  assert.doesNotThrow(() => assertDisk(41 * 2**30, 2**30, limits));
  assert.throws(() => assertDisk(39 * 2**30, 0, limits));
  assert.throws(() => assertDisk(41 * 2**30, 31 * 2**30, limits));
  assert.throws(() => completeProbe('PHP handler exited zero'));
  assert.throws(() => completeProbe('MCP_L1_COMPLETE={"ok":false}'));
  assert.deepEqual(completeProbe('MCP_L1_COMPLETE={"ok":true,"checks":3}\n'), { ok: true, checks: 3 });
});

test('cleanup inspects ownership before deletion and reports every failure', async () => {
  const { Resources } = await import('./resources.mjs');
  const calls = [];
  const command = async (args) => {
    calls.push(args);
    if (args.includes('inspect')) return { code: 0, out: JSON.stringify([{ Id: 'owned', Config: { Labels: { 'mcp.linuxserver.run': 'test' } } }]) };
    if (args.includes('rm')) return { code: 1, out: '' };
    return { code: 0, out: '' };
  };
  const resources = new Resources('test', command, async () => {});
  resources.items.push({ kind: 'container', id: 'owned' }, { kind: 'container', id: 'foreign' });
  await assert.rejects(resources.cleanup());
  assert.equal(calls.filter(args => args.includes('rm')).length, 1);
  assert.ok(calls.find(args => args.includes('rm')).includes('owned'));
});

test('private network selection avoids both broad routes and Docker subnets', async () => {
  const { chooseSubnet } = await import('./network.mjs');
  assert.equal(chooseSubnet(['10.250.0.0/24', '10.250.1.1/32'], 0), '10.250.2.0/24');
  assert.throws(() => chooseSubnet(['10.0.0.0/8'], 0));
  assert.throws(() => chooseSubnet(['invalid'], 0));
});

test('resource query failures never authorize deletion or report clean', async () => {
  const { Resources } = await import('./resources.mjs');
  const calls = [];
  const resources = new Resources('test', async args => { calls.push(args); return { code: 1, out: '' }; }, async () => {});
  resources.items.push({ kind: 'volume', id: 'owned' });
  await assert.rejects(resources.cleanup(), /Cleanup incomplete/);
  assert.equal(calls.filter(args => args.includes('rm')).length, 0);
});

test('timed-out commands and aborted execution fail explicitly', async () => {
  const { command } = await import('./command.mjs');
  assert.equal((await command(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], { timeout: 30 })).code, 124);
  const controller = new AbortController();
  const pending = command(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], { signal: controller.signal });
  controller.abort();
  await assert.rejects(pending, /abort/i);
});

test('archive extraction rejects traversal and links before writing anything', async () => {
  const fs = await import('node:fs/promises'), os = await import('node:os'), path = await import('node:path');
  const { command } = await import('./command.mjs');
  const { here } = await import('./inputs.mjs');
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-l1-archive-'));
  try {
    for (const kind of ['traversal', 'symlink', 'hardlink']) {
      const code = `import tarfile,sys\np=sys.argv[1]\nwith tarfile.open(p,'w:gz') as t:\n m=tarfile.TarInfo('mcp/../../outside' if sys.argv[2]=='traversal' else 'mcp/link')\n if sys.argv[2]!='traversal':\n  m.type=tarfile.SYMTYPE if sys.argv[2]=='symlink' else tarfile.LNKTYPE\n  m.linkname='/etc/passwd'\n t.addfile(m)\n`;
      assert.equal((await command('python3', ['-c', code, path.join(root, 'bad.tar.gz'), kind])).code, 0);
      assert.notEqual((await command('python3', [path.join(here, 'extract.py'), path.join(root, 'bad.tar.gz'), path.join(root, 'out'), 'mcp'])).code, 0);
      await assert.rejects(fs.stat(path.join(root, 'out')), { code: 'ENOENT' });
    }
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('negative native assertions reject timeouts and Docker failures', async () => {
  const { rejectedProbe } = await import('./safety.mjs');
  const expected = { code: 1, out: 'MCP_L1_REJECTED={"reason":"missing-env"}\n', err: '' };
  assert.doesNotThrow(() => rejectedProbe(expected, 'missing-env'));
  for (const code of [0, 124, 137, 143]) assert.throws(() => rejectedProbe({ ...expected, code }, 'missing-env'));
  assert.throws(() => rejectedProbe({ code: 1, out: '', err: 'Docker unavailable' }, 'missing-env'));
  assert.throws(() => rejectedProbe(expected, 'wrong-path'));
});

test('cleanup remains non-qualifying after interruption', async () => {
  const { finalStatus } = await import('./safety.mjs');
  assert.equal(finalStatus(false, true, true), 'failed');
  assert.equal(finalStatus(false, false, true), 'passed');
  assert.equal(finalStatus(true, false, true), 'failed');
  assert.equal(finalStatus(false, false, false), 'failed');
});

test('FIFO inputs are rejected before a blocking open', async () => {
  const fs = await import('node:fs/promises'), os = await import('node:os'), path = await import('node:path');
  const { command } = await import('./command.mjs');
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-l1-fifo-'));
  try {
    assert.equal((await command('mkfifo', [path.join(root, 'input')])).code, 0);
    const code = `import { checkedFile } from ${JSON.stringify(new URL('../app-archive.mjs', import.meta.url).href)}; try { await checkedFile(process.argv[1], process.argv[2], '0'.repeat(64), 0); process.exitCode=2; } catch { console.log('rejected'); }`;
    const result = await command(process.execPath, ['--input-type=module', '-e', code, path.join(root, 'input'), path.join(root, 'out')], { timeout: 2000 });
    assert.equal(result.code, 0);
    assert.equal(result.out.trim(), 'rejected');
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('native execution refuses remote Docker endpoints', async () => {
  const { assertLocalDocker } = await import('./safety.mjs');
  assert.doesNotThrow(() => assertLocalDocker({}, 'unix:///var/run/docker.sock'));
  assert.doesNotThrow(() => assertLocalDocker({ DOCKER_HOST: 'unix:///run/user/1000/docker.sock' }, 'ssh://ignored'));
  assert.throws(() => assertLocalDocker({}, 'ssh://production'));
  assert.throws(() => assertLocalDocker({ DOCKER_HOST: 'tcp://remote:2376' }, 'unix:///var/run/docker.sock'));
  assert.throws(() => assertLocalDocker({ DOCKER_HOST: 'unix:///var/run/docker.sock', DOCKER_CONTEXT: 'remote' }, 'ssh://production'));
});

test('failed preflight never contacts a daemon through empty cleanup', async () => {
  const { Resources } = await import('./resources.mjs');
  const calls = [];
  const resources = new Resources('test', async args => { calls.push(args); return { code: 0, out: '' }; }, async () => {});
  await resources.cleanup();
  assert.deepEqual(calls, []);
});

test('diagnostic collection failure cannot skip owned-resource cleanup', async () => {
  const { Resources } = await import('./resources.mjs');
  const calls = [];
  const resources = new Resources('test', async args => {
    calls.push(args);
    if (args.includes('inspect')) return { code: 0, out: JSON.stringify([{ Name: 'owned', Labels: { 'mcp.linuxserver.run': 'test' } }]) };
    return { code: 0, out: '' };
  }, async () => {});
  resources.items.push({ kind: 'volume', id: 'owned' });
  const error = new Error('Diagnostic capture failed');
  assert.equal(await resources.cleanupAfter(async () => { throw error; }), error);
  assert.ok(calls.some(args => args[0] === 'volume' && args[1] === 'rm' && args[2] === 'owned'));
  assert.equal(calls.filter(args => args.includes('ls')).length, 3);
});

test('volume accounting retries concurrent file removal but never accepts a failed scan', async () => {
  const { measureVolume } = await import('./budget.mjs');
  let calls = 0;
  assert.equal(await measureVolume(async () => ++calls === 1 ? { code: 1, out: '100 /config\n' } : { code: 0, out: '101 /config\n2 /data\n' }, async () => true), 103 * 1024);
  assert.equal(calls, 2);
  calls = 0;
  await assert.rejects(measureVolume(async () => { calls++; return { code: 1, out: '100 /config\n' }; }, async () => true), /Cannot measure/);
  assert.equal(calls, 3);
  calls = 0;
  await assert.rejects(measureVolume(async () => ++calls === 1 ? { code: 124, out: '' } : { code: 0, out: '10 /data\n' }, async () => true), /Cannot measure/);
  assert.equal(calls, 1);
  assert.equal(await measureVolume(async () => ({ code: 1, out: '' }), async () => false), null);
  await assert.rejects(measureVolume(async () => ({ code: 0, out: '' }), async () => true));
});

test('owned app replacement journals retirement and never deletes an unowned container', async () => {
  const { Resources } = await import('./resources.mjs');
  const calls = [], persisted = [];
  const resources = new Resources('run', async args => {
    calls.push(args);
    if (args.includes('inspect')) return { code: 0, out: JSON.stringify([{ Id: 'app', Config: { Labels: { 'mcp.linuxserver.run': 'run' } } }]) };
    return { code: 0, out: '' };
  }, async items => persisted.push(structuredClone(items)));
  resources.items.push({ kind: 'container', id: 'app', name: 'owned-app' });
  await assert.rejects(resources.removeContainer('foreign'));
  assert.equal(calls.length, 0);
  await resources.removeContainer('app');
  assert.equal(persisted.at(-1)[0].removed, true);
  await resources.cleanup();
  assert.equal(calls.filter(args => args.includes('rm')).length, 1);
});

test('native-suite evidence rejects empty, skipped, failed and unasserted cases', async () => {
  const fs = await import('node:fs/promises'), os = await import('node:os'), path = await import('node:path');
  const { command } = await import('./command.mjs');
  const { here } = await import('./inputs.mjs');
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-l3-junit-'));
  try {
    for (const [body, valid] of [
      ['<testcase name="native" assertions="2"/>', true], ['', false],
      ['<testcase assertions="0"/>', false],
      ...['skipped', 'failure', 'error'].map(tag => [`<testcase assertions="1"><${tag}/></testcase>`, false]),
    ]) {
      const file = path.join(root, 'junit.xml');
      await fs.writeFile(file, `<testsuites><testsuite>${body}</testsuite></testsuites>`);
      const result = await command('python3', ['-B', path.join(here, 'junit.py'), file]);
      assert.equal(result.code === 0, valid, result.err);
    }
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('LinuxServer HTTP profile refuses destinations outside its private origin', async () => {
  const { command } = await import('./command.mjs');
  const { here } = await import('./inputs.mjs');
  const path = await import('node:path');
  const code = "require $argv[1]; try { \\OCA\\Mcp\\Tests\\Integration\\Support\\Http::request('GET', $argv[2]); exit(1); } catch (RuntimeException $e) { exit($e->getMessage() === 'Unexpected native test destination' ? 0 : 2); }";
  for (const url of ['https://example.invalid/', 'https://nc.fixture.test.evil.invalid/', 'http://nc.fixture.test/']) {
    const result = await command('php', ['-r', code, path.join(here, 'l3-test-http.php'), url]);
    assert.equal(result.code, 0, result.err);
  }
});

test('database snapshot compares actual PostgreSQL stream bytes across sessions', async () => {
  const { command } = await import('./command.mjs');
  const { here } = await import('./inputs.mjs');
  const path = await import('node:path');
  const code = `require $argv[1]; $a = fopen('php://memory','r+'); $b = fopen('php://memory','r+');
    fwrite($a, "BEGIN:VCARD\\r\\nFN:Unicode català\\r\\n"); fwrite($b, "BEGIN:VCARD\\r\\nFN:Unicode català\\r\\n");
    if (databaseValue($a) !== databaseValue($b)) exit(1);
    fwrite($b, 'changed'); if (databaseValue($a) === databaseValue($b)) exit(2);
    if (databaseValue(null) !== null || databaseValue(false) !== 'f' || databaseValue(12) !== '12') exit(3);`;
  const result = await command('php', ['-r', code, path.join(here, 'state-value.php')]);
  assert.equal(result.code, 0, result.err);
});

test('app identity permits physical aliases but rejects a distinct duplicate tree', async () => {
  const fs = await import('node:fs/promises'), os = await import('node:os'), path = await import('node:path');
  const { command } = await import('./command.mjs');
  const { here } = await import('./inputs.mjs');
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-l3-apps-'));
  try {
    await fs.mkdir(path.join(root, 'apps/mcp/appinfo'), { recursive: true });
    await fs.writeFile(path.join(root, 'apps/mcp/appinfo/info.xml'), '<info><id>mcp</id></info>');
    await fs.symlink(path.join(root, 'apps'), path.join(root, 'alias'));
    const code = "require $argv[1]; libxml_set_external_entity_loader(static fn () => null); assertSingleMcpTree([['path'=>$argv[2].'/apps'],['path'=>$argv[2].'/alias']], $argv[2].'/apps/mcp');";
    assert.equal((await command('php', ['-r', code, path.join(here, 'app-paths.php'), root])).code, 0);
    await fs.mkdir(path.join(root, 'apps/duplicate/appinfo'), { recursive: true });
    await fs.writeFile(path.join(root, 'apps/duplicate/appinfo/info.xml'), '<info><id>mcp</id></info>');
    assert.notEqual((await command('php', ['-r', code, path.join(here, 'app-paths.php'), root])).code, 0);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('app startup prepares effective empty cron tables first and refuses startup on copy failure', async () => {
  const { startPausedApp } = await import('./fixture.mjs');
  const calls = [];
  await startPausedApp(async args => { calls.push(args); }, '/run-owned', 'owned-app');
  assert.deepEqual(calls, [['cp', '/run-owned/config/crontabs/.', 'owned-app:/etc/crontabs'], ['start', 'owned-app']]);
  const failed = [];
  await assert.rejects(startPausedApp(async args => { failed.push(args); throw new Error('copy failed'); }, '/run-owned', 'owned-app'), /copy failed/);
  assert.equal(failed.length, 1);
  assert.equal(failed[0][0], 'cp');
});

test('effective cron-table guard rejects jobs and query failures', async () => {
  const { emptyCronTablesCommand } = await import('./fixture.mjs');
  const { command } = await import('./command.mjs');
  for (const [body, expected] of [['return 0', 0], ['echo "* * * * * job"; return 0', 1], ['return 1', 1]]) {
    const result = await command('sh', ['-c', `crontab() { ${body}; }; ${emptyCronTablesCommand}`]);
    assert.equal(result.code, expected);
  }
});
