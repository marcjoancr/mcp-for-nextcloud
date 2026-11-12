<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
/** Bounded ordinary MIME only. Rendering never opens a transport or loads mutable source data. */
final class FrozenMessageRenderer {
    private function safe(string $value):string {
        if(preg_match('/[\x00-\x1f\x7f]/',$value))throw new \RuntimeException('Unsupported message header.');return $value;
    }
    private function address(array $a):string {
        $email=$this->safe($a['email']);
        if(strlen($email)>254||!filter_var($email,FILTER_VALIDATE_EMAIL)||preg_match('/[^\x21-\x7e]/',$email))throw new \RuntimeException('Only explicit ordinary mailbox addresses are supported.');
        return $email;
    }
    private function encoded(string $value):string {
        $this->safe($value);if($value==='')return '';
        if(!mb_check_encoding($value,'UTF-8'))throw new \RuntimeException('Unsupported header encoding.');
        $words=[];for($offset=0;$offset<strlen($value);){$part=mb_strcut($value,$offset,42,'UTF-8');$offset+=strlen($part);$words[]='=?UTF-8?B?'.base64_encode($part).'?=';}return implode("\r\n ",$words);
    }
    private function addresses(array $rows):string {return implode(",\r\n ",array_map(fn(array $a):string=>($a['name']!==''?$this->encoded($a['name']).' ':'').'<'.$this->address($a).'>',$rows));}
    private function canonical(mixed $value):mixed {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value);
        foreach($value as &$child)$child=$this->canonical($child);return $value;
    }
    public function revision(array $content):string {
        foreach($content['attachments'] as &$a){$bytes=$a['content'];$a['sha256']=hash('sha256',$bytes);$a['bytes']=strlen($bytes);unset($a['content']);}unset($a);
        return hash('sha256',json_encode($this->canonical($content),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }
    public function preview(array $content):array {
        if(($content['bodyHtml']??'')!=='')(new FrozenInlineImages())->extract($content['bodyHtml']);
        $revision=$this->revision($content);foreach($content['attachments'] as &$a)unset($a['content']);unset($a);
        return $content+['revision'=>$revision];
    }
    private function textPart(string $type,string $body):string {return 'Content-Type: '.$type."; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");}
    private function multipart(string $type,array $parts):string {
        $boundary='=_mcp_'.bin2hex(random_bytes(24));$body='Content-Type: multipart/'.$type.'; boundary="'.$boundary."\"\r\n\r\n";
        foreach($parts as $part)$body.='--'.$boundary."\r\n".$part."\r\n";
        return $body.'--'.$boundary."--\r\n";
    }
    public function freeze(array $content):array {
        $from=$this->address($content['sender']);$recipients=[];
        foreach(['to','cc','bcc'] as $kind)foreach($content[$kind] as $row)$recipients[]=$this->address($row);
        $recipients=array_values(array_unique($recipients));if(count($recipients)<1||count($recipients)>150)throw new \RuntimeException('Recipient bound exceeded.');
        $headers='Date: '.gmdate('D, d M Y H:i:s O')."\r\nMessage-ID: <".bin2hex(random_bytes(24))."@mcp.invalid>\r\nFrom: ".$this->addresses([$content['sender']])."\r\n";
        foreach(['to'=>'To','cc'=>'Cc'] as $key=>$header)if($content[$key]!==[])$headers.=$header.': '.$this->addresses($content[$key])."\r\n";
        $headers.='Subject: '.$this->encoded($content['subject']??'')."\r\nMIME-Version: 1.0\r\n";
        if(($content['replyTo']??null)!==null){$reply=$this->safe($content['replyTo']);if(!preg_match('/^<[^<>\s]+@[^<>\s]+>$/D',$reply))throw new \RuntimeException('Unsupported reply identity.');$headers.='In-Reply-To: '.$reply."\r\nReferences: ".$reply."\r\n";}
        if($content['aiGenerated']??false)$headers.="X-AI-Generated: 1\r\n";
        $inline=[];if($content['bodyHtml']!==null&&$content['bodyHtml']!==''){[$content['bodyHtml'],$inline]=(new FrozenInlineImages())->extract($content['bodyHtml']);}
        $parts=[];if($content['bodyPlain']!==null&&$content['bodyPlain']!=='')$parts[]=$this->textPart('text/plain',$content['bodyPlain']);if($content['bodyHtml']!==null&&$content['bodyHtml']!=='')$parts[]=$this->textPart('text/html',$content['bodyHtml']);
        $body=count($parts)>1?$this->multipart('alternative',$parts):($parts[0]??$this->textPart('text/plain',''));
        if($inline!==[])$body=$this->multipart('related',array_merge([$body],$inline));
        $parts=[$body];$total=0;if(count($content['attachments'])>10)throw new \RuntimeException('Attachment bound exceeded.');
        foreach($content['attachments'] as $a){
            $bytes=$a['content'];$total+=strlen($bytes);if($total>26214400||strlen($bytes)!==$a['bytes']||!hash_equals($a['sha256'],hash('sha256',$bytes)))throw new \RuntimeException('Attachment bytes changed.');
            $mime=$this->safe($a['mime']);if(!preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+$~D',$mime))throw new \RuntimeException('Unsupported attachment type.');
            $disposition=$a['disposition']??'attachment';if(!in_array($disposition,['attachment','inline'],true))throw new \RuntimeException('Unsupported attachment disposition.');
            $part='Content-Type: '.$mime."\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: ".$disposition."; filename*=UTF-8''".rawurlencode($this->safe($a['name']))."\r\n";
            if($a['contentId']!==null){$id=trim($this->safe($a['contentId']),'<>');if(preg_match('/[<>\s]/',$id))throw new \RuntimeException('Unsupported attachment identity.');$part.='Content-ID: <'.$id.">\r\n";}
            $parts[]=$part."\r\n".chunk_split(base64_encode($bytes),76,"\r\n");
        }
        if(count($parts)>1)$body=$this->multipart('mixed',$parts);
        $smtp=$headers.$body;$fcc=$headers.($content['bcc']!==[]?'Bcc: '.$this->addresses($content['bcc'])."\r\n":'').$body;
        return ['from'=>$from,'recipients'=>$recipients,'smtp'=>$smtp,'fcc'=>$fcc,'smtpHash'=>hash('sha256',$smtp),'fccHash'=>hash('sha256',$fcc)];
    }
}
