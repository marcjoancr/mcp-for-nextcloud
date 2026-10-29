// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import { SamplingGate } from './sampling.mjs';

test('intentional restart waits for a complete measurement and prevents overlapping scans', async () => {
  const gate = new SamplingGate(), events = [];
  let release;
  const held = new Promise(resolve => { release = resolve; });
  const sample = gate.sample(async () => { events.push('reading'); await held; events.push('measured'); });
  const mutation = gate.mutate(async () => { events.push('restart'); assert.equal(await gate.sample(async () => events.push('unsafe')), false); });
  await Promise.resolve();
  assert.deepEqual(events, ['reading']);
  release(); await sample; await mutation;
  assert.deepEqual(events, ['reading', 'measured', 'restart']);
  await assert.rejects(gate.mutate(async () => { throw new Error('restart failed'); }));
  assert.equal(await gate.sample(async () => events.push('next')), true);
});
