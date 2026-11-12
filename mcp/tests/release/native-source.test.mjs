// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { inventory, hashFile } from '../../scripts/release/integrity.mjs';
import { nativeAssets, sealNativeCompanion, verifyNativeCompanion, assertNativeBuildIdentity, archiveNativeOverlay } from '../../scripts/release/native-source.mjs';

test('native companion has a closed inventory independent of the five-file MCP kit', async () => {
  const root = await fs.mkdtemp(path.resolve('build/native-source-test-'));
  try {
    for (const name of nativeAssets) await fs.writeFile(path.join(root, name), name);
    const metadata = { sourceCommit: 'a'.repeat(40), nativeBuild: '5.12.5.12', nativeManifestSha256: 'b'.repeat(64),
      upstreamCommit: '8a00bf1d914317127c53104c0c4d47baa8f72fb7', upstreamRuntimeSha256: 'c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0',
      upstreamSourceSha256: 'ad77a29fbaecda976c2f1b323376bcb2e5daea099bbf39a42a7da4c30af56a60', runtimeFiles: { 'COPYING': { sha256: 'c'.repeat(64), bytes: 12 } } };
    const digest = await sealNativeCompanion(root, metadata);
    assert.equal((await verifyNativeCompanion(root, digest, 'a'.repeat(40))).nativeBuild, '5.12.5.12');
    await assert.rejects(verifyNativeCompanion(root, digest, 'd'.repeat(40)), /source commit/);
    await assert.rejects(verifyNativeCompanion(root, 'e'.repeat(64), 'a'.repeat(40)), /digest/);
    await fs.writeFile(path.join(root, 'extra'), 'unexpected');
    await assert.rejects(verifyNativeCompanion(root, digest, 'a'.repeat(40)), /inventory/);
    await fs.unlink(path.join(root, 'extra'));
    await fs.appendFile(path.join(root, nativeAssets[0]), 'tamper');
    await assert.rejects(verifyNativeCompanion(root, digest, 'a'.repeat(40)), /integrity/);
    const manifest = JSON.parse(await fs.readFile(path.join(root, 'native-manifest.json')));
    manifest.unexpected = true;
    await fs.writeFile(path.join(root, 'native-manifest.json'), JSON.stringify(manifest));
    await assert.rejects(verifyNativeCompanion(root, await hashFile(path.join(root, 'native-manifest.json')), 'a'.repeat(40)), /schema/);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('native companion refuses a coherent old build and any XML or contract identity mismatch', () => {
  const current = { contract: 'mcp-mail-mutations-v12', mail: '5.12.5', build_version: '5.12.5.12' };
  const xml = '<info><id>mail</id><version>5.12.5.12</version></info>';
  assert.doesNotThrow(() => assertNativeBuildIdentity(current, xml));
  assert.throws(() => assertNativeBuildIdentity({ contract: 'mcp-mail-mutations-v9', mail: '5.12.5', build_version: '5.12.5.9' }, xml.replace('5.12.5.12', '5.12.5.9')), /identity/);
  for (const [key, value] of Object.entries({ contract: 'mcp-mail-mutations-v10', mail: '5.12.6', build_version: '5.12.5.10' })) {
    assert.throws(() => assertNativeBuildIdentity({ ...current, [key]: value }, xml), /identity/);
  }
  for (const invalid of ['', xml.replace('mail', 'other'), xml.replace('5.12.5.12', '5.12.5.9'), xml + '<version>5.12.5.12</version>', xml + '<id>mail</id>']) {
    assert.throws(() => assertNativeBuildIdentity(current, invalid), /identity/);
  }
});

test('reviewed overlay archive preserves both generated boundary manifests alongside runtime files', async () => {
  const root = await fs.mkdtemp(path.resolve('build/native-overlay-test-'));
  try {
    const input = path.join(root, 'input'), output = path.join(root, 'overlay.tar.gz');
    await fs.mkdir(path.join(input, 'mail'), { recursive: true });
    const files = { 'mail/runtime.php': '<?php /* reviewed overlay */', 'manifest.json': '{"contract":"mcp-mail-mutations-v12"}', 'NativeGuardManifest.php': '<?php /* compiled exact boundary */' };
    for (const [name, bytes] of Object.entries(files)) await fs.writeFile(path.join(input, name), bytes);
    archiveNativeOverlay(input, output);
    for (const [name, bytes] of Object.entries(files)) assert.equal(execFileSync('tar', ['-xOf', output, name], { encoding: 'utf8' }), bytes);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});
