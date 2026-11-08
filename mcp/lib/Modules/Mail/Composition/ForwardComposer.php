<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Modules\Mail\Composition;
use OCA\Mcp\Modules\Mail\Integration\{MailMessageLocator,MailServices,MailCacheGeneration};
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
/** Explicit new local draft composition; source recipients, reply parent and MIME parts are never inherited. */
class ForwardComposer {
    public function __construct(private MailMessageLocator $locator,private \Closure $reader,private MailAttachmentAccess $attachments,private MailServices $native,private \Closure $allows,private ?OperationJournal $journal=null){}
    private function source(string $uid,int $account,int $message):array {
        if(!($this->allows)($uid,'mail_read_message'))throw new ToolFailure('Reading the forwarded source is not permitted.');
        $l=$this->locator->locate($uid,$message);if((int)$l['account']->getId()!==$account)throw new ToolFailure('Forward source unavailable in the selected account.');return $l;
    }
    public function verify(string $uid,int $account,array $binding):void {
        if(!($this->allows)($uid,'mail_write_draft')||$this->binding($this->source($uid,$account,(int)$binding['message_id']))!==$binding)throw new ToolFailure('Forward authorization or source identity changed.');
    }
    private function binding(array $l):array {return ['message_id'=>(int)$l['message']->getId(),'mailbox_id'=>(int)$l['mailbox']->getId(),'generation'=>MailCacheGeneration::of($l['mailbox']),'uid'=>(string)$l['message']->getUid()];}
    public function prepare(string $uid,int $account,int $message,array $parts,bool $explicitBody):array {
        $l=$this->source($uid,$account,$message);$binding=$this->binding($l);$body=null;
        if(!$explicitBody){
            $row=($this->reader)($uid)->readRow($uid,$l,20000);
            if(!is_array($row['body']??null)||($row['body']['truncated']??true)||($row['encrypted']??false)||($row['signed']??false))throw new ToolFailure('Automatic forwarding needs a complete unencrypted unsigned source. Supply the final body explicitly or use native Mail.');
            $from=$row['from']??['count'=>0,'addresses'=>[]];if($from['count']>count($from['addresses']))throw new ToolFailure('Forward attribution is incomplete.');$senders=[];
            foreach($from['addresses'] as $a)$senders[]=($a['name']??'').' <'.($a['email']??'').'>';
            $body="-------- Forwarded message --------\nFrom: ".implode(', ',$senders)."\nDate: ".($row['date']??'')."\nSubject: ".($row['subject']??'')."\n\n".$row['body']['text'];
            if(mb_strlen($body)>20000)throw new ToolFailure('Forwarded quote exceeds the draft body limit. Supply the final body explicitly.');
        }
        if(count($parts)>10||count(array_unique($parts))!==count($parts))throw new \InvalidArgumentException('Choose at most ten distinct source MIME part IDs.');$manifest=[];
        foreach($parts as $part){[$meta]=$this->part($uid,$account,$message,(string)$part,$binding);$manifest[]=$meta;}
        return ['source'=>$binding,'body_plain'=>$body,'attachments'=>$manifest];
    }
    private function part(string $uid,int $account,int $message,string $part,array $binding):array {
        if(!($this->allows)($uid,'mail_read_attachment')||!($this->allows)($uid,'mail_write_draft'))throw new ToolFailure('Reading forwarded attachments is not permitted.');
        $l=$this->source($uid,$account,$message);if($this->binding($l)!==$binding)throw new ToolFailure('Forward source identity changed.');$a=$this->attachments->read($uid,$message,$part);$bytes=base64_decode($a['content_base64'],true);
        if(!is_string($bytes)||strlen($bytes)>MailAttachmentAccess::MAX_ATTACHMENT_BYTES)throw new ToolFailure('Forward attachment is not supported.');
        $name=$a['name']??'attachment';$meta=['message_id'=>$message,'part_id'=>$part,'source'=>$binding,'name'=>$name,'mime_type'=>$a['mimeType'],'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'mail_source'=>hash('sha256',json_encode([$binding,$part],JSON_THROW_ON_ERROR))];return [$meta,$bytes];
    }
    public function stage(string $uid,int $account,array $manifest,array $reusable,array $resources=[]):array {
        $staged=[];$storage=$manifest===[]?null:$this->native->attachmentService($uid);
        foreach($manifest as $approved){[$fresh,$bytes]=$this->part($uid,$account,$approved['message_id'],$approved['part_id'],$approved['source']);if($fresh!==$approved)throw new ToolFailure('Forward attachment changed. Preview again.');$id=null;
            foreach($reusable as $old)if(($old['mail_source']??null)===$approved['mail_source']&&($old['sha256']??null)===$approved['sha256']){$id=(int)$old['attachment_id'];break;}
            if($id===null)$id=(int)$storage->addFileFromString($uid,$approved['name'],$approved['mime_type'],$bytes,null,'attachment')->getId();
            $resources[]=['attachment_id'=>$id,'file_id'=>0,'sha256'=>$approved['sha256'],'mail_source'=>$approved['mail_source']];$this->journal?->checkpoint(['staged'=>$resources]);
            [$a,$copy]=$storage->getAttachment($uid,$id);if($a->getLocalMessageId()!==null||$a->getFileName()!==$approved['name']||$a->getMimeType()!==$approved['mime_type']||!hash_equals($approved['sha256'],hash('sha256',$copy->getContent())))throw new ToolFailure('Forward attachment snapshot could not be verified.');$staged[]=['type'=>'local','id'=>$id];
        }
        return $staged;
    }
}
