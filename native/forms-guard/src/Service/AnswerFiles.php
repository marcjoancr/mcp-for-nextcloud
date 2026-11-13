<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCA\Forms\Db\{FormMapper,QuestionMapper,UploadedFile,UploadedFileMapper,SubmissionMapper,AnswerMapper};
use OCA\Forms\Service\FormsService;
use OCP\{Server,IDBConnection};
use OCP\Files\{File,IRootFolder};
/** Private stream admission and actor/form/question-bound, expiring, single-use handles. */
final class AnswerFiles {
    public const MAX_BYTES=26214400;
    public static function stage(string $uid,int $id,int $questionId,string $shareHash,array $sources): array {
        if (!Server::get(FormsRevisionFence::class)->active()) {throw new \LogicException('Staging requires an approved native operation');}
        $forms=Server::get(FormsService::class);$form=$forms->loadFormForSubmission($id,$shareHash);
        if (!$forms->canSubmit($form)) {throw new \RuntimeException('Form no longer accepts submissions');}
        $q=Server::get(QuestionMapper::class)->findById($questionId);
        if ($q->getFormId()!==$id || $q->getType()!=='file' || $q->getOrder()<1) {throw new \RuntimeException('File question is unavailable');}
        $settings=$q->getExtraSettings();$max=min(10,($settings['maxAllowedFilesCount']??0)>0 ? (int)$settings['maxAllowedFilesCount'] : 10);
        if (count($sources)<1 || count($sources)>$max) {throw new \RuntimeException('Native file count limit exceeded');}
        $prepared=[];$total=0;$detector=Server::get(\OCP\Files\IMimeTypeDetector::class);
        foreach ($sources as $source) {
            if (!is_resource($source['stream']??null)) {throw new \RuntimeException('File stream is unavailable');}
            $bytes=stream_get_contents($source['stream'],self::MAX_BYTES+1);$size=strlen($bytes);$total+=$size;
            if ($size!==$source['size'] || $total>self::MAX_BYTES || $size>min(self::MAX_BYTES,($settings['maxFileSize']??0)>0 ? (int)$settings['maxFileSize'] : self::MAX_BYTES)
                || !hash_equals($source['sha256'],hash('sha256',$bytes))) {throw new \RuntimeException('File content or size changed');}
            $name=$source['name'];Server::get(\OCP\Files\IFilenameValidator::class)->validateFilename($name);
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);$valid=empty($settings['allowedFileTypes'])&&empty($settings['allowedFileExtensions']);
            foreach ($settings['allowedFileTypes']??[] as $type) {if (str_starts_with($mime,$type) || str_starts_with($detector->getAllAliases()[$mime]??'',$type)) {$valid=true;}}
            $mappings=method_exists($detector,'getAllMappings')?$detector->getAllMappings():[];
            foreach ($settings['allowedFileExtensions']??[] as $ext) {if (in_array($mime,$mappings[$ext]??[],true)) {$valid=true;}}
            if (!$valid) {throw new \RuntimeException('Native file type limit exceeded');}
            $prepared[]=['name'=>$name,'bytes'=>$bytes,'sha256'=>$source['sha256'],'size'=>$size];
        }
        OriginContext::assertFresh();
        $root=Server::get(IRootFolder::class)->getUserFolder($form->getOwnerId());$path=$forms->getTemporaryUploadedFilePath($form,$q);
        // A random per-operation folder prevents collisions and never overwrites another uploader's file.
        $folder=NewFileStorage::folder($root,$path.'/mcp-'.bin2hex(random_bytes(16)));$result=[];
        foreach ($prepared as $source) {
            $file=NewFileStorage::create($folder,bin2hex(random_bytes(8)).'-'.$source['name'],$source['bytes']);
            if ((int)$file->getSize()!==$source['size'] || !hash_equals($source['sha256'],hash('sha256',$file->getContent()))) {throw new \RuntimeException('Staged file verification failed');}
            $upload=new UploadedFile();$upload->setFormId($id);$upload->setQuestionId($questionId);$upload->setUploadToken(bin2hex(random_bytes(32)));
            $upload->setOriginalFileName($file->getName());$upload->setFileId($file->getId());$upload->setCreated(time());
            Server::get(UploadedFileMapper::class)->insert($upload);$handle=bin2hex(random_bytes(32));$expires=time()+300;
            Server::get(IDBConnection::class)->executeStatement('INSERT INTO *PREFIX*forms_mcp_uploads (handle_hash, actor_hash, sha256, form_id, question_id, upload_id, expires, consumed) VALUES (?, ?, ?, ?, ?, ?, ?, 0)',
                [hash('sha256',$handle),OriginContext::identity($uid),$source['sha256'],$id,$questionId,$upload->getId(),$expires]);
            $result[]=['handle'=>$handle,'name'=>$source['name'],'size'=>$source['size'],'expires_at'=>$expires];
        }
        return ['references'=>['form_id'=>$id,'question_id'=>$questionId],'data'=>['files'=>$result],'files_phase'=>'staged_verified'];
    }
    /** Resolve privately inside the same primary transaction as native validation and consumption. */
    public static function resolve(string $uid,int $formId,array $answers): array {
        $seen=[];
        foreach ($answers as $qid=>&$values) {
            $q=Server::get(QuestionMapper::class)->findById((int)$qid);
            if ($q->getFormId()!==$formId) {throw new \RuntimeException('Answer question is unavailable');}
            if ($q->getType()!=='file') {continue;}
            foreach ($values as &$value) {
                $handle=$value['mcpHandle']??'';
                if (!is_string($handle) || !preg_match('/^[a-f0-9]{64}$/D',$handle) || isset($seen[$handle])) {throw new \RuntimeException('File handle is unavailable');}
                $seen[$handle]=true;$db=Server::get(IDBConnection::class);
                $row=$db->executeQuery('SELECT * FROM *PREFIX*forms_mcp_uploads WHERE handle_hash = ?',[hash('sha256',$handle)])->fetch();
                if (!$row || !hash_equals($row['actor_hash'],OriginContext::identity($uid)) || (int)$row['form_id']!==$formId || (int)$row['question_id']!==(int)$qid || (int)$row['expires']<=time() || (int)$row['consumed']!==0) {throw new \RuntimeException('File handle is unavailable');}
                $upload=Server::get(UploadedFileMapper::class)->getByUploadedFileId((string)$row['upload_id']);
                $form=Server::get(FormMapper::class)->findById($formId);$nodes=Server::get(IRootFolder::class)->getUserFolder($form->getOwnerId())->getById($upload->getFileId());
                $file=$nodes[0]??null;
                if (!$file instanceof File || !hash_equals($row['sha256'],hash('sha256',$file->getContent()))) {throw new \RuntimeException('Staged file changed');}
                $value=['uploadedFileId'=>$upload->getId(),'uploadToken'=>$upload->getUploadToken()];
                $db->executeStatement('UPDATE *PREFIX*forms_mcp_uploads SET consumed = 1 WHERE handle_hash = ?',[hash('sha256',$handle)]);
            }unset($value);
        }unset($values);
        return $answers;
    }
    public static function references(int $formId,int $submissionId): array {
        $submission=Server::get(SubmissionMapper::class)->findById($submissionId);
        if ($submission->getFormId()!==$formId) {throw new \RuntimeException('Response is unavailable');}
        $result=[];
        foreach (Server::get(AnswerMapper::class)->findBySubmission($submissionId) as $answer) {
            if ($answer->getFileId()) {$result[]=['form_id'=>$formId,'submission_id'=>$submissionId,'question_id'=>$answer->getQuestionId(),'file_id'=>$answer->getFileId()];}
        }
        if (count($result)>100) {throw new \RuntimeException('Response file projection exceeds limit');}
        return $result;
    }
}
