<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\Server;
final class MailContentActor {
    public static function run(string $uid,array $in):array {
        if(!trait_exists('OCA\\Mail\\IMAP\\GuardedContentRead'))return ['implementation_available'=>false];
        $mode=$in['mode'];if(str_starts_with($mode,'service_')&&!class_exists('OCA\\Mail\\Service\\GuardedContentReader'))return ['implementation_available'=>false];$p=proc_open([PHP_BINARY,__DIR__.'/content-imap-server.php',$mode],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($p))throw new \RuntimeException('Synthetic content peer unavailable.');fclose($pipes[0]);$port=json_decode(fgets($pipes[1]),true,16,JSON_THROW_ON_ERROR)['port'];
        $a=Server::get('OCA\\Mail\\Service\\AccountService')->findByUserId($uid)[0];$e=clone $a->getMailAccount();$e->setInboundHost(str_starts_with($mode,'tls_')?'localhost':'127.0.0.1');$e->setInboundPort($port);$e->setInboundSslMode(str_starts_with($mode,'tls_')?'ssl':'none');$e->setDebug(false);
        if(str_starts_with($mode,'service_'))return self::service($uid,$e,$p,$pipes,$mode);
        $c=Server::get('OCA\\Mail\\IMAP\\IMAPClientFactory')->getClient(new \OCA\Mail\Account($e),false);$accepted=false;$integrity=false;$started=hrtime(true);$elapsed=null;
        $f=new class {public function authorize():void {}};
        try{$c->mcpBeginContent(42,7,$f);$c->mcpSelectContent('INBOX');if(in_array($mode,['manifest','deep_tree','many_parts','many_params'],true)){$c->mcpContentManifest();$accepted=true;}else{$r=$c->mcpContentRaw();$accepted=true;$integrity=$r['sha256']===hash('sha256',"Content-Type: application/pkcs7-mime\r\n\r\nOpaque synthetic bytes\0\xff");}$c->mcpVerifyContent();}catch(\Throwable $error){$accepted=false;}finally{$elapsed=(hrtime(true)-$started)/1000000000;try{$c->logout();}catch(\Throwable){}$raw=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);}
        return ['implementation_available'=>true,'accepted'=>$accepted,'integrity'=>$integrity,'elapsed'=>$elapsed]+json_decode(trim($raw),true,16,JSON_THROW_ON_ERROR);
    }
    private static function service(string $uid,object $account,$peer,array $pipes,string $mode):array {
        $db=Server::get(\OCP\IDBConnection::class);$accountId=null;$mailboxId=null;$messageId=null;$erasure=null;$result=[];
        try {
            $original=$account;$account=new \OCA\Mail\Db\MailAccount();foreach(['UserId','Name','Email','InboundHost','InboundPort','InboundSslMode','InboundUser','InboundPassword','AuthMethod','Protocol'] as $field)$account->{'set'.$field}($original->{'get'.$field}());$account->setDebug(false);
            $account->setId(null);$account->setDraftsMailboxId(null);$account->setSentMailboxId(null);$account->setTrashMailboxId(null);$account->setArchiveMailboxId(null);$account->setJunkMailboxId(null);
            $account=Server::get('OCA\\Mail\\Db\\MailAccountMapper')->insert($account);$accountId=(int)$account->getId();
            $box=new \OCA\Mail\Db\Mailbox();$box->setAccountId($accountId);$box->setName('INBOX');$box->setNameHash(hash('sha256','INBOX'));$box->setSelectable(true);$box->setSyncInBackground(false);$box->setAttributes('[]');$box->setSpecialUse('[]');$box->setMessages(1);$box->setUnseen(1);$box->setDelimiter('/');
            foreach(['setSyncNewToken','setSyncChangedToken','setSyncVanishedToken'] as $set)$box->$set(base64_encode('V42,U8,H1,M1'));
            $box=Server::get('OCA\\Mail\\Db\\MailboxMapper')->insert($box);$mailboxId=(int)$box->getId();
            $account->setDraftsMailboxId($mailboxId);Server::get('OCA\\Mail\\Db\\MailAccountMapper')->update($account);
            $m=new \OCA\Mail\Db\Message();$m->setMailboxId($mailboxId);$m->setUid(7);$m->setMessageId('content-fixture@example.test');$m->setSubject('Synthetic content');$m->setSentAt(time());$m->setUpdatedAt(time());$m->setStructureAnalyzed(false);
            foreach(['Answered','Deleted','Draft','Flagged','Seen','Forwarded','Junk','Notjunk','Attachments','Mdnsent'] as $flag)$m->{'setFlag'.$flag}(false);$m->setEncrypted(false);$m->setTagCacheGeneration('42');$m->setTagCacheProfile(\OCA\Mail\Db\TagProfile::account($account));
            $m=Server::get('OCA\\Mail\\Db\\MessageMapper')->insert($m);$messageId=(int)$m->getId();
            if($mode==='service_http_scopes'||str_starts_with($mode,'service_scope_')){$result=MailSavedScopeActor::run($uid,$messageId,$accountId,$mailboxId,$mode);}elseif($mode==='service_http_draft_index'){$result=MailDraftIndexHttpActor::run($uid,$messageId,$accountId,$mailboxId);}elseif(str_starts_with($mode,'service_http_')){$result=MailContentHttpActor::run($uid,$messageId,$mode);}else {
            $service=Server::get('OCA\\Mail\\Service\\GuardedContentReader');$f=new class {public function authorize(string $uid,int $account,int $box,int $message):void {}};
            $denied=new class {public function authorize(string $uid,int $account,int $box,int $message):void {throw new \RuntimeException('Synthetic revoked grant.');}};
            $foreign=false;$revoked=false;try{$service->readRaw(MailActor::BOB,$messageId,$f);}catch(\Throwable){$foreign=true;}try{$service->readRaw($uid,$messageId,$denied);}catch(\Throwable){$revoked=true;}
            if($mode==='service_quarantined'){
                $erasure=bin2hex(random_bytes(32));foreach(['mail_mcp_erasure'=>['id'=>$erasure,'user_id'=>$uid,'state'=>'pending','created_at'=>time()],'mail_mcp_erase_accounts'=>['erasure_id'=>$erasure,'account_id'=>$accountId]] as $table=>$row){$q=$db->getQueryBuilder();$q->insert($table);foreach($row as $key=>$value)$q->setValue($key,$q->createNamedParameter($value,is_int($value)?\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT:\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR));$q->executeStatement();}
            }
            if($mode==='service_changed')$f=new class($db,$messageId) {private int $calls=0;public function __construct(private object $db,private int $id){}public function authorize(string $uid,int $account,int $box,int $message):void {if(++$this->calls===5){$q=$this->db->getQueryBuilder();$q->update('mail_messages')->set('tag_cache_generation',$q->createNamedParameter('43'))->where($q->expr()->eq('id',$q->createNamedParameter($this->id,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();}}};
            $result=['accepted'=>false,'integrity'=>false,'foreign_refused'=>$foreign,'revoked_refused'=>$revoked,'draft_headers'=>false,'display_body'=>false];
            try {
                if(str_starts_with($mode,'service_location_')){$result+=MailDraftIndexActor::location($uid,$messageId,$accountId,$f,$mode);$result['accepted']=true;}elseif(in_array($mode,['service_copy_crypto','service_copy_unsupported'],true)){$result+=MailDraftIndexActor::refused($uid,$messageId,$accountId,$f);$result['accepted']=true;}elseif(in_array($mode,['service_copy','service_copy_lost'],true)){$result+=MailDraftIndexActor::copy($uid,$messageId,$accountId,$mailboxId,$f,$mode==='service_copy_lost');$result['accepted']=true;}elseif(str_starts_with($mode,'service_index_store')){$result+=MailDraftIndexActor::index($uid,$messageId,$accountId,$mailboxId,$f,$mode);$result['accepted']=true;}elseif(str_starts_with($mode,'service_related_')||str_starts_with($mode,'service_single_')){
                    $action=substr($mode,strrpos($mode,'_')+1);$raw=match($action){'body'=>$service->readBody($uid,$messageId,$f),'index'=>$service->readIndexText($uid,$messageId,$f),'draft'=>$service->readDraft($uid,$messageId,$f),'list'=>$service->listParts($uid,$messageId,$f),'part'=>$service->readPart($uid,$messageId,'1',$f),'raw'=>$service->readRaw($uid,$messageId,$f)};
                    $html=str_starts_with($mode,'service_related_')||str_contains($mode,'_html_');$expected=$html?'<p>primary</p>':'primary';$expectedRoot="From: sender@example.test\r\nTo: recipient@example.test\r\nSubject: Fixture root\r\nContent-Type: ".($html?'text/html':'text/plain')."; charset=utf-8\r\nContent-ID: <primary@example.test>\r\nContent-Disposition: inline\r\n\r\n".$expected;$result['accepted']=true;
                    $result['primary_exact']=match($action){'body','draft'=>($raw[$html?'body_html':'body_plain']??null)===$expected&&($raw[$html?'body_plain':'body_html']??null)==='','index'=>trim($raw['text'])==='primary','part'=>$raw['bytes']===$expected,'raw'=>$raw['bytes']===$expectedRoot&&$raw['sha256']===hash('sha256',$expectedRoot),'list'=>true};
                    $result['attachment_ids']=array_values(array_map(static fn($part)=>$part['part_id'],array_filter($raw['parts']??[],static fn($part)=>($part['role']??null)==='attachment')));$result['attachment_count']=count($raw['attachments']??[]);
                }else {
                $raw=match($mode){'service_list_large'=>$service->listParts($uid,$messageId,$f),'service_part_large','service_part_over','service_crypto_lie'=>$service->readPart($uid,$messageId,'2',$f),'service_body','service_body_over'=>$service->readBody($uid,$messageId,$f),'service_index'=>$service->readIndexText($uid,$messageId,$f),'service_draft','service_not_draft','service_draft_revision'=>$service->readDraft($uid,$messageId,$f),default=>$service->readRaw($uid,$messageId,$f)};
                $result['accepted']=true;$result['display_body']=($raw['body_plain']??null)==='text';$result['integrity']=isset($raw['sha256'])&&$raw['sha256']===hash('sha256',$mode==='service_part_large'?str_repeat('a',26214400):"Content-Type: application/pkcs7-mime\r\n\r\nOpaque synthetic bytes\0\xff");
                if($mode==='service_draft_revision'){$again=$service->readDraft($uid,$messageId,$f);$result['revision_changed']=$again['content_revision']!==$raw['content_revision'];$result['manifest_retained']=isset($raw['parts'][0]['part_id']);}
                if($mode==='service_draft')$result['draft_headers']=($raw['headers']['bcc']??'')==='hidden@example.test'&&($raw['headers']['in-reply-to']??'')==='<parent@example.test>';
                }
            }catch(\Throwable $error){$result['failure_class']=get_class($error);$result['failure_site']=basename($error->getFile()).':'.$error->getLine();}
            }

        }finally {
            if($erasure!==null)foreach(['mail_mcp_erase_accounts'=>'erasure_id','mail_mcp_erasure'=>'id'] as $table=>$column){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq($column,$q->createNamedParameter($erasure)))->executeStatement();}
            foreach(['mail_messages'=>$messageId,'mail_mailboxes'=>$mailboxId,'mail_accounts'=>$accountId] as $table=>$id)if($id!==null){$q=$db->getQueryBuilder();$q->delete($table)->where($q->expr()->eq('id',$q->createNamedParameter($id,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->executeStatement();}
            if($result===[])proc_terminate($peer);$rawPeer=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($peer);
        }
        return ['implementation_available'=>true]+$result+json_decode(trim($rawPeer),true,16,JSON_THROW_ON_ERROR);
    }

}
