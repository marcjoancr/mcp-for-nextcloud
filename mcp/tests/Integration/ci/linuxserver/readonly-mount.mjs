// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { nativePhp, occ, restartClosed } from './l3-lifecycle.mjs';
import { verifyInstalled } from '../../../../scripts/release/integrity.mjs';

const appPath = '/config/www/nextcloud/apps/mcp';
const shadow = '/config/www/nextcloud/mount-trial-shadow/mcp';
const classFile = 'lib/AppInfo/Application.php';

async function probe(c, action) {
  const out = await c.exec(c.app, [...nativePhp, '/fixture-probes/readonly-mount.php', action]);
  const lines = out.split('\n').filter(line => line.startsWith('MCP_MOUNT='));
  if (lines.length !== 1) throw new Error('Mount probe missing completion marker: ' + action);
  return JSON.parse(lines[0].slice('MCP_MOUNT='.length));
}

async function httpVersion(c, expectedVersion) {
  // The synthetic app password stays inside the private client and never enters argv.
  await occ(c, 'maintenance:mode', '--off');
  try {
    await c.exec(c.app, ['rm', '/config/mcp-l1-closed']);
    const out = await c.exec(c.client, ['php', '-r', `
      $h = curl_init('https://nc.fixture.test/index.php/apps/mcp/');
      curl_setopt_array($h, [CURLOPT_CAINFO=>'/tmp/ca.crt', CURLOPT_TIMEOUT=>20, CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_USERPWD=>'l2-owner:' . file_get_contents('/tmp/mount-trial-basic'), CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Accept: application/json, text/event-stream','Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'initialize','params'=>[
          'protocolVersion'=>'2025-06-18','clientInfo'=>['name'=>'mount-test','version'=>'1']]])]);
      $body = curl_exec($h);
      if (curl_getinfo($h, CURLINFO_RESPONSE_CODE) !== 200) exit(1);
      echo json_decode($body, true, 64, JSON_THROW_ON_ERROR)['result']['serverInfo']['version'] ?? '';
    `]);
    if (out.trim() !== expectedVersion) throw new Error('Mounted candidate HTTP version mismatch');
    return out.trim();
  } finally {
    await c.close();
    await occ(c, 'maintenance:mode', '--on');
  }
}

async function recreateMounted(c, tree) {
  const previous = c.app;
  await restartClosed(c, true);
  const inspected = JSON.parse(await c.checked(['container', 'inspect', c.app]))[0];
  const mounts = inspected.Mounts.filter(row => row.Destination === appPath || row.Source === tree);
  if (previous === c.app || mounts.length !== 1 || mounts[0].Source !== tree || mounts[0].Destination !== appPath
    || mounts[0].Type !== 'bind' || mounts[0].RW !== false || inspected.HostConfig.Privileged
    || Object.keys(inspected.HostConfig.PortBindings ?? {}).length) throw new Error('Read-only mount or recreation mismatch');
  return c.app;
}

export async function checkMount(c, options) {
  const source = path.join(c.root, 'mount-source');
  const tree = path.join(source, 'mcp');
  const kit = path.join(c.root, 'source');
  const pin = options['manifest-sha256'];
  const expectedClass = c.manifest.runtimeFiles[classFile].sha256;
  const extract = await command('python3', [path.join(here, 'extract.py'), path.join(kit, `mcp-${c.manifest.version}.tar.gz`), source, 'mcp']);
  if (extract.code) throw new Error('Cannot stage verified mount source');
  await verifyInstalled(kit, pin, tree);
  const script = path.join(c.root, 'probes/readonly-mount.php');
  await fs.copyFile(path.join(here, 'readonly-mount.php'), script);
  await fs.chmod(script, 0o644);
  const b1 = JSON.parse(await fs.readFile(path.join(c.root, 'private/B1/expected.json'), 'utf8'));
  const basic = b1.users['l2-owner'].basic;
  c.protect(basic);
  const credential = path.join(c.root, 'mount-trial-basic');
  await fs.writeFile(credential, basic, {mode: 0o600, flag: 'wx'});
  await c.checked(['cp', credential, `${c.client}:/tmp/mount-trial-basic`]);
  const control = await probe(c, 'direct');
  if (!Object.values(control).every(value => value === true)) throw new Error('Unprotected positive control did not reproduce write access');
  await c.record('unmounted-writable-control', control);

  // Keep all changes in disposable storage. The alternate class differs only by a comment.
  await c.exec(c.app, ['s6-setuidgid', 'abc', 'sh', '-ec',
    'mkdir "$(dirname "$2")" && cp -R "$1" "$2" && printf "\\n// Inert mount trial marker.\\n" >> "$2/lib/AppInfo/Application.php"',
    'mount-trial', appPath, shadow]);
  const shadowHash = (await c.exec(c.app, ['sha256sum', path.join(shadow, classFile)])).split(/\s/)[0];
  if (shadowHash === expectedClass) throw new Error('Invalid replacement control');

  const generations = [];
  for (const name of ['mounted', 'recreated']) {
    const container = await recreateMounted(c, tree);
    const loaded = await probe(c, 'resolve');
    if (loaded.path !== appPath || loaded.loadedClassSha256 !== expectedClass || loaded.version !== c.manifest.version) throw new Error('Unexpected loaded app at startup');
    const http = await httpVersion(c, c.manifest.version);
    const direct = await probe(c, 'direct');
    const blockedDirect = Object.values(direct).every(value => value === false);
    await c.record(name + '-direct', {id: c.app, readOnly: true, loaded, http, direct, blockedDirect});

    const redirections = {};
    for (const [action, undo, key] of [['swap-parent', 'undo-swap', 'swapped'], ['redirect', 'undo-redirect', 'redirected']]) {
      const changed = (await probe(c, action))[key];
      let replacementLoaded = false;
      let alternative = null;
      if (changed) {
        try {
          alternative = await probe(c, 'resolve');
          replacementLoaded = alternative.path === shadow && alternative.loadedClassSha256 === shadowHash;
          if (!replacementLoaded && (alternative.path !== appPath || alternative.loadedClassSha256 !== expectedClass)) throw new Error('Unidentified app resolution');
          if (name === 'recreated' && action === 'redirect' && replacementLoaded) {
            const replacementContainer = await recreateMounted(c, tree);
            const afterRestart = await probe(c, 'resolve');
            await c.record('redirect-left-in-place-across-recreation', {
              previousContainer: container, replacementContainer, loaded: afterRestart,
              replacementStillLoaded: afterRestart.path === shadow && afterRestart.loadedClassSha256 === shadowHash,
            });
          }
        } finally { await probe(c, undo); }
      }
      const restored = await probe(c, 'resolve');
      if (restored.path !== appPath || restored.loadedClassSha256 !== expectedClass) throw new Error('Failed to restore app selection');
      redirections[action] = {changed, replacementLoaded, loaded: alternative};
      await c.record(name + '-' + action, redirections[action]);
    }
    const copy = path.join(c.root, name + '-inventory');
    await c.checked(['cp', `${c.app}:${appPath}`, copy]);
    await verifyInstalled(kit, pin, copy);
    await verifyInstalled(kit, pin, tree);
    await fs.rm(copy, {recursive: true});
    generations.push({name, id: container, blockedDirect, redirections, verifiedFiles: Object.keys(c.manifest.runtimeFiles).length});
  }
  return {generations, protected: generations.every(row => row.blockedDirect && Object.values(row.redirections).every(test => !test.replacementLoaded))};
}
