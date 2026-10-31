// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import path from 'node:path';
import os from 'node:os';
import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { stageBaseline } from './inputs.mjs';
import { readProfile } from './profile.mjs';
import { verifyKit } from '../../../../scripts/release/integrity.mjs';

const usage = 'Usage: (--plan | --check-baseline --inputs DIR | --check-mount --inputs DIR | --phase L1|L2|L3|L4 --inputs DIR) [--fixture-profile FILE] --kit DIR --manifest-sha256 TRUSTED_DIGEST (absolute paths required)';
function argumentsForPlan(args) {
  const options = { profile: fileURLToPath(new URL('./profile.json', import.meta.url)) };
  const seen = new Set();
  for (let i = 0; i < args.length; i++) {
    // Composer consumes --profile globally and decorates JSON stdout. Keep the original shell spelling.
    const key = args[i] === '--fixture-profile' ? '--profile' : args[i];
    if (seen.has(key) || !['--plan', '--check-baseline', '--check-mount', '--profile', '--kit', '--manifest-sha256', '--phase', '--inputs', '--checkpoints', '--b0-generation', '--b0-sha256', '--b1-generation', '--b1-sha256'].includes(key)) throw new Error(usage);
    seen.add(key);
    if (['--plan', '--check-baseline', '--check-mount'].includes(key)) continue;
    const value = args[++i];
    if (!value || value.startsWith('--') || /[\x00-\x1f\x7f]/.test(value)) throw new Error(usage);
    options[key.slice(2)] = value;
  }
  options.checkBaseline = seen.has('--check-baseline');
  options.checkMount = seen.has('--check-mount');
  if (['--plan', '--check-baseline', '--check-mount', '--phase'].filter(key => seen.has(key)).length !== 1
    || (seen.has('--plan') ? seen.has('--inputs') : !options.inputs || !path.isAbsolute(options.inputs))
    || (seen.has('--phase') && !['L1', 'L2', 'L3', 'L4'].includes(options.phase))
    || !options.kit || !options['manifest-sha256']
    || !path.isAbsolute(options.kit) || !path.isAbsolute(options.profile)
    || !/^[a-f0-9]{64}$/.test(options['manifest-sha256'])) throw new Error(usage);
  const recoveryKeys = ['checkpoints', 'b0-generation', 'b0-sha256', 'b1-generation', 'b1-sha256'];
  if (options.checkMount) options.phase = 'L4';
  if (options.phase === 'L4') {
    if (!options.checkpoints || !path.isAbsolute(options.checkpoints)
      || ['b0', 'b1'].some(name => !/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(options[`${name}-generation`] ?? '') || !/^[a-f0-9]{64}$/.test(options[`${name}-sha256`] ?? ''))) throw new Error(usage);
  } else if (recoveryKeys.some(key => options[key] !== undefined)) throw new Error(usage);
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
  const availablePhases = ['L1', 'L2', 'L3', 'L4'];
  if (options.checkBaseline) {
    const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-baseline-check-'));
    try {
      const baseline = await stageBaseline(root, options.inputs, profile.baseline);
      console.log(JSON.stringify({ schemaVersion: 1, validationScope: 'baseline-inputs', profileSha256,
        baseline: { ...baseline, sourceCommit: profile.baseline.sourceCommit }, candidate: profile.candidate }, null, 2));
    } finally { await fs.rm(root, { recursive: true, force: true }); }
  } else if (options.phase) {
    if (options.checkMount && profile.baseline.version !== '1.2.1') throw new Error('Unsupported phase for the selected baseline');
    const { executeFixture } = await import('./runtime.mjs');
    await executeFixture({ options, profile, profileSha256, manifest });
  } else console.log(JSON.stringify({ schemaVersion: 1, validationScope: 'profile-and-source-kit', executionAvailable: true, availablePhases,
    profileSha256, profile, sourceKit: { sourceCommit: manifest.sourceCommit, version: manifest.version, manifestSha256: options['manifest-sha256'] },
    pending: ['registry-and-app-availability-recheck-before-execution', 'host-resource-and-runtime-tool-preflight', 'native-upgrade-and-recovery',
      'production-profile-qualification', 'public-https', 'inspector-14-of-15', 'chatgpt', 'human-review'] }, null, 2));
} catch (error) {
  console.error(error.message);
  process.exitCode = 1;
}
