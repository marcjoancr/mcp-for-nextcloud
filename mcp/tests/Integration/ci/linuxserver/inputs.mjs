// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { checkedFile, stageApp, stageBaselineArchive } from '../app-archive.mjs';

export const here = fileURLToPath(new URL('.', import.meta.url));

export async function stageBaseline(root, inputs, baseline) {
  const archive = path.join(inputs, `mcp-${baseline.version}.tar.gz`);
  const measured = await stageBaselineArchive(root, archive, baseline);
  // Older 1.2.0 input directories may contain only the archive. Its extracted bytes are still measured above.
  const supplied = path.join(inputs, 'baseline-inventory.json');
  try {
    await checkedFile(supplied, path.join(root, 'baseline-inventory.json'), measured.sha256, measured.bytes);
  } catch (error) {
    if (baseline.version !== '1.2.0' || error.code !== 'ENOENT') throw error;
  }
  return { version: baseline.version, archiveSha256: baseline.archiveSha256, inventorySha256: measured.sha256, runtimeFileCount: measured.files };
}

export async function stageAppsAndKit(root, options, profile, manifest) {
  await fs.mkdir(path.join(root, 'source'), { mode: 0o755 });
  for (const app of profile.apps) {
    const file = path.join(options.inputs, `${app.id}-${app.version}.tar.gz`);
    await stageApp(root, file, app.id, app.version, app.sha256, app.bytes);
  }
  const sourceManifest = path.join(options.kit, 'manifest.json');
  const targetManifest = path.join(root, 'source/manifest.json');
  await checkedFile(sourceManifest, targetManifest, profile.candidate.manifestSha256, (await fs.lstat(sourceManifest)).size);
  await fs.chmod(targetManifest, 0o644);
  for (const [name, file] of Object.entries(manifest.files)) {
    const dest = path.join(root, 'source', name);
    await checkedFile(path.join(options.kit, name), dest, file.sha256, file.bytes);
    await fs.chmod(dest, 0o644);
  }
}
