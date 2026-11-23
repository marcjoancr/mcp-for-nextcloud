<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require_once (require __DIR__.'/repository-root.php').'/mcp/vendor/autoload.php';
require_once dirname(__DIR__).'/src/FinalReadSet.php';
use OCA\Forms\Mcp\Service\FinalReadSet;
$n=0;
$check=static function(bool $ok)use(&$n):void{if(!$ok)throw new LogicException('check failed');$n++;};
$denied=static function(Closure $run,string $message)use($check):void{$error=null;try{$run();}catch(RuntimeException $e){$error=$e->getMessage();}$check($error===$message);};
$make=static fn()=>new FinalReadSet(static function():void{});
// Exact previously consumed rows are rechecked; SQL predicates/absence are retained.
$s=$make();$s->add('SELECT configkey,configvalue FROM *PREFIX*appconfig WHERE appid = ?',['core'],[],[['configkey'=>'shareapi_enabled','configvalue'=>'yes']]);
$s->seal();$plan=$s->statement('pgsql');$check(substr_count($plan['sql'],'SELECT')===3);$check(str_contains($plan['sql'],'jsonb_build_object'));$check(str_contains($plan['sql'],'CAST(t."configkey" AS TEXT)'));$check(str_contains($plan['sql'],'CAST(t."configvalue" AS TEXT)'));$check($plan['params']===['f0p0'=>'core']);
$s->verify([['q'=>'0','row'=>'{"configkey":"shareapi_enabled","configvalue":"yes"}']]);
$denied(fn()=>$s->verify([]),'Final read snapshot escaped or repeated');
foreach(['config','membership','duplicate','view','permission']as$kind){
 $s=$make();$s->add('SELECT value FROM *PREFIX*appconfig WHERE appid = ?',[$kind],[],[['value'=>'allowed']]);$s->seal();
 $denied(fn()=>$s->verify([['q'=>0,'row'=>'{"value":"revoked"}']]),'Final read dependency changed');
}
$s=$make();$s->add('SELECT id FROM *PREFIX*share WHERE parent = ?',[7],[],[]);$s->seal();
$denied(fn()=>$s->verify([['q'=>0,'row'=>'{"_present":1}']]),'Final read dependency changed');
$s=$make();$s->add('SELECT gid FROM *PREFIX*group_user WHERE uid = ?',['synthetic'],[],[['gid'=>'b'],['gid'=>'a'],['gid'=>'a']]);$s->seal();
$s->verify([['q'=>0,'row'=>'{"gid":"a"}'],['q'=>0,'row'=>'{"gid":"b"}'],['q'=>0,'row'=>'{"gid":"a"}']]);$check(true);
$s=$make();$s->add('SELECT gid FROM *PREFIX*group_user WHERE uid = ?',['synthetic'],[],[['gid'=>'a'],['gid'=>'a']]);$s->seal();
$denied(fn()=>$s->verify([['q'=>0,'row'=>'{"gid":"a"}']]),'Final read dependency changed');
$s=$make();$denied(fn()=>$s->add('DELETE FROM *PREFIX*share',[],[],[]),'Final read query is unqualified');
$s=$make();$denied(fn()=>$s->add('SELECT id FROM *PREFIX*share FOR UPDATE',[],[],[]),'Final read query is unqualified');
$s=$make();$s->add('SELECT id FROM *PREFIX*share WHERE id = :dcValue1',['dcValue1'=>9],['dcValue1'=>1],[['id'=>9]]);$s->seal();$p=$s->statement('mysql');$check(str_contains($p['sql'],'JSON_OBJECT'));$check($p['params']===['f0p0'=>9]);
$denied(fn()=>$s->add('SELECT id FROM *PREFIX*share',[],[],[]),'Final read snapshot escaped or repeated');
$s=$make();$s->add('SELECT id FROM *PREFIX*share',[],[],[['id'=>1]]);$s->seal();
$denied(fn()=>$s->verify([['q'=>0,'row'=>'{"id":1}'],['q'=>0,'row'=>'{"id":2}']]),'Final read dependency changed');
$s=$make();$s->add('SELECT id FROM *PREFIX*share',[],[],[['id'=>1]]);$s->seal();
$denied(fn()=>$s->verify([['q'=>1,'row'=>'{"id":1}']]),'Final read snapshot is malformed');
$s=$make();$s->add('SELECT id FROM *PREFIX*share',[],[],[['id'=>1]]);$s->seal();
$denied(fn()=>$s->verify([['q'=>0,'row'=>'not-json']]),'Final read snapshot is malformed');
$check(FinalReadSet::equalRows([['id'=>1]],[['id'=>'1']])===false);
$s=$make();$s->add('SELECT id FROM *PREFIX*share WHERE id = :id',['id'=>9],['id'=>\Doctrine\DBAL\ParameterType::INTEGER],[['id'=>9]]);$s->seal();$check($s->statement('pgsql')['types']===['f0p0'=>\Doctrine\DBAL\ParameterType::INTEGER]);
$s=$make();$denied(fn()=>$s->validate('SELECT id FROM *PREFIX*share WHERE id = ?',[new stdClass()],[]),'Final read parameter is unqualified');
$s=$make();$denied(fn()=>$s->validate('SELECT id FROM *PREFIX*share WHERE id = ?',[9],[new stdClass()]),'Final read parameter type is unqualified');
$s=$make();$denied(fn()=>$s->add('SELECT id FROM *PREFIX*share',[],[],array_fill(0,1001,['id'=>1])),'Final read budget exceeded');
$s=$make();$denied(fn()=>$s->add('SELECT configvalue FROM *PREFIX*appconfig',[],[],[['configvalue'=>str_repeat('x',1048577)]]),'Final read budget exceeded');
echo json_encode(['ok'=>true,'checks'=>$n,'pure_only'=>true]),"\n";
