<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use OCA\Forms\Mcp\Service\FinalShareBudget;
use OCA\Forms\Mcp\Service\FinalShareCommitment;
foreach (['FinalShareBudget','FinalShareCommitment'] as $class) {require dirname(__DIR__).'/src/'.$class.'.php';}
eval('namespace OC; class AllConfig {public function getAppValue($appName,$key,$default="") {} public function setAppValue($appName,$key,$value) {}}');
require dirname(__DIR__).'/src/FinalShareConfig.php';
$checks=0;
function same(mixed $actual,mixed $expected):void {global $checks;if($actual!==$expected)throw new RuntimeException('assertion failed');++$checks;}
function denied(Closure $run,string $message):void {global $checks;$caught=null;try{$run();}catch(RuntimeException $e){$caught=$e->getMessage();}same($caught,$message);}
$b=new FinalShareBudget();$closed=0;$hydrated=0;
$fetch=function()use(&$hydrated):array|false {return $hydrated++<1001?['id'=>$hydrated]:false;};
$close=function()use(&$closed){++$closed;};
denied(fn()=> $b->rows($fetch,$close,1000),'Final sharing row budget exceeded');same($closed,1);same($hydrated,1001);
$b=new FinalShareBudget();$count=0;$closed=0;
$rows=$b->rows(function()use(&$count){return $count++<1000?['id'=>$count]:false;},function()use(&$closed){++$closed;},1000);same(count($rows),1000);same($closed,1);
$b=new FinalShareBudget();$closed=0;
$close=function()use(&$closed){++$closed;};
denied(fn()=> $b->rows(static function(){throw new RuntimeException('read failed');},$close,1000),'read failed');same($closed,1);
$b=new FinalShareBudget();denied(fn()=> $b->record(['path'=>str_repeat('x',1048577)]),'Final sharing byte budget exceeded');
$a=new FinalShareCommitment();$a->append('parent',1,['permissions'=>1,'accepted'=>0]);$a->seal();$a->assertMatches([['parent',1,['permissions'=>1,'accepted'=>0]]]);++$checks;
denied(fn()=> $a->append('parent',2,[]),'Final sharing commitment is sealed');
denied(fn()=> $a->assertMatches([['parent',1,['permissions'=>1,'accepted'=>1]]]),'Final sharing activation changed');
denied(fn()=> $a->assertMatches([]),'Final sharing activation changed');
$a=new FinalShareCommitment();denied(fn()=> $a->assertMatches([]),'Final sharing commitment is unsealed');
$a->append('parent',2,[]);denied(fn()=> $a->append('parent',2,[]),'Final sharing activation order changed');
$a=new FinalShareCommitment();$a->append('parent',1,[]);$a->append('child',1,[]);$a->seal();denied(fn()=> $a->assertMatches([['child',1,[]],['parent',1,[]]]),'Final sharing activation changed');
$config=new \OCA\Forms\Mcp\Service\FinalShareConfig([]);same($config->getAppValue('core','shareapi_enabled','yes'),'yes');
$config=new \OCA\Forms\Mcp\Service\FinalShareConfig([['configkey'=>'shareapi_exclude_groups','configvalue'=>'yes'],['configkey'=>'shareapi_exclude_groups_list','configvalue'=>'["one","two"]']]);same($config->getAppValue('core','shareapi_exclude_groups_list'),'["one","two"]');
foreach(['one,two','null','{"one":true}','["one","one"]','[1]'] as $bad) {denied(fn()=>new \OCA\Forms\Mcp\Service\FinalShareConfig([['configkey'=>'shareapi_exclude_groups','configvalue'=>'yes'],['configkey'=>'shareapi_exclude_groups_list','configvalue'=>$bad]]),'Final sharing policy config requires repair');}
denied(fn()=>new \OCA\Forms\Mcp\Service\FinalShareConfig([['configkey'=>'shareapi_enabled','configvalue'=>'yes'],['configkey'=>'shareapi_enabled','configvalue'=>'yes']]),'Final sharing policy config is malformed');
denied(fn()=> $config->getAppValue('other','shareapi_enabled','yes'),'Final sharing unexpected config read');
$trapped=false;try {$config->setAppValue('core','shareapi_exclude_groups_list','[]');}catch(LogicException $e){same($e->getMessage(),'Final sharing config writes are forbidden');$trapped=true;}same($trapped,true);
$b=new FinalShareBudget();$serialization=0;$object=new class($serialization) implements JsonSerializable {public function __construct(private int &$calls) {} public function jsonSerialize():mixed {++$this->calls;return 'bad';}};
denied(fn()=> $b->record(['nested'=>[$object]]),'Final sharing projection must be closed data');same($serialization,0);
echo json_encode(['ok'=>true,'checks'=>$checks],JSON_THROW_ON_ERROR)."\n";
