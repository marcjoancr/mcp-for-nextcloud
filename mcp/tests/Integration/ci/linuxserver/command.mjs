// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { spawn } from 'node:child_process';

/** Bounded subprocesses; no shell, inherited stdin, or command arguments in logs. */
export async function command(program, args, { timeout = 60000, input = '', signal } = {}) {
  return await new Promise((resolve, reject) => {
    const child = spawn(program, args, { stdio: ['pipe', 'pipe', 'pipe'], signal });
    let out = '', err = '', overflow = false, expired = false;
    const timer = setTimeout(() => { expired = true; child.kill('SIGKILL'); }, timeout);
    const collect = target => chunk => {
      if (out.length + err.length + chunk.length > 2 * 1024 * 1024) { overflow = true; child.kill('SIGKILL'); return; }
      if (target === 'out') out += chunk; else err += chunk;
    };
    child.stdout.on('data', collect('out')); child.stderr.on('data', collect('err'));
    child.on('error', error => { clearTimeout(timer); reject(error); });
    child.on('close', code => { clearTimeout(timer); resolve({ code: expired || overflow ? 124 : code ?? 1, out, err }); });
    child.stdin.on('error', () => {}); child.stdin.end(input);
  });
}
