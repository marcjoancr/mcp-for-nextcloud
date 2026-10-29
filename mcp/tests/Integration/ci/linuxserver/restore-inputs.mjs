// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { isDeepStrictEqual } from 'node:util';
import { verifyCheckpoint, sha256 } from './checkpoint.mjs';
import { verifyKit } from '../../../../scripts/release/integrity.mjs';
import { command } from './command.mjs';
import { here } from './inputs.mjs';

export function credentialWindow(users, now = Date.now()/1000) {
  const rows=Object.entries(users).filter(([uid])=>uid!=='l2-denied').map(([,value])=>value);
  const expires=rows.flatMap(row=>[row.codeExpiresAt,row.accessExpiresAt,row.refreshExpiresAt]);
  if (!rows.length || !Number.isFinite(now) || expires.some(value=>!Number.isSafeInteger(value) || value-now<30)) throw new Error('Incomplete credential evidence window');
  return Math.floor(Math.min(...expires)-now);
}
export async function validateRestore(c, directory, trust) {
  directory=path.resolve(directory);
  if (await fs.realpath(directory)!==directory || !(await fs.lstat(directory)).isDirectory()) throw new Error('Unsafe checkpoint directory');
  const manifest=await verifyCheckpoint(directory,trust.generation,trust.manifestSha256);
  if (manifest.profileSha256!==c.profileSha256 || manifest.core!==c.profile.nextcloudVersion
      || !isDeepStrictEqual(manifest.images,c.profile.images) || !isDeepStrictEqual(manifest.apps,c.profile.apps)
      || !isDeepStrictEqual(manifest.baseline,c.profile.baseline)
      || ![c.profile.baseline.version,c.profile.candidate.version].includes(manifest.installedMcp)) throw new Error('Foreign checkpoint profile');
  if (typeof manifest.privateInputDirectory!=='string' || !/^private(?:\/[a-zA-Z0-9-]+)*$/.test(manifest.privateInputDirectory)) throw new Error('Unsafe private input directory');
  const oracle=path.join(c.root,manifest.privateInputDirectory);
  if (await fs.realpath(oracle)!==oracle) throw new Error('Unsafe private input directory');
  if (!isDeepStrictEqual(Object.keys(manifest.privateInputs??{}).sort(),['app.env','expected.json','pg.env','s3.json'])) throw new Error('Incomplete private input binding');
  for (const [name,hash] of Object.entries(manifest.privateInputs)) if (await sha256(path.join(oracle,name))!==hash) throw new Error('Private input digest mismatch');
  if (manifest.privateInputs['expected.json']!==manifest.oracleSha256) throw new Error('Oracle binding mismatch');
  const bootstrap=JSON.parse(await fs.readFile(path.join(directory,'bootstrap.json'),'utf8'));
  if (bootstrap.postgres!=='16.15' || bootstrap.database!=='nextcloud' || bootstrap.owner!=='nextcloud' || bootstrap.requiredRole!=='LOGIN SUPERUSER'
      || !Array.isArray(bootstrap.ports) || bootstrap.ports.length || bootstrap.admission!=='closed' || bootstrap.maintenance!==true
      || bootstrap.backgroundWriters!=='stopped' || bootstrap.pgDataDirectoryCopied!==false) throw new Error('Unsafe checkpoint bootstrap');
  if (Object.values(manifest.files).reduce((sum,row)=>sum+row.bytes,0)>c.profile.limits.diskBudgetGiB*2**30) throw new Error('Checkpoint exceeds restore budget');
  for (const name of ['config.tar','data.tar']) {
    const result=await command('python3',['-B',path.join(here,'volume_archive.py'),path.join(directory,name)]);
    if (result.code) throw new Error(`Unsafe volume archive: ${name}`);
  }
  const s3=JSON.parse(await fs.readFile(path.join(directory,'s3.json'),'utf8'));
  if (s3.bucket!=='mcp-l1' || s3.versioning!=='disabled' || !s3.objects || !Object.keys(s3.objects).length) throw new Error('Invalid S3 restore inventory');
  let bytes=0;
  for (const [key,row] of Object.entries(s3.objects)) {
    const payload='objects/'+createHash('sha256').update(key).digest('hex');
    if (row.payload!==payload || !Number.isSafeInteger(row.bytes) || row.bytes<0 || row.sha256!==manifest.files[payload]?.sha256 || row.bytes!==manifest.files[payload]?.bytes) throw new Error('Invalid S3 payload binding');
    bytes+=row.bytes;
  }
  if (bytes>c.profile.limits.seedObjectMiB*2**20) throw new Error('S3 restore exceeds budget');
  if (manifest.installedMcp===c.profile.candidate.version) {
    if (!isDeepStrictEqual(manifest.sourceKit,c.profile.candidate)) throw new Error('Wrong candidate digest or identity');
    await verifyKit(path.join(directory,'source'),c.profile.candidate.manifestSha256);
  }
  return {directory,manifest,oracle,expected:JSON.parse(await fs.readFile(path.join(oracle,'expected.json'),'utf8'))};
}
export async function withVerifiedCheckpoint(c,directory,trust,restore) {
  return restore(await validateRestore(c,directory,trust));
}

export async function prepareRestoreInputs(c, options) {
  const originals = {};
  for (const name of ['B0','B1']) {
    const key=name.toLowerCase();
    const trust={generation:options[`${key}-generation`],manifestSha256:options[`${key}-sha256`]};
    const verified=await validateRestore({...c,root:options.checkpoints},path.join(options.checkpoints,name),trust);
    if (verified.manifest.installedMcp!==(name==='B0'?c.profile.baseline.version:c.profile.candidate.version)) throw new Error('Mislabelled original checkpoint');
    originals[name]={...verified,trust};
  }
  for(const name of ['app.env','pg.env','s3.json']) if(originals.B0.manifest.privateInputs[name]!==originals.B1.manifest.privateInputs[name]) throw new Error('Original environment identity differs');
  for(const name of ['B0','B1']) {
    const source=originals[name];
    await fs.cp(source.directory,path.join(c.root,name),{recursive:true,errorOnExist:true});
    const destination=path.join(c.root,source.manifest.privateInputDirectory);
    await fs.mkdir(destination,{recursive:true,mode:0o700});
    for(const file of Object.keys(source.manifest.privateInputs)) await fs.copyFile(path.join(source.oracle,file),path.join(destination,file));
    await fs.writeFile(path.join(c.root,`${name}-trust.json`),JSON.stringify(source.trust,null,2),{mode:0o600});
    await validateRestore(c,path.join(c.root,name),source.trust);
  }
  return Object.fromEntries(Object.entries(originals).map(([name,row])=>[name,row.trust]));
}

export async function restoreEnvironment(root) {
  const directory=path.join(root,'private');
  const env=await fs.readFile(path.join(directory,'app.env'),'utf8');
  const values=Object.fromEntries(env.trim().split('\n').map(line=>line.split('=')));
  if (values.PUID!=='1000' || values.PGID!=='1000' || values.TZ!=='UTC'
      || !isDeepStrictEqual(Object.keys(values).sort(),['MCP_ADMIN_PASSWORD','MCP_DB_PASSWORD','MCP_S3_KEY','MCP_S3_SECRET','PGID','PUID','TZ'])
      || ['MCP_ADMIN_PASSWORD','MCP_DB_PASSWORD','MCP_S3_KEY','MCP_S3_SECRET'].some(key=>! /^[a-f0-9]{24,64}$/.test(values[key]))) throw new Error('Invalid restored fixture environment');
  const pg=await fs.readFile(path.join(directory,'pg.env'),'utf8');
  if (pg!==`POSTGRES_DB=nextcloud\nPOSTGRES_USER=nextcloud\nPOSTGRES_PASSWORD=${values.MCP_DB_PASSWORD}\n`) throw new Error('Restored database environment mismatch');
  for (const [source,target] of [['app.env','app.env'],['pg.env','pg.env'],['s3.json','s3/s3.json']]) await fs.copyFile(path.join(directory,source),path.join(root,target));
  return {db:values.MCP_DB_PASSWORD,admin:values.MCP_ADMIN_PASSWORD,key:values.MCP_S3_KEY,secret:values.MCP_S3_SECRET};
}
