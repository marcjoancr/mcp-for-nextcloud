<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Content\{ContentBudget,BoundedHeaders,PartBodyRole};
use OCA\Mail\Db\{TagProfile,ExactTagAssociations};
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Lifecycle\AccountVisibility;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\{IDBConnection,IConfig};
/** Closed owned content APIs. Byte-bearing results are private integration data, never tool JSON or receipts. */
final class GuardedContentReader {
    public function __construct(private IDBConnection $db,private IConfig $config,private AccountService $accounts,private IMAPClientFactory $clients){}
    private function source(string $uid,int $id):array {
        if($this->db->getDatabaseProvider()!==IDBConnection::PLATFORM_POSTGRES)throw new \RuntimeException('Qualified content storage is unavailable.');
        if($id<1||$uid==='')throw new \RuntimeException('Invalid content source.');
        $visibility=new AccountVisibility($this->db);$q=$this->db->getQueryBuilder();
        $q->select('m.id','m.uid','m.mailbox_id','m.tag_cache_generation','m.tag_cache_profile','b.account_id','b.name','b.selectable','b.sync_new_token','b.sync_changed_token','b.sync_vanished_token','a.inbound_host','a.inbound_port','a.inbound_ssl_mode','a.inbound_user','a.auth_method','a.protocol','a.email','a.mcp_auth_revision','a.drafts_mailbox_id')
            ->from('mail_messages','m')->innerJoin('m','mail_mailboxes','b',$q->expr()->eq('m.mailbox_id','b.id'))->innerJoin('b','mail_accounts','a',$q->expr()->eq('b.account_id','a.id'))
            ->where($q->expr()->eq('m.id',$q->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->andWhere($q->expr()->eq('a.user_id',$q->createNamedParameter($uid)));
        $visibility->excludePending($q,'a.id');$q->setMaxResults(2);$r=$q->executeQuery();try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        if(count($rows)!==1)throw new \RuntimeException('Owned content source unavailable.');$row=$rows[0];$visibility->assertVisible($uid,(int)$row['account_id']);
        $generation=ExactTagAssociations::generation($row);$profile=TagProfile::row($row);
        if(!$row['selectable']||$generation===null||$generation!==$row['tag_cache_generation']||!hash_equals($profile,(string)$row['tag_cache_profile'])||preg_match('/^[1-9][0-9]{0,9}$/D',(string)$row['uid'])!==1||(float)$row['uid']>4294967295)throw new \RuntimeException('Content source generation is unproven.');
        return ['message_id'=>$id,'account_id'=>(int)$row['account_id'],'mailbox_id'=>(int)$row['mailbox_id'],'uid'=>(string)$row['uid'],'uidvalidity'=>$generation,'profile_revision'=>$profile,'auth_revision'=>(int)$row['mcp_auth_revision'],'mailbox_revision'=>hash('sha256',json_encode([$row['name'],$row['sync_new_token'],$row['sync_changed_token'],$row['sync_vanished_token']],JSON_THROW_ON_ERROR)),'mailbox'=>$row['name'],'drafts_mailbox_id'=>(int)$row['drafts_mailbox_id']];
    }
    private function run(string $uid,int $id,object $fence,\Closure $read):array {
        $source=$this->source($uid,$id);$authorize=function()use($uid,$id,$fence,$source):void {if($source!==$this->source($uid,$id))throw new \RuntimeException('Content source changed.');$fence->authorize($uid,$source['account_id'],$source['mailbox_id'],$id);};$authorize();
        $account=$this->accounts->find($uid,$source['account_id']);
        if($account->getMailAccount()->getDebug()||$this->config->getSystemValueBool('app.mail.debug',false)||!$this->config->getSystemValueBool('app.mail.verify-tls-peer',true))throw new \RuntimeException('Content requires private verified transport.');
        $client=$this->clients->getClient($account,false);
        try{
            $adapter=new class($authorize){public function __construct(private \Closure $check){}public function authorize():void {($this->check)();}};
            $client->mcpBeginContent((int)$source['uidvalidity'],(int)$source['uid'],$adapter);$client->mcpSelectContent($source['mailbox']);
            $result=$read($client,$source);$client->mcpVerifyContent();$authorize();unset($source['mailbox'],$source['drafts_mailbox_id']);
            return ['source'=>$source+['observed_at'=>time()]]+$result;
        }finally{try{$client->mcpEndContent();}catch(\Throwable){}}
    }
    /** Metadata-only current-source check before an approved effect; never opens an IMAP connection. */
    public function assertSource(string $uid,array $source,object $fence):void {
        if(!is_int($source['message_id']??null))throw new \RuntimeException('Invalid content source reference.');$current=$this->source($uid,$source['message_id']);unset($current['mailbox'],$current['drafts_mailbox_id']);
        if($current!==$source)throw new \RuntimeException('Content source reference changed.');$fence->authorize($uid,$current['account_id'],$current['mailbox_id'],$current['message_id']);
    }
    public function readRaw(string $uid,int $messageId,object $fence):array {return $this->run($uid,$messageId,$fence,static fn($c):array=>$c->mcpContentRaw());}
    public function listParts(string $uid,int $messageId,object $fence):array {return $this->run($uid,$messageId,$fence,function($c):array {$m=$this->manifest($c);$this->headers($c,$m);return $m;});}
    public function readPart(string $uid,int $messageId,string $partId,object $fence):array {return $this->run($uid,$messageId,$fence,function($c)use($partId):array {$m=$this->manifest($c);$headers=$this->headers($c,$m);return $this->part($c,$m,$headers,$partId);});}
    /** Attachment grants must never select a primary body through the generic leaf reader. */
    public function readAttachment(string $uid,int $messageId,string $partId,object $fence):array {
        return $this->run($uid,$messageId,$fence,function($c)use($partId):array {
            $m=$this->manifest($c);$headers=$this->headers($c,$m);
            foreach($m['parts'] as $part)if($part['part_id']===$partId&&$part['role']==='attachment')return $this->part($c,$m,$headers,$partId);
            throw new \RuntimeException('Content part is not an attachment.');
        });
    }
    private function manifest(object $client):array {
        $root=$client->mcpContentManifest();$parts=[];
        $walk=function(object $node,?string $parent,int $depth)use(&$walk,&$parts):void {
            if(count($parts)>=100||$depth>16)throw new \RuntimeException('Content MIME tree exceeds bound.');
            $type=strtolower($node->getType());$id=(string)$node->getMimeId();$children=$node->getParts();
            if($id==='0'&&$parent===null)$id='0';elseif(preg_match('/^[1-9][0-9]?(?:\.[1-9][0-9]?){0,15}$/D',$id)!==1)throw new \RuntimeException('Invalid content part identity.');
            if(preg_match('/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/D',$type)!==1||str_starts_with($type,'message/')||in_array($type,['multipart/signed','multipart/encrypted','application/pkcs7-mime','application/x-pkcs7-mime','application/pkcs7-signature','application/x-pkcs7-signature','application/pgp-encrypted','application/pgp-signature','application/pgp-keys'],true))throw new \RuntimeException('Unsupported protected or nested message content.');
            $encoding=$children===[]?strtolower((string)$node->mcpTransferEncoding()):null;if($encoding!==null&&!in_array($encoding,['7bit','8bit','binary','base64','quoted-printable'],true))throw new \RuntimeException('Unsupported transfer encoding.');
            $disposition=$node->getDisposition();if(!in_array($disposition,['','inline','attachment'],true))throw new \RuntimeException('Unsupported content disposition.');
            $name=$node->getName();if($name!==null){if(!mb_check_encoding($name,'UTF-8')||strlen($name)>1024)throw new \RuntimeException('Content filename exceeds bound.');$name=mb_substr(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u','_',$name),0,255);}
            $cid=$node->getContentId();if($cid!==null&&(strlen($cid)>998||preg_match('/[\x00-\x20\x7f]/',$cid)))throw new \RuntimeException('Unsupported content identity.');
            $parts[]=['part_id'=>$id,'parent_id'=>$parent,'mime_type'=>$type,'disposition'=>$disposition===''?null:$disposition,'filename'=>$name,'transfer_encoding'=>$encoding,'advertised_size'=>(int)$node->getBytes(),'size_verified'=>false,'content_id'=>$cid,'charset'=>$node->getContentTypeParameter('charset'),'leaf'=>$children===[],'related_start'=>$type==='multipart/related'?$node->getContentTypeParameter('start'):null,'related_type'=>$type==='multipart/related'&&$node->getContentTypeParameter('type')!==null?strtolower($node->getContentTypeParameter('type')):null];
            foreach($children as $child)$walk($child,$id,$depth+1);
        };$walk($root,null,1);$parts=PartBodyRole::classify($parts);
        return ['parts'=>$parts,'manifest_revision'=>hash('sha256',json_encode($parts,JSON_THROW_ON_ERROR))];
    }
    /** Root and ancestor headers are checked before any body; server BODYSTRUCTURE is advisory. */
    private function headers(object $client,array $manifest):array {
        $headers=[];$total=0;
        foreach($manifest['parts'] as $part){
            // All part headers stay bounded; no attachment body is loaded for metadata validation.
            $raw=$client->mcpContentHeader($part['parent_id']===null?'':$part['part_id']);$total+=strlen($raw);if($total>131072)throw new \RuntimeException('Aggregate content headers exceed bound.');
            $fields=BoundedHeaders::parse($raw);[$type,$parameters]=BoundedHeaders::parameters($fields['content-type']??'text/plain');
            $encoding=strtolower(trim($fields['content-transfer-encoding']??'7bit'));[$disposition,$unused]=isset($fields['content-disposition'])?BoundedHeaders::parameters($fields['content-disposition']):['',[]];
            if($type!==$part['mime_type']||($part['leaf']?$encoding!==$part['transfer_encoding']:!in_array($encoding,['7bit','8bit','binary'],true))||($disposition===''?null:$disposition)!==$part['disposition'])throw new \RuntimeException('Content metadata contradicts raw headers.');
            $filename=$unused['filename']??$parameters['name']??null;if($filename!==null)$filename=mb_substr(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u','_',$filename),0,255);if($filename!==$part['filename'])throw new \RuntimeException('Content filename contradicts raw headers.');
            $cid=isset($fields['content-id'])?trim($fields['content-id'],'<>'):null;if($cid!==$part['content_id'])throw new \RuntimeException('Content identity contradicts raw headers.');
            if($part['mime_type']==='multipart/related'&&(($parameters['start']??null)!==$part['related_start']||(isset($parameters['type'])?strtolower($parameters['type']):null)!==$part['related_type']))throw new \RuntimeException('Related root parameters contradict raw headers.');
            $headers[$part['part_id']]=['fields'=>$fields,'parameters'=>$parameters,'sha256'=>hash('sha256',$raw)];
        }
        return $headers;
    }
    private function part(object $client,array $manifest,array $headers,string $id):array {
        foreach($manifest['parts'] as $part)if($part['part_id']===$id){if(!$part['leaf'])throw new \RuntimeException('Select a leaf content part.');$result=$client->mcpContentPart($id,$part['transfer_encoding']);return $result+['part_id'=>$id,'manifest_revision'=>$manifest['manifest_revision'],'header_revision'=>$headers[$id]['sha256'],'mime_type'=>$part['mime_type'],'filename'=>$part['filename'],'disposition'=>$part['disposition'],'content_id'=>$part['content_id'],'charset'=>$headers[$id]['parameters']['charset']??null];}
        throw new \RuntimeException('Content part unavailable.');
    }
    /** Bounded display projection: only supported text bodies, never attachment payloads. */
    public function readBody(string $uid,int $messageId,object $fence):array {
        return $this->run($uid,$messageId,$fence,function($c):array {
            $c->mcpContentDisplayBudget();$m=$this->manifest($c);$headers=$this->headers($c,$m);$root=$headers[$m['parts'][0]['part_id']]['fields'];
            $reply=$root['reply-to']??'';if(substr_count($reply,',')>=50||substr_count($reply,'(')>16||str_contains($reply,':')||str_contains($reply,';'))throw new \RuntimeException('Unsupported reply address list.');
            $addresses=[];foreach((new \Horde_Mail_Rfc822())->parseAddressList($reply,['validate'=>'eai','limit'=>51]) as $address){if(count($addresses)>=50)throw new \RuntimeException('Reply addresses exceed bound.');$addresses[]=['label'=>(string)$address->personal,'email'=>(string)$address->bare_address];}
            $plain='';$html='';$attachments=[];$hashes=[];$utf8Bytes=0;
            foreach($m['parts'] as $p){if(!$p['leaf'])continue;
                if($p['role']==='attachment'){$attachments[]=['id'=>$p['part_id'],'fileName'=>$p['filename'],'mime'=>$p['mime_type'],'size'=>null];continue;}
                $r=$this->part($c,$m,$headers,$p['part_id']);$text=BoundedHeaders::text($r['bytes'],$r['charset']);$utf8Bytes+=strlen($text)+(($p['mime_type']==='text/plain'?$plain:$html)===''?0:1);if($utf8Bytes>1048576)throw new \RuntimeException('Display text exceeds bound.',413);
                if($p['mime_type']==='text/plain')$plain.=($plain===''?'':"\n").$text;else $html.=($html===''?'':"\n").$text;
                $hashes[]=hash('sha256',$r['header_revision'].$r['wire_sha256'].$r['sha256']);
            }
            return ['body_plain'=>$plain,'body_html'=>$html,'attachments'=>$attachments,'reply_to'=>$addresses,'manifest_revision'=>$m['manifest_revision'],'content_revision'=>hash('sha256',serialize([$headers,$hashes]))];
        });
    }
    public function readDraft(string $uid,int $messageId,object $fence):array {
        return $this->run($uid,$messageId,$fence,function($c,$source):array {
            if($source['drafts_mailbox_id']!==$source['mailbox_id']||!in_array('\\draft',array_map('strtolower',$c->mcpContentFlags()),true))throw new \RuntimeException('Source is not a current configured draft.');
            $m=$this->manifest($c);$headers=$this->headers($c,$m);$root=$headers[$m['parts'][0]['part_id']]['fields'];
            foreach(['sender','reply-to','disposition-notification-to','return-receipt-to','x-send-later','x-send-at','autocrypt','dkim-signature'] as $unsupported)if(isset($root[$unsupported]))throw new \RuntimeException('Unsupported draft composition controls.');
            foreach(['from','to','cc','bcc'] as $name){$value=$root[$name]??'';if(substr_count($value,',')>=50||str_contains($value,';')||str_contains($value,':'))throw new \RuntimeException('Unsupported draft address list.');}
            $reply=$root['in-reply-to']??null;if($reply!==null&&preg_match('/^<[^<>\s]{1,996}>$/D',$reply)!==1)throw new \RuntimeException('Unsupported draft reply parent.');
            if(isset($root['references'])){if(preg_match('/^<[^<>\\s]{1,996}>(?: +<[^<>\\s]{1,996}>){0,49}$/D',$root['references'])!==1)throw new \RuntimeException('Unsupported draft references.');}
            $plain=null;$html=null;$attachments=[];$aggregate=0;$revisions=[];
            foreach($m['parts'] as $p){if(!$p['leaf']){if(!in_array($p['mime_type'],['multipart/mixed','multipart/alternative','multipart/related'],true))throw new \RuntimeException('Unsupported draft MIME structure.');continue;}
                if(in_array($p['mime_type'],['text/calendar','application/ics'],true))throw new \RuntimeException('Unsupported draft invitation.');
                $r=$this->part($c,$m,$headers,$p['part_id']);$revisions[]=[$r['header_revision'],$r['wire_sha256'],$r['sha256']];$aggregate+=$r['byte_count'];if($aggregate>ContentBudget::MAX_BYTES)throw new \RuntimeException('Draft content exceeds bound.');
                if($p['role']==='body'){$text=BoundedHeaders::text($r['bytes'],$r['charset']);if(mb_strlen($text)>20000)throw new \RuntimeException('Draft body exceeds bound.');if($p['mime_type']==='text/plain'){if($plain!==null)throw new \RuntimeException('Ambiguous draft body.');$plain=$text;}else{if($html!==null)throw new \RuntimeException('Ambiguous draft body.');$html=$text;}}
                else{if(count($attachments)>=10)throw new \RuntimeException('Draft attachments exceed bound.');$attachments[]=$r;}
            }
            $subject=iconv_mime_decode($root['subject']??'',ICONV_MIME_DECODE_STRICT,'UTF-8');if($subject===false||strlen($subject)>4096||preg_match('/[\x00-\x1f\x7f]/',$subject))throw new \RuntimeException('Unsupported draft subject.');
            $result=['headers'=>array_intersect_key($root,array_flip(['from','to','cc','bcc','in-reply-to','references'])),'subject'=>$subject,'body_plain'=>$plain??'','body_html'=>$html??'','attachments'=>$attachments,'manifest_revision'=>$m['manifest_revision'],'draft_flag'=>true,'parts'=>$m['parts']];
            return $result+['content_revision'=>hash('sha256',serialize([$result,array_column($headers,'sha256'),$revisions]))];
        });
    }
    public function readIndexText(string $uid,int $messageId,object $fence):array {
        return $this->run($uid,$messageId,$fence,function($c):array {
            $m=$this->manifest($c);$headers=$this->headers($c,$m);$text='';$hashes=[];$truncated=false;
            foreach($m['parts'] as $p){if($p['role']!=='body')continue;
                $r=$this->part($c,$m,$headers,$p['part_id']);$hashes[]=hash('sha256',$r['header_revision'].$r['wire_sha256'].$r['sha256']);$body=BoundedHeaders::text($r['bytes'],$r['charset']);
                if(strlen($body)>80000){$body=mb_strcut($body,0,80000,'UTF-8');$truncated=true;}
                if($p['mime_type']==='text/html')$body=html_entity_decode(strip_tags($body),ENT_QUOTES|ENT_HTML5,'UTF-8');$text.=$body."\n";
                if(mb_strlen($text)>20000){$text=mb_substr($text,0,20000);$truncated=true;break;}
            }
            return ['text'=>$text,'truncated'=>$truncated,'coverage'=>'supported_text_parts_only','manifest_revision'=>$m['manifest_revision'],'content_revision'=>hash('sha256',json_encode([$m['manifest_revision'],$hashes,$text],JSON_THROW_ON_ERROR))];
        });
    }
}
