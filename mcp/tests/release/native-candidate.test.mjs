// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync, spawnSync } from 'node:child_process';
import { inventory, sealKit, hashFile } from '../../scripts/release/integrity.mjs';
const helper = new URL('../Integration/ci/candidate-kit.mjs', import.meta.url);
const script = path.resolve('tests/Integration/ci/run-local.sh');
async function fixture(t, { identity = 'a'.repeat(40), extraArchive = false } = {}) {
  const parent = path.resolve('build/release-tests'); fs.mkdirSync(parent, { recursive: true });
  const root = fs.mkdtempSync(path.join(parent, 'candidate-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const kit = path.join(root, 'kit'), app = path.join(root, 'tree/mcp');
  fs.mkdirSync(kit); fs.mkdirSync(app, { recursive: true });
  const legal = JSON.parse(execFileSync('php', ['-r', 'require "scripts/check_package.php"; echo json_encode(\\OCA\\Mcp\\Scripts\\PackageCheck::REQUIRED_LEGAL_FILES);'], { encoding: 'utf8' }));
  for (const name of [...legal, 'mcp/js/admin.js', 'mcp/js/personal.js']) {
    const file = path.join(root, 'tree', name); fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, 'fixture');
  }
  fs.mkdirSync(path.join(app, 'appinfo'));
  fs.writeFileSync(path.join(app, 'appinfo/info.xml'), '<info><id>mcp</id><version>2.0.0</version></info>');
  fs.writeFileSync(path.join(app, 'appinfo/source.json'), JSON.stringify({ schemaVersion: 1, sourceCommit: identity, version: '2.0.0' }));
  const runtimeFiles = await inventory(app);
  if (extraArchive) fs.writeFileSync(path.join(app, 'unlisted.php'), 'extra');
  execFileSync('tar', ['--format=ustar', '-czf', path.join(kit, 'mcp-2.0.0.tar.gz'), '-C', path.dirname(app), 'mcp']);
  for (const name of ['source.bundle', 'runtime-dependencies.tar.gz', 'BUILDING.md']) fs.writeFileSync(path.join(kit, name), 'fixture');
  const digest = await sealKit(kit, { sourceCommit: 'a'.repeat(40), version: '2.0.0', runtimeFiles });
  const marker = path.join(root, 'docker-called');
  fs.writeFileSync(path.join(root, 'docker'), '#!/bin/sh\n: > "$CANDIDATE_DOCKER_MARKER"\nexit 97\n', { mode: 0o700 });
  const env = { ...process.env, PATH: root + path.delimiter + process.env.PATH, CANDIDATE_DOCKER_MARKER: marker };
  const plan = (...args) => spawnSync('sh', [script, '--plan', ...args], { env, encoding: 'utf8' });
  return { root, kit, app, digest, marker, plan };
}
test('candidate plan binds source and runner separately without Docker or rebuilding', async t => {
  const f = await fixture(t);
  const result = f.plan('--candidate-kit', f.kit, f.digest, '35', 'sqlite', 'fresh');
  assert.equal(result.status, 0, result.stderr);
  const plan = JSON.parse(result.stdout);
  assert.equal(plan.candidate.sourceCommit, 'a'.repeat(40));
  assert.equal(plan.candidate.manifestSha256, f.digest);
  assert.match(plan.candidate.runnerCommit, /^[a-f0-9]{40}$/);
  assert.equal(plan.candidate.archiveSha256, await hashFile(path.join(f.kit, 'mcp-2.0.0.tar.gz')));
  assert.equal(fs.existsSync(f.marker), false);
});
test('candidate preflight refuses untrusted inputs and unavailable upgrade baselines before Docker', async t => {
  const f = await fixture(t);
  for (const args of [
    ['--candidate-kit', 'relative', f.digest, '35', 'sqlite', 'fresh'],
    ['--candidate-kit', f.kit, '0'.repeat(64), '35', 'sqlite', 'fresh'],
    ['--candidate-kit', f.kit, f.digest, '35', 'mariadb', 'upgrade-local'],
  ]) assert.notEqual(f.plan(...args).status, 0);
  fs.writeFileSync(path.join(f.kit, 'extra'), 'extra');
  assert.notEqual(f.plan('--candidate-kit', f.kit, f.digest, '35', 'sqlite', 'fresh').status, 0);
  assert.equal(fs.existsSync(f.marker), false);
});
test('candidate inspection rejects mismatched identity and archive inventory even with trusted manifest', async t => {
  const { inspectCandidate } = await import(helper);
  const wrong = await fixture(t, { identity: 'b'.repeat(40) });
  await assert.rejects(inspectCandidate(wrong.kit, wrong.digest), /identity/);
  const extra = await fixture(t, { extraArchive: true });
  await assert.rejects(inspectCandidate(extra.kit, extra.digest), /inventory/);
});
test('staging consumes exact bytes and refuses substitution instead of rebuilding', async t => {
  const { stageCandidate } = await import(helper);
  const f = await fixture(t), work = path.join(f.root, 'work'); fs.mkdirSync(work);
  const record = await stageCandidate(f.kit, f.digest, work);
  assert.equal(await hashFile(path.join(work, 'mcp-branch.tar.gz')), record.archiveSha256);
  assert.equal(record.sourceCommit, 'a'.repeat(40));
  await assert.rejects(stageCandidate(f.kit, f.digest, work), /exist/i);
  fs.appendFileSync(path.join(f.kit, 'mcp-2.0.0.tar.gz'), 'tamper');
  const second = path.join(f.root, 'second'); fs.mkdirSync(second);
  await assert.rejects(stageCandidate(f.kit, f.digest, second), /integrity/);
  assert.equal(fs.existsSync(path.join(second, 'mcp-branch.tar.gz')), false);
});
test('installed gate measures copied app bytes and refuses changed, missing, extra or linked files without repairing them', async t => {
  const { verifyContainerCandidate } = await import(helper);
  const f = await fixture(t), runId = 'mcp-source-12345678'; let calls = 0;
  const command = (binary, args) => {
    assert.equal(binary, 'docker'); calls++;
    if (args[0] === 'inspect') return runId;
    assert.equal(args[0], 'cp'); fs.cpSync(f.app, args[2], { recursive: true }); return '';
  };
  const run = () => verifyContainerCandidate({ kit: f.kit, digest: f.digest, container: runId + '-nc', runId, output: path.join(f.root, 'installed.json') }, command);
  await run(); assert.equal(JSON.parse(fs.readFileSync(path.join(f.root, 'installed.json'))).status, 'passed');
  const file = path.join(f.app, 'js/admin.js'); fs.writeFileSync(file, 'changed');
  await assert.rejects(run(), /integrity/); assert.equal(fs.readFileSync(file, 'utf8'), 'changed');
  fs.unlinkSync(file); await assert.rejects(run(), /inventory/);
  fs.writeFileSync(file, 'fixture'); fs.writeFileSync(path.join(f.app, 'extra'), 'extra');
  await assert.rejects(run(), /inventory/); fs.unlinkSync(path.join(f.app, 'extra'));
  fs.symlinkSync(file, path.join(f.app, 'linked')); await assert.rejects(run(), /regular|symlink/);
  const before = calls;
  await assert.rejects(verifyContainerCandidate({ kit: f.kit, digest: f.digest, container: 'production', runId, output: path.join(f.root, 'bad.json') }, command), /owned/);
  assert.equal(calls, before);
});

test('explicit empty kit cannot fall back to a checkout plan', async t => {
  const f = await fixture(t);
  const result = f.plan('--candidate-kit', '', f.digest, '35', 'sqlite', 'fresh');
  assert.notEqual(result.status, 0);
  assert.equal(fs.existsSync(f.marker), false);
});

test('empty baseline input and baseline hash substitution cannot reach Docker', async t => {
  const f = await fixture(t);
  for (const [file, digest] of [['', f.digest], [path.join(f.kit, 'mcp-2.0.0.tar.gz'), '0'.repeat(64)]]) {
    const result = f.plan('--candidate-kit', f.kit, f.digest, '--baseline-package', file, digest, '35', 'mariadb', 'upgrade-upstream-120');
    assert.notEqual(result.status, 0);
  }
  const fresh = f.plan('--candidate-kit', f.kit, f.digest, '--baseline-package', '', f.digest, '35', 'sqlite', 'fresh');
  assert.notEqual(fresh.status, 0);
  assert.equal(fs.existsSync(f.marker), false);
});

test('trusted archive with a symlink is rejected before extraction', async t => {
  const { inspectCandidate } = await import(helper);
  const f = await fixture(t);
  fs.symlinkSync('/etc/passwd', path.join(f.app, 'linked'));
  execFileSync('tar', ['--format=ustar', '-czf', path.join(f.kit, 'mcp-2.0.0.tar.gz'), '-C', path.dirname(f.app), 'mcp']);
  const manifest = JSON.parse(fs.readFileSync(path.join(f.kit, 'manifest.json')));
  fs.unlinkSync(path.join(f.kit, 'manifest.json'));
  const digest = await sealKit(f.kit, { sourceCommit: manifest.sourceCommit, version: manifest.version, runtimeFiles: manifest.runtimeFiles });
  await assert.rejects(inspectCandidate(f.kit, digest), /unsafe archive type/);
});

test('installed gate refuses a name-matching container with a different owner before copying', async t => {
  const { verifyContainerCandidate } = await import(helper);
  const f = await fixture(t), calls = [];
  await assert.rejects(verifyContainerCandidate({ kit: f.kit, digest: f.digest, container: 'mcp-source-owner-nc', runId: 'mcp-source-owner', output: path.join(f.root, 'bad.json') }, (binary, args) => {
    calls.push(args[0]); return 'another-run';
  }), /not owned/);
  assert.deepEqual(calls, ['inspect']);
});

test('candidate inspection refuses an unqualified runtime before accepting valid bytes', async t => {
  const f = await fixture(t);
  const code = `Object.defineProperty(process.versions, 'node', {value: '24.21.0'}); const {inspectCandidate} = await import(${JSON.stringify(helper.href)}); await inspectCandidate(${JSON.stringify(f.kit)}, ${JSON.stringify(f.digest)});`;
  const result = spawnSync(process.execPath, ['--input-type=module', '--eval', code], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /requires Node 24.22.1/);
});

test('a correctly hashed archive cannot impersonate another historical baseline', async t => {
  const f = await fixture(t), archive = path.join(f.kit, 'mcp-2.0.0.tar.gz');
  const result = f.plan('--candidate-kit', f.kit, f.digest, '--baseline-package', archive, await hashFile(archive), '35', 'mariadb', 'upgrade-upstream-120');
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /reviewed baseline/);
  assert.equal(fs.existsSync(f.marker), false);
});

test('baseline inspection binds the reviewed commit, size and digest to the actual bytes', async t => {
  const { inspectBaseline } = await import(helper);
  const f = await fixture(t), file = path.join(f.kit, 'mcp-2.0.0.tar.gz');
  const digest = await hashFile(file), expected = { sourceCommit: 'b'.repeat(40), archiveSha256: digest, archiveBytes: fs.statSync(file).size, version: '1.1.2' };
  const result = await inspectBaseline(file, digest, expected);
  assert.equal(result.sourceCommit, expected.sourceCommit);
  assert.equal(result.sha256, digest);
  await assert.rejects(inspectBaseline(file, digest, { ...expected, archiveSha256: '0'.repeat(64) }), /reviewed baseline/);
  fs.appendFileSync(file, 'changed'); await assert.rejects(inspectBaseline(file, digest, expected), /integrity/);
});

test('the 1.2.1 upgrade fixture requires release inputs and rejects substitution before Docker', async t => {
  const f = await fixture(t);
  const cell = ['35', 'mariadb', 'upgrade-upstream-121'];
  assert.notEqual(f.plan(...cell).status, 0);
  const other = path.join(f.kit, 'mcp-2.0.0.tar.gz');
  const result = f.plan('--candidate-kit', f.kit, f.digest, '--baseline-package', other, await hashFile(other), ...cell);
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /reviewed baseline/);
  assert.equal(fs.existsSync(f.marker), false);
});

test('Apache baseline admission rejects a wrong file inventory even when the archive digest matches', async t => {
  const { inspectBaseline } = await import(helper);
  const f = await fixture(t);
  const file = path.join(f.kit, 'mcp-2.0.0.tar.gz');
  const digest = await hashFile(file);
  const expected = { sourceCommit: 'a'.repeat(40), version: '2.0.0', archiveBytes: fs.statSync(file).size,
    archiveSha256: digest, inventorySha256: '0'.repeat(64), runtimeFileCount: Object.keys(await inventory(f.app)).length };
  await assert.rejects(inspectBaseline(file, digest, expected), /inventory mismatch/);
  assert.equal(fs.existsSync(f.marker), false);
});

test('incomplete catalog pins cannot downgrade a reviewed origin to archive-only admission', async t => {
  const { inspectBaseline } = await import(helper);
  const f = await fixture(t);
  const file = path.join(f.kit, 'mcp-2.0.0.tar.gz'), digest = await hashFile(file);
  for (const version of ['1.2.0', '1.2.1']) {
    const pins = { version, sourceCommit: 'b'.repeat(40), archiveSha256: digest, archiveBytes: fs.statSync(file).size };
    await assert.rejects(inspectBaseline(file, digest, pins), /inventory pins/);
    await assert.rejects(inspectBaseline(file, digest, { ...pins, runtimeFileCount: 574 }), /inventory pins/);
  }
});

test('an upgrade plan requires a baseline commit even when the matrix entry is missing', async t => {
  const f = await fixture(t);
  const plan = { mode: 'upgrade-upstream-121', baseline_commit: null };
  const result = spawnSync(process.execPath, [helper.pathname, 'plan', f.kit, f.digest, JSON.stringify(plan)], { encoding: 'utf8' });
  assert.notEqual(result.status, 0);
  assert.match(result.stderr, /baseline commit/);
});
