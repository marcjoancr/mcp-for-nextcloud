// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { isIPv4 } from 'node:net';
import { createHash } from 'node:crypto';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { nativePhp, occ } from './l3-lifecycle.mjs';
import { emptyCronTablesCommand } from './fixture.mjs';
import { verifyInstalled } from '../../../../scripts/release/integrity.mjs';

const config = '/config/www/nextcloud/config';
const mcp = '/config/www/nextcloud/apps/mcp';
const docRoot = '/app/www/public';
const catalogPin = '7c8406804324fd3af69cd7a0c166c282ad0046d72b0fb762bbecb129b845ef17';
const digest = data => createHash('sha256').update(data).digest('hex');
const worker = (c, ...args) => c.checked(['exec', '--user', '1000:1000', c.app, 'php', '-d', 'apc.enable_cli=1', ...args]);
const workerOcc = (c, ...args) => worker(c, docRoot + '/occ', ...args);

async function probe(c, mode) {
  const text = await c.exec(c.app, [...nativePhp, '/fixture-probes/operator-paths.php', mode]);
  const lines = text.split('\n').filter(line => line.startsWith('MCP_PATHS='));
  if (lines.length !== 1) throw new Error('Operator path probe did not complete: ' + mode);
  return JSON.parse(lines[0].slice('MCP_PATHS='.length));
}
async function inventory(c, options, label) {
  const snapshot = path.join(c.root, 'operator-inventory');
  await c.checked(['cp', `${c.app}:${mcp}`, snapshot]);
  try { await verifyInstalled(options.kit, options['manifest-sha256'], snapshot); }
  finally { await fs.rm(snapshot, {recursive: true}); }
  await c.record(label + '-inventory', {files: Object.keys(c.manifest.runtimeFiles).length, manifest: options['manifest-sha256']});
}
async function catalogServer(c, inputs) {
  const directory = path.join(inputs, 'protection');
  const metadata = await fs.readFile(path.join(directory, 'manifest.json'));
  if (digest(metadata) !== catalogPin) throw new Error('Unexpected protection catalog inputs');
  const pins = JSON.parse(metadata);
  const ip = Object.values(JSON.parse(await c.checked(['inspect', c.client]))[0].NetworkSettings.Networks)[0].IPAddress;
  if (!isIPv4(ip)) throw new Error('Invalid internal client address');
  const base = 'http://' + ip + ':18765';
  const root = path.join(c.root, 'notes-store');
  await fs.mkdir(path.join(root, 'apps'), {recursive: true});
  for (const view of ['install-old', 'filtered-stable']) {
    const bytes = await fs.readFile(path.join(directory, 'catalogs', view, 'apps.json'));
    if (digest(bytes) !== pins.catalogs[view].sha256) throw new Error('Changed catalog view');
    await fs.mkdir(path.join(root, view));
    await fs.writeFile(path.join(root, view, 'apps.json'), bytes.toString().replaceAll('http://127.0.0.1:18765', base));
  }
  for (const version of ['6.0.0','6.1.0']) {
    const name = 'notes-' + version + '.tar.gz';
    const bytes = await fs.readFile(path.join(directory, 'apps', name));
    if (digest(bytes) !== pins.notes[version].sha256) throw new Error('Changed Notes archive');
    await fs.writeFile(path.join(root, 'apps', name), bytes);
  }
  await c.checked(['cp', root, c.client + ':/tmp/notes-store']);
  await c.exec(c.client, ['sh','-ec','php -S "$1:18765" -t /tmp/notes-store >/tmp/operator-store.log 2>&1 &', 'store', ip]);
  await c.wait('internal signed Notes catalog', async () => !(await c.rawExec(c.client, ['curl','-fsS','-o','/dev/null',base+'/install-old/apps.json'])).code);
  return base;
}
async function storeConfig(c, base, view, readOnly) {
  const file = path.join(c.root, 'operator.config.php');
  await fs.writeFile(file, "<?php\n$CONFIG = ['appstoreenabled' => true, 'allow_local_remote_servers' => true, 'appstoreurl' => '" +
    base + '/' + view + "', 'config_is_read_only' => " + (readOnly ? 'true' : 'false') + "];\n");
  await c.checked(['cp', file, c.app + ':' + config + '/zz-operator.config.php']);
  await c.exec(c.app, ['chmod', '644', config + '/zz-operator.config.php']);
}
async function clearStoreCache(c) {
  await worker(c, '-r', `define('OC_CONSOLE',true);require '/app/www/public/lib/base.php';
    $f=\\OCP\\Server::get(\\OC\\Files\\AppData\\Factory::class)->get('appstore')->getFolder('/');
    try {$f->getFile('apps.json')->delete();} catch(\\OCP\\Files\\NotFoundException) {}`);
}
async function notesVersion(c, version, service) {
  const run = service ? occ : workerOcc;
  if ((await run(c, 'config:app:get','notes','installed_version')).trim() !== version) throw new Error('Wrong installed Notes version');
  const check = await run(c, 'integrity:check-app','notes','--verbose');
  if (!check.includes('No errors found') || check.includes('skipping')) throw new Error('Native Notes signature check incomplete');
}
async function request(c, test) {
  const out = await c.exec(c.client, ['php', '/tmp/operator-http.php', test]);
  if (out.trim() !== (test === 'mcp' ? c.manifest.version : '503')) throw new Error('Unexpected fixture HTTP response: ' + test);
}

export async function testOperatorPaths(c, options, replaceRole) {
  const script = path.join(c.root, 'probes/operator-paths.php');
  await fs.copyFile(path.join(here,'operator-paths.php'), script); await fs.chmod(script, 0o644);
  const startupProbe = path.join(c.root, 'probes/startup-readiness.php');
  await fs.copyFile(path.join(here,'startup-readiness.php'),startupProbe);
  await fs.chmod(startupProbe,0o644);
  const writable = await probe(c, 'direct');
  const initial = await probe(c, 'paths');
  if (!Object.values(writable).every(v => v === true) || !initial.swapParent || !initial.injectConfig || !initial.openConfigForWrite) throw new Error('Writable control failed');
  await c.record('writable-control', {direct: writable, paths: initial});
  await inventory(c, options, 'restored');
  const gate = path.join(c.root, 'operator-gate');
  await fs.mkdir(gate, {mode: 0o755}); await fs.chmod(gate, 0o755);
  await c.checked(['cp', c.app + ':/app', path.join(c.root,'operator-app')]);
  for (const name of ['apps','config']) {
    if (await fs.readlink(path.join(c.root,'operator-app/www/public',name)) !== '/config/www/nextcloud/'+name) throw new Error('Unexpected document root alias');
  }
  const health = path.join(c.root,'operator-app/www/public/mcp-l1-health.php');
  await fs.copyFile(path.join(here,'operator-fpm.php'),health);
  await fs.chmod(health,0o644);
  const site = path.join(c.root, 'config/nginx/site-confs/default.conf');
  const original = await fs.readFile(site,'utf8');
  const condition = 'if (-f /config/mcp-l1-closed)';
  if (original.split(condition).length !== 2) throw new Error('Fixture gate mismatch');
  const siteFile = path.join(c.root, 'operator-site.conf');
  await fs.writeFile(siteFile, original.replace(condition, 'if (!-f /operator-gate/admit)'));
  await c.checked(['cp',siteFile, c.app + ':/config/nginx/site-confs/default.conf']);
  await c.exec(c.app, ['chmod','644','/config/nginx/site-confs/default.conf']);
  await occ(c, 'maintenance:mode', '--off'); // Original pre-PHP gate remains closed.
  const b1 = JSON.parse(await fs.readFile(path.join(c.root,'private/B1/expected.json')));
  const credential = path.join(c.root,'synthetic-basic');
  c.protect(b1.users['l2-owner'].basic);
  await fs.writeFile(credential,b1.users['l2-owner'].basic,{mode:0o600});
  await c.checked(['cp',credential,c.client+':/tmp/operator-basic']);
  await c.checked(['cp',path.join(here,'operator-http.php'),c.client+':/tmp/operator-http.php']);
  const base = await catalogServer(c, options.inputs);

  let preparedHashes;
  async function maintenance(stage) {
    await replaceRole(false);
    const state = JSON.parse(await c.checked(['container','inspect',c.app]))[0];
    const processes = (await c.checked(['top', c.app, '-eo', 'pid,comm'])).trim().split('\n').slice(1).map(line => path.posix.basename(line.trim().split(/\s+/)[1]));
    await c.record('maintenance-processes-' + stage, {entrypoint: state.Path, processes});
    if (state.Path !== '/bin/sleep' || !processes.includes('sleep') || processes.some(name => !['sleep','docker-init','tini'].includes(name))) {
      throw new Error('Unexpected process in the maintenance container');
    }
    if (stage === 'prepare' && options['host-startup-inputs']) {
      // The observed MinicoLab config already has these LinuxServer first-start settings.
      for (const name of ['memcache.local','memcache.locking']) {
        await workerOcc(c,'config:system:set',name,'--value','\\OC\\Memcache\\APCu');
      }
      await workerOcc(c,'config:system:set','filelocking.enabled','--type=boolean','--value=true');
    }
    const view = stage === 'prepare' ? 'install-old' : 'filtered-stable';
    await storeConfig(c, base, view, false);
    await clearStoreCache(c);
    if (stage === 'prepare') {
      await workerOcc(c, 'app:remove','notes','--no-interaction');
      await workerOcc(c, 'app:install','notes','--no-interaction');
    } else {
      await workerOcc(c, 'app:update','notes','--no-interaction');
    }
    const version = stage === 'prepare' ? '6.0.0' : '6.1.0';
    await notesVersion(c, version, false);
    await inventory(c, options, stage);
    await storeConfig(c, base, 'filtered-stable', true);
    await clearStoreCache(c);
    const prepared = JSON.parse(await worker(c,'/fixture-probes/startup-readiness.php'));
    preparedHashes = prepared.hashes;
    await c.record(stage + '-startup-inputs', prepared.predicates);
    if (options['host-startup-inputs'] && !Object.values(prepared.predicates).every(value => value === true)) throw new Error('Unprepared LinuxServer startup inputs');
    await c.record('operator-' + stage, {notes: version, nativeSignature: 'verified', webAndCron: 'absent'});
  }

  async function service(label, version) {
    const previous = c.app;
    await replaceRole(true);
    const row = JSON.parse(await c.checked(['container','inspect',c.app]))[0];
    const mounts = Object.fromEntries(row.Mounts.map(m => [m.Destination,m]));
    if (row.Id === previous || mounts['/config']?.RW !== false || mounts['/app']?.RW !== false || mounts['/app'].Source !== path.join(c.root,'operator-app')
      || mounts['/operator-gate']?.RW !== false || mounts['/data']?.RW !== true || row.HostConfig.Privileged || Object.keys(row.HostConfig.PortBindings ?? {}).length) throw new Error('Serving mount or role mismatch');
    await c.wait('sealed Nginx gate', async () => (await c.rawExec(c.client, ['curl','-sS','--max-time','3','--cacert','/tmp/ca.crt','-o','/dev/null','-w','%{http_code}','https://nc.fixture.test/status.php'])).out === '503');
    await c.exec(c.app, ['s6-rc','-d','change','svc-cron']);
    await c.exec(c.app, ['sh','-ec',emptyCronTablesCommand + ' && ! pgrep -x crond']);
    if (options['host-startup-inputs']) await (await import('./host-startup.mjs')).verifyHostStartup(c,label);
    const logResult = await command('docker',['logs',c.app]);
    if (logResult.code !== 0) throw new Error('Could not inspect serving startup logs');
    const logs = logResult.out + logResult.err;
    const readOnlyMessages = logs.split('\n').filter(line => /read-only file system/i.test(line));
    // Record operation and target only. Docker's complete startup logs stay private.
    const refusals = {};
    for (const line of readOnlyMessages) {
      const op = /^(find|cp|sed|touch|chmod):/.exec(line)?.[1] ?? 'other';
      const target = line.includes('/config/www/nextcloud/config/config.php') ? 'config.php'
        : line.includes('/config/nginx/') || line.includes("'/config/nginx'") ? 'nginx' : 'other';
      const key = op + ' ' + target;
      refusals[key] = (refusals[key] ?? 0) + 1;
    }
    const startup = JSON.parse(await c.exec(c.app,[...nativePhp,'/fixture-probes/startup-readiness.php']));
    const unchanged = Object.keys(preparedHashes).length === Object.keys(startup.hashes).length
      && Object.entries(preparedHashes).every(([file,sha]) => startup.hashes[file] === sha);
    await c.exec(c.app,['nginx','-t']);
    const status = JSON.parse(await occ(c,'status','--output=json'));
    await c.record(label + '-startup', {examinedLines:logs.split('\n').length, readOnlyMessages:readOnlyMessages.length, refusals,
      preparedFilesUnchanged:unchanged, checkedFiles:Object.keys(preparedHashes), effective:startup.predicates,
      nginxConfigValid:true, needsDbUpgrade:status.needsDbUpgrade, maintenance:status.maintenance});
    if (!unchanged || (options['host-startup-inputs'] && !Object.values(startup.predicates).every(value => value === true))
      || status.needsDbUpgrade !== false || status.maintenance !== false || status.installed !== true) {
      throw new Error('Startup configuration or database state changed');
    }
    const direct = await probe(c,'direct');
    const paths = await probe(c,'paths');
    const loaded = await probe(c,'resolve');
    if (Object.values(direct).some(Boolean) || paths.swapParent || paths.injectConfig || paths.openConfigForWrite
        || paths.appRoots.length === 0 || paths.appRoots.some(p => p !== '/config/www/nextcloud/apps') || paths.canonicalConfig !== config
        || loaded.path !== mcp || loaded.loadedClassSha256 !== c.manifest.runtimeFiles['lib/AppInfo/Application.php'].sha256) throw new Error('Effective operator boundary failed');
    const anchors = await probe(c,'anchors');
    await c.record(label + '-anchors', anchors);
    if (Object.values(anchors).some(v => v.createdFile) || anchors['/'].ownedByService) {
      throw new Error('Service controls an ancestor of the mounted code or configuration');
    }
    await notesVersion(c,version,true);
    if (label === 'first-service') {
      const attempt = await c.rawExec(c.app,[...nativePhp,docRoot+'/occ','app:update','notes','--no-interaction']);
      if (![0,1].includes(attempt.code)) throw new Error('Serving app update did not complete normally');
      await notesVersion(c,'6.0.0',true);
      await c.record('serving-update-did-not-replace-notes',{exit:attempt.code,notes:'6.0.0',nativeSignature:'verified'});
    }
    await fs.writeFile(path.join(gate,'admit'),'allow',{mode:0o644});
    let fpm;
    try {
      await request(c,'mcp');
      fpm = JSON.parse(await c.exec(c.client,['curl','-fsS','--max-time','20','--cacert','/tmp/ca.crt','https://nc.fixture.test/mcp-l1-health.php']));
      if (fpm.sapi !== 'fpm-fcgi' || fpm.uid !== 1000 || fpm.config !== config || fpm.class !== mcp + '/lib/AppInfo/Application.php'
          || fpm.sha256 !== loaded.loadedClassSha256) throw new Error('FPM resolves a different MCP or configuration');
    } finally { await c.close(); }
    await request(c,'closed');
    await inventory(c,options,label);
    await c.record(label,{id:c.app,direct,paths,loaded,fpm,notes:version,privateHttpsInitialize:true,admissionAtEnd:503});
  }
  await maintenance('prepare');
  await service('first-service','6.0.0');
  await maintenance('update');
  await service('service-after-update','6.1.0');
  await service('recreated-service','6.1.0');
  // One normal scheduler invocation under the sealed service UID. The daemon remains paused.
  const beforeCron = (await occ(c,'config:app:get','core','lastcron','--default-value=0')).trim();
  await c.exec(c.app,[...nativePhp,docRoot + '/cron.php']);
  const afterCron = (await occ(c,'config:app:get','core','lastcron','--default-value=0')).trim();
  if (!/^\d+$/.test(afterCron) || Number(afterCron) <= Number(beforeCron)) throw new Error('Cron did not advance its completion timestamp');
  await inventory(c,options,'after-cron');
  await c.record('sealed-cron', {user:1000, completed:true, admission:'closed'});
  return {generations:3, signedNotesUpdate:'6.0.0 to 6.1.0', protected:true};
}
