<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/standalone/GroupLeaseFlow.php';
use McpFormsServerExtension\GroupLeaseFlow as Flow;
$n=0;
function check(bool $v):void {global $n;if(!$v)throw new RuntimeException('test failed');++$n;}
function scenario(?string $fail):array {
    $trace=[];$tx=false;$committed=false;$closed=false;
    $step=static function(string $name)use(&$trace,$fail):void {$trace[]=$name;if($fail===$name)throw new RuntimeException('injected-'.$name);};
    $error=null;$value=null;
    try {$value=Flow::run(
        static function()use($step):void{$step('acquire');},
        static function()use($step):void{$step('verify');},
        static function()use($step,&$tx):void{$step('begin');$tx=true;},
        static function()use($step):string{$step('work');return 'synthetic';},
        static function()use($step,&$tx,&$committed):void{$step('commit');$tx=false;$committed=true;},
        static function()use($step):void{$step('release');},
        static function()use(&$tx):bool{return $tx;},
        static function()use($step,&$tx):void{$step('rollback');$tx=false;},
        static function()use(&$trace,&$closed):void{$trace[]='close';$closed=true;}
    );}catch(RuntimeException $e){$error=$e->getMessage();}
    return compact('trace','tx','committed','closed','error','value');
}
$r=scenario(null);check($r['error']===null&&$r['value']==='synthetic'&&$r['committed']);check($r['trace']===['acquire','verify','begin','work','verify','commit','verify','release']);
foreach(['acquire','verify','begin','work','commit','release'] as $fail){$r=scenario($fail);check($r['error']==='injected-'.$fail);check(in_array('release',$r['trace'],true)||$r['closed']);check(!$r['committed']||$fail==='release');if(in_array($fail,['work','commit'],true))check(in_array('rollback',$r['trace'],true));if($fail==='release')check($r['closed']);}
// Rollback failure cannot skip release, and preserves the actual business failure.
$trace=[];$message=null;
try{Flow::run(static function(){},static function(){},static function(){},static function(){throw new RuntimeException('business');},static function(){},static function()use(&$trace){$trace[]='release';},static fn()=>true,static function(){throw new RuntimeException('rollback');},static function()use(&$trace){$trace[]='close';});}catch(RuntimeException $e){$message=$e->getMessage();}
check($message==='business');check($trace===['close','release']);
echo json_encode(['ok'=>true,'checks'=>$n],JSON_THROW_ON_ERROR)."\n";
