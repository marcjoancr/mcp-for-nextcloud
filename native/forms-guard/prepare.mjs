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
// Native account lifetime tracking must remain registered while the MCP app is disabled.
const appFile=join(output,'lib/AppInfo/Application.php');let appSource=readFileSync(appFile,'utf8');
const registration='$context->registerCapability(Capabilities::class);';
if(!appSource.includes(registration))throw new Error('Native account lifecycle registration missing');
appSource=appSource.replace(registration,registration+'\n\t\t$context->registerEventListener(\\OCP\\User\\Events\\BeforeUserCreatedEvent::class, \\OCA\\Forms\\Mcp\\Service\\NativeHistoryUserListener::class);');writeFileSync(appFile,appSource);
// Route only an active authenticated managed-share producer. Anonymous/global/background metadata remains stock.
const circleServiceFile=join(output,'lib/Service/CirclesService.php');let circleService=readFileSync(circleServiceFile,'utf8');
for(const [method,header,args] of [
 ['isCirclesEnabled','public function isCirclesEnabled(): bool {','[]'],
 ['getCircle','public function getCircle(string $circleId): ?Circle {',"['circleId'=>$circleId]"],
 ['isUserInCircle','public function isUserInCircle(string $circleId, string $userId): bool {',"['circleId'=>$circleId,'userId'=>$userId]"],
 ['getCircleUsers','public function getCircleUsers(string $circleId): array {',"['circleId'=>$circleId]"],
 ['getUserTeamIds','public function getUserTeamIds(string $userId): array {',"['userId'=>$userId]"],
]) {
 if(circleService.split(header).length!==2)throw new Error(`Native Circle method boundary missing: ${method}`);
 circleService=circleService.replace(header,header+`\n\t\tif (\\OCA\\Forms\\Mcp\\Service\\CircleReadRuntime::managedProducerActive()) { return \\OCA\\Forms\\Mcp\\Service\\CircleReadRuntime::managedProducer('${method}',${args}); }`);
}
writeFileSync(circleServiceFile,circleService);
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
// Stale queued jobs recheck the marker inside the global fence, before resolving or switching owner session.
const linkedJobFile=join(output,'lib/BackgroundJob/SyncSubmissionsWithLinkedFileJob.php');
let linkedJob=readFileSync(linkedJobFile,'utf8');
const linkedFence='\\OCA\\Forms\\Mcp\\Service\\FormsRevisionFence::run(function () use ($argument) {';
if (!linkedJob.includes(linkedFence)) throw new Error('Linked job fence hook missing');
linkedJob=linkedJob.replace(linkedFence,linkedFence+'\n if (\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::suppress((int)$argument[\'form_id\'])) { return; }');
writeFileSync(linkedJobFile,linkedJob);
const linkedApiFile=join(output,'lib/Controller/ApiController.php');let linkedApi=readFileSync(linkedApiFile,'utf8');
const linkedQueue='private function queueLinkedFileSync(Form $form): void {';
if (!linkedApi.includes(linkedQueue)) throw new Error('Linked queue hook missing');
linkedApi=linkedApi.replace(linkedQueue,linkedQueue+'\n if (\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::suppress($form->getId())) { return; }');
for (const header of ["if (isset($keyValuePairs['path']) && isset($keyValuePairs['fileFormat'])) {", "if (array_key_exists('fileId', $keyValuePairs) && array_key_exists('fileFormat', $keyValuePairs) && !isset($keyValuePairs['fileFormat'])) {"]) {
 if (!linkedApi.includes(header)) throw new Error('Explicit linked UI hook missing');
 linkedApi=linkedApi.replace(header,header+'\n \\OCP\\Server::get(\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::class)->retire($form->getId());');
}
writeFileSync(linkedApiFile,linkedApi);
const linkedMapperFile=join(output,'lib/Db/FormMapper.php');let linkedMapper=readFileSync(linkedMapperFile,'utf8');
linkedMapper=linkedMapper.replace('return parent::update($entity);',"return \\OCP\\Server::get(\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::class)->formMutation($entity,'update',fn()=>parent::update($entity));");
writeFileSync(linkedMapperFile,linkedMapper);
// Under an approved MCP file operation, delete only the verified native file IDs. Never recurse by folder name.
for (const [file,method,body] of [
 ['Db/FormMapper.php','deleteFormFolder','if (\\OCA\\Forms\\Mcp\\Service\\FileRecovery::active()) { return; }'],
 ['Db/FormMapper.php','deleteUploadedFiles','if (\\OCA\\Forms\\Mcp\\Service\\FileRecovery::active()) { $this->uploadedFileMapper->deleteByFormId($form->getId()); return; }'],
 ['Db/SubmissionMapper.php','deleteSubmissionFolder','if (\\OCA\\Forms\\Mcp\\Service\\FileRecovery::active()) { return; }'],
 ['Helper/FilePathHelper.php','syncFormUploadFolderName','if (\\OCA\\Forms\\Mcp\\Service\\FileRecovery::active()) { return; }'],
 ['BackgroundJob/DeleteQuestionFoldersJob.php','run','if (\\OCA\\Forms\\Mcp\\Service\\FileRecovery::skipDeferred($argument)) { return; }'],
]) {
 const p=join(output,'lib',file);let value=readFileSync(p,'utf8');
 const pattern=new RegExp(`((?:public|private) function ${method}\\([^\\n]*\\)(?:[^\\n{]*)\\{)`);
 if (!pattern.test(value)) throw new Error('Managed file hook missing: '+file+'::'+method);
 value=value.replace(pattern,'$1\n '+body);writeFileSync(p,value);
}
// Track exactly the folder and Files share objects native Forms creates. Existing unrelated shares are never adopted.
const uploadSharesFile=join(output,'lib/Service/UploadedFilesShareService.php');let uploadShares=readFileSync(uploadSharesFile,'utf8');
const removeHeader='public function removeForCollaborator(Form $form, Share $formShare): void {';
if (!uploadShares.includes(removeHeader)) throw new Error('Native uploaded-share removal hook missing');
uploadShares=uploadShares.replace(removeHeader,removeHeader+'\n \\OCA\\Forms\\Mcp\\Service\\ManagedShares::remove($form,$formShare); return;');
writeFileSync(uploadSharesFile,uploadShares);
const deletingShareFile=join(output,'lib/Db/FormMapper.php');let deletingShare=readFileSync(deletingShareFile,'utf8');
const removeAllCall='$this->uploadedFilesShareService->removeAllForForm($form);';
if (!deletingShare.includes(removeAllCall)) throw new Error('Native form share deletion hook missing');
deletingShare=deletingShare.replace(removeAllCall,'\\OCA\\Forms\\Mcp\\Service\\ManagedSharePhase::removeForDeletedForm($form);');
writeFileSync(deletingShareFile,deletingShare);
const managedShareFile=join(output,'lib/Controller/ShareApiController.php');let managedShare=readFileSync(managedShareFile,'utf8');
const shareBlockStart=managedShare.indexOf("\t\t\t\t$userFolder = $this->rootFolder->getUserFolder($form->getOwnerId());");
const shareBlockEnd=managedShare.indexOf('$this->shareManager->createShare($folderShare);',shareBlockStart);
if (shareBlockStart<0 || shareBlockEnd<0) throw new Error('Native uploaded-share creation block missing');
managedShare=managedShare.slice(0,shareBlockStart)+'\\OCA\\Forms\\Mcp\\Service\\ManagedSharePhase::schedule($form,$formShare,true);'+managedShare.slice(shareBlockEnd+'$this->shareManager->createShare($folderShare);'.length);
managedShare=managedShare.replaceAll('\\OCA\\Forms\\Mcp\\Service\\FormsRevisionFence::run(', '\\OCA\\Forms\\Mcp\\Service\\ManagedSharePhase::nativeRun(');
writeFileSync(managedShareFile,managedShare);
const nativeDeleteFile=join(output,'lib/Controller/ApiController.php');let nativeDelete=readFileSync(nativeDeleteFile,'utf8');
const nativeDeletePattern=/(public function deleteForm\([^)]*\)[^{]*\{\s*return )\\OCA\\Forms\\Mcp\\Service\\FormsRevisionFence::run/;
if (!nativeDeletePattern.test(nativeDelete)) throw new Error('Native delete form request hook missing');
nativeDelete=nativeDelete.replace(nativeDeletePattern,'$1\\OCA\\Forms\\Mcp\\Service\\ManagedSharePhase::nativeRun');
writeFileSync(nativeDeleteFile,nativeDelete);

// An expired opaque handle is not authorization to delete its bytes. Retain MCP staging until approved recovery-backed cleanup.
const temporaryFile=join(output,'lib/BackgroundJob/CleanupUploadedFilesJob.php');let temporary=readFileSync(temporaryFile,'utf8');
const temporaryLoop='foreach ($uploadedFiles as $uploadedFile) {';
if (!temporary.includes(temporaryLoop) || !temporary.includes('// now delete empty folders in user folders')) throw new Error('Native temporary cleanup hook missing');
temporary=temporary.replace(temporaryLoop,temporaryLoop+"\n if (\\OCP\\Server::get(\\OCP\\IDBConnection::class)->executeQuery('SELECT upload_id FROM *PREFIX*forms_mcp_uploads WHERE upload_id = ?',[$uploadedFile->getId()])->fetchOne() !== false) { continue; }");
// Stock code deletes whole timestamp folders even when they contain unrelated files; exact record deletion above is sufficient.
temporary=temporary.replace('// now delete empty folders in user folders','return; // Retain containers; never recurse by timestamp.\n // now delete empty folders in user folders');
writeFileSync(temporaryFile,temporary);
// Every pinned native Node deletion checks retained exports, including another form's unlinked output.
for (const [file,count] of [
 ['Db/FormMapper.php',2],['Db/SubmissionMapper.php',1],['BackgroundJob/DeleteQuestionFoldersJob.php',1],
 ['BackgroundJob/CleanupUploadedFilesJob.php',2],['Helper/FilePathHelper.php',3],
]) {
 const p=join(output,'lib',file);let source=readFileSync(p,'utf8');
 const calls=/\$([A-Za-z_][A-Za-z0-9_]*)->delete\(\);(?:\s*\$deletedCount\+\+;)?/g;
 if ([...source.matchAll(calls)].length!==count) throw new Error('Native retained-output delete hook changed: '+file);
 source=source.replace(calls,(call,node)=>`if (!\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::containsOutput($${node})) { ${call} }`);
 writeFileSync(p,source);
}
// Inherited mapper writes can be invoked by native service extensions as well as controllers.
for (const entity of ['Form','Question','Option','Share','Answer','Submission','UploadedFile']) {
 const p=join(output,'lib/Db',entity+'Mapper.php');let source=readFileSync(p,'utf8');
 const additions=['insert','update','delete'].filter(method=>!new RegExp(`function ${method}\\(`).test(source)).map(method=> {
  let mutation=`\\OCA\\Forms\\Mcp\\Service\\FormsRevisionFence::run(fn()=>parent::${method}($entity))`;
  if (['Answer','Submission'].includes(entity)) {
   mutation=`\\OCA\\Forms\\Mcp\\Service\\ResultSourceRevision::mutate('${entity}', '${method}', $entity, fn()=>parent::${method}($entity))`;
  } else if (['Share','UploadedFile'].includes(entity)) {
   mutation=`\\OCA\\Forms\\Mcp\\Service\\BulkEpoch::mutate('${entity}',$entity,fn()=>parent::${method}($entity))`;
  } else if (entity==='Form' && method==='delete') {
   mutation="\\OCP\\Server::get(\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::class)->formMutation($entity,'delete',fn()=>parent::delete($entity))";
  } else if (['Question','Option'].includes(entity)) {
   mutation=`\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::schemaMutation('${entity}',$entity,fn()=>parent::${method}($entity))`;
  }
  return `
 public function ${method}(\\OCP\\AppFramework\\Db\\Entity $entity): \\OCP\\AppFramework\\Db\\Entity {
   return ${mutation};
 }
`; }).join('');
 const end=source.lastIndexOf('}');writeFileSync(p,source.slice(0,end)+additions+source.slice(end));
}
// The two pinned direct data deletes bypass generic Entity methods. Resolve ownership under the already-wrapped fence.
for (const [file,lookup] of [['Db/QuestionMapper.php','$formId'],['Db/OptionMapper.php',"\\OCP\\Server::get(\\OCP\\IDBConnection::class)->executeQuery('SELECT form_id FROM *PREFIX*forms_v2_questions WHERE id = ?',[$questionId])->fetchOne()"]]) {
 const p=join(output,'lib',file);let source=readFileSync(p,'utf8');
 const statement='$qb->executeStatement();';
 if(source.split(statement).length!==2)throw new Error('Pinned linked schema delete changed: '+file);
 source=source.replace(statement,'$mcpLinkedForm='+lookup+';\n $mcpRows=$qb->executeStatement();\n if ($mcpRows>0 && $mcpLinkedForm!==false) { \\OCP\\Server::get(\\OCA\\Forms\\Mcp\\Service\\LinkedMarkers::class)->dirty((int)$mcpLinkedForm); }');
 writeFileSync(p,source);
}
for (const file of ['Db/ShareMapper.php','Db/UploadedFileMapper.php']) {
 const p=join(output,'lib',file);let source=readFileSync(p,'utf8');const statement='$qb->executeStatement();';
 if(source.split(statement).length!==2)throw new Error('Pinned bulk source delete changed: '+file);
 source=source.replace(statement,'$mcpRows=$qb->executeStatement(); if ($mcpRows>0) { \\OCA\\Forms\\Mcp\\Service\\BulkEpoch::touch($formId); }');writeFileSync(p,source);
}
for (const [file,kind] of [['Db/AnswerMapper.php','answer'],['Db/SubmissionMapper.php','submission']]) {
 const p=join(output,'lib',file);let source=readFileSync(p,'utf8');
 const statement='$qb->executeStatement();';
 if(source.split(statement).length!==2)throw new Error('Pinned result bulk mutation changed: '+file);
 if(kind==='answer') {
  const start='$qb = $this->db->getQueryBuilder();';
  const offset=source.indexOf('public function deleteBySubmission(');const at=source.indexOf(start,offset);
  if(at<0)throw new Error('Answer result owner hook missing');
  source=source.slice(0,at)+'$mcpFormId=\\OCA\\Forms\\Mcp\\Service\\ResultSourceRevision::forSubmission($submissionId);\n'+source.slice(at);
 }
 source=source.replace(statement,'$mcpRows=$qb->executeStatement();\n if ($mcpRows>0) { '+(kind==='answer'?'if ($mcpFormId===null) {throw new \\RuntimeException("Answer result source is missing");} ':'')+'\\OCA\\Forms\\Mcp\\Service\\ResultSourceRevision::touch('+(kind==='answer'?'$mcpFormId':'$formId')+'); }');
 writeFileSync(p,source);
}
const formsService=join(output,'lib/Service/FormsService.php');let service=readFileSync(formsService,'utf8');
for(const [method,kind,param] of [['notifyNewShares','share','share'],['notifyNewSubmission','submission','submission']]) {
 const pattern=new RegExp(`(public function ${method}\\([^\\n]+\\): void \\{)`);
 if(!pattern.test(service)) throw new Error('Notification hook missing');
 service=service.replace(pattern,`$1\n if (\\OCA\\Forms\\Mcp\\Service\\FormsEffectOutbox::capture('${kind}', $form->getId(), $${param}->getId())) { return; }`);
}
writeFileSync(formsService,service);
const confirmationFile=join(output,'lib/Service/ConfirmationEmailService.php');let confirmation=readFileSync(confirmationFile,'utf8');
const mailPattern=/\$this->jobList->add\(SendConfirmationMailJob::class, (\[[\s\S]*?\n\t\t\])\);/;
if(!mailPattern.test(confirmation))throw new Error('Confirmation mail enqueue hook missing');
confirmation=confirmation.replace(mailPattern,"$mcpMailArguments=$1;\n if (!\\OCA\\Forms\\Mcp\\Service\\FormsEffectOutbox::queueMail($mcpMailArguments)) { $this->jobList->add(SendConfirmationMailJob::class,$mcpMailArguments); }");
writeFileSync(confirmationFile,confirmation);
const mailFile=join(output,'lib/BackgroundJob/SendConfirmationMailJob.php');let mail=readFileSync(mailFile,'utf8');
const mailHeader='public function run($argument): void {';
if(!mail.includes(mailHeader))throw new Error('Confirmation mail worker hook missing');
mail=mail.replace(mailHeader,mailHeader+"\n if (array_key_exists('_mcp_mail',$argument)) { \\OCA\\Forms\\Mcp\\Service\\FormsEffectOutbox::sendMail($argument['_mcp_mail'],function(array $nativeArgument):void {$this->run($nativeArgument);}); return; }");
writeFileSync(mailFile,mail);

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
for (const name of ['lib/Service/CirclesService.php','lib/Service/ConfirmationEmailService.php','lib/BackgroundJob/SendConfirmationMailJob.php',...Object.keys(hooks).map(p=>'lib/'+p), ...walk(join(base,'src')).map(p=>p.startsWith('Migration/')?'lib/'+p:'lib/Mcp/'+p)]) {
 const lint=spawnSync('php',['-l',join(output,name)],{encoding:'utf8'});
 if(lint.status!==0) throw new Error(lint.stderr || lint.stdout || 'Generated PHP is invalid');
}
const installed={protocol:1,formsVersion:manifest.formsVersion,formsCommit:manifest.formsCommit,sha256:{}};
for(const name of walk(output)) if(/\.(php|xml|json)$/.test(name)) installed.sha256[name]=hash(join(output,name));
writeFileSync(join(output,'mcp-guard-installed.json'),JSON.stringify(installed,null,2)+'\n');
console.log(JSON.stringify({output,formsVersion:manifest.formsVersion,protocol:1,files:Object.keys(installed.sha256).length,manifestSha256:hash(join(output,'mcp-guard-installed.json'))}));
