// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { inventory, hashFile } from '../../scripts/release/integrity.mjs';
const api = () => import('../../scripts/release/forms-native-source.mjs');
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
async function fixture(t) {
  const root = await fs.mkdtemp(path.resolve('build/forms-source-test-'));t.after(() => fs.rm(root,{ recursive:true,force:true }));
  const kit=path.join(root,'kit'),runtime=path.join(root,'forms');await fs.mkdir(kit);await fs.mkdir(path.join(runtime,'appinfo'),{recursive:true});
  const files={'appinfo/info.xml':'<info><id>forms</id><version>5.4.2</version></info>','runtime.php':'<?php /* qualified */','asset.js':'preserved upstream asset'};
  for(const [name,bytes]of Object.entries(files))await fs.writeFile(path.join(runtime,name),bytes);
  const installed={protocol:1,formsVersion:'5.4.2',formsCommit:'066573fd592513a06f8b8624def8244e911efdfd',sha256:{'appinfo/info.xml':sha(files['appinfo/info.xml']),'runtime.php':sha(files['runtime.php'])}};
  const marker=JSON.stringify(installed,null,2)+'\n';await fs.writeFile(path.join(runtime,'mcp-guard-installed.json'),marker);
  const m=await api();for(const name of m.formsNativeAssets)await fs.writeFile(path.join(kit,name),name);
  const metadata={sourceCommit:'a'.repeat(40),formsVersion:'5.4.2',installedManifestSha256:sha(marker),...m.formsUpstreamPins,runtimeFiles:await inventory(runtime)};
  return {root,kit,runtime,files,installed,marker,metadata,m};
}
test('Forms verifier CLI dispatches to its independent trusted digest boundary',()=>{
  const p=spawnSync(process.execPath,['scripts/release/cli.mjs','verify-forms-native','build/not-used','bad','a'.repeat(40)],{encoding:'utf8'});
  assert.notEqual(p.status,0);assert.match(p.stderr,/Trusted Forms manifest digest required/);
});
test('Forms source companion has its own closed assets, source commit and independently trusted manifest',async t=>{
  const f=await fixture(t);const digest=await f.m.sealFormsNativeCompanion(f.kit,f.metadata);
  assert.equal((await f.m.verifyFormsNativeCompanion(f.kit,digest,f.metadata.sourceCommit)).formsVersion,'5.4.2');
  await assert.rejects(f.m.verifyFormsNativeCompanion(f.kit,'b'.repeat(64),f.metadata.sourceCommit),/digest/);
  await assert.rejects(f.m.verifyFormsNativeCompanion(f.kit,digest,'c'.repeat(40)),/source commit/);
  await fs.writeFile(path.join(f.kit,'extra'),'unexpected');await assert.rejects(f.m.verifyFormsNativeCompanion(f.kit,digest,f.metadata.sourceCommit),/inventory/);await fs.unlink(path.join(f.kit,'extra'));
  await fs.appendFile(path.join(f.kit,f.m.formsNativeAssets[0]),'tamper');await assert.rejects(f.m.verifyFormsNativeCompanion(f.kit,digest,f.metadata.sourceCommit),/integrity/);
});
test('Forms installed verification covers every asset as well as the exact independently pinned guard manifest',async t=>{
  const f=await fixture(t);const digest=await f.m.sealFormsNativeCompanion(f.kit,f.metadata);
  await f.m.verifyFormsInstalled(f.kit,digest,f.metadata.sourceCommit,f.runtime);
  await fs.appendFile(path.join(f.runtime,'asset.js'),'tamper');await assert.rejects(f.m.verifyFormsInstalled(f.kit,digest,f.metadata.sourceCommit,f.runtime),/integrity/);await fs.writeFile(path.join(f.runtime,'asset.js'),f.files['asset.js']);
  await fs.symlink('asset.js',path.join(f.runtime,'linked'));await assert.rejects(f.m.verifyFormsInstalled(f.kit,digest,f.metadata.sourceCommit,f.runtime),/symlink|regular/);await fs.unlink(path.join(f.runtime,'linked'));
  await fs.writeFile(path.join(f.runtime,'extra'),'unexpected');await assert.rejects(f.m.verifyFormsInstalled(f.kit,digest,f.metadata.sourceCommit,f.runtime),/inventory/);
  assert.throws(()=>f.m.assertFormsBuildIdentity(f.marker,f.files['appinfo/info.xml'],'d'.repeat(64)),/identity/);
  for(const xml of [f.files['appinfo/info.xml'].replace('forms','mail'),f.files['appinfo/info.xml']+'<version>5.4.2</version>','<info><!-- <id>forms</id><version>5.4.2</version> --></info>','<!DOCTYPE info SYSTEM "file:///nonexistent-forms-identity">'+f.files['appinfo/info.xml']])assert.throws(()=>f.m.assertFormsBuildIdentity(f.marker,xml,sha(f.marker)),/identity/);
  const bad=JSON.stringify({...f.installed,formsVersion:'5.4.1'});assert.throws(()=>f.m.assertFormsBuildIdentity(bad,f.files['appinfo/info.xml'],sha(bad)),/identity/);
});
test('Forms companion refuses extra schema fields, unsafe paths, unknown upstream and linked delivery assets',async t=>{
  const f=await fixture(t);const digest=await f.m.sealFormsNativeCompanion(f.kit,f.metadata);const file=path.join(f.kit,'forms-native-manifest.json');
  const original=JSON.parse(await fs.readFile(file));
  for(const change of [m=>{m.extra=true;},m=>{m.upstreamCommit='e'.repeat(40);},m=>{m.runtimeFiles['../escape']={sha256:'f'.repeat(64),bytes:1};},m=>{m.files[f.m.formsNativeAssets[0]].extra=true;}]){
    const m=structuredClone(original);change(m);await fs.writeFile(file,JSON.stringify(m));await assert.rejects(f.m.verifyFormsNativeCompanion(f.kit,await hashFile(file),f.metadata.sourceCommit),/schema|pins|inventory|path/);
  }
  await fs.writeFile(file,JSON.stringify(original,null,2)+'\n');assert.equal(await hashFile(file),digest);
  const first=path.join(f.kit,f.m.formsNativeAssets[0]);await fs.unlink(first);await fs.symlink(f.m.formsNativeAssets[1],first);await assert.rejects(f.m.verifyFormsNativeCompanion(f.kit,digest,f.metadata.sourceCommit),/symlink|regular/);
});

test('Forms compiled pin requires the effective namespaced class constant, never comments or another class',async()=>{
  const m=await api();const pin='a'.repeat(64);
  const body="<?php namespace OCA\\Mcp\\Modules\\Forms\\Integration; final class FormsGuardIdentity { public const INSTALLED_MANIFEST_SHA256='"+pin+"'; }";
  assert.equal(m.readFormsCompiledPin(body),pin);
  for(const bad of ["<?php /* INSTALLED_MANIFEST_SHA256='"+pin+"' */",body.replace('public const','/* public const').replace("'; }","'; */ }"),body.replace('FormsGuardIdentity','Decoy'),body.replace('OCA\\Mcp','Other\\Mcp')])assert.throws(()=>m.readFormsCompiledPin(bad),/compiled identity/);
});
test('Forms preparation rejects a linked build root before reading any kit or writing outside the project',async t=>{
  const m=await api();const root=await fs.mkdtemp(path.resolve('build/forms-output-boundary-'));t.after(()=>fs.rm(root,{recursive:true,force:true}));
  const repository=path.join(root,'project'),outside=path.join(root,'outside');await fs.mkdir(path.join(repository,'mcp'),{recursive:true});await fs.mkdir(outside);await fs.symlink(outside,path.join(repository,'mcp/build'));
  await assert.rejects(m.prepareFormsNativeCompanion(repository,'must-not-be-read','bad','unused','unused',path.join(outside,'output')),/Forms build root/);
  assert.deepEqual(await fs.readdir(outside),[]);
});
