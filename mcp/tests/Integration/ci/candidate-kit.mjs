// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { hashFile, inventory, verifyKit, verifyInstalled } from '../../../scripts/release/integrity.mjs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const checkPackage = path.join(root, 'scripts/check_package.php');
function command(binary, args) {
  return execFileSync(binary, args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024 });
}
function scratch() {
  const parent = path.join(root, 'build/candidate-validation');
  fs.mkdirSync(parent, { recursive: true });
  return fs.mkdtempSync(path.join(parent, 'case-'), { mode: 0o700 });
}
function runtime() {
  if (process.versions.node !== '24.22.1') throw new Error('Candidate verification requires Node 24.22.1');
}
function absolute(value) {
  if (!value || !path.isAbsolute(value)) throw new Error('An absolute input path is required');
}
function identity(app, manifest) {
  const source = JSON.parse(fs.readFileSync(path.join(app, 'appinfo/source.json'), 'utf8'));
  const xml = fs.readFileSync(path.join(app, 'appinfo/info.xml'), 'utf8');
  if (source.schemaVersion !== 1 || source.sourceCommit !== manifest.sourceCommit || source.version !== manifest.version
    || !/<id>mcp<\/id>/.test(xml) || xml.match(/<version>([^<]+)<\/version>/)?.[1] !== manifest.version) {
    throw new Error('Runtime source identity does not match the kit');
  }
}
export async function inspectCandidate(kit, digest) {
  runtime();
  absolute(kit);
  const manifest = await verifyKit(kit, digest);
  const archiveName = `mcp-${manifest.version}.tar.gz`;
  const expected = ['BUILDING.md', archiveName, 'runtime-dependencies.tar.gz', 'source.bundle'].sort();
  if (JSON.stringify(Object.keys(manifest.files).sort()) !== JSON.stringify(expected)) throw new Error('Unexpected source kit inventory');
  const temporary = scratch();
  try {
    const archive = path.join(temporary, archiveName);
    fs.copyFileSync(path.join(kit, archiveName), archive, fs.constants.COPYFILE_EXCL);
    if (await hashFile(archive) !== manifest.files[archiveName].sha256) throw new Error('Copied archive integrity differs');
    // Check destinations and entry types before extracting any archive member.
    command('php', [checkPackage, archive, path.join(temporary, 'not-extracted')]);
    command('tar', ['-xzf', archive, '-C', temporary, '--no-same-owner', '--no-same-permissions']);
    const app = path.join(temporary, 'mcp');
    identity(app, manifest);
    await verifyInstalled(kit, digest, app);
    command('php', [checkPackage, archive, path.join(app, 'templates'), path.join(app, 'lib')]);
    const runnerFiles = {};
    for (const name of ['tests/Integration/ci/candidate-kit.mjs', 'tests/Integration/ci/run-local.sh',
      'tests/Integration/ci/harness.sh', 'scripts/release/accept.mjs', 'scripts/release/integrity.mjs', 'scripts/check_package.php',
      'tests/Integration/ci/matrix.json', 'tests/Integration/ci/baseline-packages.json']) {
      runnerFiles[name] = await hashFile(path.join(root, name));
    }
    return { sourceCommit: manifest.sourceCommit, version: manifest.version, manifestSha256: digest,
      archiveSha256: manifest.files[archiveName].sha256, archiveName,
      runnerCommit: command('git', ['rev-parse', 'HEAD']).trim(),
      runnerDirty: command('git', ['status', '--porcelain']).trim() !== '', runnerFiles };
  } finally { fs.rmSync(temporary, { recursive: true, force: true }); }
}
export async function stageCandidate(kit, digest, work) {
  const record = await inspectCandidate(kit, digest);
  const staged = path.join(work, 'candidate-kit');
  fs.mkdirSync(staged); // Never replace a previous run's inputs.
  for (const name of fs.readdirSync(kit)) fs.copyFileSync(path.join(kit, name), path.join(staged, name), fs.constants.COPYFILE_EXCL);
  await verifyKit(staged, digest);
  const archive = path.join(work, 'mcp-branch.tar.gz');
  fs.copyFileSync(path.join(staged, record.archiveName), archive, fs.constants.COPYFILE_EXCL);
  if (await hashFile(archive) !== record.archiveSha256) throw new Error('Staged archive integrity differs');
  fs.writeFileSync(path.join(work, 'candidate.json'), JSON.stringify(record, null, 2) + '\n', { flag: 'wx' });
  return record;
}
export async function verifyContainerCandidate({ kit, digest, container, runId, output }, execute = command) {
  runtime();
  if (!/^mcp-(?:it|source)-[a-z0-9-]+$/.test(runId ?? '') || container !== `${runId}-nc`) throw new Error('Expected an owned acceptance container');
  const owner = execute('docker', ['inspect', '--format', '{{ index .Config.Labels "mcp.integration.run" }}', container]).trim();
  if (owner !== runId) throw new Error('Container is not owned by this acceptance run');
  const temporary = scratch(), app = path.join(temporary, 'installed');
  const record = { status: 'failed', sourceCommit: null, manifestSha256: digest, installedFiles: null };
  try {
    execute('docker', ['cp', `${container}:/var/www/html/custom_apps/mcp`, app]);
    record.installedFiles = await inventory(app);
    const manifest = await verifyInstalled(kit, digest, app);
    identity(app, manifest);
    record.sourceCommit = manifest.sourceCommit;
    record.status = 'passed';
    return record;
  } finally {
    fs.writeFileSync(output, JSON.stringify(record, null, 2) + '\n');
    fs.rmSync(temporary, { recursive: true, force: true });
  }
}
export async function inspectBaseline(file, digest, expected) {
  runtime();
  absolute(file);
  if (!expected || !/^[a-f0-9]{40}$/.test(expected.sourceCommit ?? '') || digest !== expected.archiveSha256) throw new Error('Input does not match the reviewed baseline');
  const stat = fs.lstatSync(file);
  if (!/^[a-f0-9]{64}$/.test(digest ?? '') || !stat.isFile() || stat.size !== expected.archiveBytes || await hashFile(file) !== digest) throw new Error('Baseline package integrity differs');
  return { file, sha256: digest, sourceCommit: expected.sourceCommit, version: expected.version };
}
function reviewedBaseline(mode, commit) {
  const catalog = JSON.parse(fs.readFileSync(new URL('./baseline-packages.json', import.meta.url)));
  const expected = catalog.packages?.[mode];
  if (catalog.schemaVersion !== 1 || !expected || expected.sourceCommit !== commit) throw new Error('No matching reviewed baseline for this mode/commit');
  return expected;
}
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const [action, kit, digest, target, ...extra] = process.argv.slice(2);
    if (action === 'plan' && [0, 2].includes(extra.length)) {
      const plan = JSON.parse(target);
      plan.candidate = await inspectCandidate(kit, digest);
      if (plan.baseline_commit) {
        if (extra.length !== 2) throw new Error('A reviewed --baseline-package PATH SHA256 is required for candidate upgrades');
        plan.baselinePackage = await inspectBaseline(...extra, reviewedBaseline(plan.mode, plan.baseline_commit));
      } else if (extra.length) throw new Error('Fresh cells cannot consume a baseline package');
      console.log(JSON.stringify(plan, null, 2));
    } else if (action === 'stage' && extra.length === 0) {
      console.log(JSON.stringify(await stageCandidate(kit, digest, target)));
    } else if (action === 'baseline' && extra.length === 0) {
      const matrix = JSON.parse(fs.readFileSync(new URL('./matrix.json', import.meta.url)));
      console.log(JSON.stringify(await inspectBaseline(kit, digest, reviewedBaseline(target, matrix.baselines[target]))));
    } else if (action === 'installed' && extra.length === 0) {
      const record = JSON.parse(fs.readFileSync(path.join(kit, 'candidate.json')));
      await verifyContainerCandidate({ kit: path.join(kit, 'candidate-kit'), digest: record.manifestSha256,
        container: digest, runId: target, output: path.join(kit, 'artifacts/installed-candidate.json') });
    } else throw new Error('Expected plan KIT DIGEST PLAN_JSON [BASELINE SHA256], stage KIT DIGEST WORK baseline ARCHIVE DIGEST MODE or installed WORK CONTAINER RUN_ID');
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
