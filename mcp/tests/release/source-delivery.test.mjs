// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { snapshotRepository } from '../../scripts/release/source.mjs';
import { hashFile, inventory, sealKit, verifyKit, verifyInstalled } from '../../scripts/release/integrity.mjs';

function fixture(t) {
  const parent = path.resolve('build/release-tests');
  fs.mkdirSync(parent, { recursive: true });
  const dir = fs.mkdtempSync(path.join(parent, 'case-'));
  t.after(() => fs.rmSync(dir, { recursive: true, force: true }));
  return dir;
}
function git(root, ...args) {
  return execFileSync('git', ['-C', root, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
}

test('source snapshot binds the requested commit without working files, Git config or unrelated refs', t => {
  const dir = fixture(t), repo = path.join(dir, 'repo');
  fs.mkdirSync(repo); git(repo, 'init', '-b', 'main');
  git(repo, 'config', 'user.name', 'Source test'); git(repo, 'config', 'user.email', 'source@example.invalid');
  fs.writeFileSync(path.join(repo, 'source.txt'), 'original source');
  git(repo, 'add', '.'); git(repo, 'commit', '-m', 'source');
  const revision = git(repo, 'rev-parse', 'HEAD');
  git(repo, 'checkout', '-b', 'unrelated'); fs.writeFileSync(path.join(repo, 'unrelated.txt'), 'other branch only');
  git(repo, 'add', '.'); git(repo, 'commit', '-m', 'unrelated'); const unrelated = git(repo, 'rev-parse', 'HEAD');
  git(repo, 'checkout', 'main'); git(repo, 'config', 'source.testPrivateValue', 'must-not-be-exported');
  fs.writeFileSync(path.join(repo, 'source.txt'), 'dirty source');
  fs.writeFileSync(path.join(repo, 'credentials.local'), 'untracked test sentinel');
  const clone = path.join(dir, 'snapshot'), bundle = path.join(dir, 'source.bundle');
  snapshotRepository(repo, revision, clone, bundle);
  assert.equal(fs.readFileSync(path.join(clone, 'source.txt'), 'utf8'), 'original source');
  assert.equal(fs.existsSync(path.join(clone, 'credentials.local')), false);
  const offline = path.join(dir, 'offline'); git(repo, 'clone', bundle, offline);
  assert.equal(git(offline, 'rev-parse', 'HEAD'), revision);
  assert.throws(() => git(offline, 'cat-file', '-e', unrelated));
  assert.throws(() => git(offline, 'config', '--get', 'source.testPrivateValue'));
  assert.match(git(repo, 'bundle', 'list-heads', bundle), new RegExp(`^${revision} HEAD$`));
  assert.equal(fs.readFileSync(path.join(repo, 'source.txt'), 'utf8'), 'dirty source');
});

test('source snapshot refuses mutable revisions before creating a destination', t => {
  const dir = fixture(t), clone = path.join(dir, 'snapshot');
  for (const revision of ['HEAD', 'main', '--all', '../source', 'f'.repeat(39)]) {
    assert.throws(() => snapshotRepository(dir, revision, clone, path.join(dir, 'source.bundle')), /full commit/);
  }
  assert.equal(fs.existsSync(clone), false);
});

async function kitFixture(t) {
  const dir = fixture(t), kit = path.join(dir, 'kit'), installed = path.join(dir, 'installed');
  fs.mkdirSync(kit); fs.mkdirSync(installed); fs.mkdirSync(path.join(installed, 'appinfo'));
  fs.writeFileSync(path.join(installed, 'appinfo/info.xml'), '<info>candidate</info>');
  fs.writeFileSync(path.join(kit, 'source.bundle'), 'source fixture');
  fs.writeFileSync(path.join(kit, 'mcp-2.0.0.tar.gz'), 'package fixture');
  const metadata = { sourceCommit: 'a'.repeat(40), version: '2.0.0', runtimeFiles: await inventory(installed) };
  const digest = await sealKit(kit, metadata);
  return { kit, installed, digest };
}

test('trusted manifest digest binds the exact kit inventory and detects tampering', async t => {
  const { kit, digest } = await kitFixture(t);
  assert.equal((await verifyKit(kit, digest)).sourceCommit, 'a'.repeat(40));
  await assert.rejects(verifyKit(kit, '0'.repeat(64)), /manifest digest/);
  fs.appendFileSync(path.join(kit, 'source.bundle'), 'tampered');
  await assert.rejects(verifyKit(kit, digest), /source.bundle/);
});

test('extra kit files and symlink substitutions are refused', async t => {
  const { kit, digest } = await kitFixture(t);
  fs.writeFileSync(path.join(kit, 'unexpected'), 'extra');
  await assert.rejects(verifyKit(kit, digest), /inventory/);
  fs.unlinkSync(path.join(kit, 'unexpected'));
  const original = path.join(kit, 'source.bundle'), outside = path.join(path.dirname(kit), 'outside');
  fs.renameSync(original, outside); fs.symlinkSync(outside, original);
  await assert.rejects(verifyKit(kit, digest), /regular|symlink/);
});

test('installed-tree verification checks all files and never repairs changed, missing or extra bytes', async t => {
  const { kit, installed, digest } = await kitFixture(t);
  await verifyInstalled(kit, digest, installed);
  const info = path.join(installed, 'appinfo/info.xml'); fs.writeFileSync(info, 'changed');
  await assert.rejects(verifyInstalled(kit, digest, installed), /appinfo\/info.xml/);
  assert.equal(fs.readFileSync(info, 'utf8'), 'changed');
  fs.unlinkSync(info); await assert.rejects(verifyInstalled(kit, digest, installed), /inventory/);
  fs.writeFileSync(info, '<info>candidate</info>');
  fs.writeFileSync(path.join(installed, 'extra.php'), 'extra');
  await assert.rejects(verifyInstalled(kit, digest, installed), /inventory/);
  fs.unlinkSync(path.join(installed, 'extra.php'));
  fs.symlinkSync(os.tmpdir(), path.join(installed, 'escape'));
  await assert.rejects(verifyInstalled(kit, digest, installed), /regular|symlink/);
});

test('manifest edits cannot authorize a changed installed tree without a new trusted digest', async t => {
  const { kit, installed, digest } = await kitFixture(t);
  const file = path.join(kit, 'manifest.json'), manifest = JSON.parse(fs.readFileSync(file));
  manifest.runtimeFiles = {}; fs.writeFileSync(file, JSON.stringify(manifest));
  await assert.rejects(verifyInstalled(kit, digest, installed), /manifest digest/);
  assert.notEqual(await hashFile(file), digest);
});

test('prototype-like filenames cannot disappear from an installed inventory', async t => {
  const { kit, installed, digest } = await kitFixture(t);
  fs.writeFileSync(path.join(installed, '__proto__'), 'unexpected executable content');
  await assert.rejects(verifyInstalled(kit, digest, installed), /inventory/);
});
