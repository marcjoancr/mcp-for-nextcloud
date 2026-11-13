// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { readFileSync, writeFileSync, readdirSync, lstatSync, mkdirSync, cpSync, existsSync } from 'node:fs';
import { resolve, join, relative, dirname } from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
const base=import.meta.dirname;
const [sourceArg,outputArg]=process.argv.slice(2);
if (!sourceArg || !outputArg) throw new Error('Usage: node prepare.mjs PINNED_INSTALLABLE_FORMS_DIRECTORY NEW_OUTPUT_DIRECTORY');
const source=resolve(sourceArg),output=resolve(outputArg);
const manifest=JSON.parse(readFileSync(join(base,'manifest.json'),'utf8'));
const hash=p=>createHash('sha256').update(readFileSync(p)).digest('hex');
if (lstatSync(source).isSymbolicLink() || !lstatSync(source).isDirectory()) throw new Error('Source must be a regular directory');
if (existsSync(output)) throw new Error('Output must not exist');
if (output.startsWith(source+'/')) throw new Error('Output must be outside source');
for (const [name,sha] of Object.entries(manifest.sourceSha256)) {
  const p=join(source,name);
  if (!existsSync(p) || lstatSync(p).isSymbolicLink() || !lstatSync(p).isFile() || hash(p)!==sha) throw new Error(`Unknown Forms source: ${name}`);
}
function walk(root,dir=root){return readdirSync(dir).flatMap(n=>{const p=join(dir,n),st=lstatSync(p);if(st.isSymbolicLink()) throw new Error('Symlink source refused');return st.isDirectory()?walk(root,p):[relative(root,p)];});}
for (const name of walk(source)) if (/\.(php|xml|json)$/.test(name) && !manifest.sourceSha256[name]) throw new Error(`Unexpected executable/configuration source: ${name}`);
mkdirSync(output);cpSync(source,output,{recursive:true});
const hooks={
 'Controller/ApiController.php':['newForm','updateForm','deleteForm','newQuestion','updateQuestion','deleteQuestion','reorderQuestions','newOption','updateOption','deleteOption','reorderOptions','deleteAllSubmissions','newSubmission','updateSubmission','deleteSubmission','exportSubmissionsToCloud','uploadFiles'],
 'Controller/ShareApiController.php':['newShare','updateShare','deleteShare'],
 'Controller/ConfigController.php':['updateAppConfig'],
 'FormsMigrator.php':['import'],
 'ShareReview/ShareReviewSource.php':['deleteShare'],
 'BackgroundJob/UserDeletedJob.php':['run'],
 'BackgroundJob/CleanupUploadedFilesJob.php':['run'],
 'BackgroundJob/DeleteQuestionFoldersJob.php':['run'],
 'BackgroundJob/SyncSubmissionsWithLinkedFileJob.php':['run'],
 'Db/FormMapper.php':['insert','update','deleteForm'],
 'Db/QuestionMapper.php':['deleteByForm'],
 'Db/OptionMapper.php':['deleteByQuestion'],
 'Db/ShareMapper.php':['deleteByForm'],
 'Db/AnswerMapper.php':['deleteBySubmission'],
 'Db/SubmissionMapper.php':['deleteById','deleteByForm'],
 'Db/UploadedFileMapper.php':['deleteByFormId'],
};
for (const [file,methods] of Object.entries(hooks)) {
 const result=spawnSync('php',[join(base,'transform.php'),join(output,'lib',file),...methods],{encoding:'utf8'});
 if(result.status!==0) throw new Error(result.stderr || result.stdout || `Patch failed: ${file}`);
}
// Inherited mapper writes can be invoked by native service extensions as well as controllers.
for (const entity of ['Form','Question','Option','Share','Answer','Submission','UploadedFile']) {
 const p=join(output,'lib/Db',entity+'Mapper.php');let source=readFileSync(p,'utf8');
 const additions=['insert','update','delete'].filter(method=>!new RegExp(`function ${method}\\(`).test(source)).map(method=>`
 public function ${method}(\\OCP\\AppFramework\\Db\\Entity $entity): \\OCP\\AppFramework\\Db\\Entity {
   return \\OCA\\Forms\\Mcp\\Service\\FormsRevisionFence::run(fn()=>parent::${method}($entity));
 }
`).join('');
 const end=source.lastIndexOf('}');writeFileSync(p,source.slice(0,end)+additions+source.slice(end));
}
const formsService=join(output,'lib/Service/FormsService.php');let service=readFileSync(formsService,'utf8');
for(const [method,kind,param] of [['notifyNewShares','share','share'],['notifyNewSubmission','submission','submission']]) {
 const pattern=new RegExp(`(public function ${method}\\([^\\n]+\\): void \\{)`);
 if(!pattern.test(service)) throw new Error('Notification hook missing');
 service=service.replace(pattern,`$1\n if (\\OCA\\Forms\\Mcp\\Service\\FormsEffectOutbox::capture('${kind}', $form->getId(), $${param}->getId())) { return; }`);
}
writeFileSync(formsService,service);
for(const name of walk(join(base,'src'))) {
 const dest=name.startsWith('Migration/')?join(output,'lib',name):join(output,'lib/Mcp',name);
 mkdirSync(dirname(dest),{recursive:true});cpSync(join(base,'src',name),dest);
}
// Native debug logs must not contain submitted answers or bearer share capabilities.
const apiFile=join(output,'lib/Controller/ApiController.php');let api=readFileSync(apiFile,'utf8');
api=api.replace(/\$this->logger->debug\('(?:Inserting|Updating) submission:[\s\S]*?\n\t\t\]\);/g,"$this->logger->debug('Forms submission requested');");
api=api.replace(/\$this->logger->debug\('[^'\n]*keyValuePairs[^'\n]*',[\s\S]*?\n\t\t\]\);/g,"$this->logger->debug('Forms field update requested');");
writeFileSync(apiFile,api);
const shareFile=join(output,'lib/Controller/ShareApiController.php');let share=readFileSync(shareFile,'utf8');
share=share.replace(/\$this->logger->debug\('[^'\n]*keyValuePairs[^'\n]*',[\s\S]*?\n\t\t\]\);/g,"$this->logger->debug('Forms share update requested');");
writeFileSync(shareFile,share);
for (const name of [...Object.keys(hooks).map(p=>'lib/'+p), ...walk(join(base,'src')).map(p=>p.startsWith('Migration/')?'lib/'+p:'lib/Mcp/'+p)]) {
 const lint=spawnSync('php',['-l',join(output,name)],{encoding:'utf8'});
 if(lint.status!==0) throw new Error(lint.stderr || lint.stdout || 'Generated PHP is invalid');
}
const installed={protocol:1,formsVersion:manifest.formsVersion,formsCommit:manifest.formsCommit,sha256:{}};
for(const name of walk(output)) if(/\.(php|xml|json)$/.test(name)) installed.sha256[name]=hash(join(output,name));
writeFileSync(join(output,'mcp-guard-installed.json'),JSON.stringify(installed,null,2)+'\n');
console.log(JSON.stringify({output,formsVersion:manifest.formsVersion,protocol:1,files:Object.keys(installed.sha256).length,manifestSha256:hash(join(output,'mcp-guard-installed.json'))}));
