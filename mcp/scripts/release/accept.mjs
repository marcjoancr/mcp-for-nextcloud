// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { verifyKit } from './integrity.mjs';
import { cleanupSourceFixture } from './fixture-cleanup.mjs';

const root = fileURLToPath(new URL('../../../', import.meta.url));
const [kitArgument, digest, ...extra] = process.argv.slice(2);
let runRoot, env;
let accepted = false;
function command(binary, args) {
  return execFileSync(binary, args, { cwd: root, env: env ?? process.env, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 16 * 1024 * 1024 });
}
try {
  if (!kitArgument || !digest || extra.length || !path.isAbsolute(kitArgument)) throw new Error('Usage: composer test:source -- ABSOLUTE_KIT TRUSTED_MANIFEST_SHA256');
  const manifest = await verifyKit(kitArgument, digest);
  command('php', ['mcp/scripts/check_package.php', path.join(kitArgument, `mcp-${manifest.version}.tar.gz`)]);
  const identity = JSON.parse(command('tar', ['-xOf', path.join(kitArgument, `mcp-${manifest.version}.tar.gz`), 'mcp/appinfo/source.json']));
  if (identity.schemaVersion !== 1 || identity.sourceCommit !== manifest.sourceCommit || identity.version !== manifest.version) throw new Error('Runtime source identity does not match the kit');
  const plan = JSON.parse(command('php', ['mcp/tests/Integration/ci/matrix.php', '35', 'sqlite', 'fresh']));
  const id = `mcp-source-${randomBytes(8).toString('hex')}`;
  runRoot = path.join(root, 'mcp/build/source-acceptance', id);
  fs.mkdirSync(path.dirname(runRoot), { recursive: true });
  fs.mkdirSync(runRoot, { mode: 0o700 });
  fs.mkdirSync(path.join(runRoot, 'mcp-it'), { mode: 0o700 });
  fs.writeFileSync(path.join(runRoot, 'plan.json'), JSON.stringify(plan));
  fs.copyFileSync(path.join(kitArgument, `mcp-${manifest.version}.tar.gz`), path.join(runRoot, 'mcp-it/mcp-branch.tar.gz'));
  env = { ...process.env, MCP_IT_LOCAL: '1', RUNNER_TEMP: runRoot, MCP_IT_RUN_ID: id,
    MCP_IT_NC: '35', MCP_IT_DB: 'sqlite', MCP_IT_MODE: 'fresh', MCP_IT_BASELINE: '',
    MCP_IT_NC_IMAGE: plan.nc_image, MCP_IT_DB_IMAGE: '', MCP_IT_NET: `${id}-net`, MCP_IT_NC_NAME: `${id}-nc`, MCP_IT_DB_NAME: `${id}-db` };
  for (const step of ['start-db', 'start-nextcloud', 'install-app', 'fixtures']) {
    console.log(`Source acceptance: ${step}`);
    try { fs.writeFileSync(path.join(runRoot, `${step}.log`), command('sh', ['mcp/tests/Integration/ci/harness.sh', step])); }
    catch (error) { fs.writeFileSync(path.join(runRoot, `${step}.log`), `${error.stdout ?? ''}\n${error.stderr ?? ''}`); throw new Error(`Native fixture failed: ${step}; private log retained in ${runRoot}`); }
  }
  command('docker', ['cp', `${kitArgument}/.`, `${env.MCP_IT_NC_NAME}:/tmp/mcp-source-fixture`]);
  command('docker', ['exec', env.MCP_IT_NC_NAME, 'chmod', '-R', 'a+rX', '/tmp/mcp-source-fixture']);
  command('docker', ['exec', '-d', '-u', 'www-data', env.MCP_IT_NC_NAME, 'sh', '-c',
    'exec php -S 127.0.0.1:8765 -t /tmp/mcp-source-fixture /mcp-it-src/mcp/tests/Integration/ci/source-server.php >/tmp/mcp-source-http.log 2>&1']);
  const result = command('docker', ['exec', '-u', 'www-data', '-e', 'NEXTCLOUD_ROOT=/var/www/html', '-e', 'MCP_SOURCE_ACCEPTANCE=1',
    '-e', `MCP_SOURCE_DIGEST=${digest}`, env.MCP_IT_NC_NAME, 'php', '/mcp-it-src/mcp/tests/Integration/ci/source-offer.php']);
  const outcome = JSON.parse(result);
  if (!Number.isSafeInteger(outcome.checks) || outcome.checks < 1 || outcome.nonAdmin !== true
    || outcome.sourceCommit !== manifest.sourceCommit || outcome.version !== manifest.version || outcome.manifestSha256 !== digest) {
    throw new Error('Native acceptance did not report complete matching evidence');
  }
  fs.writeFileSync(path.join(runRoot, 'acceptance.json'), result);
  const retrieved = path.join(runRoot, 'retrieved');
  command('docker', ['cp', `${env.MCP_IT_NC_NAME}:/tmp/mcp-source-retrieved`, retrieved]);
  await verifyKit(retrieved, digest);
  fs.appendFileSync(path.join(retrieved, 'source.bundle'), 'tamper probe');
  let refused = false;
  try { await verifyKit(retrieved, digest); } catch { refused = true; }
  if (!refused) throw new Error('Altered downloaded source was accepted');
  // Keep the actually downloaded kit usable for the subsequent clean rebuild.
  fs.truncateSync(path.join(retrieved, 'source.bundle'), manifest.files['source.bundle'].bytes);
  await verifyKit(retrieved, digest);
  accepted = true;
} catch (error) {
  // Do not print HTTP dumps, container environments or fixture credentials.
  if (runRoot) fs.writeFileSync(path.join(runRoot, 'failure.log'), `${error.message}\n${error.stderr ?? ''}`);
  console.error(`Source acceptance failed${runRoot ? `; private evidence: ${runRoot}` : `: ${error.message}`}`);
  process.exitCode = 1;
} finally {
  if (env) {
    const cleanupErrors = cleanupSourceFixture(command, {
      runId: env.MCP_IT_RUN_ID, container: env.MCP_IT_NC_NAME, network: env.MCP_IT_NET,
    });
    if (cleanupErrors.length) {
      process.exitCode = 1;
      fs.writeFileSync(path.join(runRoot, 'cleanup-errors.json'), JSON.stringify(cleanupErrors));
      console.error(`Source acceptance cleanup failed; inspect run ${env.MCP_IT_RUN_ID}`);
    }
    fs.writeFileSync(path.join(runRoot, 'result.json'), JSON.stringify({ exitCode: process.exitCode ?? 0, sourceKit: path.resolve(kitArgument), manifestSha256: digest }));
  }
}

if (accepted && !process.exitCode) console.log(`Source acceptance passed; evidence: ${runRoot}`);
