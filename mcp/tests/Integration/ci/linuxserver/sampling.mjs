// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** Never interrupt a volume scan with an intentional stop/restart. Errors remain fatal. */
export class SamplingGate {
  scan = null;
  pending = 0;
  change = Promise.resolve();
  async sample(callback) {
    if (this.pending || this.scan) return false;
    this.scan = Promise.resolve().then(callback);
    try { await this.scan; return true; } finally { this.scan = null; }
  }
  async mutate(callback) {
    this.pending++;
    const previous = this.change;
    let release;
    this.change = new Promise(resolve => { release = resolve; });
    try { await previous; await this.scan; return await callback(); }
    finally { this.pending--; release(); }
  }
}
