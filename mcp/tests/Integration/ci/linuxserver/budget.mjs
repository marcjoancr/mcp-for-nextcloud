// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** du may race with a gate removal or service restart; never use partial output. */
export async function measureVolume(read, isRunning) {
  for (let attempt = 0; attempt < 3; attempt++) {
    const result = await read();
    if (result.code === 0) {
      const lines = result.out.trim().split('\n');
      if (lines.some(line => !/^\d+\s+\//.test(line))) throw new Error('Invalid volume measurement');
      return lines.reduce((sum, line) => sum + Number(line.split(/\s/)[0]) * 1024, 0);
    }
    if (result.code !== 1) throw new Error('Cannot measure fixture volume usage');
    // A stopped volume retains its last complete measurement in the caller.
    if (!await isRunning()) return null;
  }
  throw new Error('Cannot measure fixture volume usage');
}
