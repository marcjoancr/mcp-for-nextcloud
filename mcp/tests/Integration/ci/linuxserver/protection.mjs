// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { assertInventoryNegatives, nativePhp } from './l3-lifecycle.mjs';
import { inventory, verifyInstalled } from '../../../../scripts/release/integrity.mjs';

const digest = bytes => createHash('sha256').update(bytes).digest('hex');
const appRoot = '/config/www/nextcloud/apps';
const safeIp = value => typeof value === 'string' && /^(?:\d{1,3}\.){3}\d{1,3}$/.test(value);
const trustedInputs = {
  manifest: '7c8406804324fd3af69cd7a0c166c282ad0046d72b0fb762bbecb129b845ef17',
  sourceCatalog: '730f4bbb1c0678a271f11ea41b967312cca81265af68ee5cafec52a6b448db1c',
  notes: {
    '6.0.0': { archive: 'c62017cbb09e9d8355c60bbd2af5531f75c22632e946aab15eff9852c39a6fdd', signature: '1b519430c2900bb621d7efd52dc29ca0f27d9dbc06242f4b03dd177df444fc4a' },
    '6.1.0': { archive: '03c493f45cb17a5005a0dce91c4e59b294ef91d4520c3ef44a21d5e61e419526', signature: 'a1e11ebc70e8ccdbe312d70fa47035574c7b3352d049244a4f275d0fcab81d33' },
  },
  certificate: 'db732d6a81d5ae2240fbd2035788149a13d88a0a87613eb426b424eccff15fea',
  publicKey: '9d00eb86c9c46a6498c36cb7ef25d173fa3aea815c8718205a8ff14adad6984f',
};

async function settleSystemConfig() { await new Promise(resolve => setTimeout(resolve, 3500)); }

async function probe(c, file) {
  await c.checked(['cp', file, `${c.app}:/tmp/protection-probe.php`]);
  await c.exec(c.app, ['chmod', '644', '/tmp/protection-probe.php']);
  const result = await c.rawExec(c.app, [...nativePhp, '/tmp/protection-probe.php']);
  if (result.code) throw new Error('native-probe-failed');
  const lines = result.out.split('\n').filter(line => line.startsWith('MCP_PROTECTION='));
  if (lines.length !== 1) throw new Error('native-probe-incomplete');
  return JSON.parse(lines[0].slice('MCP_PROTECTION='.length));
}

async function occ(c, ...args) {
  return await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', ...args]);
}

function resultStatus(reply) {
  return { exitCode: Number.isInteger(reply.code) ? reply.code : 1, outputPresent: Boolean((reply.out + reply.err).trim()) };
}

function nativeSignaturePass(reply) {
  const output = (reply.out + reply.err).toLowerCase();
  return reply.code === 0 && output.includes('no errors found') && !output.includes('skipping app integrity check');
}

async function evidenceApp(c, target, options) {
  await fs.rm(target, { recursive: true, force: true });
  const state = await probe(c, path.resolve(here, 'protection-probe.php'));
  const resolved = state.mcpPath;
  if (typeof resolved !== 'string' || !resolved.startsWith(`${appRoot}/`)) throw new Error('unexpected-mcp-path');
  await c.checked(['cp', `${c.app}:${resolved}`, target]);
  await verifyInstalled(options.kit, options['manifest-sha256'], target);
  return { state, files: await inventory(target) };
}

async function setSystem(c, key, value, type) {
  const args = ['config:system:set', key];
  if (type) args.push('--type', type);
  args.push('--value', String(value));
  const reply = await occ(c, ...args);
  if (reply.code) throw new Error('temporary-config-write-failed');
}

async function deleteSystem(c, key) {
  const reply = await occ(c, 'config:system:delete', key);
  if (reply.code) throw new Error('temporary-config-delete-failed');
}

async function catalogFixture(input, root, clientIp) {
  const manifestBytes = await fs.readFile(path.join(input, 'manifest.json'));
  if (digest(manifestBytes) !== trustedInputs.manifest) throw new Error('protection-input-manifest-digest-mismatch');
  const manifest = JSON.parse(manifestBytes.toString('utf8'));
  if (manifest.schemaVersion !== 1 || manifest.syntheticMcpControl?.downloadAttemptAllowed !== false
    || manifest.syntheticMcpControl?.signatureClaim !== false) throw new Error('protection-input-manifest-invalid');
  const source = path.join(input, 'apps-control-source.json');
  if (digest(await fs.readFile(source)) !== trustedInputs.sourceCatalog
    || manifest.catalogSource?.sha256 !== trustedInputs.sourceCatalog) throw new Error('protection-source-catalog-digest-mismatch');
  const staticRoot = path.join(root, 'static');
  const catalogsRoot = path.join(staticRoot, 'catalogs');
  const appsRoot = path.join(staticRoot, 'apps');
  await fs.mkdir(catalogsRoot, { recursive: true, mode: 0o700 });
  await fs.mkdir(appsRoot, { recursive: true, mode: 0o700 });
  const baseUrl = `http://${clientIp}:18765`;
  for (const name of ['install-old', 'control-stable', 'control-unstable', 'filtered-stable', 'filtered-unstable']) {
    const sourceFile = path.join(input, 'catalogs', name, 'apps.json');
    const bytes = await fs.readFile(sourceFile);
    if (digest(bytes) !== manifest.catalogs?.[name]?.sha256) throw new Error(`protection-catalog-digest-mismatch:${name}`);
    const rows = JSON.parse(bytes.toString('utf8'));
    const appIds = rows.map(row => row.id);
    if (new Set(appIds).size !== appIds.length) throw new Error(`protection-catalog-duplicate-app:${name}`);
    const hasMcp = appIds.includes('mcp');
    if (hasMcp !== name.startsWith('control-')) throw new Error(`protection-catalog-filter-mismatch:${name}`);
    const mcp = rows.find(row => row.id === 'mcp');
    if (mcp && !mcp.releases.some(release => release.version === manifest.syntheticMcpControl.version)) {
      throw new Error(`protection-catalog-control-version-missing:${name}`);
    }
    const rewritten = Buffer.from(JSON.stringify(rows).replaceAll('http://127.0.0.1:18765', baseUrl) + '\n');
    const destination = path.join(catalogsRoot, name);
    await fs.mkdir(destination, { recursive: true, mode: 0o755 });
    await fs.writeFile(path.join(destination, 'apps.json'), rewritten, { mode: 0o644 });
  }
  for (const version of ['6.0.0', '6.1.0']) {
    const sourceFile = path.join(input, 'apps', `notes-${version}.tar.gz`);
    const expected = trustedInputs.notes[version].archive;
    if (digest(await fs.readFile(sourceFile)) !== expected || manifest.notes?.[version]?.sha256 !== expected) {
      throw new Error(`protection-notes-archive-digest-mismatch:${version}`);
    }
    await fs.copyFile(sourceFile, path.join(appsRoot, `notes-${version}.tar.gz`));
  }
  return { manifest, staticRoot };
}

async function appStoreCache(c, sourceFile, mode) {
  await c.checked(['cp', sourceFile, `${c.app}:/tmp/protection-cache.php`]);
  await c.exec(c.app, ['chmod', '644', '/tmp/protection-cache.php']);
  await c.exec(c.app, ['chown', 'abc:abc', '/tmp/protection-cache.php']);
  const reply = await c.rawExec(c.app, [...nativePhp, '/tmp/protection-cache.php', mode]);
  const line = reply.out.split('\n').find(value => value.startsWith('MCP_PROTECTION_CACHE='));
  if (reply.code || !line) throw new Error(`appstore-cache-${mode}-failed`);
  return JSON.parse(line.slice('MCP_PROTECTION_CACHE='.length));
}

async function storeCall(c, clientIp, catalogName) {
  const url = `http://${clientIp}:18765/catalogs/${catalogName}/apps.json`;
  const reply = await c.rawExec(c.client, ['curl', '--fail', '--silent', '--show-error', '--max-time', '10', url]);
  if (reply.code) throw new Error(`catalog-unavailable:${catalogName}`);
  const rows = JSON.parse(reply.out);
  return { url, rows, ids: rows.map(row => row.id) };
}

async function appStoreRequestCount(c, catalogName) {
  const log = await c.rawExec(c.client, ['cat', '/tmp/protection-server.log']);
  if (log.code) return null;
  const escaped = catalogName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return (log.out.match(new RegExp(`GET /catalogs/${escaped}/apps\\.json`, 'g')) ?? []).length;
}

async function uiCall(c, mode, baseUrl) {
  const reply = await c.rawExec(c.client, ['s6-setuidgid', 'abc', 'php', '/tmp/protection-ui.php', mode, baseUrl]);
  const lines = reply.out.split('\n').filter(line => line.startsWith('MCP_PROTECTION_UI='));
  if (lines.length !== 1) {
    const reason = /^MCP_PROTECTION_UI_ERROR=([a-z-]+)$/m.exec(reply.err)?.[1] ?? 'missing-completion';
    return { ok: false, exitCode: reply.code, markerPresent: false, reason };
  }
  const result = JSON.parse(lines[0].slice('MCP_PROTECTION_UI='.length));
  return { ...result, exitCode: reply.code, markerPresent: true };
}

async function activeVersion(c, appId) {
  const reply = await occ(c, 'config:app:get', appId, 'installed_version');
  return reply.code === 0 ? reply.out.trim() : null;
}

/**
 * Exercise update discovery only after the B1 checkpoint exists. Returns blockers rather than
 * throwing so the caller can continue with a fresh checkpoint-based recovery trial.
 */
export async function runProtection(c, options) {
  const checks = [], blockers = [];
  const add = async (name, passed, details = {}) => {
    const item = { name, status: passed ? 'passed' : 'blocked', ...details };
    checks.push(item);
    await c.record(`protection-${name}`, item);
    if (!passed) blockers.push(name);
  };
  const privateRoot = path.join(c.root, 'private/protection');
  const input = path.join(options.inputs, 'protection');
  const probeFile = path.resolve(here, 'protection-probe.php');
  const baselineTree = path.join(privateRoot, 'mcp-before');
  const afterTree = path.join(privateRoot, 'mcp-after');
  const negativeTree = path.join(privateRoot, 'mcp-negative');
  const stateSource = path.resolve(here, 'protection-state.php');
  const cacheSource = path.resolve(here, 'protection-cache.php');
  const uiSource = path.resolve(here, 'protection-ui.php');
  const stateOracle = path.join(privateRoot, 'state-oracle.json');
  const storeOverride = '/config/www/nextcloud/config/zz-protection-store.config.php';
  let temporaryStoreEnabled = false;
  const cacheBackupHost = path.join(privateRoot, 'appstore-cache-before.json');
  await fs.mkdir(privateRoot, { recursive: true, mode: 0o700 });
  let initial, settings, cacheInitial, serverStarted = false, movedMcp = null, clientIp = null, maintenanceOpened = false, admissionOpened = false;
  let configRestored = false, cacheRestored = false, maintenanceRestored = false, admissionClosed = false;
  let catalogFiles, notesBackup, notesPath;
  try {
    initial = await probe(c, probeFile);
    const b1 = JSON.parse(await fs.readFile(path.join(c.root, 'private/B1/expected.json'), 'utf8'));
    if (!b1.current || typeof b1.current !== 'object') throw new Error('b1-state-oracle-missing');
    await fs.writeFile(stateOracle, JSON.stringify(b1.current), { mode: 0o600 });
    await c.checked(['cp', stateSource, `${c.app}:/tmp/protection-state.php`]);
    await c.exec(c.app, ['chmod', '644', '/tmp/protection-state.php']);
    await c.checked(['cp', stateOracle, `${c.app}:/tmp/protection-state-oracle.json`]);
    await c.exec(c.app, ['chown', 'abc:abc', '/tmp/protection-state.php', '/tmp/protection-state-oracle.json']);
    const verifyMcpAndPolicyState = async name => {
      const after = await evidenceApp(c, afterTree, options);
      const bytesUnchanged = JSON.stringify(snapshot.files) === JSON.stringify(after.files);
      const stateReply = await c.rawExec(c.app, [...nativePhp, '/tmp/protection-state.php']);
      const stateLine = stateReply.out.split('\n').find(line => line.startsWith('MCP_PROTECTION_STATE='));
      const nativeState = stateLine ? JSON.parse(stateLine.slice('MCP_PROTECTION_STATE='.length)) : {};
      const stable = stateReply.code === 0 && nativeState.matchesB1 === true;
      await add(`${name}-mcp-bytes`, bytesUnchanged, { mcpBytesUnchanged: bytesUnchanged });
      await add(`${name}-mcp-grants-migrations`, stable, {
        matchesB1: nativeState.matchesB1 === true, migrationsSha256: nativeState.migrationsSha256,
        policySha256: nativeState.policySha256, migrationRows: nativeState.migrationRows,
        policyTables: nativeState.policyTables,
      });
      return bytesUnchanged && stable;
    };
    const snapshot = await evidenceApp(c, baselineTree, options);
    const candidateBytes = Object.values(snapshot.files).reduce((sum, file) => sum + file.bytes, 0);
    await add('single-mcp-path-and-candidate-bytes', snapshot.state.mcpCopies.length === 1
      && snapshot.state.mcpCopies[0] === snapshot.state.mcpPath
      && JSON.stringify(snapshot.files) === JSON.stringify(await inventory(baselineTree)), {
      mcpPath: snapshot.state.mcpPath, copies: snapshot.state.mcpCopies.length,
      candidateManifest: options['manifest-sha256'],
      runtimeFileCount: Object.keys(snapshot.files).length, runtimeBytes: candidateBytes,
    });
    await fs.cp(baselineTree, negativeTree, { recursive: true, errorOnExist: true, force: false });
    const negativeChecks = await assertInventoryNegatives(negativeTree, options.kit, options['manifest-sha256']);
    const negativesPass = negativeChecks.modifiedFile.rejected && negativeChecks.modifiedFile.restored
      && negativeChecks.missingFile.rejected && negativeChecks.missingFile.restored
      && negativeChecks.extraFile.rejected && negativeChecks.extraFile.restored;
    await add('candidate-modified-missing-extra-rejected', negativesPass, negativeChecks);
    await fs.rm(negativeTree, { recursive: true, force: true });
    await add('fixture-exposes-app-tree-write-risk', snapshot.state.mcpPathWritable === false
      && snapshot.state.mcpParentWritable === false, {
      appTreeWritable: snapshot.state.mcpPathWritable, appParentWritable: snapshot.state.mcpParentWritable,
      note: 'Writable persistent app paths do not establish a write-prevention boundary.',
    });
    if (snapshot.state.mcpPathWritable || snapshot.state.mcpParentWritable) blockers.push('write-protection-incomplete');

    const manifestBytes = await fs.readFile(path.join(input, 'manifest.json'));
    const manifest = JSON.parse(manifestBytes.toString('utf8'));
    if (digest(await fs.readFile(path.join(input, 'notes-cert.pem'))) !== trustedInputs.certificate
      || digest(await fs.readFile(path.join(input, 'notes-public.pem'))) !== trustedInputs.publicKey) {
      throw new Error('official-notes-certificate-pin-mismatch');
    }
    for (const version of ['6.0.0', '6.1.0']) {
      if (digest(await fs.readFile(path.join(input, `notes-${version}.sig`))) !== trustedInputs.notes[version].signature) {
        throw new Error(`official-notes-signature-pin-mismatch:${version}`);
      }
      const signature = await command('openssl', ['dgst', '-sha512', '-verify', path.join(input, 'notes-public.pem'),
        '-signature', path.join(input, `notes-${version}.sig`), path.join(input, 'apps', `notes-${version}.tar.gz`)]);
      if (signature.code || !signature.out.includes('Verified OK')) throw new Error(`official-notes-signature-invalid:${version}`);
    }

    const appRow = JSON.parse(await c.checked(['inspect', c.client]))[0];
    clientIp = Object.values(appRow.NetworkSettings.Networks ?? {})[0]?.IPAddress;
    if (!safeIp(clientIp)) throw new Error('client-address-unavailable');
    catalogFiles = await catalogFixture(input, privateRoot, clientIp);
    const servedFiles = await fs.readdir(catalogFiles.staticRoot, { withFileTypes: true });
    const staticBytes = await command('du', ['-sb', catalogFiles.staticRoot]);
    const staticByteCount = staticBytes.code === 0 ? Number(staticBytes.out.split(/\s/)[0]) : Number.POSITIVE_INFINITY;
    await add('protection-input-resource-limit', staticByteCount <= 32 * 1024 * 1024, {
      staticAssetGroups: servedFiles.length, staticBytes: staticByteCount, maximumBytes: 32 * 1024 * 1024,
      diskBudgetGiB: c.profile.limits.diskBudgetGiB,
    });
    await c.exec(c.client, ['mkdir', '-p', '/tmp/protection-server']);
    await c.checked(['cp', path.join(catalogFiles.staticRoot, 'catalogs'), `${c.client}:/tmp/protection-server`]);
    await c.checked(['cp', path.join(catalogFiles.staticRoot, 'apps'), `${c.client}:/tmp/protection-server`]);
    const launch = await c.rawExec(c.client, ['sh', '-c', `php -S ${clientIp}:18765 -t /tmp/protection-server >/tmp/protection-server.log 2>&1 & echo $! >/tmp/protection-server.pid`]);
    if (launch.code) throw new Error('local-catalog-server-start-failed');
    serverStarted = true;
    const readiness = await c.rawExec(c.client, ['sh', '-c', 'test -s /tmp/protection-server.pid']);
    if (readiness.code) throw new Error('local-catalog-server-not-ready');
    const control = await storeCall(c, clientIp, 'control-stable');
    const filtered = await storeCall(c, clientIp, 'filtered-stable');
    const unstableControl = await storeCall(c, clientIp, 'control-unstable');
    const unstableFiltered = await storeCall(c, clientIp, 'filtered-unstable');
    const hasEligibleVisible = control.rows.find(row => row.id === 'mcp')?.releases?.some(row => row.version === manifest.syntheticMcpControl.version) === true;
    const hasEligibleFiltered = filtered.ids.includes('mcp');
    const hasUnstableVisible = unstableControl.rows.find(row => row.id === 'mcp')?.releases?.some(row => row.version === manifest.syntheticMcpControl.unstableVersion) === true;
    const hasUnstableFiltered = unstableFiltered.ids.includes('mcp');
    await add('control-and-filtered-catalogs-stable-unstable', hasEligibleVisible && !hasEligibleFiltered
      && hasUnstableVisible && !hasUnstableFiltered, {
      controlVisibleMcp: hasEligibleVisible, filteredVisibleMcp: hasEligibleFiltered,
      unstableControlVisibleMcp: hasUnstableVisible, unstableFilteredVisibleMcp: hasUnstableFiltered,
      syntheticOnly: true, signatureClaim: false,
    });

    const currentClientUrl = `http://${clientIp}:18765`;
    settings = { appstoreenabled: initial.appstoreenabled, appstoreurl: initial.appstoreurl,
      allowLocal: initial.allow_local_remote_servers, updateChannel: initial.updateChannel };
    cacheInitial = await appStoreCache(c, cacheSource, 'snapshot');
    await c.checked(['cp', `${c.app}:/tmp/protection-appstore-cache.json`, cacheBackupHost]);
    await fs.chmod(cacheBackupHost, 0o600);
    // Only the public CA certificate must be readable by the unprivileged browser client.
    await c.exec(c.client, ['chmod', '644', '/tmp/ca.crt']);
    await c.checked(['cp', uiSource, `${c.client}:/tmp/protection-ui.php`]);
    await c.exec(c.client, ['chmod', '644', '/tmp/protection-ui.php']);
    const uiPrivate = path.join(privateRoot, 'ui-private.json');
    await fs.writeFile(uiPrivate, JSON.stringify({ adminPassword: b1.adminPassword }), { mode: 0o600 });
    await c.checked(['cp', uiPrivate, `${c.client}:/tmp/protection-ui-private.json`]);
    await c.exec(c.client, ['chown', 'abc:abc', '/tmp/protection-ui-private.json']);
    await c.exec(c.client, ['chmod', '600', '/tmp/protection-ui-private.json']);
    const maintenance = await occ(c, 'maintenance:mode', '--off');
    maintenanceOpened = true;
    if (maintenance.code) throw new Error('maintenance-open-for-ui-failed');
    await setSystem(c, 'allow_local_remote_servers', true, 'boolean');
    // The sealed fixture explicitly disables the store in fixture.config.php.
    // A later fragment can enable its isolated store without editing that backed-up file.
    if ((await c.rawExec(c.app, ['test', '-e', storeOverride])).code !== 1) throw new Error('fixture-store-override-exists');
    const overrideFile = path.join(privateRoot, 'store.config.php');
    await fs.writeFile(overrideFile, "<?php\n$CONFIG = ['appstoreenabled' => true];\n", {mode: 0o600});
    temporaryStoreEnabled = true;
    await c.checked(['cp', overrideFile, `${c.app}:${storeOverride}`]);
    await c.exec(c.app, ['chmod', '644', storeOverride]);
    if ((await probe(c, probeFile)).appstoreenabled !== true) throw new Error('fixture-store-could-not-be-enabled');
    admissionOpened = true;
    await c.exec(c.app, ['rm', '/config/mcp-l1-closed']);

    const uiOutcomes = [];
    for (const [mode, catalog, channel] of [
      ['control-stable', 'control-stable', 'stable'], ['filtered-stable', 'filtered-stable', 'stable'],
      ['control-beta', 'control-unstable', 'beta'], ['filtered-beta', 'filtered-unstable', 'beta'],
    ]) {
      await setSystem(c, 'updater.release.channel', channel);
      await setSystem(c, 'appstoreurl', `${currentClientUrl}/catalogs/${catalog}`);
      const cleared = await appStoreCache(c, cacheSource, 'clear');
      await settleSystemConfig();
      const coldCache = await appStoreCache(c, cacheSource, 'inspect');
      const requestsBefore = await appStoreRequestCount(c, catalog);
      const cold = await uiCall(c, mode, currentClientUrl);
      const requestsAfterCold = await appStoreRequestCount(c, catalog);
      const warm = await uiCall(c, mode, currentClientUrl);
      const requestsAfterWarm = await appStoreRequestCount(c, catalog);
      const warmCache = await appStoreCache(c, cacheSource, 'inspect');
      const nativeColdFetch = requestsBefore !== null && requestsAfterCold !== null && requestsAfterCold > requestsBefore;
      const warmServedFromCache = requestsAfterWarm !== null && requestsAfterWarm === requestsAfterCold
        && warmCache.exists === true && typeof warmCache.sha256 === 'string';
      const modePassed = cold.ok === true && warm.ok === true && cold.exitCode === 0 && warm.exitCode === 0
        && nativeColdFetch && cleared.exists === false && coldCache.exists === false && warmServedFromCache;
      const outcome = { mode, cold, warm, nativeColdFetch, warmServedFromCache,
        requests: { before: requestsBefore, afterCold: requestsAfterCold, afterWarm: requestsAfterWarm },
        cacheAfterClear: coldCache, cacheAfterWarm: warmCache };
      uiOutcomes.push(outcome);
      await add(`native-ui-${mode}-cold-warm`, modePassed, outcome);
      // Prevent the next stable/beta or control/filtered request from reusing the previous catalog.
      if (mode !== 'filtered-beta') await appStoreCache(c, cacheSource, 'clear');
    }
    await c.close();
    admissionOpened = false;
    await add('ui-stable-control-exposes-filtered-eligible-mcp', uiOutcomes[0]?.cold?.mcpUpdateVersion === '2.1.0');
    await add('ui-stable-filter-hides-mcp-update', uiOutcomes[1]?.cold?.mcpRowPresent === true
      && uiOutcomes[1]?.cold?.mcpUpdateVersion === null && uiOutcomes[1]?.cold?.ok === true && uiOutcomes[1]?.cold?.mcpCurrentVersion === c.manifest.version);
    await add('ui-beta-control-exposes-prerelease-mcp', uiOutcomes[2]?.cold?.mcpUpdateVersion === '2.2.0-beta.1');
    await add('ui-beta-filter-hides-mcp-update', uiOutcomes[3]?.cold?.mcpRowPresent === true
      && uiOutcomes[3]?.cold?.mcpUpdateVersion === null && uiOutcomes[3]?.cold?.ok === true && uiOutcomes[3]?.cold?.mcpCurrentVersion === c.manifest.version);

    // AppFetcher callers use the filtered endpoint for both named and bulk OCC update discovery.
    for (const mode of ['stable', 'unstable']) {
      await setSystem(c, 'updater.release.channel', mode === 'stable' ? 'stable' : 'beta');
      const url = `${currentClientUrl}/catalogs/filtered-${mode}`;
      await setSystem(c, 'appstoreurl', url);
      await appStoreCache(c, cacheSource, 'clear');
      const requestsBefore = await appStoreRequestCount(c, `filtered-${mode}`);
      const explicit = await occ(c, 'app:update', 'mcp', '--no-interaction');
      const requestsAfterExplicit = await appStoreRequestCount(c, `filtered-${mode}`);
      const explicitStable = await verifyMcpAndPolicyState(`filtered-${mode}-explicit`);
      const repeat = await occ(c, 'app:update', 'mcp', '--no-interaction');
      const repeatStable = await verifyMcpAndPolicyState(`filtered-${mode}-repeat`);
      const bulk = await occ(c, 'app:update', '--all', '--no-interaction');
      const bulkStable = await verifyMcpAndPolicyState(`filtered-${mode}-bulk`);
      const nativeFetchObserved = requestsBefore !== null && requestsAfterExplicit !== null && requestsAfterExplicit > requestsBefore;
      const passed = explicit.code === 0 && repeat.code === 0 && bulk.code === 0 && explicitStable && repeatStable && bulkStable && nativeFetchObserved;
      await add(`filtered-occ-${mode}-explicit-repeat-bulk`, passed, {
        explicit: resultStatus(explicit), repeated: resultStatus(repeat), bulk: resultStatus(bulk),
        mcpBytesUnchanged: explicitStable && repeatStable && bulkStable,
        nativeCatalogFetchObserved: nativeFetchObserved,
        catalogRequests: { before: requestsBefore, afterExplicit: requestsAfterExplicit },
      });
    }

    // The install command sees an absent app tree while the B1 bytes are safely held outside app roots.
    const current = await probe(c, probeFile);
    const hold = '/config/protection-mcp-held';
    if (current.mcpPath !== snapshot.state.mcpPath) throw new Error('mcp-path-changed-before-reinstall-probe');
    movedMcp = { path: current.mcpPath, hold };
    await c.exec(c.app, ['mv', current.mcpPath, hold]);
    const reinstall = await occ(c, 'app:install', 'mcp', '--keep-disabled', '--no-interaction');
    const pathStayedAbsent = (await c.rawExec(c.app, ['test', '!', '-e', current.mcpPath])).code === 0;
    const missingFromStore = reinstall.code === 1
      && (reinstall.out + reinstall.err).includes('Could not download app mcp, it was not found on the appstore');
    await c.exec(c.app, ['rm', '-rf', current.mcpPath]);
    await c.exec(c.app, ['mv', hold, current.mcpPath]);
    movedMcp = null;
    const reinstallState = await verifyMcpAndPolicyState('filtered-download-reinstall');
    const reinstallRefused = missingFromStore && pathStayedAbsent && reinstallState;
    await add('filtered-download-reinstall-path', reinstallRefused, {
      command: resultStatus(reinstall), missingFromStore, pathStayedAbsent, mcpBytesAndStateUnchanged: reinstallState,
      limitation: 'OCC reinstall refusal is exercised; browser-driven install remains separate.',
    });

    // Run a complete native signed update on Notes using two signed, pinned official releases.
    let notesUpdate = { attempted: false, passed: false };
    notesPath = '/config/www/nextcloud/apps/notes';
    notesBackup = path.join(privateRoot, 'notes-before');
    const copyNotes = await c.rawExec(c.app, ['sh', '-c', `test -d '${notesPath}'`]);
    if (copyNotes.code === 0) {
      await c.checked(['cp', `${c.app}:${notesPath}`, notesBackup]);
      const removed = await occ(c, 'app:remove', 'notes', '--no-interaction');
      if (removed.code === 0) {
        await setSystem(c, 'updater.release.channel', 'stable');
        await setSystem(c, 'appstoreurl', `${currentClientUrl}/catalogs/install-old`);
        await appStoreCache(c, cacheSource, 'clear');
        const installedOld = await occ(c, 'app:install', 'notes', '--no-interaction');
        const oldVersion = await activeVersion(c, 'notes');
        const oldIntegrity = await occ(c, 'integrity:check-app', 'notes', '--verbose');
        const oldSignatureValid = nativeSignaturePass(oldIntegrity);
        if (installedOld.code === 0 && oldVersion === '6.0.0' && oldSignatureValid) {
          await setSystem(c, 'appstoreurl', `${currentClientUrl}/catalogs/filtered-stable`);
          await appStoreCache(c, cacheSource, 'clear');
          const updated = await occ(c, 'app:update', 'notes', '--no-interaction');
          const newVersion = await activeVersion(c, 'notes');
          const integrity = await occ(c, 'integrity:check-app', 'notes', '--verbose');
          const newSignatureValid = nativeSignaturePass(integrity);
          notesUpdate = { attempted: true, passed: updated.code === 0 && newVersion === '6.1.0' && newSignatureValid,
            oldVersion, newVersion, update: resultStatus(updated), oldSignatureCheck: resultStatus(oldIntegrity),
            oldSignatureValid, signatureCheck: resultStatus(integrity), newSignatureValid };
        } else notesUpdate = { attempted: true, passed: false, installedOld: resultStatus(installedOld), oldVersion,
          oldSignatureCheck: resultStatus(oldIntegrity), oldSignatureValid };
      } else notesUpdate = { attempted: true, passed: false, remove: resultStatus(removed) };
      // Ensure later recovery sees the original Notes bytes, even after a partial AppFetcher failure.
      await c.exec(c.app, ['rm', '-rf', notesPath]);
      await c.checked(['cp', notesBackup, `${c.app}:${notesPath}`]);
      await c.exec(c.app, ['chown', '-R', 'abc:abc', notesPath]);
      const enableNotes = await occ(c, 'app:enable', 'notes', '--no-interaction');
      if (enableNotes.code) notesUpdate = { ...notesUpdate, passed: false, restoreEnable: resultStatus(enableNotes) };
      await fs.rm(notesBackup, { recursive: true, force: true });
      notesBackup = null;
    }
    await add('official-signed-second-app-update', notesUpdate.passed, {
      ...notesUpdate,
      inputs: ['Notes 6.0.0', 'Notes 6.1.0'],
      integrityCheckScope: 'Nextcloud native Notes app signature check',
    });
    await add('ui-catalog-path', uiOutcomes.length === 4 && uiOutcomes.every(outcome => outcome.cold.ok && outcome.warm.ok), {
      modes: uiOutcomes.map(outcome => outcome.mode),
    });
    await add('cold-warm-cache-boundary', uiOutcomes.length === 4 && uiOutcomes.every(outcome => outcome.nativeColdFetch && outcome.warmServedFromCache), {
      cache: 'Nextcloud AppFetcher AppData apps.json; cold GET followed by warm cache hit',
    });

    await setSystem(c, 'updater.release.channel', settings.updateChannel);
    const filteredCatalogUrl = `${currentClientUrl}/catalogs/filtered-stable`;
    await setSystem(c, 'appstoreurl', filteredCatalogUrl);
    await appStoreCache(c, cacheSource, 'clear');
    const roots = await (await import('./protection-roots.mjs')).runProtectionRoots(c, {
      options, probeFile, stateSource, stateOracle, filteredCatalogUrl,
    });
    await add('alternate-app-root-and-recreation', roots?.passed === true, roots ?? { result: 'no-result' });

    const finalTree = await evidenceApp(c, afterTree, options);
    const exactBytes = JSON.stringify(snapshot.files) === JSON.stringify(finalTree.files);
    await add('candidate-bytes-unchanged', exactBytes, { candidateManifest: options['manifest-sha256'] });
  } catch (error) {
    const safe = /^[a-z0-9:-]+$/.test(error?.message ?? '') ? error.message : 'protection-trial-incomplete';
    await add(safe, false);
  } finally {
    try {
      if (notesBackup && notesPath) {
        await c.exec(c.app, ['rm', '-rf', notesPath]);
        await c.checked(['cp', notesBackup, `${c.app}:${notesPath}`]);
        await c.exec(c.app, ['chown', '-R', 'abc:abc', notesPath]);
        await occ(c, 'app:enable', 'notes', '--no-interaction');
        await fs.rm(notesBackup, { recursive: true, force: true });
      }
    } catch { blockers.push('notes-tree-restore-failed'); }
    try {
      if (movedMcp) {
        await c.exec(c.app, ['rm', '-rf', movedMcp.path]);
        await c.exec(c.app, ['mv', movedMcp.hold, movedMcp.path]);
      }
    } catch { blockers.push('mcp-tree-restore-failed'); }
    try {
      if (settings) {
        if (temporaryStoreEnabled) await c.exec(c.app, ['rm', '-f', storeOverride]);
        for (const [key, value] of Object.entries(initial.storeOverrides)) {
          if (key === 'appstoreenabled') continue;
          if (value === null) await deleteSystem(c, key);
          else await setSystem(c, key, value, typeof value === 'boolean' ? 'boolean' : undefined);
        }
        const restored = await probe(c, probeFile);
        if (JSON.stringify(restored.storeOverrides) !== JSON.stringify(initial.storeOverrides)) {
          throw new Error('restored-system-config-differs');
        }
        configRestored = true;
      }
    } catch { blockers.push('temporary-config-restore-failed'); }
    try {
      if (admissionOpened) await c.close();
      const closed = (await c.rawExec(c.app, ['test', '-f', '/config/mcp-l1-closed'])).code === 0;
      admissionClosed = closed;
      if (!closed) blockers.push('admission-restore-failed');
      else await add('admission-restored-closed', true);
    } catch { blockers.push('admission-restore-failed'); }
    try {
      if (maintenanceOpened) {
        const restoreMaintenance = await occ(c, 'maintenance:mode', '--on');
        if (restoreMaintenance.code) blockers.push('maintenance-mode-restore-failed');
        else maintenanceRestored = true;
      }
    } catch { blockers.push('maintenance-mode-restore-failed'); }
    try {
      if (cacheInitial) {
        const cacheFile = await c.rawExec(c.app, ['test', '-f', '/tmp/protection-appstore-cache.json']);
        if (cacheFile.code !== 0) {
          await c.checked(['cp', cacheBackupHost, `${c.app}:/tmp/protection-appstore-cache.json`]);
          await c.exec(c.app, ['chown', 'abc:abc', '/tmp/protection-appstore-cache.json']);
        }
        const restoredCache = await appStoreCache(c, cacheSource, 'restore');
        if (restoredCache.exists !== cacheInitial.exists || restoredCache.sha256 !== cacheInitial.sha256) blockers.push('appstore-cache-restore-failed');
        else { cacheRestored = true; await add('appstore-cache-restored', true, { existed: cacheInitial.exists, sha256: cacheInitial.sha256 }); }
      }
    } catch { blockers.push('appstore-cache-restore-failed'); }
    try {
      if (serverStarted) {
        await c.rawExec(c.client, ['sh', '-c', 'if test -s /tmp/protection-server.pid; then kill "$(cat /tmp/protection-server.pid)" 2>/dev/null || true; rm -f /tmp/protection-server.pid; fi']);
      }
      await c.exec(c.app, ['rm', '-f', '/tmp/protection-probe.php']);
      await c.exec(c.app, ['rm', '-f', '/tmp/protection-state.php', '/tmp/protection-state-oracle.json']);
      await c.exec(c.app, ['rm', '-f', '/tmp/protection-cache.php', '/tmp/protection-appstore-cache.json']);
      await c.exec(c.client, ['rm', '-f', '/tmp/protection-ui.php', '/tmp/protection-ui-private.json']);
    } catch { blockers.push('fixture-cleanup-incomplete'); }
    if (!blockers.some(item => ['notes-tree-restore-failed', 'mcp-tree-restore-failed', 'temporary-config-restore-failed',
      'admission-restore-failed', 'maintenance-mode-restore-failed', 'appstore-cache-restore-failed'].includes(item))) {
      try { await fs.rm(privateRoot, { recursive: true, force: true }); }
      catch { blockers.push('private-protection-artifact-cleanup-failed'); }
    }
  }
  const passed = blockers.length === 0;
  const summary = { passed, checks, blockers: [...new Set(blockers)], restoration: {
    config: settings ? (configRestored ? 'restored-to-starting-values' : 'failed') : 'unchanged-or-not-reached',
    appstoreCache: cacheInitial ? (cacheRestored ? 'restored-to-starting-content' : 'failed') : 'unchanged-or-not-reached',
    maintenance: maintenanceOpened ? (maintenanceRestored ? 'on' : 'failed') : 'unchanged-or-not-reached',
    admission: admissionClosed ? 'closed' : 'failed-or-unverified',
  } };
  await c.record('protection-summary', summary);
  return summary;
}
