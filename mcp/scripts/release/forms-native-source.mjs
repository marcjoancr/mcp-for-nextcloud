// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { inventory, hashFile, verifyKit } from './integrity.mjs';

export const formsNativeAssets = Object.freeze(['forms-upstream-runtime.tar.gz', 'forms-upstream-source.tar.gz', 'forms-reviewed-overlay.tar.gz', 'forms-assembled-runtime.tar.gz', 'FORMS-BUILDING.md']);
export const formsUpstreamPins = Object.freeze({ upstreamCommit: '066573fd592513a06f8b8624def8244e911efdfd', upstreamRuntimeSha256: 'c12cc4e05dd9c41cfee8df75a8cc0f759e9a95169fefabd617713a934da7943a', upstreamSourceSha256: '96f5bbb6ddf44466df78080d11fa2454e39415d81b30dd991ed98253273c1c2d' });
const digest = /^[a-f0-9]{64}$/;
const isDigest = value => typeof value === 'string' && digest.test(value);
function exactKeys(actual, expected, message) {
  if (!actual || typeof actual !== 'object' || Array.isArray(actual) || JSON.stringify(Object.keys(actual).sort()) !== JSON.stringify([...expected].sort())) throw new Error(message);
}
function safePath(name) {
  if (!name || /[\\\x00-\x1f\x7f]/.test(name) || name.startsWith('/') || name.split('/').some(p => !p || p === '.' || p === '..')) throw new Error('Unsafe Forms inventory path');
}
function validateInventory(files) {
  if (!files || typeof files !== 'object' || Array.isArray(files) || !Object.keys(files).length) throw new Error('Invalid Forms inventory');
  for (const [name, record] of Object.entries(files)) {
    safePath(name);exactKeys(record, ['sha256', 'bytes'], 'Invalid Forms inventory record schema');
    if (!isDigest(record.sha256) || !Number.isSafeInteger(record.bytes) || record.bytes < 0) throw new Error('Invalid Forms inventory record');
  }
}
function validate(manifest) {
  exactKeys(manifest, ['schemaVersion', 'sourceCommit', 'formsVersion', 'installedManifestSha256', ...Object.keys(formsUpstreamPins), 'runtimeFiles', 'files'], 'Invalid Forms companion schema');
  if (manifest.schemaVersion !== 1 || (typeof manifest.sourceCommit !== 'string' || !/^[a-f0-9]{40}$/.test(manifest.sourceCommit)) || manifest.formsVersion !== '5.4.2'
    || !isDigest(manifest.installedManifestSha256) || Object.entries(formsUpstreamPins).some(([k, v]) => manifest[k] !== v)) throw new Error('Invalid Forms companion pins');
  exactKeys(manifest.files, formsNativeAssets, 'Invalid Forms asset inventory');validateInventory(manifest.files);validateInventory(manifest.runtimeFiles);
}
function compare(actual, expected) {
  exactKeys(actual, Object.keys(expected), 'Forms file inventory differs');
  for (const [name, record] of Object.entries(expected)) {
    if (actual[name].sha256 !== record.sha256 || actual[name].bytes !== record.bytes) throw new Error(`Forms file integrity differs: ${name}`);
  }
}
export async function sealFormsNativeCompanion(directory, metadata) {
  const manifest = { schemaVersion: 1, ...metadata, files: await inventory(directory) };validate(manifest);
  await fs.writeFile(path.join(directory, 'forms-native-manifest.json'), JSON.stringify(manifest, null, 2) + '\n', { flag: 'wx' });
  return hashFile(path.join(directory, 'forms-native-manifest.json'));
}
export async function verifyFormsNativeCompanion(directory, trustedDigest, sourceCommit) {
  if (!isDigest(trustedDigest)) throw new Error('Trusted Forms manifest digest required');
  if (!(await fs.lstat(directory)).isDirectory()) throw new Error('Forms companion must be a regular directory');
  const file = path.join(directory, 'forms-native-manifest.json'), stat = await fs.lstat(file);
  if (!stat.isFile() || stat.size > 4 * 1024 * 1024) throw new Error('Forms manifest must be a bounded regular file');
  const bytes = await fs.readFile(file);
  if (createHash('sha256').update(bytes).digest('hex') !== trustedDigest) throw new Error('Forms manifest digest mismatch');
  const manifest = JSON.parse(bytes);validate(manifest);
  if (manifest.sourceCommit !== sourceCommit) throw new Error('Forms companion source commit mismatch');
  const actual = await inventory(directory);delete actual['forms-native-manifest.json'];compare(actual, manifest.files);return manifest;
}
function phpIdentity(program, input, message) {
  try { return execFileSync('php', ['-r', program], { input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 1024 * 1024 }); }
  catch { throw new Error(message); }
}
function formsXmlIdentity(xml) {
  if (typeof xml !== 'string' || Buffer.byteLength(xml) > 1024 * 1024) throw new Error('Invalid Forms installed XML identity');
  return JSON.parse(phpIdentity(String.raw`
    $xml=stream_get_contents(STDIN,1048577);
    if(strlen($xml)>1048576||preg_match('/<!DOCTYPE|<!ENTITY/i',$xml))exit(2);
    libxml_use_internal_errors(true);$doc=new DOMDocument();
    if(!$doc->loadXML($xml,LIBXML_NONET)||$doc->doctype!==null)exit(2);
    $xpath=new DOMXPath($doc);$ids=$xpath->query('/info/id');$versions=$xpath->query('/info/version');
    if($ids->length!==1||$versions->length!==1)exit(2);
    echo json_encode(['id'=>trim($ids->item(0)->textContent),'version'=>trim($versions->item(0)->textContent)],JSON_THROW_ON_ERROR);
  `, xml, 'Invalid Forms installed XML identity'));
}
/** Tokenization checks the actual top-level namespaced class declaration without evaluating its source. */
export function readFormsCompiledPin(source) {
  if (typeof source !== 'string' || Buffer.byteLength(source) > 1024 * 1024) throw new Error('Invalid Forms compiled identity');
  return phpIdentity(String.raw`
    $source=stream_get_contents(STDIN,1048577);if(strlen($source)>1048576)exit(2);
    $tokens=array_values(array_filter(PhpToken::tokenize($source,TOKEN_PARSE),static fn(PhpToken $t):bool=>!$t->isIgnorable()));
    $namespace='';$depth=0;$target=false;$pending=false;$pins=[];
    foreach($tokens as$i=>$token){
      if($depth===0&&$token->id===T_NAMESPACE){
        if(($tokens[$i+2]->text??null)!==';')exit(2);$namespace=$tokens[$i+1]->text;
      }
      if($depth===0&&$token->id===T_CLASS&&($tokens[$i-1]->id??null)!==T_DOUBLE_COLON){
        $pending=$namespace==='OCA\Mcp\Modules\Forms\Integration'&&($tokens[$i+1]->text??null)==='FormsGuardIdentity';
      }
      if($token->text==='{'||$token->is([T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES])){++$depth;if($depth===1){$target=$pending;$pending=false;}}
      if($target&&$depth===1&&$token->id===T_CONST&&($tokens[$i+1]->text??null)==='INSTALLED_MANIFEST_SHA256'){
        if(($tokens[$i+2]->text??null)!=='='||($tokens[$i+3]->id??null)!==T_CONSTANT_ENCAPSED_STRING||($tokens[$i+4]->text??null)!==';')exit(2);
        $literal=$tokens[$i+3]->text;$pin=substr($literal,1,-1);
        if(!in_array($literal[0],["'",'"'],true)||substr($literal,-1)!==$literal[0]||preg_match('/^[a-f0-9]{64}$/D',$pin)!==1)exit(2);$pins[]=$pin;
      }
      if($token->text==='}'){if($depth===1)$target=false;--$depth;}
    }
    if(count($pins)!==1)exit(2);echo $pins[0];
  `, source, 'Invalid Forms compiled identity');
}
async function formsOutputParent(root, destination) {
  const repository = await fs.realpath(root), mcp = path.join(repository, 'mcp'), build = path.join(mcp, 'build');
  if (!(await fs.lstat(mcp)).isDirectory() || !(await fs.lstat(build)).isDirectory()
    || await fs.realpath(build) !== build) throw new Error('Forms build root must be a real directory inside the repository');
  const parent = await fs.realpath(path.dirname(destination));
  if (parent !== build && !parent.startsWith(build + path.sep)) throw new Error('Forms output must stay below project mcp/build');
  return parent;
}

export function assertFormsBuildIdentity(bytes, xml, expectedDigest) {
  if (typeof bytes !== 'string' || Buffer.byteLength(bytes) > 1024 * 1024 || !isDigest(expectedDigest)
    || createHash('sha256').update(bytes).digest('hex') !== expectedDigest) throw new Error('Forms installed identity digest mismatch');
  const manifest = JSON.parse(bytes);exactKeys(manifest, ['protocol', 'formsVersion', 'formsCommit', 'sha256'], 'Invalid Forms installed identity schema');
  const identity = formsXmlIdentity(xml);
  if (manifest.protocol !== 1 || manifest.formsVersion !== '5.4.2' || manifest.formsCommit !== formsUpstreamPins.upstreamCommit
    || identity.id !== 'forms' || identity.version !== '5.4.2'
    || !manifest.sha256 || typeof manifest.sha256 !== 'object' || Array.isArray(manifest.sha256) || !Object.keys(manifest.sha256).length) throw new Error('Invalid Forms installed identity');
  for (const [name, sha] of Object.entries(manifest.sha256)) { safePath(name);if (!isDigest(sha)) throw new Error('Invalid Forms installed identity hash'); }
  return manifest;
}
export async function verifyFormsInstalled(directory, trustedDigest, sourceCommit, installed) {
  const manifest = await verifyFormsNativeCompanion(directory, trustedDigest, sourceCommit);compare(await inventory(installed), manifest.runtimeFiles);
  const native = assertFormsBuildIdentity(await fs.readFile(path.join(installed, 'mcp-guard-installed.json'), 'utf8'), await fs.readFile(path.join(installed, 'appinfo/info.xml'), 'utf8'), manifest.installedManifestSha256);
  for (const [name, sha] of Object.entries(native.sha256)) if (manifest.runtimeFiles[name]?.sha256 !== sha) throw new Error('Forms installed identity inventory differs');
  return manifest;
}
function run(command, args, cwd) { return execFileSync(command, args, { cwd, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 16 * 1024 * 1024 }); }
function archive(source, output, entries) { run('tar', ['--sort=name', '--mtime=@0', '--owner=0', '--group=0', '--numeric-owner', '--mode=u=rwX,go=rX', '-czf', output, '-C', source, '--', ...entries]); }
/** Both Forms profiles pin MCP code used by the native group and sharing paths. */
export async function assertFormsMcpSourcePins(repository, runtimeFiles) {
  const guard = path.join(repository, 'native/forms-guard');
  const groups = JSON.parse(await fs.readFile(path.join(guard, 'standalone/GroupSources.json'), 'utf8'));
  const finalShares = JSON.parse(await fs.readFile(path.join(guard, 'src/Service/FinalShareSources.json'), 'utf8'));
  for (const file of ['lib/AppInfo/Application.php', 'lib/Listener/GroupLifecycleListener.php']) {
    const installed = 'custom_apps/mcp/' + file;
    const sha = runtimeFiles[file]?.sha256;
    if (!isDigest(sha) || groups.native?.[installed] !== sha || finalShares[installed] !== sha) {
      throw new Error(`Forms source profile differs from packaged MCP: ${file}`);
    }
  }
}
async function assemble(repository, original, directory, expected) {
  await fs.mkdir(directory);const upstream = path.join(directory, 'upstream'), forms = path.join(directory, 'forms');await fs.mkdir(upstream);
  run('tar', ['-xzf', original, '-C', upstream]);run(process.execPath, ['native/forms-guard/prepare.mjs', path.join(upstream, 'forms'), forms], repository);
  const marker = await fs.readFile(path.join(forms, 'mcp-guard-installed.json'), 'utf8');
  assertFormsBuildIdentity(marker, await fs.readFile(path.join(forms, 'appinfo/info.xml'), 'utf8'), expected);
  return { forms, runtimeFiles: await inventory(forms) };
}
/** Executes only the reviewed source bundle after independent kit/archive integrity admission. No fetch/install/publication. */
export async function prepareFormsNativeCompanion(root, kit, kitDigest, original, preferred, destination) {
  const parent = await formsOutputParent(root, destination);
  const mcp = await verifyKit(kit, kitDigest);
  if (await hashFile(original) !== formsUpstreamPins.upstreamRuntimeSha256 || await hashFile(preferred) !== formsUpstreamPins.upstreamSourceSha256) throw new Error('Pinned Forms upstream archive mismatch');
  destination = path.resolve(destination);await fs.mkdir(destination);const stage = await fs.mkdtemp(path.join(parent, '.forms-native-prepare-'));
  try {
    const runtime = path.join(stage, 'upstream-runtime.tar.gz'), source = path.join(stage, 'upstream-source.tar.gz'), bundle = path.join(stage, 'source.bundle');
    await fs.copyFile(original, runtime);await fs.copyFile(preferred, source);await fs.copyFile(path.join(kit, 'source.bundle'), bundle);
    if (await hashFile(runtime) !== formsUpstreamPins.upstreamRuntimeSha256 || await hashFile(source) !== formsUpstreamPins.upstreamSourceSha256 || await hashFile(bundle) !== mcp.files['source.bundle']?.sha256) throw new Error('Frozen Forms build input integrity mismatch');
    const repository = path.join(stage, 'source');run('git', ['clone', '--quiet', '--no-checkout', bundle, repository]);run('git', ['checkout', '--quiet', '--detach', mcp.sourceCommit], repository);
    await assertFormsMcpSourcePins(repository, mcp.runtimeFiles);
    const pinFile = path.join(repository, 'mcp/lib/Modules/Forms/Integration/FormsGuardIdentity.php');
    if (await hashFile(pinFile) !== mcp.runtimeFiles['lib/Modules/Forms/Integration/FormsGuardIdentity.php']?.sha256) throw new Error('Forms compiled identity differs between source and MCP kit');
    const expected = readFormsCompiledPin(await fs.readFile(pinFile, 'utf8'));
    await fs.mkdir(path.join(repository, 'mcp/build'), { recursive: true });
    const first = await assemble(repository, runtime, path.join(repository, 'mcp/build/first'), expected);
    const second = await assemble(repository, runtime, path.join(repository, 'mcp/build/reconstruction'), expected);compare(second.runtimeFiles, first.runtimeFiles);
    await fs.copyFile(runtime, path.join(destination, formsNativeAssets[0]));await fs.copyFile(source, path.join(destination, formsNativeAssets[1]));
    archive(repository, path.join(destination, formsNativeAssets[2]), ['native/forms-guard']);archive(path.dirname(first.forms), path.join(destination, formsNativeAssets[3]), ['forms']);
    await fs.writeFile(path.join(destination, 'FORMS-BUILDING.md'), `# Guarded Forms corresponding source\n\nMCP source commit: ${mcp.sourceCommit}\nForms version: 5.4.2\nUpstream source commit: ${formsUpstreamPins.upstreamCommit}\nIndependently compiled installed-manifest SHA-256: ${expected}\n\nThis is a modified, unsigned Forms distribution. The upstream signature does not authenticate the overlay. Preserve the original COPYING, dependency licenses and notices. Keep the separate five-file MCP kit and its trusted digest unchanged.\n\n1. Verify forms-native-manifest.json using its independently recorded digest and verify all five assets. Clone the matching MCP source.bundle and check out the exact commit above. The reviewed overlay archive separately retains native/forms-guard source, preparation, migrations, standalone comments integration and tests.\n2. Extract forms-upstream-runtime.tar.gz to a fresh upstream directory. In the frozen checkout run node native/forms-guard/prepare.mjs /absolute/upstream/forms /absolute/new/forms. Preparation needs Node 24 and PHP with the documented development extensions.\n3. Compare every regular reconstructed file's size and SHA-256 with runtimeFiles, including assets outside the guard's selected executable/configuration hashes. Reject missing/extra paths, links and special files. The assembled runtime is separately provided in forms-assembled-runtime.tar.gz.\n4. Verify an installed tree with node mcp/scripts/release/cli.mjs verify-forms-installed /absolute/companion TRUSTED_DIGEST ${mcp.sourceCommit} /absolute/installed/forms during a quiescent window. It checks the complete inventory and independently compiled marker.\n\nThe preferred upstream archive contains source, Composer/npm locks and build configuration. Reconstruction preserves upstream shipped assets; a bit-identical independent frontend build and a fully offline toolchain are not claimed. Preparation reconstructed twice and required identical complete inventories.\n\nInstallation is a separate authorized maintenance action. Follow native/forms-guard/README.md for migrations and the qualified comments writer. Back up the database, complete app code and affected files first. Rollback may require the matching database/file checkpoint as well as complete previous code; old code alone does not undo mutations or migrations. Never blindly drop companion history/evidence tables or prune retained recovery files. No command in this delivery prepares production traffic or publishes an offer.\n`);
    const manifestSha256 = await sealFormsNativeCompanion(destination, { sourceCommit: mcp.sourceCommit, formsVersion: '5.4.2', installedManifestSha256: expected, ...formsUpstreamPins, runtimeFiles: first.runtimeFiles });
    await verifyFormsInstalled(destination, manifestSha256, mcp.sourceCommit, first.forms);
    return { directory: destination, manifestSha256, sourceCommit: mcp.sourceCommit, installedManifestSha256: expected, runtimeFiles: Object.keys(first.runtimeFiles).length, reconstruction: 'two exact full inventories match' };
  } finally { await fs.rm(stage, { recursive: true, force: true }); }
}
