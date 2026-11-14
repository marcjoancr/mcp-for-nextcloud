<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCA\Forms\Db\{FormMapper,QuestionMapper,SubmissionMapper,AnswerMapper,UploadedFileMapper};
use OCA\Forms\Helper\FilePathHelper;
use OCP\{Server,IDBConnection};
use OCP\Files\{File,Folder,IRootFolder,NotFoundException};
use OCP\Lock\ILockingProvider;
/** Recovery copies and a signed manifest are verified before any approved managed-file removal. */
final class FileRecovery {
    private static bool $active=false;
    public static function active(): bool {return self::$active;}
    public static function skipDeferred(array $argument): bool {
        return (bool)Server::get(IDBConnection::class)->executeQuery('SELECT form_id FROM *PREFIX*forms_mcp_file_guards WHERE form_id = ? AND question_id = ?',[(int)$argument['formId'],(int)$argument['questionId']])->fetchOne();
    }
    private static function relevant(string $action,array $input): bool {
        if (in_array($action,['forms_update_share','forms_unshare'],true)) {
            $share=Server::get(\OCA\Forms\Db\ShareMapper::class)->findById((int)$input['share_id']);
            if ($share->getFormId()!==(int)$input['id']) {throw new \RuntimeException('Share is unavailable');}
            // Public links never touch Files. Native collaborator permission updates can create or remove a Files share.
            return in_array($share->getShareType(),[0,1,2,7],true)
                && ($action==='forms_update_share' ? isset($input['permissions']) : in_array('results',$share->getPermissions(),true));
        }
        return isset($input['id']) && (in_array($action,['forms_delete_form','forms_delete_response','forms_delete_own_response','forms_delete_responses','forms_delete_question','forms_update_response','forms_update_own_response','forms_submit_response','forms_update_share','forms_unshare','forms_transfer'],true)
            || ($action==='forms_update' && isset($input['fields']['title'])));
    }
    public static function snapshot(string $uid,string $action,array $input): array {
        StorageAdmission::operation($uid,$action,$input);
        if ($action==='forms_restore') {
            $data=self::readBackup($uid,$input['backup_id']);
            return ['backup_id'=>$input['backup_id'],'digest'=>hash_hmac('sha256',json_encode($data,JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret')),'restore'=>'new_private_closed_form'];
        }
        if (!self::relevant($action,$input)) {return [];}
        $data=self::capture((int)$input['id'],$action,$input);self::assertImpact($action,$input,$data);
        return self::project($action,$input,$data);
    }
    private static function project(string $action,array $input,array $data): array {
        $removals=$action==='forms_delete_form'?ManagedShares::removalSnapshots((int)$input['id'],$data['files']):[];
        $shareImpact=in_array($action,['forms_update_share','forms_unshare'],true)?ManagedShares::snapshot((int)$input['id'],$data['files'],$input):[];
        $shareEffect=($shareImpact['create']??false) || array_filter($shareImpact['managed_shares']??[],static fn(array $row):bool=>!($row['missing']??false))!==[];
        return ['digest'=>hash_hmac('sha256',json_encode($data,JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret')),'form_id'=>(int)$input['id'],'files'=>$data['files'],
            'delete_file_ids'=>self::deletions($action,$input,$data),'files_share'=>$shareEffect||$removals!==[],'share_removals'=>$removals,
            'share_impact'=>$shareImpact,
            'folders'=>'retained','recovery'=>!AuthoringScale::metadata($action) && (str_starts_with($action,'forms_delete_') || str_starts_with($action,'forms_update_'))];
    }
    private static function capture(int $id,string $action,array $input): array {
        $start=microtime(true);$db=Server::get(IDBConnection::class);
        $form=$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_forms WHERE id = ?',[$id])->fetch();
        if (!$form) {throw new \RuntimeException('Form is unavailable');}
        $metadata=AuthoringScale::metadata($action);
        $questions=$metadata?[]:$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_questions WHERE form_id = ? ORDER BY id LIMIT 1001',[$id])->fetchAll();
        $single=in_array($action,['forms_delete_response','forms_delete_own_response','forms_update_response','forms_update_own_response'],true);
        $newSubmission=$action==='forms_submit_response';
        $filter=$single?' AND s.id = ?':'';$params=$single?[$id,(int)$input['submission_id']]:[$id];
        $submissions=($newSubmission||$metadata)?[]:$db->executeQuery('SELECT s.* FROM *PREFIX*forms_v2_submissions s WHERE s.form_id = ?'.$filter.' ORDER BY s.id LIMIT 101',$params)->fetchAll();
        if (count($submissions)>100) {throw new \RuntimeException('Recovery requires a bounded continuation for more than 100 responses');}
        $answers=($newSubmission||$metadata)?[]:$db->executeQuery('SELECT a.* FROM *PREFIX*forms_v2_answers a INNER JOIN *PREFIX*forms_v2_submissions s ON a.submission_id = s.id WHERE s.form_id = ?'.$filter.' ORDER BY a.id LIMIT 1001',$params)->fetchAll();
        $options=$metadata?[]:$db->executeQuery('SELECT o.* FROM *PREFIX*forms_v2_options o INNER JOIN *PREFIX*forms_v2_questions q ON o.question_id = q.id WHERE q.form_id = ? ORDER BY o.id LIMIT 1001',[$id])->fetchAll();
        $uploads=$metadata?[]:$db->executeQuery('SELECT * FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ? ORDER BY id LIMIT 1001',[$id])->fetchAll();
        foreach ([$questions,$answers,$options,$uploads] as $rows) {if(count($rows)>1000){throw new \RuntimeException('Recovery rows require bounded continuation');}}
        if ($newSubmission || $single) {
            $used=[];$seen=[];$actor=Server::get(\OCP\IUserSession::class)->getUser()?->getUID();
            foreach ($input['answers']??[] as $questionKey=>$values) {
                if (isset($values['question_id'])) {$qid=(int)$values['question_id'];$handles=$values['file_handles']??[];}
                else {$qid=(int)$questionKey;$handles=[];foreach ($values as $value) {if(is_array($value)&&isset($value['mcpHandle'])){$handles[]=$value['mcpHandle'];}}}
                foreach ($handles as $handle) {
                    if (!is_string($handle) || !preg_match('/^[a-f0-9]{64}$/D',$handle) || isset($seen[$handle])) {throw new \RuntimeException('File handle is unavailable');}
                    $seen[$handle]=true;
                    $record=$db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_uploads WHERE handle_hash = ?',[hash('sha256',$handle)])->fetch();
                    if (!$record || (int)$record['form_id']!==$id || (int)$record['question_id']!==$qid || (int)$record['consumed']!==0 || (int)$record['expires']<=time() || !hash_equals($record['actor_hash'],OriginContext::identity((string)$actor))) {throw new \RuntimeException('File handle is unavailable');}
                    $used[(int)$record['upload_id']]=true;
                }
            }
            $uploads=array_values(array_filter($uploads,static fn($upload)=>isset($used[(int)$upload['id']])));
        }
        foreach ($uploads as &$upload) {unset($upload['upload_token']);}unset($upload);
        $artifacts=($newSubmission || $single || $metadata)?[]:$db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_artifacts WHERE form_id = ? ORDER BY file_id LIMIT 101',[$id])->fetchAll();
        $data=compact('form','questions','submissions','answers','options','uploads','artifacts')+['scope'=>$metadata?'authoring_effects_v1':($single?'single_response':($newSubmission?'staged_submission':'whole_form'))];
        if (strlen(json_encode($data,JSON_THROW_ON_ERROR))>1048576) {throw new \RuntimeException('Recovery metadata exceeds the projection limit');}
        $ids=[];foreach (array_merge($answers,$uploads,$artifacts) as $row) {if ($row['file_id']??null) {$ids[(int)$row['file_id']]=true;}}if($metadata){foreach($db->executeQuery('SELECT file_id FROM (SELECT a.file_id FROM *PREFIX*forms_v2_answers a JOIN *PREFIX*forms_v2_submissions s ON s.id=a.submission_id WHERE s.form_id = ? AND a.file_id IS NOT NULL UNION SELECT file_id FROM *PREFIX*forms_v2_uploaded_files WHERE form_id = ? UNION SELECT file_id FROM *PREFIX*forms_mcp_artifacts WHERE form_id = ?) authoring_files ORDER BY file_id LIMIT 101',[$id,$id,$id])->fetchAll() as $row){$ids[(int)$row['file_id']]=true;}}ksort($ids);
        if (count($ids)>100) {throw new \RuntimeException('Recovery file count exceeds the bounded step');}
        $root=Server::get(IRootFolder::class)->getUserFolder($form['owner_id']);$files=[];$total=0;
        foreach (array_keys($ids) as $fileId) {
            if($metadata&&HistoricalFiles::unavailable($id,$fileId)!==null){continue;}
            $nodes=$root->getById($fileId);$file=$nodes[0]??null;
            if (!$file instanceof File) {throw new \RuntimeException('Managed Forms file is unavailable');}
            $meta=self::fileMeta($file);$total+=$meta['size'];
            if ($total>AnswerFiles::MAX_BYTES || microtime(true)-$start>20) {throw new \RuntimeException('Recovery exceeds the bounded transfer step');}
            $files[]=$meta;
        }
        return $data+['files'=>$files];
    }
    private static function fileMeta(File $file,bool $underFolderLock=false): array {
        StorageAdmission::node($file);
        $size=(int)$file->getSize();$etag=$file->getEtag();
        if ($size<0 || $size>AnswerFiles::MAX_BYTES) {throw new \RuntimeException('Recovery file exceeds the byte limit');}
        $stream=$underFolderLock?$file->getStorage()->fopen($file->getInternalPath(),'rb'):$file->fopen('rb');if (!is_resource($stream)) {throw new \RuntimeException('Managed file cannot be read');}
        try {$ctx=hash_init('sha256');$read=hash_update_stream($ctx,$stream,AnswerFiles::MAX_BYTES+1);$sha=hash_final($ctx);} finally {if (is_resource($stream)) {fclose($stream);}}
        if ($read!==$size || $etag!==$file->getEtag()) {throw new \RuntimeException('Managed file changed during capture');}
        return ['file_id'=>(int)$file->getId(),'path'=>$file->getPath(),'name'=>$file->getName(),'etag'=>$etag,'size'=>$size,'sha256'=>$sha];
    }
    public static function verifyShareFiles(array $files,Folder $root): void {
        foreach ($files as $meta) {
            $file=$root->getById((int)$meta['file_id'])[0]??null;
            if (!$file instanceof File || self::fileMeta($file,true)!==$meta) {throw new \RuntimeException('Approved Forms share file changed');}
        }
    }
    private static function assertImpact(string $action,array $input,array $data): void {
        $id=(int)$input['id'];$form=Server::get(FormMapper::class)->findById($id);
        if ($action==='forms_delete_form') {foreach (Server::get(\OCA\Forms\Db\ShareMapper::class)->findByForm($id) as $share) {if (in_array('results',$share->getPermissions(),true)) {ManagedShares::snapshot($id,$data['files'],['share_id'=>$share->getId()]);}}}
        if ($form->getFileId() && Server::get(LinkedMarkers::class)->tuple($form)===null && in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response','forms_delete_response','forms_delete_own_response','forms_delete_responses'],true)) {
            throw new \RuntimeException('Linked export authority is not qualified');
        }
        if ($action==='forms_transfer') {
            $target=Server::get(\OCP\IUserManager::class)->get($input['owner_id']);
            if ($target===null || !$target->isEnabled()) {throw new \RuntimeException('New form owner is unavailable');}
            $destination=Server::get(IRootFolder::class)->getUserFolder($input['owner_id']);
            $path=Server::get(FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
            if ($data['files']!==[] && $destination->nodeExists($path)) {throw new \RuntimeException('Transfer destination already exists');}
        }
        if (in_array($action,['forms_submit_response','forms_update_response','forms_update_own_response'],true)) {return;}
        $roots=Server::get(FilePathHelper::class)->getFormsFolder($form->getOwnerId());
        if ($roots===null) {return;}
        $managed=array_fill_keys(array_column($data['files'],'file_id'),true);
        foreach ($roots->getDirectoryListing() as $node) {
            if (!str_starts_with($node->getName(),$id.' - ')) {continue;}
            // These are candidate effect paths, NOT proof of managed ownership. Every file must have a native record.
            if (in_array($action,['forms_delete_response','forms_delete_own_response'],true)) {
                if ($node instanceof Folder && $node->nodeExists((string)$input['submission_id'])) {self::assertTree($node->get((string)$input['submission_id']),$managed);}
            } else {self::assertTree($node,$managed);}
        }
    }
    private static function assertTree(\OCP\Files\Node $node,array $managed): void {
        if ($node instanceof File) {
            if (!isset($managed[(int)$node->getId()])) {throw new \RuntimeException('Unmanaged file in native Forms effect path');}
            return;
        }
        if (!$node instanceof Folder) {throw new \RuntimeException('Unsupported Forms storage node');}
        foreach ($node->getDirectoryListing() as $child) {self::assertTree($child,$managed);}
    }
    private static function deletions(string $action,array $input,array $data): array {
        if ($action==='forms_delete_form') {return array_column($data['files'],'file_id');}
        $ids=[];
        foreach ($data['answers'] as $answer) {
            if (!$answer['file_id']) {continue;}
            $remove=match($action) {
                'forms_delete_responses'=>true,
                'forms_delete_response','forms_delete_own_response'=>(int)$answer['submission_id']===(int)$input['submission_id'],
                'forms_delete_question'=>(int)$answer['question_id']===(int)$input['question_id'],
                default=>false,
            };
            if ($remove) {$ids[]=(int)$answer['file_id'];}
        }
        return array_values(array_unique($ids));
    }
    public static function run(string $uid,string $action,array $input,?array $approved,string $operationKey,callable $work): array {
        $impact = $approved ?? [];
        $impact['source_files'] = $input['_mcp_sources'] ?? [];
        return OriginContext::withFileImpact($action, $impact,
            function () use ($uid,$action,$input,$approved,$operationKey,$work): array {
                try {return self::execute($uid,$action,$input,$approved,$operationKey,$work);}
                finally {Server::get(ManagedSharePhase::class)->completeImpact($operationKey,OriginContext::current()['verified_deleted_ids']??[]);}
            });
    }
    private static function execute(string $uid,string $action,array $input,?array $approved,string $operationKey,callable $work): array {
        try {StorageAdmission::operation($uid,$action,$input);} catch (\Throwable $e) {throw new \OCA\Forms\Mcp\BeforeEffect('Forms storage admission failed',0,$e);}
        if ($action==='forms_restore') {
            $current=self::snapshot($uid,$action,$input);
            if ($approved!==$current) {throw new \OCA\Forms\Mcp\BeforeEffect('Recovery preview changed');}
            return self::restore($uid,$input['backup_id']);
        }
        if (!self::relevant($action,$input)) {return $work();}
        try {$data=self::capture((int)$input['id'],$action,$input);self::assertImpact($action,$input,$data);$current=self::project($action,$input,$data);}
        catch (\Throwable $e) {throw new \OCA\Forms\Mcp\BeforeEffect('Forms file admission failed',0,$e);}
        // Legacy file-free native callers remain compatible. Any existing bytes require the approved manifest.
        if (($approved!==null && $approved!==$current) || ($approved===null && $data['files']!==[])) {throw new \OCA\Forms\Mcp\BeforeEffect('Forms file preview changed');}
        $root=Server::get(IRootFolder::class)->getUserFolder($data['form']['owner_id']);$locked=[];$wrappers=[];$backup=null;$locksReleased=false;
        try {
            foreach ($data['files'] as $meta) {
                $file=$root->getById($meta['file_id'])[0]??null;
                if (!$file instanceof File) {throw new \OCA\Forms\Mcp\BeforeEffect('Managed file is unavailable');}
                $file->lock(ILockingProvider::LOCK_SHARED);$locked[]=$file;
                if (self::fileMeta($file)!==$meta) {throw new \OCA\Forms\Mcp\BeforeEffect('Managed file changed after approval');}
            }
            // Back up before database deletion as well: metadata contains form, questions, options and original answers.
            if (!AuthoringScale::metadata($action) && (str_starts_with($action,'forms_delete_') || $data['files']!==[])) {$backup=self::backup($uid,$operationKey,$data);}
            $mounts=[];
            foreach ($locked as $file) {$mount=$file->getMountPoint();$key=$mount->getMountPoint();$mounts[$key]['mount']=$mount;$mounts[$key]['approved'][$file->getInternalPath()]=$current['files'][array_search((int)$file->getId(),array_column($current['files'],'file_id'),true)];}
            foreach ($mounts as $mount) {$mount['mount']->wrapStorage(function($path,$storage)use($mount,&$wrappers){$wrapper=new ManagedFileStorage($storage,$mount['approved']);$wrappers[]=$wrapper;return $wrapper;});}
            // Core View takes the mutation locks. The storage wrapper revalidates the backup digest under that lock.
            $locksReleased=true;
            foreach (array_reverse($locked) as $file) {$file->unlock(ILockingProvider::LOCK_SHARED);}
            self::$active=true;
            OriginContext::assertFresh();
            OriginContext::assertFiles($action,$current);
            $outcome=$work();
            if ($action==='forms_transfer' && $data['files']!==[]) {self::transferFiles($input,$data);}
            foreach ($locked as $file) {
                $fileId=(int)$file->getId();
                if (in_array($fileId,$current['delete_file_ids'],true)) {
                    $storage=$file->getStorage();$path=$file->getInternalPath();$file->delete();
                    if ($storage->file_exists($path)) {throw new \RuntimeException('Managed file removal was not verified');}
                    OriginContext::verifiedDeletion($fileId);
                    Server::get(IDBConnection::class)->executeStatement('DELETE FROM *PREFIX*forms_mcp_artifacts WHERE file_id = ?',[$fileId]);
                }
            }
            if ($current['share_impact']!==[]) {ManagedShares::verify((int)$input['id'],$current['share_impact']);}
            if ($action==='forms_delete_question') {
                Server::get(IDBConnection::class)->executeStatement('UPDATE *PREFIX*forms_v2_answers SET file_id = NULL WHERE question_id = ?',[$input['question_id']]);
                $db=Server::get(IDBConnection::class);
                if (!self::skipDeferred(['formId'=>$input['id'],'questionId'=>$input['question_id']])) {
                    $db->executeStatement('INSERT INTO *PREFIX*forms_mcp_file_guards (form_id,question_id) VALUES (?, ?)',[$input['id'],$input['question_id']]);
                }
            }
            if ($backup!==null) {$outcome['references']['backup_id']=$backup;}
            $outcome['files_phase']=$action==='forms_transfer' && $data['files']!==[]?'ownership_file_copies_verified':($current['share_impact']!==[]?(Server::get(ManagedSharePhase::class)->hasPending((int)$input['id'],(int)$current['share_impact']['native_share_id'])?'native_files_shares_pending':'native_files_shares_verified'):($current['delete_file_ids']!==[]?'managed_files_removed_recovery_verified':($data['files']!==[]?'managed_files_preserved':'not_applicable')));
            return $outcome;
        } finally {
            self::$active=false;
            foreach ($wrappers as $wrapper) {$wrapper->enabled=false;}
            if (!$locksReleased) {foreach (array_reverse($locked) as $file) {try {$file->unlock(ILockingProvider::LOCK_SHARED);} catch (\Throwable) {}}}
        }
    }
    private static function backup(string $uid,string $key,array $data): string {
        OriginContext::assertFresh();
        StorageAdmission::recovery();
        $id=hash('sha256','forms-recovery:'.$key);$app=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_recovery');
        try {$app->getFolder($id);throw new \RuntimeException('Recovery target already exists');} catch (NotFoundException) {}
        $folder=$app->newFolder($id);$root=Server::get(IRootFolder::class)->getUserFolder($data['form']['owner_id']);
        foreach ($data['files'] as $meta) {
            OriginContext::assertFresh();
            $file=$root->getById($meta['file_id'])[0];$stream=$file->fopen('rb');
            if (!is_resource($stream)) {throw new \RuntimeException('Recovery source cannot be read');}
            try {$copy=$folder->newFile('file-'.$meta['file_id'],$stream);} finally {if (is_resource($stream)) {fclose($stream);}}
            if ((int)$copy->getSize()!==$meta['size'] || !hash_equals($meta['sha256'],hash('sha256',$copy->getContent()))) {throw new \RuntimeException('Recovery copy verification failed');}
        }
        $data['actor_hash']=OriginContext::identity($uid);$data['created']=time();$data['backup_id']=$id;
        $json=json_encode($data,JSON_THROW_ON_ERROR);$signed=json_encode(['data'=>$data,'hmac'=>hash_hmac('sha256',$json,Server::get(\OCP\IConfig::class)->getSystemValueString('secret'))],JSON_THROW_ON_ERROR);
        $manifest=$folder->newFile('manifest.json',$signed);
        if (!hash_equals(hash('sha256',$signed),hash('sha256',$manifest->getContent()))) {throw new \RuntimeException('Recovery manifest verification failed');}
        // No code path updates, expires or removes a retained recovery copy.
        return $id;
    }
    private static function readBackup(string $uid,string $id): array {
        StorageAdmission::recovery();
        if (!preg_match('/^[a-f0-9]{64}$/D',$id)) {throw new \RuntimeException('Recovery is unavailable');}
        $folder=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_recovery')->getFolder($id);
        $signed=json_decode($folder->getFile('manifest.json')->getContent(),true,64,JSON_THROW_ON_ERROR);$data=$signed['data'];
        if (!hash_equals($signed['hmac'],hash_hmac('sha256',json_encode($data,JSON_THROW_ON_ERROR),Server::get(\OCP\IConfig::class)->getSystemValueString('secret')))
            ) {throw new \RuntimeException('Recovery is unavailable');}
        $originalActor=hash_equals($data['actor_hash'],OriginContext::identity($uid));
        if (!$originalActor) {throw new \RuntimeException('Recovery is unavailable');}
        $authorized=false;
        try {
            $form=Server::get(FormMapper::class)->findById((int)$data['form']['id']);
            $permissions=(new ScopedServices($uid))->forms()->getPermissions($form);
            $authorized=$form->getOwnerId()===$uid || ($originalActor && in_array('results',$permissions,true) && in_array('results_delete',$permissions,true));
        } catch (\OCP\AppFramework\Db\DoesNotExistException) {$authorized=$originalActor && $data['form']['owner_id']===$uid;}
        if (!$authorized) {throw new \RuntimeException('Recovery is unavailable');}
        if (!(new ScopedServices($uid))->get(\OCA\Forms\Service\ConfigService::class)->canCreateForms()) {throw new \RuntimeException('Form creation is not allowed');}
        foreach ($data['files'] as $meta) {
            $file=$folder->getFile('file-'.$meta['file_id']);
            if ((int)$file->getSize()!==$meta['size'] || !hash_equals($meta['sha256'],hash('sha256',$file->getContent()))) {throw new \RuntimeException('Recovery data failed verification');}
        }
        return $data;
    }
    private static function transferFiles(array $input,array $data): void {
        $form=Server::get(FormMapper::class)->findById((int)$input['id']);$destination=Server::get(IRootFolder::class)->getUserFolder($input['owner_id']);
        $path=Server::get(FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        if ($destination->nodeExists($path)) {throw new \RuntimeException('Transfer destination already exists');}
        $folder=NewFileStorage::folder($destination,$path.'/transferred-'.bin2hex(random_bytes(16)));$source=Server::get(IRootFolder::class)->getUserFolder($data['form']['owner_id']);
        $db=Server::get(IDBConnection::class);
        foreach ($data['files'] as $meta) {
            $file=$source->getById($meta['file_id'])[0];$file->lock(ILockingProvider::LOCK_SHARED);
            try {
                if (self::fileMeta($file)!==$meta) {throw new \RuntimeException('Transfer source changed');}
                $stream=$file->fopen('rb');if (!is_resource($stream)) {throw new \RuntimeException('Transfer source cannot be read');}
                try {$copy=NewFileStorage::create($folder,$meta['file_id'].'-'.$meta['name'],$stream);}finally{if(is_resource($stream)){fclose($stream);}}
                if ((int)$copy->getSize()!==$meta['size'] || !hash_equals($meta['sha256'],hash('sha256',$copy->getContent()))) {throw new \RuntimeException('Transferred file verification failed');}
                $db->executeStatement('UPDATE *PREFIX*forms_v2_answers SET file_id = ? WHERE file_id = ?',[$copy->getId(),$meta['file_id']]);
                $db->executeStatement('UPDATE *PREFIX*forms_mcp_artifacts SET file_id = ? WHERE form_id = ? AND file_id = ?',[$copy->getId(),$input['id'],$meta['file_id']]);
                $db->executeStatement('UPDATE *PREFIX*forms_v2_uploaded_files SET file_id = ? WHERE form_id = ? AND file_id = ?',[$copy->getId(),$input['id'],$meta['file_id']]);
            }finally{$file->unlock(ILockingProvider::LOCK_SHARED);}
        }
        // Existing source Files remain untouched. Only native answer/upload references move to verified copies.
    }
    public static function lookup(string $uid,string $operationKey): ?array {
        $id=hash('sha256','forms-recovery:'.$operationKey);
        try {$data=self::readBackup($uid,$id);} catch (NotFoundException) {return null;}
        return ['state'=>'partial','operation_key'=>$operationKey,'references'=>['form_id'=>(int)$data['form']['id'],'backup_id'=>$id],
            'phases'=>['database'=>'unknown','files'=>'recovery_available','events'=>[]]];
    }
    private static function entity(string $kind,array $row): \OCP\AppFramework\Db\Entity {
        $class='OCA\\Forms\\Db\\'.$kind;$entity=new $class();
        foreach ($row as $name=>$value) {if ($name==='id') {continue;}$field=str_replace(' ','',ucwords(str_replace('_',' ',$name)));$entity->{'set'.$field}($value);}
        return $entity;
    }
    private static function insert(string $kind,array $row): \OCP\AppFramework\Db\Entity {
        $mapper='OCA\\Forms\\Db\\'.$kind.'Mapper';return Server::get($mapper)->insert(self::entity($kind,$row));
    }
    public static function retainFolders(callable $work): mixed {
        $old=self::$active;self::$active=true;try{return $work();}finally{self::$active=$old;}
    }
    private static function restore(string $uid,string $id): array {
        $data=self::readBackup($uid,$id);$row=$data['form'];$row['hash']=bin2hex(random_bytes(16));$row['owner_id']=$uid;$row['title'].=' (recovered)';
        $row['access_enum']=0;$row['state']=1;$row['locked_by']=null;$row['locked_until']=null;$row['file_id']=null;$row['file_format']=null;
        $row['confirmation_email_enabled']=false;$row['confirmation_email_question_id']=null;
        OriginContext::assertFresh();
        $form=self::insert('Form',$row);$questions=[];$types=[];$gridTypes=[];$options=[];$submissions=[];$files=[];
        foreach ($data['questions'] as $q) {$old=(int)$q['id'];$q['form_id']=$form->getId();$questions[$old]=self::insert('Question',$q)->getId();$types[$old]=$q['type'];$gridTypes[$old]=json_decode($q['extra_settings_json']??'{}',true,64,JSON_THROW_ON_ERROR)['questionType']??null;}
        foreach ($data['options'] as $o) {$old=(int)$o['id'];$o['question_id']=$questions[(int)$o['question_id']];$options[$old]=self::insert('Option',$o)->getId();}
        foreach ($data['submissions'] as $s) {$old=(int)$s['id'];$s['form_id']=$form->getId();$submissions[$old]=self::insert('Submission',$s)->getId();}
        $root=Server::get(IRootFolder::class)->getUserFolder($uid);$path=Server::get(FilePathHelper::class)->getFormUploadedFilesFolderPath($form);
        if ($root->nodeExists($path)) {throw new \RuntimeException('Recovery destination already exists');}
        $folder=NewFileStorage::folder($root,$path.'/recovered-'.bin2hex(random_bytes(16)));
        $backup=Server::get(\OCP\Files\AppData\IAppDataFactory::class)->get('forms_mcp_recovery')->getFolder($id);
        foreach ($data['files'] as $meta) {
            $stream=$backup->getFile('file-'.$meta['file_id'])->read();if (!is_resource($stream)) {throw new \RuntimeException('Recovery source cannot be read');}
            // Each original ID gets a unique destination, without searching or replacing another file.
            try {$file=NewFileStorage::create($folder,$meta['file_id'].'-'.$meta['name'],$stream);} finally {if (is_resource($stream)) {fclose($stream);}}
            if ((int)$file->getSize()!==$meta['size'] || !hash_equals($meta['sha256'],hash('sha256',$file->getContent()))) {throw new \RuntimeException('Restored file verification failed');}
            $files[$meta['file_id']]=$file->getId();
            Server::get(IDBConnection::class)->executeStatement('INSERT INTO *PREFIX*forms_mcp_artifacts (form_id,file_id) VALUES (?, ?)',[$form->getId(),$file->getId()]);
        }
        foreach ($data['answers'] as $a) {
            $oldQ=(int)$a['question_id'];$a['question_id']=$questions[$oldQ];$a['submission_id']=$submissions[(int)$a['submission_id']];
            if ($a['file_id']) {$a['file_id']=$files[(int)$a['file_id']];}
            if (in_array($types[$oldQ],['grid','ranking'],true)) {
                $decoded=json_decode($a['text'],true,64,JSON_THROW_ON_ERROR);
                if ($types[$oldQ]==='ranking') {$decoded=array_map(static fn($v)=>$options[(int)$v]??$v,$decoded);}
                else {
                    $mapped=[];
                    foreach ($decoded as $key=>$value) {
                        if ($gridTypes[$oldQ]===\OCA\Forms\Constants::ANSWER_GRID_TYPE_NUMBER) {
                            $cells=[];foreach($value as $column=>$number){$cells[$options[(int)$column]??$column]=$number;}
                            $mapped[$options[(int)$key]??$key]=$cells;
                        } else {$mapped[$options[(int)$key]??$key]=is_array($value)?array_map(static fn($v)=>$options[(int)$v]??$v,$value):($options[(int)$value]??$value);}
                    }
                    $decoded=$mapped;
                }
                $a['text']=json_encode($decoded,JSON_THROW_ON_ERROR);
            }
            self::insert('Answer',$a);
        }
        return ['references'=>['form_id'=>$form->getId(),'backup_id'=>$id],'data'=>['form_id'=>$form->getId(),'recovered_file_ids'=>array_values($files),'private'=>true,'state'=>'closed'],'files_phase'=>'restored_verified'];
    }
}
