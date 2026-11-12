<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
/** Strict supported composition subset. Refusal is preferable to losing MIME or header semantics. */
final class DraftCopyComposition {
    private static function reject():never {throw new \RuntimeException('Draft composition cannot be preserved by a protected local draft.');}
    /** Parsed MIME headers must be representable before the reader projects fields or fetches bodies. */
    public static function assertMimeHeaders(array $fields):void {
        foreach(array_keys($fields) as $name){$name=strtolower($name);if(str_starts_with($name,'content-')&&!in_array($name,['content-type','content-transfer-encoding','content-disposition','content-id'],true))self::reject();}
    }
    private static function addresses(string $raw):array {
        if(strlen($raw)>4096||preg_match('/[\x00-\x1f\x7f:;()]/',$raw)||!mb_check_encoding($raw,'UTF-8'))self::reject();
        if(trim($raw)==='')return [];
        $items=[];$token='';$quoted=false;$escaped=false;
        foreach(str_split($raw) as $c){if($escaped){$token.=$c;$escaped=false;continue;}if($c==='\\'&&$quoted){$token.=$c;$escaped=true;continue;}if($c==='"')$quoted=!$quoted;if($c===','&&!$quoted){$items[]=trim($token);$token='';}else $token.=$c;}
        if($quoted||$escaped)self::reject();$items[]=trim($token);if(count($items)>50)self::reject();$result=[];
        foreach($items as $item){$name='';$email=$item;
            if(str_contains($item,'<')){if(preg_match('/^(.*?)\s*<([^<>]+)>$/Du',$item,$m)!==1)self::reject();$name=trim($m[1]);$email=$m[2];
                if(str_starts_with($name,'"')){if(preg_match('/^"((?:[^"\\\\]|\\\\["\\\\])*)"$/Du',$name,$n)!==1)self::reject();$name=preg_replace('/\\\\(["\\\\])/','$1',$n[1]);}
                elseif(str_contains($name,'"')||str_contains($name,'\\'))self::reject();
                $decoded=iconv_mime_decode($name,ICONV_MIME_DECODE_STRICT,'UTF-8');if($decoded===false)self::reject();$name=$decoded;
            }
            if(strlen($email)>254||preg_match('/[^\x21-\x7e]/',$email)||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($name)>1024||preg_match('/[\x00-\x1f\x7f]/',$name))self::reject();
            $result[]=['email'=>$email,'name'=>$name];
        }return $result;
    }
    private static function structure(array $parts):void {
        $root=$parts[0];$children=[];$known=[];
        foreach($parts as $part){if(isset($known[$part['part_id']]))self::reject();$known[$part['part_id']]=true;if($part['parent_id']!==null)$children[$part['parent_id']][]=$part;}
        $branch=$root;$consumed=1;
        if($root['mime_type']==='multipart/mixed'){
            $direct=$children[$root['part_id']]??[];if(count($direct)<2)self::reject();$branch=array_shift($direct);$consumed+=count($direct)+1;
            foreach($direct as $attachment)if($attachment['role']!=='attachment'||isset($children[$attachment['part_id']]))self::reject();
        }
        if($branch['mime_type']==='multipart/alternative'){
            $bodies=$children[$branch['part_id']]??[];if(count($bodies)!==2||array_column($bodies,'mime_type')!==['text/plain','text/html']||array_column($bodies,'role')!==['body','body'])self::reject();$consumed+=2;
        }elseif($branch['role']!=='body'||isset($children[$branch['part_id']]))self::reject();
        if($consumed!==count($parts))self::reject();
    }
    public static function parse(array $draft):array {
        if(($draft['draft_flag']??false)!==true)self::reject();$headers=$draft['headers'];$from=self::addresses($headers['from']??'');if(count($from)!==1)self::reject();
        $reply=$headers['in-reply-to']??null;if($reply!==null&&preg_match('/^<[^<>\s]+@[^<>\s]+>$/D',$reply)!==1)self::reject();
        // The existing frozen renderer can preserve only this References form.
        if(isset($headers['references'])&&$headers['references']!==$reply)self::reject();
        if($reply!==null&&!isset($headers['references']))self::reject();
        foreach(['body_plain','body_html'] as $key)if(!is_string($draft[$key])||!mb_check_encoding($draft[$key],'UTF-8')||mb_strlen($draft[$key])>20000||str_contains($draft[$key],"\0"))self::reject();
        if(strlen($draft['subject'])>4096||preg_match('/[\x00-\x1f\x7f]/',$draft['subject']))self::reject();
        $parts=$draft['parts'];if($parts===[]||count($parts)>100||count($draft['attachments'])>10)self::reject();$attachmentParts=[];$cids=[];$bodyTypes=[];self::structure($parts);
        foreach($parts as $p){
            if($p['role']!=='attachment'){
                if($p['content_id']!==null||$p['disposition']!==null||$p['filename']!==null)self::reject();
                if($p['role']==='container'&&!in_array($p['mime_type'],['multipart/mixed','multipart/alternative'],true))self::reject();
                if($p['role']==='body'){if(!in_array($p['mime_type'],['text/plain','text/html'],true)||isset($bodyTypes[$p['mime_type']]))self::reject();$bodyTypes[$p['mime_type']]=true;}
            }else{$attachmentParts[$p['part_id']]=$p;}
            if($p['content_id']!==null){if(isset($cids[$p['content_id']])||preg_match('/[<>\s]/',$p['content_id']))self::reject();$cids[$p['content_id']]=true;}
        }
        $total=0;foreach($draft['attachments'] as $a){$p=$attachmentParts[$a['part_id']]??null;if($p===null)self::reject();unset($attachmentParts[$a['part_id']]);
            foreach(['mime_type','filename','disposition','content_id'] as $key)if($a[$key]!==$p[$key])self::reject();
            // Native LocalAttachment cannot retain unnamed resources or MIME charset parameters.
            if(!is_string($a['filename'])||$a['filename']===''||preg_match('/[\x00-\x1f\x7f\/\\\\]/',$a['filename'])||!in_array($a['disposition'],['inline','attachment'],true)||$a['charset']!==null)self::reject();
            if(!is_string($a['bytes'])||strlen($a['bytes'])!==$a['byte_count']||!hash_equals(hash('sha256',$a['bytes']),$a['sha256']))self::reject();$total+=$a['byte_count'];
            if($total>26214400)self::reject();
        }if($attachmentParts!==[])self::reject();
        preg_match_all('/cid:([^\s"\'<>]+)/i',html_entity_decode($draft['body_html'],ENT_QUOTES|ENT_HTML5,'UTF-8'),$refs);
        foreach($refs[1] as $cid)if(!isset($cids[rawurldecode($cid)]))self::reject();
        return ['from'=>$from[0],'to'=>self::addresses($headers['to']??''),'cc'=>self::addresses($headers['cc']??''),'bcc'=>self::addresses($headers['bcc']??''),'subject'=>$draft['subject'],'body_plain'=>$draft['body_plain'],'body_html'=>$draft['body_html'],'has_plain'=>isset($bodyTypes['text/plain']),'has_html'=>isset($bodyTypes['text/html']),'reply_to'=>$reply,'attachments'=>$draft['attachments']];
    }
}
