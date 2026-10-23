// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { writeIdentity } from '../../scripts/release/identity.mjs';

test('only clean committed inputs receive packaged source identity; dirty builds cannot impersonate the commit', t => {
  const parent = path.resolve('build/release-tests'); fs.mkdirSync(parent, { recursive: true });
  const root = fs.mkdtempSync(path.join(parent, 'identity-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const git = (...args) => execFileSync('git', ['-C', root, ...args], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  fs.mkdirSync(path.join(root, 'mcp/appinfo'), { recursive: true });
  fs.writeFileSync(path.join(root, 'mcp/appinfo/info.xml'), '<info><version>2.0.0</version></info>');
  fs.writeFileSync(path.join(root, '.gitignore'), '/build/\n');
  git('init', '-b', 'main'); git('config', 'user.name', 'Source test'); git('config', 'user.email', 'source@example.invalid');
  git('add', '.'); git('commit', '-m', 'source');
  const stage = path.join(root, 'build/app'); fs.mkdirSync(path.join(stage, 'appinfo'), { recursive: true });
  assert.equal(writeIdentity(root, stage), true);
  assert.equal(JSON.parse(fs.readFileSync(path.join(stage, 'appinfo/source.json'))).sourceCommit, git('rev-parse', 'HEAD'));
  fs.unlinkSync(path.join(stage, 'appinfo/source.json'));
  fs.appendFileSync(path.join(root, 'mcp/appinfo/info.xml'), '\n<!-- dirty -->');
  assert.equal(writeIdentity(root, stage), false);
  assert.equal(fs.existsSync(path.join(stage, 'appinfo/source.json')), false);
});
