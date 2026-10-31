// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { inventory, verifyInstalled, verifyKit } from '../../../../scripts/release/integrity.mjs';
import { nativePhp, restartClosed } from './l3-lifecycle.mjs';

const appRoot = '/config/www/nextcloud/apps';
const shadowRoot = '/config/protection-shadow/apps';
const shadowApp = `${shadowRoot}/mcp`;
const override = '/config/www/nextcloud/config/zzz-protection-apps.config.php';

async function probe(c, file) {
  await c.checked(['cp', file, `${c.app}:/tmp/protection-probe.php`]);
  await c.exec(c.app, ['chmod', '644', '/tmp/protection-probe.php']);
  const reply = await c.rawExec(c.app, [...nativePhp, '/tmp/protection-probe.php']);
  if (reply.code) throw new Error('protection-root-probe-failed');
  const lines = reply.out.split('\n').filter(line => line.startsWith('MCP_PROTECTION='));
  if (lines.length !== 1) throw new Error('protection-root-probe-incomplete');
  return JSON.parse(lines[0].slice('MCP_PROTECTION='.length));
}

async function appTree(c, target, options, probeFile) {
  const state = await probe(c, probeFile);
  const source = state.mcpPath;
  if (typeof source !== 'string' || !source.startsWith(`${appRoot}/`)) throw new Error('protection-root-unexpected-mcp-path');
  await fs.rm(target, { recursive: true, force: true });
  await c.checked(['cp', `${c.app}:${source}`, target]);
  const manifest = await verifyInstalled(options.kit, options['manifest-sha256'], target);
  return { state, files: await inventory(target), manifest };
}

async function recordedAppStoreRequests(c, catalog) {
  const reply = await c.rawExec(c.client, ['cat', '/tmp/protection-server.log']);
  if (reply.code) return null;
  const escaped = catalog.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return (reply.out.match(new RegExp(`GET /catalogs/${escaped}/apps\\.json`, 'g')) ?? []).length;
}

export async function runProtectionRoots(c, { options, probeFile, stateSource, stateOracle, filteredCatalogUrl }) {
  const result = {};
  const privateRoot = path.join(c.root, 'private/protection');
  const shadowManifest = path.join(privateRoot, 'shadow-info.xml');
  const overrideFile = path.join(privateRoot, 'shadow-apps.config.php');
  const beforeTree = path.join(privateRoot, 'roots-before-recreate');
  const afterTree = path.join(privateRoot, 'roots-after-recreate');
  let overrideInstalled = false;
  let shadowInstalled = false;
  let needsRestart = false;
  let rootProbeInstalled = false;
  try {
    const url = new URL(filteredCatalogUrl);
    if (url.protocol !== 'http:' || url.pathname !== '/catalogs/filtered-stable') throw new Error('protection-root-invalid-filtered-catalog-url');
    const catalogName = 'filtered-stable';

    const existingOverride = await c.rawExec(c.app, ['test', '-e', override]);
    const existingShadow = await c.rawExec(c.app, ['test', '-e', shadowRoot]);
    if (existingOverride.code !== 1 || existingShadow.code !== 1) throw new Error('protection-root-target-already-exists');

    await c.close();
    const maintenance = await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', 'maintenance:mode', '--on']);
    if (maintenance.code) throw new Error('protection-root-maintenance-close-failed');

    const initial = await appTree(c, beforeTree, options, probeFile);
    const shadowXml = '<?xml version="1.0"?>\n<info><id>mcp</id><name>Synthetic shadow</name></info>\n';
    await fs.writeFile(shadowManifest, shadowXml, { mode: 0o600 });
    const appRoots = [...initial.state.appsPaths, {path: shadowRoot, url: '/protection-shadow/apps', writable: false}];
    const encodedRoots = Buffer.from(JSON.stringify(appRoots)).toString('base64');
    await fs.writeFile(overrideFile, `<?php\n$CONFIG = ['apps_paths' => json_decode(base64_decode('${encodedRoots}'), true, 16, JSON_THROW_ON_ERROR)];\n`, { mode: 0o600 });
    shadowInstalled = true;
    await c.exec(c.app, ['mkdir', '-p', `${shadowApp}/appinfo`]);
    await c.checked(['cp', shadowManifest, `${c.app}:${shadowApp}/appinfo/info.xml`]);
    await c.exec(c.app, ['chmod', '644', `${shadowApp}/appinfo/info.xml`]);
    overrideInstalled = true;
    await c.checked(['cp', overrideFile, `${c.app}:${override}`]);
    await c.exec(c.app, ['chmod', '644', override]);
    needsRestart = true;

    const configuredShadow = await probe(c, probeFile);
    const shadowRootConfigured = configuredShadow.appsPaths.some(root => root.path === shadowRoot);
    const twoCopiesObserved = configuredShadow.mcpCopies.length === 2 && configuredShadow.mcpCopies.includes(shadowApp);
    if (!shadowRootConfigured || !twoCopiesObserved) throw new Error('protection-root-shadow-not-configured');

    const shadowProbe = path.join(privateRoot, 'assert-single-mcp-shadow.php');
    await fs.writeFile(shadowProbe, `<?php\nrequire '/fixture-probes/app-paths.php';\ndefine('OC_CONSOLE', true);\nrequire '/app/www/public/lib/base.php';\n$manager = \\OCP\\Server::get(\\OCP\\App\\IAppManager::class);\n$expected = realpath($manager->getAppPath('mcp'));\ntry { assertSingleMcpTree(\\OC::$APPSROOTS, $expected); echo "MCP_SHADOW=accepted\\n"; exit(2); } catch (RuntimeException $error) { if ($error->getMessage() !== 'Duplicate or missing physical MCP app tree') throw $error; echo "MCP_SHADOW=duplicate-detected\\n"; }\n`, { mode: 0o600 });
    rootProbeInstalled = true;
    await c.checked(['cp', shadowProbe, `${c.app}:/tmp/protection-shadow-check.php`]);
    await c.exec(c.app, ['chmod', '644', '/tmp/protection-shadow-check.php']);
    const shadow = await c.rawExec(c.app, [...nativePhp, '/tmp/protection-shadow-check.php']);
    if (shadow.code) throw new Error('protection-root-shadow-check-failed');
    const shadowLine = shadow.out.split('\n').filter(line => line === 'MCP_SHADOW=duplicate-detected');
    if (shadowLine.length !== 1) throw new Error('protection-root-shadow-not-detected');
    await c.record('protection-alternate-apps-path-shadow-detected', {
      duplicateRejected: true, configuredRoot: shadowRoot, configuredRoots: configuredShadow.appsPaths.length,
      mcpCopies: configuredShadow.mcpCopies.length, selectedPath: configuredShadow.mcpPath,
    });

    await c.exec(c.app, ['rm', '-rf', shadowRoot]);
    shadowInstalled = false;
    await c.exec(c.app, ['rm', '-f', override]);
    overrideInstalled = false;
    needsRestart = true;
    await restartClosed(c, true);
    needsRestart = false;

    const after = await appTree(c, afterTree, options, probeFile);
    const samePath = after.state.mcpPath === initial.state.mcpPath;
    const noShadow = !after.state.appsPaths.some(root => root.path === shadowRoot)
      && after.state.mcpCopies.length === 1 && samePath;
    const sameBytes = JSON.stringify(initial.files) === JSON.stringify(after.files);
    const kitManifest = await verifyKit(options.kit, options['manifest-sha256']);
    const catalogPersisted = after.state.appstoreurl === filteredCatalogUrl;
    const contextSettingsPersisted = after.state.appstoreenabled === true && after.state.allow_local_remote_servers === true;
    const updateChannelPersisted = after.state.updateChannel === initial.state.updateChannel;
    const candidateSourceValid = after.manifest.version === c.manifest.version
      && after.manifest.sourceCommit === c.manifest.sourceCommit
      && kitManifest.version === c.manifest.version && kitManifest.sourceCommit === c.manifest.sourceCommit;
    const catalogReply = await c.rawExec(c.client, ['curl', '--fail', '--silent', '--show-error', '--max-time', '10', `${filteredCatalogUrl}/apps.json`]);
    const catalogRows = catalogReply.code === 0 ? JSON.parse(catalogReply.out) : [];
    const filtered = !catalogRows.some(row => row.id === 'mcp');
    const requestsBefore = await recordedAppStoreRequests(c, catalogName);
    const update = await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', 'app:update', 'mcp', '--no-interaction']);
    const requestsAfter = await recordedAppStoreRequests(c, catalogName);
    const fetchedAfterRecreate = requestsBefore !== null && requestsAfter !== null && requestsAfter > requestsBefore;
    const notesVersionExpected = c.profile.apps.find(app => app.id === 'notes')?.version;
    const notesVersionReply = await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', 'config:app:get', 'notes', 'installed_version']);
    const notesVersion = notesVersionReply.code === 0 ? notesVersionReply.out.trim() : null;
    const notesSignature = await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', 'integrity:check-app', 'notes', '--verbose']);
    const signatureOutput = notesSignature.out + notesSignature.err;
    const signedSourceVerified = notesVersion === notesVersionExpected && notesSignature.code === 0
      && signatureOutput.includes('No errors found') && !signatureOutput.includes('skipping app integrity check');

    await c.checked(['cp', stateSource, `${c.app}:/tmp/protection-state.php`]);
    await c.exec(c.app, ['chmod', '644', '/tmp/protection-state.php']);
    await c.checked(['cp', stateOracle, `${c.app}:/tmp/protection-state-oracle.json`]);
    await c.exec(c.app, ['chown', 'abc:abc', '/tmp/protection-state-oracle.json']);
    await c.exec(c.app, ['chmod', '600', '/tmp/protection-state-oracle.json']);
    const state = await c.rawExec(c.app, [...nativePhp, '/tmp/protection-state.php']);
    const stateLine = state.out.split('\n').find(line => line.startsWith('MCP_PROTECTION_STATE='));
    const stateResult = stateLine ? JSON.parse(stateLine.slice('MCP_PROTECTION_STATE='.length)) : {};
    const stateMatches = state.code === 0 && stateResult.matchesB1 === true;
    const closed = (await c.rawExec(c.app, ['test', '-f', '/config/mcp-l1-closed'])).code === 0;

    result.alternateAppsPath = noShadow;
    result.filteredCatalogPersisted = catalogPersisted && contextSettingsPersisted && updateChannelPersisted && filtered && update.code === 0 && fetchedAfterRecreate;
    result.candidateBytesUnchanged = sameBytes && candidateSourceValid;
    result.signedSourceVerified = signedSourceVerified;
    result.b1StateUnchanged = stateMatches;
    result.admissionClosed = closed;
    await c.record('protection-filtered-catalog-after-app-recreation', {
      appstoreUrlPersisted: catalogPersisted, filteredCatalogStillExcludesMcp: filtered,
      temporaryAppStoreSettingsPersisted: contextSettingsPersisted, updateChannelPersisted, candidateSourceIdentityVerified: candidateSourceValid,
      appFetcherFetchedAfterRecreation: fetchedAfterRecreate, updateExitCode: update.code,
      signedNotesVersion: notesVersion, signedNotesIntegrityExitCode: notesSignature.code,
      candidateBytesUnchanged: sameBytes, matchesB1: stateMatches, admissionClosed: closed,
      noShadowRootAfterCleanup: noShadow,
    });
    if (!noShadow || !sameBytes || !candidateSourceValid || !signedSourceVerified || !catalogPersisted || !contextSettingsPersisted || !updateChannelPersisted
      || !filtered || update.code !== 0 || !fetchedAfterRecreate || !stateMatches || !closed) {
      throw new Error('protection-root-after-recreation-check-failed');
    }
    result.passed = true;
  } finally {
    const cleanupErrors = [];
    const clean = async action => { try { await action(); } catch { cleanupErrors.push(true); } };
    await clean(() => c.close());
    if (overrideInstalled) await clean(() => c.exec(c.app, ['rm', '-f', override]));
    if (shadowInstalled) await clean(() => c.exec(c.app, ['rm', '-rf', shadowRoot]));
    if (rootProbeInstalled) await clean(() => c.exec(c.app, ['rm', '-f', '/tmp/protection-shadow-check.php']));
    await clean(() => fs.rm(shadowManifest, { force: true }));
    await clean(() => fs.rm(overrideFile, { force: true }));
    await clean(() => fs.rm(path.join(privateRoot, 'assert-single-mcp-shadow.php'), { force: true }));
    await clean(() => fs.rm(beforeTree, { recursive: true, force: true }));
    await clean(() => fs.rm(afterTree, { recursive: true, force: true }));
    if (needsRestart) {
      await clean(() => c.close());
      await clean(() => c.recreateApp());
      await clean(() => c.close());
    }
    await clean(async () => {
      const maintenance = await c.rawExec(c.app, [...nativePhp, '/app/www/public/occ', 'maintenance:mode', '--on']);
      if (maintenance.code) throw new Error('maintenance-mode-restore-failed');
    });
    await clean(() => c.close());
    if (cleanupErrors.length) throw new Error('protection-root-cleanup-incomplete');
  }
  return result;
}
