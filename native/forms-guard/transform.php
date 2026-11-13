<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Token-based body wrapping keeps native signatures, attributes, and all validators intact.
$path=$argv[1];$methods=array_slice($argv,2);$source=file_get_contents($path);
$tokens=token_get_all($source);$offset=0;$patches=[];
for($i=0;$i<count($tokens);$i++) {
    $token=$tokens[$i];$text=is_array($token)?$token[1]:$token;
    if(is_array($token) && $token[0]===T_FUNCTION) {
        $j=$i+1;while(is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j++;
        $name=is_array($tokens[$j])?$tokens[$j][1]:'';
        if(in_array($name,$methods,true)) {
            $start=$offset;$signature='';$vars=[];$depth=0;$inParams=false;
            for($k=$i;$k<count($tokens);$k++) {
                $t=$tokens[$k];$v=is_array($t)?$t[1]:$t;
                if($v==='{') break;
                $signature.=$v;
                if($v==='('){$depth++;$inParams=true;}
                if($v===')'){$depth--;if($depth===0)$inParams=false;}
                if($inParams && is_array($t) && $t[0]===T_VARIABLE) $vars[]=$v;
            }
            $bodyStart=$start+strlen($signature)+1;$cursor=$bodyStart;$nest=1;
            for($k++;$k<count($tokens);$k++) {
                $t=$tokens[$k];$v=is_array($t)?$t[1]:$t;
                if($v==='{' || (is_array($t) && in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)))$nest++;
                if($v==='}')$nest--;
                if($nest===0)break;
                $cursor+=strlen($v);
            }
            $void=preg_match('/:\s*void\s*$/',$signature)===1;
            $prefix="\n".($void?'':'return ').'\\OCA\\Forms\\Mcp\\Service\\FormsRevisionFence::run(function ()'.($vars?' use ('.implode(', ',array_unique($vars)).')':'').' {';
            $patches[]=[$bodyStart,$cursor,$prefix.substr($source,$bodyStart,$cursor-$bodyStart)."\n});\n"];
        }
    }
    $offset+=strlen($text);
}
if(count($patches)!==count($methods)) throw new RuntimeException('Incomplete pinned method transformation: '.$path);
foreach(array_reverse($patches) as [$start,$end,$replacement])$source=substr($source,0,$start).$replacement.substr($source,$end);
file_put_contents($path,$source);
