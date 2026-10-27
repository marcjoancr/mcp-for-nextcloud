// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { readProfile } from './profile.mjs';
import { verifyKit } from '../../../../scripts/release/integrity.mjs';

const usage = 'Usage: --plan [--profile ABSOLUTE_FILE] --kit ABSOLUTE_DIRECTORY --manifest-sha256 TRUSTED_DIGEST (execution is not implemented)';
function argumentsForPlan(args) {
  const options = { profile: fileURLToPath(new URL('./profile.json', import.meta.url)) };
  const seen = new Set();
  for (let i = 0; i < args.length; i++) {
    const key = args[i];
    if (seen.has(key) || !['--plan', '--profile', '--kit', '--manifest-sha256'].includes(key)) throw new Error(usage);
    seen.add(key);
    if (key === '--plan') continue;
    const value = args[++i];
    if (!value || value.startsWith('--') || /[\x00-\x1f\x7f]/.test(value)) throw new Error(usage);
    options[key.slice(2)] = value;
  }
  if (!seen.has('--plan') || !options.kit || !options['manifest-sha256']
    || !path.isAbsolute(options.kit) || !path.isAbsolute(options.profile)
    || !/^[a-f0-9]{64}$/.test(options['manifest-sha256'])) throw new Error(usage);
  return options;
}
try {
  const options = argumentsForPlan(process.argv.slice(2));
  const { profile, profileSha256 } = await readProfile(options.profile);
  if (options['manifest-sha256'] !== profile.candidate.manifestSha256) throw new Error('Trusted manifest digest does not match profile');
  let manifest;
  try { manifest = await verifyKit(options.kit, options['manifest-sha256']); }
  catch { throw new Error('Source kit verification failed; use verify:source privately for detailed diagnostics'); }
  if (manifest.sourceCommit !== profile.candidate.sourceCommit || manifest.version !== profile.candidate.version) throw new Error('Source kit identity does not match profile');
  const expected = ['BUILDING.md', `mcp-${manifest.version}.tar.gz`, 'runtime-dependencies.tar.gz', 'source.bundle'];
  if (JSON.stringify(Object.keys(manifest.files).sort()) !== JSON.stringify(expected.sort())) throw new Error('Source kit identity is incomplete');
  console.log(JSON.stringify({ schemaVersion: 1, validationScope: 'profile-and-source-kit', executionAvailable: false,
    profileSha256, profile, sourceKit: { sourceCommit: manifest.sourceCommit, version: manifest.version, manifestSha256: options['manifest-sha256'] },
    pending: ['registry-and-app-availability-recheck-before-execution', 'host-resource-and-runtime-tool-preflight', 'native-upgrade-and-recovery',
      'production-profile-qualification', 'public-https', 'inspector-14-of-15', 'chatgpt', 'human-review'] }, null, 2));
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
