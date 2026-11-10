<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** Exact pinned method body range, independent of braces inside PHP strings. */
function nativeDraftMethodRange(string $source,string $name):array {
    $tokens=token_get_all($source);$offset=0;$matched=false;$function=false;$start=null;$depth=0;
    foreach($tokens as $token){$text=is_array($token)?$token[1]:$token;$kind=is_array($token)?$token[0]:null;
        if($kind===T_FUNCTION&&$start===null){$function=true;$matched=false;}
        elseif($function&&$kind===T_STRING){$matched=$text===$name;$function=false;}
        if($matched){
            if($text==='{'||$kind===T_CURLY_OPEN||$kind===T_DOLLAR_OPEN_CURLY_BRACES){if($start===null)$start=$offset+strlen($text);$depth++;}
            elseif($text==='}'&&$start!==null){$depth--;if($depth===0)return [$start,$offset];}
        }
        $offset+=strlen($text);
    }
    throw new RuntimeException('Pinned native lifecycle method unavailable: '.$name);
}
function nativeDraftReplaceBody(string $source,string $name,Closure $transform):string {
    [$start,$end]=nativeDraftMethodRange($source,$name);return substr($source,0,$start).$transform(substr($source,$start,$end-$start)).substr($source,$end);
}
/** Shared native mutex covers filesystem phases that may commit their own cache transactions. */
function nativeDraftLifecycleOverlay(Closure $read,array $files):array {
    $take=static fn(string $path):string=>$files[$path]??$read($path);
    $replace=static function(string $source,string $needle,string $next):string {if(substr_count($source,$needle)!==1)throw new RuntimeException('Unexpected pinned draft lifecycle source.');return str_replace($needle,$next,$source);};
    $path='lib/Db/LocalMessage.php';$local=$take($path);
    $local=$replace($local,'protected $mcpDraftOnly = false;',"protected \$mcpDraftOnly = false;\n    private ?string \$mcpOriginalRevision = null;");
    $needle="\t#[\\Override]\n\t#[ReturnTypeWillChange]\n\tpublic function jsonSerialize()";
    $methods= <<<'PHP'
    public function mcpOriginalSnapshot():?string {return $this->mcpOriginalRevision;}
    public function mcpCaptureSnapshot():void {
        if($this->getRecipients()!==null)$this->mcpOriginalRevision=\OCA\Mail\Drafts\DraftSnapshot::of($this);
    }

PHP;
    $files[$path]=$replace($local,$needle,$methods.$needle);
    $path='lib/Db/LocalMessageMapper.php';$mapper=$take($path);
    if(substr_count($mapper,'return $localMessage;')!==3)throw new RuntimeException('Unexpected native message hydration sites.');
    $mapper=str_replace('return $localMessage;','$localMessage->mcpCaptureSnapshot();return $localMessage;',$mapper);
    $mapper=$replace($mapper,'return $entity;','$entity->mcpCaptureSnapshot();return $entity;');
    foreach(['saveWithRecipients','updateWithRecipients','deleteWithRecipients'] as $method){
        $void=$method==='deleteWithRecipients';$vars=$void?'$message':'$message,$to,$cc,$bcc';
        $mapper=nativeDraftReplaceBody($mapper,$method,static function(string $body)use($void,$vars,$method):string {
            $admit=$method==='saveWithRecipients'?'':($void?'if(!$scope->permits((int)$message->getId(),[\'delete\',\'convert\',\'send\']))$guard->admit($message,$this,$scope->uid,\'delete\');':'$guard->admit($message,$this,$scope->uid,\'write\');');
            $capture=$void?'':"\$result->mcpCaptureSnapshot();return \$result;";
            return "\n        \$guard=\$this->draftCoordinator();\n        ".($void?'':'return ')."\$guard->runMessage(\$message,function(\$scope)use($vars,\$guard){\n$admit\n            ".($void?'':"\$result=(function()use($vars){").$body.($void?'':"})();$capture")."\n        });\n    ";
        });
    }
    $files[$path]=$mapper;
    $path='lib/Db/LocalAttachmentMapper.php';$mapper=$take($path);
    $mapper=$replace($mapper,'class LocalAttachmentMapper extends QBMapper {',"class LocalAttachmentMapper extends QBMapper {\n    use GuardedDraftAttachments;");
    foreach(['saveLocalMessageAttachments','deleteForLocalMessage'] as $method){
        $vars=$method==='saveLocalMessageAttachments'?'$userId,$localMessageId,$attachmentIds':'$userId,$localMessageId';
        $check=$method==='saveLocalMessageAttachments'?'$this->mcpValidateAttachmentSet($userId,$localMessageId,$attachmentIds);':'$this->mcpAdmitAttachmentRemoval($userId,$localMessageId);';
        $mapper=nativeDraftReplaceBody($mapper,$method,static fn(string $body):string=>"\n        \$this->draftCoordinator()->runOwner(\$userId,function(\$scope)use($vars){\n$check\$scope->markWrite();$body\n        });\n    ");
    }
    $files[$path]=$mapper;
    $path='lib/Service/DraftsService.php';$service=$take($path);
    $service=$replace($service,'public function sendMessage(LocalMessage $message, Account $account): void {','public function sendMessage(LocalMessage $message, Account $account, bool $automatic = false): void {');
    $service=$replace($service,"\t\t\t\t\t\$account,\n\t\t\t\t);","\t\t\t\t\t\$account,\n                    true,\n\t\t\t\t);");
    foreach(['saveMessage','updateMessage','deleteMessage','sendMessage'] as $method){
        $void=in_array($method,['deleteMessage','sendMessage'],true);$uid=$method==='deleteMessage'?'$userId':'$account->getUserId()';
        $vars=match($method){'saveMessage','updateMessage'=>'$account,$message,$to,$cc,$bcc,$attachments','deleteMessage'=>'$userId,$message','sendMessage'=>'$account,$message,$automatic'};
        $purpose=match($method){'sendMessage'=>'convert','deleteMessage'=>'delete',default=>'write'};
        $service=nativeDraftReplaceBody($service,$method,static function(string $body)use($void,$uid,$vars,$purpose,$method):string {
            $admit="\$guard->admit(\$message,\$this->mapper,$uid,'$purpose'".($method==='sendMessage'?', $automatic':'').");";
            if($method==='sendMessage')$body=str_replace('$this->transmission->saveLocalDraft($account, $message);',"\$scope->markWrite();\$this->transmission->saveLocalDraft(\$account, \$message, \$scope);\$scope->assertOwned();",$body);
            $capture=$void?'':'$result->mcpCaptureSnapshot();return $result;';
            return "\n        \$guard=\$this->mapper->draftCoordinator();\n        ".($void?'':'return ')."\$guard->runOwner($uid,function(\$scope)use($vars,\$guard){\n$admit\n            ".($void?'':"\$result=(function()use($vars){").$body.($void?'':"})();$capture")."\n        });\n    ";
        });
    }
    $service=$replace($service,'public function getMessage(int $id, string $userId): LocalMessage {',"public function draftCoordinator(): \\OCA\\Mail\\Drafts\\DraftCoordinator { return \$this->mapper->draftCoordinator(); }\n\n    public function getMessage(int \$id, string \$userId): LocalMessage {");
    $files[$path]=$service;
    $path='lib/Service/OutboxService.php';$service=$take($path);
    foreach(['saveMessage','updateMessage','deleteMessage','convertDraft'] as $method){
        $void=$method==='deleteMessage';$entity=$method==='convertDraft'?'$draftMessage':'$message';
        $vars=match($method){'saveMessage','updateMessage'=>'$account,$message,$to,$cc,$bcc,$attachments','deleteMessage'=>'$userId,$message','convertDraft'=>'$draftMessage,$sendAt'};
        $service=nativeDraftReplaceBody($service,$method,static function(string $body)use($void,$vars,$entity,$method):string {
            $purpose=$void?'delete':'write';$admit="\$guard->admit($entity,\$this->mapper,\$scope->uid,'$purpose');";
            return "\n        \$guard=\$this->mapper->draftCoordinator();\n        ".($void?'':'return ')."\$guard->runMessage($entity,function(\$scope)use($vars,\$guard){\n$admit\n            ".($void?'':"\$result=(function()use($vars){").$body.($void?'':'})();$result->mcpCaptureSnapshot();return $result;')."\n        });\n    ";
        });
    }
    $files[$path]=$service;
    $path='lib/Service/Attachment/AttachmentService.php';$attachments=$take($path);
    foreach(['addFile','addFileFromString','deleteAttachment','deleteLocalMessageAttachments','deleteLocalMessageAttachmentsById','saveLocalMessageAttachments','updateLocalMessageAttachments'] as $method){
        $void=in_array($method,['deleteAttachment','deleteLocalMessageAttachments','deleteLocalMessageAttachmentsById'],true);
        $vars=match($method){'addFile'=>'$userId,$file','addFileFromString'=>'$userId,$name,$mime,$fileContents,$contentId,$disposition','deleteAttachment'=>'$userId,$id','deleteLocalMessageAttachments'=>'$userId,$localMessageId','deleteLocalMessageAttachmentsById'=>'$userId,$localMessageId,$attachmentIds','saveLocalMessageAttachments'=>'$userId,$messageId,$attachmentIds','updateLocalMessageAttachments'=>'$userId,$message,$newAttachmentIds'};
        $check=match($method){
            'deleteAttachment'=>'$state=$this->mapper->mcpAttachmentState($userId,$id);if($state!==null&&$state[\'local_message_id\']!==null)$this->mapper->mcpAdmitAttachmentRemoval($userId,(int)$state[\'local_message_id\'],true);$scope->markWrite();',
            'deleteLocalMessageAttachments'=>'$this->mapper->mcpAdmitAttachmentRemoval($userId,$localMessageId);$scope->markWrite();',
            'deleteLocalMessageAttachmentsById'=>'foreach($attachmentIds as $attachmentId){$state=$this->mapper->mcpAttachmentState($userId,(int)$attachmentId);if($state!==null&&(int)$state[\'local_message_id\']!==$localMessageId)throw new \\OCA\\Mail\\Exception\\DraftAdmissionFailure(\'Attachment association changed before removal.\');}$this->mapper->mcpAdmitAttachmentRemoval($userId,$localMessageId,true);$scope->markWrite();',
            'updateLocalMessageAttachments'=>'$guard->admit($message,\\OCP\\Server::get(\\OCA\\Mail\\Db\\LocalMessageMapper::class),$userId,\'write\');',
            default=>'',
        };
        $attachments=nativeDraftReplaceBody($attachments,$method,static function(string $body)use($void,$vars,$check):string {
            return "\n        \$guard=\$this->mapper->draftCoordinator();\n        ".($void?'':'return ')."\$guard->runOwner(\$userId,function(\$scope)use($vars,\$guard){\n            \$guard->assertNoTransaction();$check\n            ".($void?'':"return (function()use($vars){").$body.($void?'':"})();")."\n        });\n    ";
        });
    }
    $cleanup= <<<'PHP'
    /** Atomic orphan eligibility and deletion; never removes an associated snapshot. */
    public function deleteUnassociatedAttachment(string $userId,int $id):bool {
        $guard=$this->mapper->draftCoordinator();
        return $guard->runOwner($userId,function($scope)use($userId,$id,$guard):bool {
            $guard->assertNoTransaction();$state=$this->mapper->mcpAttachmentState($userId,$id);
            if($state!==null&&$state['local_message_id']!==null)return false;
            $this->deleteAttachment($userId,$id);return true;
        });
    }

PHP;
    $attachmentRead="\t#[\\Override]\n\tpublic function getAttachment(string \$userId, int \$id): array {";
    $attachments=$replace($attachments,$attachmentRead,$cleanup.$attachmentRead);
    $files[$path]=$attachments;
    $path='lib/Send/Chain.php';$chain=$take($path);
    $files[$path]=nativeDraftReplaceBody($chain,'process',static fn(string $body):string=>"\n        \$guard=\$this->localMessageMapper->draftCoordinator();\n        return \$guard->runOwner(\$account->getUserId(),function(\$scope)use(\$account,\$localMessage,\$guard){\$guard->admit(\$localMessage,\$this->localMessageMapper,\$account->getUserId(),'send');\$scope->markWrite();$body\n        });\n    ");
    return $files;
}
