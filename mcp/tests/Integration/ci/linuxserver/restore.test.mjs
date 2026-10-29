// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import os from 'node:os';
import { createHash } from 'node:crypto';
import { command } from './command.mjs';
import { sealCheckpoint, sha256 } from './checkpoint.mjs';

async function fixture() {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'mcp-restore-'));
  const directory = path.join(root, 'B0'), oracle = path.join(root, 'private');
  await fs.mkdir(directory); await fs.mkdir(oracle); await fs.mkdir(path.join(directory, 'objects'));
  const profile = JSON.parse(await fs.readFile(new URL('./profile.json', import.meta.url), 'utf8'));
  const key = createHash('sha256').update('urn:oid:1').digest('hex');
  await fs.writeFile(path.join(directory, 'objects', key), 'x');
  for (const name of ['database.dump', 'semantic.json']) await fs.writeFile(path.join(directory, name), '{}');
  for (const name of ['config.tar', 'data.tar']) {
    const r = await command('python3', ['-c', 'import tarfile,sys,io\nwith tarfile.open(sys.argv[1],"w") as t:\n i=tarfile.TarInfo("fixture");i.size=1;t.addfile(i,io.BytesIO(b"x"))', path.join(directory, name)]);
    assert.equal(r.code, 0, r.err);
  }
  await fs.writeFile(path.join(directory, 'bootstrap.json'), JSON.stringify({ postgres:'16.15', database:'nextcloud', owner:'nextcloud', requiredRole:'LOGIN SUPERUSER', ports:[], admission:'closed', maintenance:true, backgroundWriters:'stopped', pgDataDirectoryCopied:false }));
  await fs.writeFile(path.join(directory, 's3.json'), JSON.stringify({ bucket:'mcp-l1', versioning:'disabled', objects:{ 'urn:oid:1':{payload:`objects/${key}`, bytes:1, sha256:await sha256(path.join(directory,'objects',key)), metadata:{}, tags:[], etag:'"9dd4e461268c8034f5c8564e155c67a6"'} } }));
  const privateInputs = {};
  for (const name of ['expected.json','app.env','pg.env','s3.json']) {
    await fs.writeFile(path.join(oracle,name), '{}'); privateInputs[name] = await sha256(path.join(oracle,name));
  }
  const metadata = { generation:'00000000-0000-4000-8000-000000000001', profileSha256:'a'.repeat(64), privateInputDirectory:'private', privateInputs, oracleSha256:privateInputs['expected.json'], baseline:profile.baseline, installedMcp:'1.2.0', core:profile.nextcloudVersion, images:profile.images, apps:profile.apps };
  const digest = await sealCheckpoint(directory,metadata);
  return {root,directory,metadata,trust:{generation:metadata.generation,manifestSha256:digest},context:{root,profile,profileSha256:'a'.repeat(64)}};
}

test('verified restore inputs admit one mutation; changed data, generation or private inputs admit none', async () => {
  const { withVerifiedCheckpoint } = await import('./restore-inputs.mjs');
  const f = await fixture(), target = path.join(f.root,'target');
  try {
    const restore = async () => fs.writeFile(target,'restored');
    await withVerifiedCheckpoint(f.context,f.directory,f.trust,restore);
    assert.equal(await fs.readFile(target,'utf8'),'restored'); await fs.unlink(target);
    await assert.rejects(withVerifiedCheckpoint(f.context,f.directory,{...f.trust,generation:'foreign'},restore));
    await fs.appendFile(path.join(f.directory,'config.tar'),'corrupt');
    await assert.rejects(withVerifiedCheckpoint(f.context,f.directory,f.trust,restore));
    f.trust.manifestSha256=await sealCheckpoint(f.directory,f.metadata);
    await fs.writeFile(path.join(f.root,'private/app.env'),'changed');
    await assert.rejects(withVerifiedCheckpoint(f.context,f.directory,f.trust,restore));
    await assert.rejects(fs.stat(target),{code:'ENOENT'});
  } finally { await fs.rm(f.root,{recursive:true,force:true}); }
});

test('restore refuses foreign profile and unsafe archives even with a newly sealed manifest', async () => {
  const { withVerifiedCheckpoint } = await import('./restore-inputs.mjs');
  const f = await fixture(); let activated=false;
  try {
    const restore=async()=>{activated=true;};
    await assert.rejects(withVerifiedCheckpoint({...f.context,profileSha256:'b'.repeat(64)},f.directory,f.trust,restore));
    const r=await command('python3',['-c','import tarfile,sys\nwith tarfile.open(sys.argv[1],"w") as t:\n i=tarfile.TarInfo("../escape");t.addfile(i)',path.join(f.directory,'config.tar')]);
    assert.equal(r.code,0);
    f.trust.manifestSha256=await sealCheckpoint(f.directory,f.metadata);
    await assert.rejects(withVerifiedCheckpoint(f.context,f.directory,f.trust,restore),/archive/);
    assert.equal(activated,false);
  } finally { await fs.rm(f.root,{recursive:true,force:true}); }
});

test('a code near expiry is incomplete evidence, including after a long restore', async () => {
  const { credentialWindow }=await import('./restore-inputs.mjs');
  const users={owner:{codeExpiresAt:600,accessExpiresAt:900,refreshExpiresAt:1200}};
  assert.equal(credentialWindow(users,570),30);
  for(const now of [571,600,601,NaN]) assert.throws(()=>credentialWindow(users,now),/window/);
  assert.throws(()=>credentialWindow({},100),/window/);
});

test('volume retirement verifies ownership and cannot target a foreign or unrecorded volume', async () => {
  const { Resources }=await import('./resources.mjs');
  const removed=[];
  const owner=new Resources('run',async args=>{
    if(args[1]==='inspect') return {code:0,out:JSON.stringify([{Name:args[2],Labels:{'mcp.linuxserver.run':args[2]==='owned'?'run':'other'}}])};
    if(args[1]==='rm') removed.push(args[2]);
    return {code:0,out:''};
  },async()=>{});
  owner.items.push({kind:'volume',id:'owned'},{kind:'volume',id:'foreign'});
  await assert.rejects(owner.removeVolume('foreign'));
  await assert.rejects(owner.removeVolume('unknown'));
  assert.deepEqual(removed,[]);
  await owner.removeVolume('owned');
  assert.deepEqual(removed,['owned']);
  await assert.rejects(owner.removeVolume('owned'));
});

test('case checkpoint names bind versions and reject traversal or mislabelled cases', async () => {
  const { checkpointPhase }=await import('./capture.mjs');
  assert.equal(checkpointPhase('B1-r1'),'B1');
  assert.equal(checkpointPhase('B0-r0-replaced'),'B0');
  for(const name of ['../B0','B0-r1','B1-r0-upgraded','B0/other']) assert.throws(()=>checkpointPhase(name));
});

test('missing objects, wrong candidate identity and escaping symlinks never admit restore', async () => {
  const { withVerifiedCheckpoint } = await import('./restore-inputs.mjs');
  for (const scenario of ['missing-object', 'candidate-digest', 'escaping-link']) {
    const f = await fixture(); let activated = false;
    try {
      if (scenario === 'missing-object') {
        const [name] = await fs.readdir(path.join(f.directory, 'objects'));
        await fs.unlink(path.join(f.directory, 'objects', name));
      } else if (scenario === 'candidate-digest') {
        f.metadata.installedMcp = '2.0.0';
        f.metadata.sourceKit = { ...f.context.profile.candidate, manifestSha256: '0'.repeat(64) };
        f.trust.manifestSha256 = await sealCheckpoint(f.directory, f.metadata);
      } else {
        const r = await command('python3', ['-c', 'import tarfile,sys\nwith tarfile.open(sys.argv[1],"w") as t:\n i=tarfile.TarInfo("escape");i.type=tarfile.SYMTYPE;i.linkname="../../outside";t.addfile(i)', path.join(f.directory, 'config.tar')]);
        assert.equal(r.code, 0);
        f.trust.manifestSha256 = await sealCheckpoint(f.directory, f.metadata);
      }
      await assert.rejects(withVerifiedCheckpoint(f.context, f.directory, f.trust, async () => { activated = true; }));
      assert.equal(activated, false, scenario);
    } finally { await fs.rm(f.root, { recursive: true, force: true }); }
  }
});

test('volume readback rejects changed bytes and numeric ownership', async () => {
  const f = await fixture();
  try {
    const expected = path.join(f.directory, 'data.tar'), actual = path.join(f.root, 'actual.tar');
    await fs.copyFile(expected, actual);
    const compare = () => command('python3', ['-B', new URL('./compare-volume.py', import.meta.url).pathname, expected, actual]);
    assert.equal((await compare()).code, 0);
    for (const mutation of ['i.uid=1000', 'body=b"y"']) {
      const result = await command('python3', ['-c', `import tarfile,sys,io\nwith tarfile.open(sys.argv[1],"w") as t:\n i=tarfile.TarInfo("fixture");i.size=1;body=b"x";${mutation};t.addfile(i,io.BytesIO(body))`, actual]);
      assert.equal(result.code, 0);
      assert.notEqual((await compare()).code, 0);
    }
  } finally { await fs.rm(f.root, { recursive: true, force: true }); }
});
