// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { assertOwned, ownershipLabel } from './safety.mjs';

/** Only this invocation's recorded, label-verified resources may be removed. */
export class Resources {
  constructor(runId, command, persist) { this.runId = runId; this.command = command; this.persist = persist; this.items = []; }
  async create(kind, name, args) {
    // Persist the unique name before creation: a timeout may hide a successful create.
    const item = { kind, name, id: null };
    this.items.push(item);
    await this.persist(this.items);
    const result = await this.command([kind, 'create', ...(kind === 'container' ? ['--name', name] : []), '--label', `${ownershipLabel}=${this.runId}`, ...args, ...(kind === 'container' ? [] : [name])]);
    if (result.code !== 0) throw new Error(`${kind} creation failed`);
    const row = JSON.parse((await this.checked([kind, 'inspect', name])).out)[0];
    item.id = kind === 'volume' ? row.Name : row.Id;
    assertOwned(kind, item.id, row, this.runId);
    await this.persist(this.items);
    return item.id;
  }
  async checked(args) {
    const result = await this.command(args);
    if (result.code !== 0) throw new Error(`Docker ${args[0]} ${args[1]} failed`);
    return result;
  }
  async assertActive(kind, id) {
    const item = this.items.find(item => item.kind === kind && item.id === id && !item.removed);
    if (!item) throw new Error('Resource is not an active owned resource');
    const row = JSON.parse((await this.checked([kind, 'inspect', id])).out)[0];
    assertOwned(kind, id, row, this.runId);
    return item;
  }
  async retire(kind, id) {
    if (!['container', 'volume'].includes(kind)) throw new Error('Unsupported retirement');
    const item = await this.assertActive(kind, id);
    await this.checked([kind, 'rm', ...(kind === 'container' ? ['-f', '-v'] : []), id]);
    item.removed = true;
    await this.persist(this.items);
  }
  async removeContainer(id) { return this.retire('container', id); }
  async removeVolume(id) { return this.retire('volume', id); }
  async cleanupAfter(diagnostics) {
    let diagnosticError;
    try { await diagnostics(); } catch (error) { diagnosticError = error; }
    await this.cleanup();
    return diagnosticError;
  }
  async cleanup() {
    // No daemon contact after rejected preflight; creation is always journalled first.
    if (this.items.length === 0) return;
    const errors = [];
    for (const item of [...this.items].reverse()) {
      if (item.removed) continue;
      try {
        if (item.id === null && item.name) {
          const names = (await this.checked([item.kind, 'ls', ...(item.kind === 'container' ? ['-a'] : []), '--format', item.kind === 'container' ? '{{.Names}}' : '{{.Name}}', '--filter', `name=${item.name}`])).out.trim().split('\n');
          if (!names.includes(item.name)) continue;
        }
        const result = await this.checked([item.kind, 'inspect', item.id ?? item.name]);
        const row = JSON.parse(result.out)[0];
        const id = item.id ?? (item.kind === 'volume' ? row?.Name : row?.Id);
        assertOwned(item.kind, id, row, this.runId);
        await this.checked([item.kind, 'rm', ...(item.kind === 'container' ? ['-f', '-v'] : []), id]);
      } catch { errors.push(`${item.kind}:${item.name ?? item.id}`); }
    }
    // Query failures are failures, not an empty resource set.
    for (const kind of ['container', 'volume', 'network']) {
      try {
        const result = await this.checked([kind, 'ls', '-q', ...(kind === 'container' ? ['-a'] : []), '--filter', `label=${ownershipLabel}=${this.runId}`]);
        if (result.out.trim()) errors.push(`remaining-${kind}`);
      } catch { errors.push(`unverified-${kind}`); }
    }
    if (errors.length) throw new Error(`Cleanup incomplete: ${errors.join(', ')}`);
  }
}
