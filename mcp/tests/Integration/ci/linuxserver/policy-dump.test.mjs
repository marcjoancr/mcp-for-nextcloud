// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyPolicyDump } from './policy-dump.mjs';

test('actual dump rows must match the independent oracle, including mutex sequence', () => {
  const expected = { mcp_policy_state: [{ id: 1, lock_seq: '7' }] };
  const dump = 'COPY public.oc_mcp_policy_state (id, lock_seq) FROM stdin;\n1\t7\n\\.\n';
  assert.equal(verifyPolicyDump(dump, expected), 1);
  assert.throws(() => verifyPolicyDump(dump.replace('1\t7', '1\t8'), expected), /mcp_policy_state/);
  assert.throws(() => verifyPolicyDump('', expected), /missing/);
  assert.throws(() => verifyPolicyDump(dump + dump, expected), /duplicate/i);
});

test('COPY decoding retains nulls, Unicode and escaped bytes and filters only the recorded app scope', () => {
  const dump = 'COPY public.oc_preferences (userid, appid, configvalue) FROM stdin;\nuser\tmcp\tline\\n\\303\\251\\t\\\\end\nother\tcore\tunrelated\nnone\tmcp\t\\N\n\\.\n';
  assert.equal(verifyPolicyDump(dump, { preferences: [
    { userid: 'user', appid: 'mcp', configvalue: 'line\né\t\\end' },
    { userid: 'none', appid: 'mcp', configvalue: null },
  ] }), 1);
  assert.throws(() => verifyPolicyDump(dump.replace('\\.\n', '').trimEnd(), { preferences: [] }), /unterminated/i);
});

test('native quoted column identifiers are decoded as names, not skipped tables', () => {
  assert.equal(verifyPolicyDump('COPY public.oc_mcp_oauth_clients (client_id, "position") FROM stdin;\nfixture\t0\n\\.\n',
    { mcp_oauth_clients: [{ client_id: 'fixture', position: 0 }] }), 1);
});
