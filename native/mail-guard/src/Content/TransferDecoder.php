<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Content;
/** Streaming strict transfer decoder. Only bounded chunks enter a codec; no advertised size is trusted. */
final class TransferDecoder {
    public static function decode($stream,string $encoding,ContentBudget $budget):array {
        $encoding=strtolower($encoding);
        if(!is_resource($stream)||!in_array($encoding,['7bit','8bit','binary','base64','quoted-printable'],true))throw new \RuntimeException('Unsupported content encoding.');
        $bytes='';$pending='';$wire=0;$ended=false;$hash=hash_init('sha256');$wireHash=hash_init('sha256');
        $append=static function(string $chunk)use(&$bytes,$hash,$budget):void {$budget->check();if(strlen($chunk)>ContentBudget::MAX_BYTES-strlen($bytes))throw new \RuntimeException('Decoded content exceeds its bound.');$budget->decoded(strlen($chunk));hash_update($hash,$chunk);$bytes.=$chunk;};
        while(!feof($stream)){
            $budget->check();$chunk=fread($stream,8192);if($chunk===false||($chunk===''&&!feof($stream)))throw new \RuntimeException('Content stream incomplete.');hash_update($wireHash,$chunk);$wire+=strlen($chunk);if($wire>ContentBudget::MAX_WIRE)throw new \RuntimeException('Encoded content exceeds its bound.');
            if($encoding==='base64'){
                if(preg_match('/[^A-Za-z0-9+\/= \t\r\n]/D',$chunk))throw new \RuntimeException('Malformed base64 content.');
                $pending.=str_replace([" ","\t","\r","\n"],'',$chunk);
                if($ended&&$pending!=='')throw new \RuntimeException('Trailing base64 content.');
                $length=intdiv(strlen($pending),4)*4;if($length===0)continue;
                $block=substr($pending,0,$length);$pending=substr($pending,$length);
                $decoded=base64_decode($block,true);
                if($decoded===false||base64_encode($decoded)!==$block)throw new \RuntimeException('Noncanonical base64 content.');
                if(str_contains($block,'=')){$ended=true;if($pending!=='')throw new \RuntimeException('Trailing base64 content.');}$append($decoded);
            }elseif($encoding==='quoted-printable'){
                $pending.=$chunk;$out='';$i=0;$length=strlen($pending);
                while($i<$length){if($pending[$i]!=='='){$out.=$pending[$i++];continue;}if($length-$i<3)break;$code=substr($pending,$i+1,2);if($code==="\r\n"){$i+=3;continue;}if(!ctype_xdigit($code))throw new \RuntimeException('Malformed quoted-printable content.');$out.=chr(hexdec($code));$i+=3;}
                $pending=substr($pending,$i);$append($out);
            }else{if($encoding==='7bit'&&preg_match('/[\x80-\xff]/',$chunk))throw new \RuntimeException('Non-ASCII 7bit content.');$append($chunk);}
        }
        if($pending!=='')throw new \RuntimeException('Truncated transfer encoding.');
        return ['bytes'=>$bytes,'byte_count'=>strlen($bytes),'sha256'=>hash_final($hash),'wire_sha256'=>hash_final($wireHash),'wire_byte_count'=>$wire];
    }
}
