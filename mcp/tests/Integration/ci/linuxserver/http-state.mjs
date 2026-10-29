// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
const fields = ['note', 'noteAfterEdit', 'deck', 'deckReadback', 'deckDependencies', 'deckFiles', 'talk', 'talkReadback', 'httpChecks', 'pendingPlans'];
/** HTTP-created state must survive transfer and credential refresh unchanged. */
export function httpState(oracle) {
  const state = {};
  for (const name of fields) {
    if (!oracle?.[name] || typeof oracle[name] !== 'object') throw new Error(`Incomplete HTTP oracle: ${name}`);
    state[name] = oracle[name];
  }
  if (!Array.isArray(state.httpChecks) || !state.httpChecks.length || state.httpChecks.some(value => typeof value !== 'string')) throw new Error('Missing HTTP assertions');
  for (const name of ['notes', 'deck']) {
    const pending = state.pendingPlans[name];
    if (!pending?.arguments || typeof pending.arguments !== 'object' || typeof pending.plan?.plan_state !== 'string' || !pending.plan.plan_state) throw new Error(`Missing pending plan: ${name}`);
  }
  return state;
}
