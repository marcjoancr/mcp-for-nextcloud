// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/** Only remove this run's resources; uncertain ownership or cleanup must fail acceptance. */
export function cleanupSourceFixture(command, { runId, container, network }) {
  const errors = [];
  for (const [kind, name] of [['container', container], ['network', network]]) {
    try {
      const label = kind === 'container' ? '.Config.Labels' : '.Labels';
      const owner = command('docker', [kind, 'inspect', '--format', `{{ index ${label} "mcp.integration.run" }}`, name]).trim();
      if (owner !== runId) {
        errors.push(`Refused cleanup: ${kind} ownership does not match this run`);
        continue;
      }
      command('docker', kind === 'container' ? [kind, 'rm', '-fv', name] : [kind, 'rm', name]);
    } catch {
      // Raw engine diagnostics may contain private fixture details.
      errors.push(`Could not verify or remove the owned ${kind}; inspect the exact run label`);
    }
  }
  return errors;
}
