// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cleanupSourceFixture } from '../../scripts/release/fixture-cleanup.mjs';

const runId = 'mcp-source-owned';
const resources = { runId, container: `${runId}-nc`, network: `${runId}-net` };

test('cleanup removes only matching labeled resources and attempts the network after a container error', () => {
  for (const failure of ['none', 'container', 'network', 'foreign']) {
    const removed = [];
    const inspected = [];
    const errors = cleanupSourceFixture((_binary, args) => {
      if (args[1] === 'inspect') {
        const kind = args[0]; inspected.push(kind);
        if (failure === kind) throw new Error('Docker inspection unavailable');
        return failure === 'foreign' ? 'another-run' : runId;
      }
      removed.push(args.at(-1));
      return '';
    }, resources);
    assert.deepEqual(inspected, ['container', 'network']);
    if (failure === 'none') {
      assert.deepEqual(errors, []);
      assert.deepEqual(removed, [resources.container, resources.network]);
    } else {
      assert.ok(errors.length > 0, 'uncertain cleanup must fail acceptance');
      assert.deepEqual(removed, failure === 'container' ? [resources.network] : failure === 'network' ? [resources.container] : []);
    }
  }
});

test('failed removal is reported without exposing raw Docker errors', () => {
  const errors = cleanupSourceFixture((_binary, args) => {
    if (args[1] === 'inspect') return runId;
    throw new Error('private engine diagnostic');
  }, resources);
  assert.equal(errors.length, 2);
  assert.ok(errors.every(message => !message.includes('private engine diagnostic')));
});
