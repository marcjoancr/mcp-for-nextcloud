// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { randomUUID } from 'node:crypto';
import { command } from './command.mjs';
import { SamplingGate } from './sampling.mjs';
import { measureVolume } from './budget.mjs';
import { Resources } from './resources.mjs';
import { allocations, assertDisk, assertImage, ownershipLabel, finalStatus, assertLocalDocker } from './safety.mjs';
import { here, prepareInputs } from './inputs.mjs';
import { fixture } from './fixture.mjs';
import { fixtureSubnet } from './network.mjs';
import { bootstrap, validateBaseline } from './validation.mjs';

export async function executeFixture({ options, profile, profileSha256, manifest }) {
  process.umask(0o077);
  const phase = options.phase;
  const runId = `mcp-${phase.toLowerCase()}-${randomUUID()}`;
  const root = path.resolve(here, `../../../../build/linuxserver-${phase.toLowerCase()}/runs`, runId);
  await fs.mkdir(root, { recursive: true, mode: 0o700 });
  const result = { schemaVersion: 1, runId, phase, scope: phase === 'L2' ? 'baseline-checkpoint' : 'baseline-bootstrap', status: 'running',
    profileSha256, baseline: profile.baseline, candidate: profile.candidate, upgradePerformed: false,
    checkpointsCreated: false, checks: [], cleanup: 'pending', pending: ['L2-L5', 'public-https', 'inspector', 'chatgpt', 'human-review'] };
  let secrets = {}, app, s3, postgres, interrupted = false, cleaning = false;
  const abort = new AbortController();
  const deadline = Date.now() + profile.limits.runSeconds * 1000;
  const roles = allocations(profile.limits);
  const logArgs = ['--log-driver', 'local', '--log-opt', 'max-size=5m', '--log-opt', 'max-file=2'];
  const redact = text => Object.values(secrets).reduce((value, secret) => value.replaceAll(secret, '[redacted]'), text);
  const docker = async args => {
    if (!cleaning && (interrupted || Date.now() >= deadline)) throw new Error(`${phase} interrupted or deadline exceeded`);
    const reply = await command('docker', args, { timeout: cleaning ? 30000 : Math.min(300000, Math.max(1, deadline - Date.now())), signal: cleaning ? undefined : abort.signal });
    if (args[0] === 'exec' && args.includes('abc')) await fs.appendFile(path.join(root, 'diagnostics.log'), `native: ${redact(reply.out + reply.err)}\n`);
    if (reply.code) await fs.appendFile(path.join(root, 'diagnostics.log'), `${args[0]} ${args[1]} exit=${reply.code}: ${redact(reply.err + reply.out)}\n`);
    return reply;
  };
  const owner = new Resources(runId, docker, items => fs.writeFile(path.join(root, 'resources.json'), JSON.stringify({ runId, items }, null, 2)));
  const sampling = new SamplingGate();
  const checked = async args => {
    const call = async () => (await owner.checked(args)).out;
    return !cleaning && ['start', 'stop', 'restart'].includes(args[0]) ? sampling.mutate(call) : call();
  };
  const exec = (id, args) => checked(['exec', id, ...args]);
  const rawExec = (id, args) => docker(['exec', id, ...args]);
  const wait = async (label, callback) => {
    const end = Math.min(deadline, Date.now() + profile.limits.readinessSeconds * 1000);
    while (Date.now() < end) {
      if (await callback()) return;
      await new Promise(resolve => setTimeout(resolve, 1000));
    }
    throw new Error(`Readiness deadline exceeded: ${label}`);
  };
  const record = async (name, details = true) => {
    result.checks.push({ name, details });
    await fs.writeFile(path.join(root, 'result.json'), JSON.stringify(result, null, 2));
    console.error(`${phase}: ${name}`);
  };
  const close = async () => { if (app) await checked(['cp', path.join(root, 'closed'), `${app}:/config/mcp-l1-closed`]); };
  const onSignal = () => { interrupted = true; abort.abort(); };
  process.on('SIGINT', onSignal); process.on('SIGTERM', onSignal);
  let failure, monitor, monitoring = false, budgetFailure;
  try {
    for (const tool of ['python3', 'openssl']) if ((await command(tool, [tool === 'openssl' ? 'version' : '--version'])).code) throw new Error(`Missing ${tool}`);
    const dockerContext = await command('docker', ['context', 'inspect']);
    if (dockerContext.code) throw new Error('Cannot inspect local Docker context');
    assertLocalDocker(process.env, JSON.parse(dockerContext.out)[0]?.Endpoints?.docker?.Host);
    const info = JSON.parse(await checked(['info', '--format', '{{json .}}']));
    if (info.OSType !== 'linux' || !['x86_64', 'amd64'].includes(info.Architecture)) throw new Error('Unsupported Docker host');
    const storage = await fs.statfs(root);
    assertDisk(storage.bavail * storage.bsize, 0, profile.limits);
    // Do not silently reuse or remove interrupted runs.
    for (const kind of ['container', 'network', 'volume']) {
      const leftovers = await checked([kind, 'ls', '-q', ...(kind === 'container' ? ['-a'] : []), '--filter', `label=${ownershipLabel}`]);
      if (leftovers.trim()) throw new Error('Labelled fixture resources already exist; audit them before running L1');
    }
    for (const [name, pin] of Object.entries(profile.images)) {
      const row = JSON.parse(await checked(['image', 'inspect', pin.reference]))[0];
      assertImage(pin, row);
      await record(`image-${name}`, { id: row.Id, reference: pin.reference, platform: 'linux/amd64' });
    }
    await prepareInputs(root, options, profile, manifest);
    await fs.mkdir(path.join(root, 'probes'), { mode: 0o755 });
    for (const name of ['probe.php', 'health.php', ...(phase === 'L2' ? ['l2-seed.php', 'l2-http.php', 'l2-s3.php', 's3-inventory.php', 'tool-response.php'] : [])]) await fs.copyFile(path.join(here, name), path.join(root, 'probes', name));
    await fs.copyFile(path.resolve(here, '../../Support/ProbeCompletion.php'), path.join(root, 'probes/ProbeCompletion.php'));
    await fs.writeFile(path.join(root, 'probes/apps.json'), JSON.stringify(Object.fromEntries([['mcp', profile.baseline.version], ...profile.apps.map(app => [app.id, app.version])])));
    for (const name of await fs.readdir(path.join(root, 'probes'))) await fs.chmod(path.join(root, 'probes', name), 0o644);
    await fs.writeFile(path.join(root, 'closed'), 'closed\n');
    const network = await owner.create('network', `${runId}-net`, ['--internal', '--subnet', await fixtureSubnet(checked)]);
    const volumes = {};
    for (const name of ['config', 'data', 'postgres', 's3']) volumes[name] = await owner.create('volume', `${runId}-${name}`, []);
    const create = async (role, image, args) => owner.create('container', `${runId}-${role}`, [
      '--platform', profile.platform, '--network', network, '--cpus', String(roles[role].cpus), '--memory', `${roles[role].memoryMiB}m`,
      '--memory-swap', `${roles[role].memoryMiB}m`, '--pids-limit', '512', ...logArgs, ...args, image,
    ]);
    const clientArgs = ['--tmpfs', '/config:rw,size=16m', '--tmpfs', '/data:rw,size=16m', '--entrypoint', '/bin/sleep'];
    // Docker create command arguments follow the image, so clients use a separate explicit helper.
    const createClient = async role => owner.create('container', `${runId}-${role}`, [
      '--platform', profile.platform, '--network', network, '--cpus', String(roles[role].cpus), '--memory', `${roles[role].memoryMiB}m`,
      '--memory-swap', `${roles[role].memoryMiB}m`, '--pids-limit', '64', ...logArgs, ...clientArgs, profile.images.nextcloud.reference, 'infinity',
    ]);
    const client = await createClient('client'), denied = await createClient('denied');
    await checked(['start', client, denied]);
    const clientRow = JSON.parse(await checked(['inspect', client]))[0];
    const clientIp = Object.values(clientRow.NetworkSettings.Networks)[0].IPAddress;
    secrets = await fixture(root, clientIp, profile);
    await checked(['cp', path.join(root, 'config/tls/ca.crt'), `${client}:/tmp/ca.crt`]);
    await checked(['cp', path.join(root, 'config/tls/ca.crt'), `${denied}:/tmp/ca.crt`]);
    postgres = await create('postgres', profile.images.postgres.reference, ['--network-alias', 'postgres', '--env-file', path.join(root, 'pg.env'), '-v', `${volumes.postgres}:/var/lib/postgresql/data`]);
    s3 = await owner.create('container', `${runId}-seaweedfs`, [
      '--platform', profile.platform, '--network', network, '--network-alias', 's3', '--cpus', String(roles.seaweedfs.cpus), '--memory', `${roles.seaweedfs.memoryMiB}m`,
      '--memory-swap', `${roles.seaweedfs.memoryMiB}m`, '--pids-limit', '256', ...logArgs, '--entrypoint', 'weed', '-v', `${volumes.s3}:/data`, '-v', `${root}/s3:/etc/seaweedfs:ro`,
      profile.images.seaweedfs.reference, 'server', '-dir=/data', '-master.dir=/data', '-ip=s3', '-ip.bind=0.0.0.0', '-filer', '-s3', '-s3.config=/etc/seaweedfs/s3.json',
      `-volume.max=${profile.objectStore.volumeCount}`, `-master.volumeSizeLimitMB=${profile.objectStore.volumeSizeLimitMiB}`, '-volume.preStopSeconds=1',
    ]);
    app = await create('app', profile.images.nextcloud.reference, ['--network-alias', 'nc.fixture.test', '--env-file', path.join(root, 'app.env'),
      '-v', `${volumes.config}:/config`, '-v', `${volumes.data}:/data`, '-v', `${root}/source:/fixture-source:ro`, '-v', `${root}/probes:/fixture-probes:ro`]);
    await checked(['cp', `${root}/config/.`, `${app}:/config`]);
    // This image imports /etc/crontabs directly; it has no /defaults/crontabs.
    await checked(['cp', `${root}/config/crontabs/.`, `${app}:/etc/crontabs`]);
    await checked(['start', postgres, s3, app]);
    if (JSON.parse(await checked(['network', 'inspect', network]))[0].Internal !== true) throw new Error('Fixture network permits egress');
    for (const id of [app, postgres, s3, client, denied]) {
      const row = JSON.parse(await checked(['container', 'inspect', id]))[0];
      if (Object.keys(row.NetworkSettings.Networks).length !== 1 || Object.keys(row.HostConfig.PortBindings ?? {}).length
        || row.Mounts.some(m => m.Destination.includes('docker.sock'))) throw new Error('Fixture isolation mismatch');
    }
    await record('isolated-topology', { containers: 5, volumes: 4, internal: true, publishedPorts: [] });
    const volumeBytes = new Map();
    const budget = async () => {
      const stat = await fs.statfs(root);
      const hostDu = await command('du', ['-sk', root]);
      if (hostDu.code) throw new Error('Cannot measure fixture disk usage');
      // Reserve the maximum bounded Docker logs as well as measured data.
      let used = 50 * 2**20 + Number(hostDu.out.split(/\s/)[0]) * 1024;
      for (const [id, dirs] of [[app, ['/config', '/data']], [postgres, ['/var/lib/postgresql/data']], [s3, ['/data']]]) {
        const row = JSON.parse(await checked(['inspect', '--size', id]))[0];
        used += row.SizeRw ?? 0;
        if (row.State.Running) {
          const measured = await measureVolume(
            () => rawExec(id, ['du', '-sk', ...dirs]),
            async () => JSON.parse(await checked(['inspect', id]))[0].State.Running,
          );
          if (measured !== null) volumeBytes.set(id, measured);
        }
        used += volumeBytes.get(id) ?? 0;
      }
      for (const id of [client, denied]) used += JSON.parse(await checked(['inspect', '--size', id]))[0].SizeRw ?? 0;
      const disk = await exec(client, ['df', '-Pk', '/']);
      const available = Number(disk.trim().split('\n').at(-1).trim().split(/\s+/)[3]) * 1024;
      assertDisk(available, used, profile.limits);
      assertDisk(stat.bavail * stat.bsize, used, profile.limits);
      result.peakMeasuredBytes = Math.max(result.peakMeasuredBytes ?? 0, used);
    };
    monitor = setInterval(async () => {
      if (monitoring || cleaning || budgetFailure) return;
      monitoring = true;
      try { await sampling.sample(budget); } catch (error) { budgetFailure = error; interrupted = true; abort.abort(); }
      finally { monitoring = false; }
    }, 5000);
    const context = { app, postgres, s3, client, denied, profile, manifest, root, exec, rawExec, checked, wait, record, close, profileSha256 };
    await bootstrap(context);
    await budget();
    await validateBaseline(context);
    if (phase === 'L2') {
      const { executeL2 } = await import('./l2.mjs');
      result.checkpoint = await executeL2(context);
      result.checkpointsCreated = true;
      result.pending[0] = 'L3-L5';
    }
    if (budgetFailure) throw budgetFailure;
    await budget();
    await record('aggregate-budget', { allocations: roles, peakMeasuredBytes: result.peakMeasuredBytes, diskBudgetGiB: profile.limits.diskBudgetGiB });
    result.status = 'passed';
  } catch (error) {
    failure = budgetFailure ?? error; result.status = 'failed'; result.failure = redact(failure.message);
  } finally {
    clearInterval(monitor);
    cleaning = true;
    while (monitoring) await new Promise(resolve => setTimeout(resolve, 50));
    try { await close(); result.admissionAtCleanup = 'closed'; } catch { result.admissionAtCleanup = 'unverified'; failure ??= new Error('Could not close admission'); result.status = 'failed'; }
    try {
      const diagnosticError = await owner.cleanupAfter(async () => {
        if (result.status === 'failed') for (const [name, id] of Object.entries({ app, s3, postgres })) {
          if (!id) continue;
          if (name === 'app') {
            const modes = await docker(['exec', id, 'stat', '-c', '%u:%g %a %n', '/config', '/config/www', '/config/www/nextcloud', '/config/www/nextcloud/config', '/config/www/nextcloud/config/config.php', '/config/www/nextcloud/apps', '/data']);
            await fs.appendFile(path.join(root, 'diagnostics.log'), modes.out);
          }
          const logs = await docker(['logs', '--tail', '100', id]);
          await fs.appendFile(path.join(root, 'diagnostics.log'), `${name}:\n${redact(logs.out + logs.err)}`);
        }
      });
      result.cleanup = 'complete';
      if (diagnosticError) { result.diagnostics = 'unavailable'; failure ??= diagnosticError; }
    }
    catch (error) { result.cleanup = error.message; result.status = 'failed'; result.failure ??= error.message; failure ??= error; }
    // L1 destroys private inputs. L2 retains only a verified B0 and its separate private recovery oracle.
    if (result.cleanup === 'complete') {
      for (const name of await fs.readdir(root)) if (!['result.json', 'resources.json', 'diagnostics.log', ...(phase === 'L2' && result.checkpointsCreated ? ['B0', 'B0-trust.json', 'private'] : [])].includes(name)) await fs.rm(path.join(root, name), { recursive: true, force: true });
    }
    result.status = finalStatus(Boolean(failure), interrupted, result.cleanup === 'complete');
    if (interrupted) { result.failure ??= `${phase} interrupted`; failure ??= new Error(`${phase} interrupted`); }
    process.off('SIGINT', onSignal); process.off('SIGTERM', onSignal);
    result.finishedAt = new Date().toISOString();
    await fs.writeFile(path.join(root, 'result.json'), JSON.stringify(result, null, 2));
  }
  console.log(JSON.stringify({ ...result, evidence: root }, null, 2));
  if (failure) throw new Error(`${phase} failed; sanitized evidence: ${root}`);
}
