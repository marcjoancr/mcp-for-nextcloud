// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export const ownershipLabel = 'mcp.linuxserver.run';
export function allocations(limits) {
  const roles = { app: { cpus: 2, memoryMiB: 4096 }, postgres: { cpus: .5, memoryMiB: 1024 },
    seaweedfs: { cpus: .5, memoryMiB: 1024 }, client: { cpus: .5, memoryMiB: 512 }, denied: { cpus: .5, memoryMiB: 512 } };
  for (const key of ['cpus', 'memoryMiB']) {
    if (Object.values(roles).reduce((sum, role) => sum + role[key], 0) > limits[key]) throw new Error(`Insufficient aggregate ${key}`);
  }
  return roles;
}
export function assertOwned(kind, id, row, runId) {
  const identity = kind === 'volume' ? row?.Name : row?.Id;
  const labels = kind === 'container' ? row?.Config?.Labels : row?.Labels;
  if (!id || identity !== id || !runId || labels?.[ownershipLabel] !== runId) throw new Error('Resource ownership cannot be established');
}
export function assertImage(pin, row) {
  const [ref, digest] = pin.reference.split('@');
  const repository = ref.replace(/:[^/:]+$/, '');
  if (row?.Os !== 'linux' || row?.Architecture !== 'amd64' || !row.RepoDigests?.includes(`${repository}@${digest}`)) throw new Error('Pinned image identity/platform mismatch');
}
export function assertDisk(free, used, limits) {
  if (!Number.isFinite(free) || !Number.isFinite(used) || free < limits.minimumFreeGiB * 2**30 || used > limits.diskBudgetGiB * 2**30) throw new Error('Fixture disk budget exceeded');
}
export function completeProbe(output) {
  const markers = output.split('\n').filter(line => line.startsWith('MCP_L1_COMPLETE='));
  if (markers.length !== 1) throw new Error('Native probe completion missing or ambiguous');
  const result = JSON.parse(markers[0].slice('MCP_L1_COMPLETE='.length));
  if (result.ok !== true || !Number.isInteger(result.checks) || result.checks < 1) throw new Error('Native probe did not succeed');
  return result;
}
export function rejectedProbe(reply, reason) {
  if (reply.code !== 1 || reply.out.includes('MCP_L1_COMPLETE=')) throw new Error('Negative probe was not an expected rejection');
  if (reason === 'incomplete' && reply.err.trim() === 'Native probe did not complete.') return;
  const markers = reply.out.split('\n').filter(line => line.startsWith('MCP_L1_REJECTED='));
  if (markers.length !== 1 || JSON.parse(markers[0].slice('MCP_L1_REJECTED='.length)).reason !== reason) throw new Error('Negative probe rejection reason missing or incorrect');
}
export function finalStatus(failed, interrupted, cleanupComplete) {
  return !failed && !interrupted && cleanupComplete ? 'passed' : 'failed';
}
export function assertLocalDocker(environment, contextEndpoint) {
  const endpoint = environment.DOCKER_CONTEXT ? contextEndpoint : environment.DOCKER_HOST || contextEndpoint;
  if (typeof endpoint !== 'string' || !endpoint.startsWith('unix:///') || /[\x00-\x1f\x7f]/.test(endpoint)) throw new Error('L1 requires a local Unix-socket Docker daemon');
}
