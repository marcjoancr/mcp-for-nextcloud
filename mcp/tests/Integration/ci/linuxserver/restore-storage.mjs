// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { isDeepStrictEqual } from 'node:util';
import { validateRestore } from './restore-inputs.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';
import { sha256 } from './checkpoint.mjs';
import { startPausedApp, emptyCronTablesCommand } from './fixture.mjs';
import { inventory, verifyInstalled } from '../../../../scripts/release/integrity.mjs';

// A storage generation is retired only after every exact ID and backup input was checked.
export function configureRecovery(c, runtime) {
  const { owner, sampling, volumes, create, network, roles, logArgs, update } = runtime;
  let serial = 0;
  c.restore = async (directory, trust, fault = null) => {
    c.restoreProgress = null;
    const verified = await validateRestore(c, directory, trust);
    const started = Date.now();
    const suffix = `restore-${++serial}`;
    await c.close();
    await sampling.mutate(async () => {
      const ids = [c.app, c.postgres, c.s3];
      for (const id of ids) await owner.assertActive('container', id);
      for (const id of Object.values(volumes)) await owner.assertActive('volume', id);
      for (const id of ids) await owner.removeContainer(id);
      for (const id of Object.values(volumes)) await owner.removeVolume(id);
      for (const name of Object.keys(volumes)) volumes[name] = await owner.create('volume', `${owner.runId ?? path.basename(c.root)}-${suffix}-${name}`, []);
      const postgres = await create('postgres', c.profile.images.postgres.reference, ['--network-alias', 'postgres', '--env-file', path.join(c.root, 'pg.env'), '-v', `${volumes.postgres}:/var/lib/postgresql/data`], `${suffix}-pg`);
      const s3 = await owner.create('container', `${path.basename(c.root)}-${suffix}-s3`, [
        '--platform', c.profile.platform, '--network', network, '--network-alias', 's3', '--cpus', String(roles.seaweedfs.cpus), '--memory', `${roles.seaweedfs.memoryMiB}m`, '--memory-swap', `${roles.seaweedfs.memoryMiB}m`, '--pids-limit', '256', ...logArgs,
        '--entrypoint', 'weed', '-v', `${volumes.s3}:/data`, '-v', `${c.root}/s3:/etc/seaweedfs:ro`, c.profile.images.seaweedfs.reference,
        'server', '-dir=/data', '-master.dir=/data', '-ip=s3', '-ip.bind=0.0.0.0', '-filer', '-s3', '-s3.config=/etc/seaweedfs/s3.json',
        `-volume.max=${c.profile.objectStore.volumeCount}`, `-master.volumeSizeLimitMB=${c.profile.objectStore.volumeSizeLimitMiB}`, '-volume.preStopSeconds=1',
      ]);
      const appArgs = ['--network-alias', 'nc.fixture.test', '--env-file', path.join(c.root, 'app.env'), '-v', `${volumes.config}:/config`, '-v', `${volumes.data}:/data`, '-v', `${c.root}/source:/fixture-source:ro`, '-v', `${c.root}/probes:/fixture-probes:ro`];
      let app = await create('app', c.profile.images.nextcloud.reference, [...appArgs, '--entrypoint', '/bin/sleep'], `${suffix}-import`, ['infinity']);
      update({ app, postgres, s3 });
      await owner.checked(['start', postgres, s3, app]);
      await c.wait('empty PostgreSQL', async () => !(await c.rawExec(postgres, ['pg_isready', '-U', 'nextcloud', '-d', 'nextcloud'])).code);
      const sql = statement => c.exec(postgres, ['psql', '-v', 'ON_ERROR_STOP=1', '-U', 'nextcloud', '-d', 'nextcloud', '-Atc', statement]);
      if ((await sql("SELECT count(*) FROM information_schema.tables WHERE table_schema='public'")).trim() !== '0') throw new Error('Restore database is not empty');
      for (const name of ['config', 'data']) {
        const file = `${name}.tar`;
        await c.checked(['cp', path.join(directory, file), `${app}:/tmp/${file}`]);
        if ((await c.exec(app, ['sha256sum', `/tmp/${file}`])).split(/\s/)[0] !== await sha256(path.join(directory, file))) throw new Error('Restore archive transfer mismatch');
        await c.exec(app, ['tar', '--numeric-owner', '-xpf', `/tmp/${file}`, '-C', `/${name}`]);
        await c.exec(app, ['tar', '-cpf', `/tmp/readback-${file}`, '-C', `/${name}`, '.']);
        const readback = path.join(c.root, `readback-${file}`);
        await c.checked(['cp', `${app}:/tmp/readback-${file}`, readback]);
        const comparison = await command('python3', ['-B', path.join(here, 'compare-volume.py'), path.join(directory, file), readback]);
        await fs.rm(readback);
        if (comparison.code) throw new Error(`Restored ${name} bytes or ownership differ`);
      }
      await c.exec(app, ['test', '-f', '/config/mcp-l1-closed']);
      // The only environment overlay: this run's private transport CA and admitted client IP.
      await c.checked(['cp', `${c.root}/config/tls/.`, `${app}:/config/tls`]);
      await c.checked(['cp', `${c.root}/config/nginx/site-confs/default.conf`, `${app}:/config/nginx/site-confs/default.conf`]);
      if (verified.manifest.installedMcp === c.profile.candidate.version) {
        await fs.rm(path.join(c.root, 'source'), { recursive: true });
        await fs.cp(path.join(directory, 'source'), path.join(c.root, 'source'), { recursive: true });
      }
      // Nextcloud's installer creates a separate login/table owner; derive its private
      // password from this generation's verified config, never from command arguments.
      await c.exec(app, ['php', '/fixture-probes/l4-role.php']);
      await c.checked(['cp', `${app}:/tmp/restore-role.sql`, path.join(c.root, 'restore-role.sql')]);
      await fs.chmod(path.join(c.root, 'restore-role.sql'), 0o600);
      const roleSql = await fs.readFile(path.join(c.root, 'restore-role.sql'), 'utf8');
      const quotedPassword = roleSql.match(/PASSWORD '((?:[^']|'')*)';\n$/)?.[1];
      if (!quotedPassword) throw new Error('Invalid private role bootstrap');
      c.protect(quotedPassword); c.protect(quotedPassword.replaceAll("''", "'"));
      await c.checked(['cp', path.join(c.root, 'restore-role.sql'), `${postgres}:/tmp/restore-role.sql`]);
      await c.exec(postgres, ['psql', '-v', 'ON_ERROR_STOP=1', '-U', 'nextcloud', '-d', 'nextcloud', '-f', '/tmp/restore-role.sql']);
      await c.checked(['cp', path.join(directory, 'database.dump'), `${postgres}:/tmp/restore.dump`]);
      if ((await c.exec(postgres, ['sha256sum', '/tmp/restore.dump'])).split(/\s/)[0] !== await sha256(path.join(directory, 'database.dump'))) throw new Error('Restore database transfer mismatch');
      await c.exec(postgres, ['pg_restore', '-U', 'nextcloud', '-d', 'nextcloud', '--exit-on-error', '--single-transaction', '/tmp/restore.dump']);
      if (fault === 'partial-database') {
        await sql('DROP TABLE oc_mcp_oauth_tokens');
        c.restoreProgress = 'partial-database-validation';
      }
      const policyTables = Object.keys(verified.expected.policySnapshot);
      for (const name of policyTables) {
        if (!/^[a-z_]+$/.test(name)) throw new Error('Unsafe policy table');
        const filter = name === 'migrations' ? " WHERE app='mcp'" : name === 'preferences' ? " WHERE appid='mcp'" : '';
        if ((await sql(`SELECT count(*) FROM oc_${name}${filter}`)).trim() !== String(verified.expected.policySnapshot[name].length)) throw new Error(`Incomplete restored policy database: ${name}`);
      }
      if ((await sql("SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname='nextcloud'")).trim() !== 'nextcloud'
        || (await sql("SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tableowner<>'oc_admin'")).trim() !== '0'
        || (await sql("SELECT rolcanlogin AND rolsuper FROM pg_roles WHERE rolname='nextcloud'")).trim() !== 't'
        || (await sql("SELECT rolcanlogin AND NOT rolsuper AND NOT rolcreaterole AND NOT rolcreatedb FROM pg_roles WHERE rolname='oc_admin'")).trim() !== 't'
        || (await sql("SELECT configvalue FROM oc_appconfig WHERE appid='mcp' AND configkey='installed_version'")).trim() !== verified.manifest.installedMcp) throw new Error('Restored database identity mismatch');
      await c.exec(app, ['mkdir', '/tmp/restore-objects']);
      await c.checked(['cp', path.join(directory, 's3.json'), `${app}:/tmp/restore-objects/s3.json`]);
      await c.checked(['cp', path.join(directory, 'objects'), `${app}:/tmp/restore-objects/objects`]);
      if (fault === 'unavailable-storage') await owner.checked(['stop', s3]);
      if (fault === 'missing-s3') {
        const objects = JSON.parse(await fs.readFile(path.join(directory, 's3.json'))).objects;
        await c.exec(app, ['rm', `/tmp/restore-objects/${Object.values(objects)[0].payload}`]);
      }
      if (fault === 'missing-s3' || fault === 'unavailable-storage') c.restoreProgress = `${fault}-validation`;
      const s3Result = await c.exec(app, ['php', '/fixture-probes/l4-s3.php']);
      if (!s3Result.includes('MCP_L4_S3=')) throw new Error('Incomplete S3 restore');
      await c.record(`${suffix}-storage-import`, JSON.parse(s3Result.split('MCP_L4_S3=')[1].trim()));
      const installed = path.join(c.root, 'restored-app');
      await c.checked(['cp', `${app}:/config/www/nextcloud/apps/mcp`, installed]);
      if (verified.manifest.installedMcp === c.profile.candidate.version) await verifyInstalled(path.join(c.root, 'source'), c.profile.candidate.manifestSha256, installed);
      else if (!isDeepStrictEqual(await inventory(installed), await inventory(path.join(c.root, 'apps/mcp')))) throw new Error('Restored baseline app bytes differ');
      await fs.rm(installed, { recursive: true });
      await owner.removeContainer(app);
      app = await create('app', c.profile.images.nextcloud.reference, appArgs, `${suffix}-app`);
      update({ app, postgres, s3 });
      await startPausedApp(args => owner.checked(args), c.root, app);
    });
    await c.wait('restored closed Nginx', async () => {
      const r = await c.rawExec(c.client, ['curl', '--silent', '--cacert', '/tmp/ca.crt', '--max-time', '5', '-o', '/dev/null', '-w', '%{http_code}', 'https://nc.fixture.test/status.php']);
      return !r.code && r.out === '503';
    });
    await c.exec(c.app, ['s6-rc', '-d', 'change', 'svc-cron']);
    await c.exec(c.app, ['sh', '-c', `${emptyCronTablesCommand} && ! pgrep -x crond && ! pgrep -x cron`]);
    const status = JSON.parse(await c.exec(c.app, ['s6-envdir', '/run/s6/container_environment', 's6-setuidgid', 'abc', 'php', '/app/www/public/occ', 'status', '--output=json']));
    if (status.versionstring !== c.profile.nextcloudVersion || status.maintenance !== true || status.needsDbUpgrade !== false || status.installed !== true) throw new Error('Restored core is not ready under maintenance');
    const details = { core: status.versionstring, generation: trust.generation, version: verified.manifest.installedMcp, restoreSeconds: (Date.now() - started) / 1000,
      checkpointAgeSeconds: (started - Date.parse(verified.manifest.finishedAt)) / 1000, freshVolumes: 4, maintenance: true, admission: 'closed', cron: 'stopped', transportOverlay: 'new private CA and admitted client IP only' };
    await c.record(`${suffix}-ready`, details);
    return { ...verified, details };
  };
}
