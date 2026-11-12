<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Tests\Integration\Support\{LoginFactory,McpClient,ToolPermissionFixture,ToolReply};
use OCP\{Server,IDBConnection};
use OCP\Files\IRootFolder;

/** Only the labelled disposable native UI fixture invokes these actions. */
final class MailDraftUiActor {
    private static function outgoing(string $uid):array {
        $ids=array_map(static fn(object $message):int=>(int)$message->getId(),Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->getAllForUser($uid,0));sort($ids);return $ids;
    }
    private static function submissionDigest():string {
        $lines=is_file('/tmp/mcp-mail-probe-smtp.log')?file('/tmp/mcp-mail-probe-smtp.log',FILE_IGNORE_NEW_LINES):[];
        return hash('sha256',implode("\n",array_filter($lines,static fn(string $line):bool=>in_array(trim($line),['MAIL','RCPT','DATA','BDAT','SEND','SOML','SAML'],true))));
    }
    private static function data(ToolReply $reply):array {
        if(!$reply->ok())throw new \RuntimeException('Native UI fixture tool failed: '.$reply->describe());
        return $reply->structured()!==[]?$reply->structured():$reply->json();
    }
    /** Scalar mutation boundary observed by the browser fixture without exposing message contents. */
    private static function draftFields(object $draft):array {
        return [
            'subject'=>$draft->getSubject(),'body_plain'=>$draft->getBodyPlain(),'body_html'=>$draft->getBodyHtml(),
            'account_id'=>$draft->getAccountId(),'alias_id'=>$draft->getAliasId(),'parent_id'=>$draft->getInReplyToMessageId(),
            'smime_sign'=>$draft->getSmimeSign(),'smime_encrypt'=>$draft->getSmimeEncrypt(),'pgp_mime'=>$draft->isPgpMime(),
            'send_at'=>$draft->getSendAt(),'is_html'=>$draft->isHtml(),'failed'=>$draft->isFailed(),
            'draft_only'=>$draft->getMcpDraftOnly(),
        ];
    }
    private static function changedFields(array $before,array $after):array {
        return array_values(array_filter(array_keys($before),static fn(string $name):bool=>($before[$name]??null)!==($after[$name]??null)));
    }
    public static function prepare(string $uid,string $format="plain"):array {
        if(!in_array($format,["plain","html"],true))throw new \InvalidArgumentException("Unsupported native UI fixture format.");
        if($uid!==MailActor::ALICE)throw new \RuntimeException('Unexpected native UI fixture actor.');
        $policy=Server::get(GrantPolicy::class);$policy->setGlobalEnabled(true);$policy->setEligible($uid,true);$policy->setConnected($uid,true);$policy->setGrant($uid,'files','read',true);
        ToolPermissionFixture::assign($uid,array_fill_keys(['mail_write_draft','mail_attach_files','mail_read_message','mail_read_drafts','files_read'],'allow'));
        $accounts=Server::get('OCA\\Mail\\Service\\AccountService');$account=$accounts->findByUserId($uid)[0];$entity=$account->getMailAccount();$entity->setEditorMode($format==='html'?'html':'plaintext');$accounts->update($entity);
        $q=Server::get(IDBConnection::class)->getQueryBuilder();$r=$q->select('m.id')->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b','m.mailbox_id = b.id')->where($q->expr()->eq('b.account_id',$q->createNamedParameter($account->getId())))->setMaxResults(1)->executeQuery();
        try{$source=(int)$r->fetchOne();}finally{$r->closeCursor();}
        if($source<1)throw new \RuntimeException('Native UI fixture source missing.');
        $token=bin2hex(random_bytes(8));$bytes="Native UI attachment\0".random_bytes(24);
        $file=Server::get(IRootFolder::class)->getUserFolder($uid)->newFile('mcp-draft-ui-'.$token.'.bin');$file->putContent($bytes);
        $alias=Server::get('OCA\\Mail\\Service\\AliasesService')->create($uid,$account->getId(),'ui-'.$token.'@example.test','Native UI proof');
        $args=['account_id'=>$account->getId(),'subject'=>'Native UI draft '.$token,'body_plain'=>"First approved line\nSecond approved line\n",'reply_to_message_id'=>$source,'alias_id'=>$alias->getId(),'add_files'=>[$file->getPath()]];
        if($format==='html'){unset($args['body_plain']);$args['is_html']=true;$args['body_html']='<p>First approved <strong>bold</strong> line.</p><p>Second approved line.</p>';}
        // Files tools take a path relative to the user's Files root.
        $args['add_files']=['/'.$file->getName()];
        $client=new McpClient(LoginFactory::basic($uid));$p=self::data($client->tool('mail_write_draft',$args));
        $saved=self::data($client->tool('mail_write_draft',$args+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]));
        $id=$saved['draft']['id'];$draft=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage($id,$uid);
        if($format==='html'?(!$draft->isHtml()||$draft->getBodyHtml()!==$args['body_html']):($draft->isHtml()||$draft->getBodyPlain()!==$args['body_plain']))throw new \RuntimeException('HTTP writer changed the approved fixture format or body.');
        $armed=false;
        if(!$draft->getMcpDraftOnly()){
            if(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::DRAFT_IDENTITY_QUALIFIED)throw new \RuntimeException('Enabled HTTP writer omitted draft-only provenance.');
            $draft->setMcpDraftOnly(true);$draft->setFailed(true);Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->update($draft);$armed=true;
        }
        $read=self::data($client->tool('mail_read_drafts',['draft_id'=>$id]));
        return ['outgoing_before'=>self::outgoing($uid),'smtp_submission_before'=>self::submissionDigest(),'draft_id'=>$id,'account_id'=>$account->getId(),'alias_id'=>$alias->getId(),'alias_email'=>$alias->getAlias(),'parent'=>$draft->getInReplyToMessageId(),'subject'=>$args['subject'],'body_plain'=>$draft->getBodyPlain(),'body_html'=>$draft->getBodyHtml(),'format'=>$format,'file_name'=>$file->getName(),'file_id'=>$file->getId(),'file_sha256'=>hash('sha256',$bytes),'attachment_base64'=>base64_encode($bytes),'native_edit_url'=>$read['native_edit_url']??null,'native_entry_url'=>Server::get(\OCP\IURLGenerator::class)->linkToRouteAbsolute('mail.page.index').'?' . http_build_query(['mcpLocalDraft'=>$id]),'initially_held'=>$draft->isFailed(),'candidate_fixture_armed'=>$armed];
    }
    public static function normal(string $uid):array {
        $account=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];
        $client=new McpClient(LoginFactory::basic($uid));$args=['account_id'=>$account->getId(),'subject'=>'Native normal draft '.bin2hex(random_bytes(8)),'body_plain'=>'Normal unsent body.'];
        $plan=self::data($client->tool('mail_write_draft',$args));
        $saved=self::data($client->tool('mail_write_draft',$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]));$id=$saved['draft']['id'];
        try{return ['email'=>$account->getEMailAddress(),'proof'=>MailLifecycleActor::draft($uid,$id)];}
        finally{try{$service=Server::get('OCA\\Mail\\Service\\DraftsService');$service->deleteMessage($uid,$service->getMessage($id,$uid));}catch(\OCP\AppFramework\Db\DoesNotExistException){}}
    }
    public static function safety(string $uid,array $p):array {
        $draft=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage((int)$p['draft_id'],$uid);$sourceBefore=self::draftFields($draft);
        $accounts=Server::get('OCA\\Mail\\Service\\AccountService');$account=$accounts->find($uid,$draft->getAccountId());
        $bob=$accounts->findByUserId(MailActor::BOB)[0];$aliases=Server::get('OCA\\Mail\\Service\\AliasesService');
        $foreign=$aliases->create(MailActor::BOB,$bob->getId(),'safety-'.bin2hex(random_bytes(8)).'@example.test','Native safety fixture');
        $cases=['missing_alias'=>['aliasId',2147483647],'foreign_alias'=>['aliasId',$foreign->getId()],
            'account_changed'=>['accountId',$bob->getId()],'empty_parent'=>['inReplyToMessageId',''],
            'header_injection'=>['inReplyToMessageId',"<parent@example.test>\r\nBcc: injected@example.test"],
            'oversized_parent'=>['inReplyToMessageId','<'.str_repeat('a',999).'@example.test>'],
            'crypto'=>['smimeSign',true]];
        $refused=[];$caseStates=[];
        try{
            foreach($cases as $name=>[$field,$value]){
                $copy=clone $draft;$setter='set'.ucfirst($field);$copy->$setter($value);
                try{Server::get('OCA\\Mail\\Service\\MailTransmission')->saveLocalDraft($account,$copy);$refused[$name]=false;}
                catch(\OCA\Mail\Exception\ServiceException){$refused[$name]=true;}
                $after=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage((int)$p['draft_id'],$uid);$state=self::draftFields($after);
                $caseStates[$name]=['refused'=>$refused[$name],'changed_fields'=>self::changedFields($sourceBefore,$state)];
            }
        }finally{$aliases->delete(MailActor::BOB,$foreign->getId());}
        $copy=clone $draft;$copy->setSmimeSign(true);$cryptoRefused=false;
        try{Server::get('OCA\\Mail\\Db\\LocalMessageMapper')->update($copy);}catch(\OCA\Mail\Exception\ClientException){$cryptoRefused=true;}
        $afterMapper=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage((int)$p['draft_id'],$uid);$mapperState=self::draftFields($afterMapper);$caseStates['crypto_mapper']=['refused'=>$cryptoRefused,'changed_fields'=>self::changedFields($sourceBefore,$mapperState)];
        $controller=Server::get('OCA\\Mail\\Controller\\DraftsController');$payload=$draft->jsonSerialize();$controllerRefused=false;
        try{$controller->update(id:$draft->getId(),accountId:$draft->getAccountId(),subject:$draft->getSubject(),bodyPlain:$draft->getBodyPlain(),bodyHtml:$draft->getBodyHtml(),editorBody:$draft->getEditorBody(),isHtml:$draft->isHtml(),smimeSign:true,smimeEncrypt:false,to:$payload['to'],cc:$payload['cc'],bcc:$payload['bcc'],attachments:$payload['attachments'],aliasId:$draft->getAliasId(),inReplyToMessageId:$draft->getInReplyToMessageId());}
        catch(\OCA\Mail\Exception\ClientException){$controllerRefused=true;}
        $remaining=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage((int)$p['draft_id'],$uid);
        $remainingFields=self::draftFields($remaining);
        $caseStates['crypto_controller']=['refused'=>$controllerRefused,'changed_fields'=>self::changedFields($sourceBefore,$remainingFields)];
        return ['crypto_mutation_refused'=>$cryptoRefused,'crypto_controller_refused'=>$controllerRefused,'refused'=>$refused,'source_retained'=>$remaining->getSubject()===$p['subject']&&$remaining->getBodyPlain()===$p['body_plain']&&$remaining->getBodyHtml()===($p['body_html']??null)&&$remaining->getAliasId()===$p['alias_id'],'case_states'=>$caseStates];
    }
    public static function finish(string $uid,array $p):array {
        $draft=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage((int)$p['draft_id'],$uid);
        $before=['subject'=>$draft->getSubject(),'body_plain'=>$draft->getBodyPlain(),'alias_id'=>$draft->getAliasId(),'parent'=>$draft->getInReplyToMessageId(),'body_html'=>$draft->getBodyHtml(),'is_html'=>$draft->isHtml(),'attachments'=>count($draft->getAttachments())];
        $outbox=Server::get('OCA\\Mail\\Service\\OutboxService');$mapper=Server::get('OCA\\Mail\\Db\\LocalMessageMapper');
        $refused=[];
        foreach(['normal','stale_conversion','stale_schedule'] as $mode){
            $copy=clone $draft;if($mode!=='normal')$copy->setMcpDraftOnly(false);
            try{
                if($mode==='stale_schedule'){$copy->setSendAt(time()+600);$mapper->update($copy);}else{$outbox->convertDraft($copy,time()+600);}
                $refused[$mode]=false;
            }catch(\OCA\Mail\Exception\ClientException){$refused[$mode]=true;}
        }
        $draft=Server::get('OCA\\Mail\\Service\\DraftsService')->getMessage((int)$p['draft_id'],$uid);
        $heldAfterAttempts=$draft->isFailed()&&$draft->getMcpDraftOnly()&&$draft->getSendAt()===null;
        $proof=MailLifecycleActor::draft($uid,(int)$p['draft_id'],['attachment_base64'=>$p['attachment_base64']]);
        $file=Server::get(IRootFolder::class)->getUserFolder($uid)->get($p['file_name']);
        return ['ui_outgoing_unchanged'=>$p['outgoing_before']===self::outgoing($uid),'ui_smtp_submission_unchanged'=>hash_equals($p['smtp_submission_before'],self::submissionDigest()),'send_refusals'=>$refused,'held_after_attempts'=>$heldAfterAttempts,'after_ui'=>$before,'proof'=>$proof,'original_file_unchanged'=>$file->getId()===$p['file_id']&&hash_equals($p['file_sha256'],hash('sha256',$file->getContent()))];
    }
    public static function cleanup(string $uid,array $p):array {
        if($uid!==MailActor::ALICE||!preg_match('/^mcp-draft-ui-[a-f0-9]{16}\.bin$/D',$p['file_name']??''))throw new \RuntimeException('Unexpected fixture cleanup.');
        $drafts=Server::get('OCA\\Mail\\Service\\DraftsService');
        try{$d=$drafts->getMessage((int)$p['draft_id'],$uid);if($d->getSubject()===$p['subject']||$d->getSubject()===$p['subject'].' edited')$drafts->deleteMessage($uid,$d);}catch(\OCP\AppFramework\Db\DoesNotExistException){}
        $folder=Server::get(IRootFolder::class)->getUserFolder($uid);
        if($folder->nodeExists($p['file_name'])){$f=$folder->get($p['file_name']);if($f->getId()===$p['file_id'])$f->delete();}
        Server::get('OCA\\Mail\\Service\\AliasesService')->delete($uid,(int)$p['alias_id']);return ['cleaned'=>true];
    }
}
