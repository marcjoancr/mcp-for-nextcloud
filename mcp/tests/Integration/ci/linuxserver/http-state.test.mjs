// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import { httpState } from './http-state.mjs';

test('losing HTTP-created fixtures or pending plans cannot qualify the retained oracle', () => {
  const oracle = { note: { id: 1 }, noteAfterEdit: { content: 'updated' }, deck: { id: 2 },
    deckReadback: { id: 3 }, deckDependencies: [], deckFiles: [], talk: { token: 'room' },
    talkReadback: [], httpChecks: ['allowed', 'denied'], pendingPlans: {
      notes: { arguments: { id: 1 }, plan: { plan_state: 'signed-note' } },
      deck: { arguments: { cardId: 3 }, plan: { plan_state: 'signed-deck' } },
    } };
  assert.deepEqual(httpState(oracle), oracle);
  assert.deepEqual(httpState({ ...oracle, users: { changed: 'fresh credentials' } }), oracle);
  for (const field of Object.keys(oracle)) {
    const incomplete = structuredClone(oracle); delete incomplete[field];
    assert.throws(() => httpState(incomplete), /HTTP oracle/);
  }
  for (const name of ['notes', 'deck']) {
    const incomplete = structuredClone(oracle); delete incomplete.pendingPlans[name].plan.plan_state;
    assert.throws(() => httpState(incomplete), /pending plan/);
  }
  assert.throws(() => httpState({ ...oracle, httpChecks: [] }), /HTTP assertions/);
});
