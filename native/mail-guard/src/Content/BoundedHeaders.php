<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
/** Small strict header grammar, before address or MIME helpers. No MIME recursion or HTML parsing. */
final class BoundedHeaders {
    public static function parse(string $raw):array {
        if(strlen($raw)>16384||str_contains($raw,"\0")||preg_match('/\r(?!\n)|(?<!\r)\n/',$raw))throw new \RuntimeException('Unsupported raw headers.');
        $headers=[];$last=null;$count=0;
        foreach(explode("\r\n",$raw) as $line){
            if($line===''){$last=null;continue;}
            if($line[0]===' '||$line[0]==="\t"){if($last===null)throw new \RuntimeException('Invalid header continuation.');$headers[$last].=' '.ltrim($line," \t");}
            else{
                if(++$count>64||preg_match('/^([A-Za-z0-9-]{1,128}):[ \t]*(.*)$/D',$line,$m)!==1)throw new \RuntimeException('Header count or syntax exceeds bound.');$last=strtolower($m[1]);
                if(isset($headers[$last])){if(!in_array($last,['received'],true))throw new \RuntimeException('Ambiguous duplicate header.');$headers[$last].=' '.$m[2];}else $headers[$last]=$m[2];
            }
            if(strlen($headers[$last])>4096||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$headers[$last]))throw new \RuntimeException('Unfolded header exceeds bound.');
        }
        return $headers;
    }
    /** Strict bounded MIME parameter subset; extended/continued parameters refuse instead of silently changing meaning. */
    public static function parameters(string $raw):array {
        if(strlen($raw)>4096)throw new \RuntimeException('MIME header exceeds bound.');
        if(preg_match('/^([A-Za-z0-9!#$&^_.+\/-]+)[ \t]*/D',$raw,$m)!==1)throw new \RuntimeException('Unsupported MIME header.');
        $type=strtolower($m[1]);$offset=strlen($m[0]);$params=[];
        while($offset<strlen($raw)){
            if(count($params)>=32||preg_match('/\G;[ \t]*([A-Za-z0-9_-]{1,128})[ \t]*=[ \t]*(?:"((?:[^"\\\\\r\n]|\\\\["\\\\])*)"|([A-Za-z0-9!#$&^_.+\/-]+))[ \t]*/A',$raw,$m,0,$offset)!==1)throw new \RuntimeException('Unsupported MIME parameter.');
            $key=strtolower($m[1]);if(isset($params[$key]))throw new \RuntimeException('Duplicate MIME parameter.');$value=isset($m[3])&&$m[3]!==''?$m[3]:str_replace(['\\"','\\\\'],['"','\\'],$m[2]);$params[$key]=$value;$offset+=strlen($m[0]);
        }
        return [$type,$params];
    }
    public static function text(string $bytes,?string $charset):string {
        $charset=strtolower($charset??'us-ascii');if(!in_array($charset,['utf-8','us-ascii','ascii','iso-8859-1','windows-1252'],true))throw new \RuntimeException('Unsupported content charset.');
        if(!mb_check_encoding($bytes,$charset))throw new \RuntimeException('Malformed content text.');return mb_convert_encoding($bytes,'UTF-8',$charset);
    }
}
