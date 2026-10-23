// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { inventory, sealKit, verifyKit } from './integrity.mjs';

function run(command, args, cwd, capture = false) {
  return execFileSync(command, args, { cwd, encoding: 'utf8', stdio: capture ? ['ignore', 'pipe', 'pipe'] : 'inherit' });
}
function fullCommit(revision) {
  if (!/^[a-f0-9]{40}$/.test(revision ?? '')) throw new Error('An explicit full commit SHA (40 lowercase hex characters) is required');
}
export function snapshotRepository(root, revision, destination, bundle) {
  fullCommit(revision);
  if (run('git', ['cat-file', '-t', revision], root, true).trim() !== 'commit') throw new Error('Source revision must be a commit');
  if (fs.existsSync(destination) || fs.existsSync(bundle)) throw new Error('Source destination already exists');
  fs.mkdirSync(destination);
  run('git', ['init', '--quiet'], destination);
  run('git', ['fetch', '--quiet', '--no-tags', path.resolve(root), revision], destination);
  run('git', ['checkout', '--quiet', '--detach', revision], destination);
  run('git', ['bundle', 'create', path.resolve(bundle), 'HEAD'], destination);
  run('git', ['bundle', 'verify', path.resolve(bundle)], destination);
}

export async function prepareKit(root, revision) {
  fullCommit(revision);
  const parent = path.join(root, 'mcp/build/source');
  fs.mkdirSync(parent, { recursive: true });
  const destination = path.join(parent, revision);
  if (fs.existsSync(destination)) throw new Error(`Source kit already exists: ${destination}`);
  const stage = fs.mkdtempSync(path.join(parent, '.prepare-'));
  try {
    const repository = path.join(stage, 'repository'), kit = path.join(stage, 'kit');
    fs.mkdirSync(kit);
    snapshotRepository(root, revision, repository, path.join(kit, 'source.bundle'));
    const app = path.join(repository, 'mcp');
    const info = fs.readFileSync(path.join(app, 'appinfo/info.xml'), 'utf8');
    const version = info.match(/<version>(\d+\.\d+\.\d+)<\/version>/)?.[1];
    if (!version || !/<id>mcp<\/id>/.test(info)) throw new Error('Expected mcp app and a numeric release version');
    // Only run this command for reviewed commits: the frozen build executes their scripts.
    run('npm', ['ci', '--ignore-scripts'], app);
    run('composer', ['package'], app);
    const archive = `mcp-${version}.tar.gz`;
    fs.copyFileSync(path.join(app, 'build', archive), path.join(kit, archive));
    // composer package validates archive paths, types and documentation before extraction.
    const extracted = path.join(stage, 'extracted'); fs.mkdirSync(extracted);
    run('tar', ['-xzf', path.join(kit, archive), '-C', extracted], app);
    const identity = JSON.parse(fs.readFileSync(path.join(extracted, 'mcp/appinfo/source.json'), 'utf8'));
    if (identity.schemaVersion !== 1 || identity.version !== version || identity.sourceCommit !== revision) throw new Error('Package source identity does not match the frozen kit');
    run('tar', ['--no-xattrs', '-czf', path.join(kit, 'runtime-dependencies.tar.gz'), '-C', path.join(extracted, 'mcp'), 'vendor'], app);
    fs.writeFileSync(path.join(kit, 'BUILDING.md'), `# Local source delivery: Nextcloud MCP ${version}\n\nSource commit: ${revision}\n\nThis is an unpublished candidate, not a signed Nextcloud release.\n\n1. Verify manifest.json against a separately trusted SHA-256 using the release verifier, then verify every kit file.\n2. Run \`git clone source.bundle source\` and \`git -C source checkout --detach ${revision}\`. The bundle retains reachable history for documentation provenance; it contains no repository-local Git configuration.\n3. Use the PHP, Composer, Node and npm versions documented in source/docs/en/development/setup.md. In source/mcp run \`npm ci --ignore-scripts\` and \`composer package\`. These commands need access to the locked package registries (or a populated trusted cache). Full offline toolchain reconstruction is not claimed.\n4. The original runtime package is ${archive}. runtime-dependencies.tar.gz contains its complete vendor tree, including PHP source, Composer loader and dependency licenses. The application preferred source, original JavaScript, build scripts, locks and license texts are in source.bundle.\n5. Compare extracted file inventories. Archive timestamps can differ; Composer Git-derived root metadata may differ if you rebuild from a different commit or branch. Never normalize differences away as evidence.\n\nPreserve this kit with its externally recorded manifest digest. Review reachable historical content before publication. Providing this local kit does not itself implement the ordinary-user source offer.\n`);
    const manifestDigest = await sealKit(kit, { sourceCommit: revision, version, status: 'unpublished-candidate', runtimeFiles: await inventory(path.join(extracted, 'mcp')) });
    await verifyKit(kit, manifestDigest);
    fs.renameSync(kit, destination);
    return { directory: destination, sourceCommit: revision, manifestDigest };
  } finally { fs.rmSync(stage, { recursive: true, force: true }); }
}
