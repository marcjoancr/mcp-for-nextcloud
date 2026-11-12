// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { inventory, hashFile, verifyKit } from './integrity.mjs';

export const nativeAssets = Object.freeze(['mail-upstream-runtime.tar.gz', 'mail-upstream-source.tar.gz', 'mail-reviewed-overlay.tar.gz', 'mail-assembled-runtime.tar.gz', 'NATIVE-BUILDING.md']);
export const upstreamPins = Object.freeze({ upstreamCommit: '8a00bf1d914317127c53104c0c4d47baa8f72fb7', upstreamRuntimeSha256: 'c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0', upstreamSourceSha256: 'ad77a29fbaecda976c2f1b323376bcb2e5daea099bbf39a42a7da4c30af56a60' });
function exactKeys(actual, expected, message) {
  if (!actual || typeof actual !== 'object' || Array.isArray(actual) || JSON.stringify(Object.keys(actual).sort()) !== JSON.stringify([...expected].sort())) throw new Error(message);
}
function validate(m) {
  exactKeys(m, ['schemaVersion', 'sourceCommit', 'nativeBuild', 'nativeManifestSha256', ...Object.keys(upstreamPins), 'runtimeFiles', 'files'], 'Invalid native companion schema');
  if (m.schemaVersion !== 1 || !/^[a-f0-9]{40}$/.test(m.sourceCommit) || m.nativeBuild !== '5.12.5.13' || !/^[a-f0-9]{64}$/.test(m.nativeManifestSha256) || Object.entries(upstreamPins).some(([k,v]) => m[k] !== v)) throw new Error('Invalid native companion pins');
  exactKeys(m.files, nativeAssets, 'Invalid native asset inventory');
  for (const files of [m.files, m.runtimeFiles]) {
    if (!files || typeof files !== 'object' || Array.isArray(files) || !Object.keys(files).length) throw new Error('Invalid native inventory');
    for (const [name, record] of Object.entries(files)) {
      if (!name || /[\\\x00-\x1f\x7f]/.test(name) || name.startsWith('/') || name.split('/').some(p => !p || p === '.' || p === '..') || !record || !/^[a-f0-9]{64}$/.test(record.sha256) || !Number.isSafeInteger(record.bytes) || record.bytes < 0) throw new Error('Invalid native inventory record');
      exactKeys(record, ['sha256', 'bytes'], 'Invalid native inventory record schema');
    }
  }
}
export async function sealNativeCompanion(directory, metadata) {
  const manifest = { schemaVersion: 1, ...metadata, files: await inventory(directory) }; validate(manifest);
  await fs.writeFile(path.join(directory, 'native-manifest.json'), JSON.stringify(manifest, null, 2) + '\n', { flag: 'wx' });
  return hashFile(path.join(directory, 'native-manifest.json'));
}
export async function verifyNativeCompanion(directory, trustedDigest, sourceCommit) {
  if (!/^[a-f0-9]{64}$/.test(trustedDigest ?? '')) throw new Error('Trusted native manifest digest required');
  if (!(await fs.lstat(directory)).isDirectory()) throw new Error('Native directory must not be a symlink');
  const file = path.join(directory, 'native-manifest.json'), stat = await fs.lstat(file);
  if (!stat.isFile() || stat.size > 4 * 1024 * 1024) throw new Error('Native manifest must be a bounded regular file');
  const bytes = await fs.readFile(file);
  if (createHash('sha256').update(bytes).digest('hex') !== trustedDigest) throw new Error('Native manifest digest mismatch');
  const manifest = JSON.parse(bytes); validate(manifest);
  if (manifest.sourceCommit !== sourceCommit) throw new Error('Native companion source commit mismatch');
  const actual = await inventory(directory); delete actual['native-manifest.json'];
  exactKeys(actual, nativeAssets, 'Native companion inventory mismatch');
  if (JSON.stringify(actual) !== JSON.stringify(manifest.files)) throw new Error('Native companion integrity mismatch');
  return manifest;
}
function run(command, args, cwd) { return execFileSync(command, args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 16 * 1024 * 1024 }); }
function archive(source, destination, entries = ['mail']) { run('tar', ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner', '--mode=u=rwX,go=rX', '-czf', destination, '-C', source, ...entries]); }
export function archiveNativeOverlay(source, destination) { archive(source, destination, ['mail', 'manifest.json', 'NativeGuardManifest.php']); }
export function assertNativeBuildIdentity(manifest, infoXml) {
  const ids = typeof infoXml === 'string' ? [...infoXml.matchAll(/<id>\s*([^<]+?)\s*<\/id>/g)].map(match => match[1]) : [];
  const versions = typeof infoXml === 'string' ? [...infoXml.matchAll(/<version>\s*([^<]+?)\s*<\/version>/g)].map(match => match[1]) : [];
  if (!manifest || manifest.contract !== 'mcp-mail-mutations-v13' || manifest.mail !== '5.12.5' || manifest.build_version !== '5.12.5.13'
    || ids.length !== 1 || ids[0] !== 'mail' || versions.length !== 1 || versions[0] !== manifest.build_version) throw new Error('Native build identity must be exact Mail v13');
}
async function assemble(repository, original, directory) {
  await fs.mkdir(directory);
  const overlay = path.join(directory, 'overlay'), assembled = path.join(directory, 'assembled'); await fs.mkdir(assembled);
  run('php', ['native/mail-guard/build.php', original, overlay], repository);
  if (await hashFile(path.join(overlay, 'NativeGuardManifest.php')) !== await hashFile(path.join(repository, 'mcp/lib/Modules/Mail/Integration/NativeGuardManifest.php'))) throw new Error('Frozen native boundary differs from rebuilt overlay');
  assertNativeBuildIdentity(JSON.parse(await fs.readFile(path.join(overlay, 'manifest.json'), 'utf8')), await fs.readFile(path.join(overlay, 'mail/appinfo/info.xml'), 'utf8'));
  run('tar', ['-xzf', original, '-C', assembled]);
  await fs.cp(path.join(overlay, 'mail'), path.join(assembled, 'mail'), { recursive: true });
  return { overlay, assembled, runtimeFiles: await inventory(path.join(assembled, 'mail')) };
}
/** Never fetches or installs. Executes only build sources from the explicitly reviewed, verified MCP kit. */
export async function prepareNativeCompanion(root, kit, kitDigest, original, preferred, destination) {
  const mcp = await verifyKit(kit, kitDigest);
  if (await hashFile(original) !== upstreamPins.upstreamRuntimeSha256 || await hashFile(preferred) !== upstreamPins.upstreamSourceSha256) throw new Error('Pinned upstream archive mismatch');
  const build = await fs.realpath(path.join(root, 'mcp/build')), parent = await fs.realpath(path.dirname(destination));
  if (parent !== build && !parent.startsWith(build + path.sep)) throw new Error('Native output must stay below project mcp/build');
  await fs.mkdir(destination);
  const stage = await fs.mkdtemp(path.join(parent, '.native-prepare-'));
  try {
    const repository = path.join(stage, 'source');
    run('git', ['clone', '--quiet', '--no-checkout', path.resolve(kit, 'source.bundle'), repository]);
    run('git', ['checkout', '--quiet', '--detach', mcp.sourceCommit], repository);
    await fs.mkdir(path.join(repository, 'mcp/build'), { recursive: true });
    const first = await assemble(repository, path.resolve(original), path.join(repository, 'mcp/build/first'));
    const second = await assemble(repository, path.resolve(original), path.join(repository, 'mcp/build/reconstruction'));
    if (JSON.stringify(first.runtimeFiles) !== JSON.stringify(second.runtimeFiles)) throw new Error('Native reconstruction inventory differs');
    await fs.copyFile(original, path.join(destination, nativeAssets[0])); await fs.copyFile(preferred, path.join(destination, nativeAssets[1]));
    archiveNativeOverlay(first.overlay, path.join(destination, nativeAssets[2])); archive(first.assembled, path.join(destination, nativeAssets[3]));
    const nativeManifestSha256 = await hashFile(path.join(first.overlay, 'manifest.json'));
    await fs.writeFile(path.join(destination, 'NATIVE-BUILDING.md'), `# Guarded Mail corresponding source\n\nMCP source commit: ${mcp.sourceCommit}\nNative build: 5.12.5.13\nNative boundary manifest SHA-256: ${nativeManifestSha256}\nUpstream source commit: ${upstreamPins.upstreamCommit}\n\nVerify native-manifest.json against the release's independently recorded digest and verify every companion asset. Preserve the separate five-file MCP kit unchanged.\n\nThe original runtime preserves dependency sources, notices, licenses and embedded JavaScript source maps. The preferred-source snapshot includes src, Composer/npm manifests, locks, build configuration and patch inputs. Reviewed overlay/build sources are in the MCP source.bundle. Reproduction uses exact original shipped chunks and reviewed transformations; an independently bit-identical upstream webpack rebuild and an offline toolchain are not claimed.\n\n1. Verify the MCP kit, clone source.bundle and check out the exact MCP commit above.\n2. Create mcp/build/reconstruct in that checkout. Run php native/mail-guard/build.php /absolute/mail-upstream-runtime.tar.gz /absolute/checkout/mcp/build/reconstruct/overlay\n3. Extract mail-upstream-runtime.tar.gz into a fresh directory and recursively overlay reconstruct/overlay/mail into its mail directory. Retain original maps, licenses and untouched files.\n4. Compare every assembled regular file SHA-256 and size against runtimeFiles in native-manifest.json, rejecting missing/extra paths and links/special files. Selected native fingerprints are not a full inventory.\n5. The reviewed overlay and complete assembled runtime are also provided as separate archives. Preserve their notices in redistributions.\n\nPreparation reconstructs the full runtime twice from the frozen bundle and original archive and requires identical inventories. Installation and enabling production sending are separate operator actions.\n`);
    const digest = await sealNativeCompanion(destination, { sourceCommit: mcp.sourceCommit, nativeBuild: '5.12.5.13', nativeManifestSha256, ...upstreamPins, runtimeFiles: first.runtimeFiles });
    await verifyNativeCompanion(destination, digest, mcp.sourceCommit);
    return { directory: destination, manifestSha256: digest, sourceCommit: mcp.sourceCommit, nativeManifestSha256, runtimeFiles: Object.keys(first.runtimeFiles).length, reconstruction: 'two exact full inventories match' };
  } finally { await fs.rm(stage, { recursive: true, force: true }); }
}
