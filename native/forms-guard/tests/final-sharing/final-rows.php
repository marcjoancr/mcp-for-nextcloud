<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/src/FinalShareRows.php';
use OCA\Forms\Mcp\Service\FinalShareRows as Rows;
$n=0;
function check(bool $v):void{global $n;if(!$v)throw new RuntimeException('test failed');++$n;}
function denied(Closure $f,string $expected):void{$m=null;try{$f();}catch(RuntimeException $e){$m=$e->getMessage();}check($m===$expected);}
$original=['id'=>4,'share_type'=>1,'share_with'=>'synthetic-group','uid_owner'=>'original','uid_initiator'=>'original','file_source'=>10,'item_type'=>'folder','parent'=>null,'permissions'=>1,'accepted'=>1,'expiration'=>'2030-01-02 23:59:59','attributes'=>null,'stime'=>123];
$record=['files_share_id'=>'ocinternal:4','folder_id'=>10,'approved_tuple'=>['type'=>1,'recipient'=>'synthetic-group','owner_id'=>'original'],'core_state'=>$original,'share_state'=>['accepted'=>1,'attributes'=>null,'expiration'=>$original['expiration']]];
Rows::original($original,$record);check(true);
foreach(['id'=>5,'share_type'=>0,'share_with'=>'different','uid_owner'=>'different','uid_initiator'=>'different','file_source'=>11,'permissions'=>0,'accepted'=>0,'attributes'=>'[]','expiration'=>null] as $key=>$value){$bad=$original;$bad[$key]=$value;denied(fn()=>Rows::original($bad,$record),'Final sharing original tuple changed');}
$job=['uid'=>'original','input'=>['owner_id'=>'target']];$mapping=['files_share_id'=>'ocinternal:8','folder_id'=>20];
$target=$original;$target['id']=8;$target['uid_owner']='target';$target['file_source']=20;
Rows::target($target,$record,$mapping,$job);check(true);
foreach(['id'=>9,'uid_owner'=>'original','uid_initiator'=>'target','file_source'=>21,'permissions'=>17,'parent'=>7] as $key=>$value){$bad=$target;$bad[$key]=$value;denied(fn()=>Rows::target($bad,$record,$mapping,$job),'Final sharing target identity changed');}
$bad=$target;$bad['expiration']=null;denied(fn()=>Rows::target($bad,$record,$mapping,$job),'Final sharing expiry widened');
$bad=$target;$bad['accepted']=0;denied(fn()=>Rows::target($bad,$record,$mapping,$job),'Final sharing recipient decision changed');
$child=$original;$child['id']=5;$child['share_type']=2;$child['share_with']='recipient';$child['parent']=4;$child['expiration']='2030-01-03 23:59:59';
$copied=$child;$copied['id']=9;$copied['uid_owner']='target';$copied['file_source']=20;$copied['parent']=8;$copied['expiration']=$target['expiration'];
Rows::child($copied,$child,9,$target,$job);check(true);
foreach(['share_with'=>'changed','accepted'=>0,'permissions'=>17,'attributes'=>'[]'] as $key=>$value){$bad=$copied;$bad[$key]=$value;denied(fn()=>Rows::child($bad,$child,9,$target,$job),'Final sharing child decision changed');}
foreach(['id'=>10,'parent'=>4,'uid_initiator'=>'target','expiration'=>$child['expiration']] as $key=>$value){$bad=$copied;$bad[$key]=$value;denied(fn()=>Rows::child($bad,$child,9,$target,$job),'Final sharing child identity changed');}
$form=['id'=>1,'owner_id'=>'original','state'=>0,'locked_by'=>'original','locked_until'=>999,'file_id'=>3,'file_format'=>'csv','title'=>'synthetic'];$changed=$form;$changed['owner_id']='target';foreach(['locked_by','locked_until','file_id','file_format'] as $key)$changed[$key]=null;
Rows::form($changed,$form,'target');check(true);
$bad=$changed;$bad['title']='unapproved';denied(fn()=>Rows::form($bad,$form,'target'),'Final sharing intended owner transition changed');
denied(fn()=>Rows::form($form,$form,'target'),'Final sharing intended owner transition changed');
foreach(['ocCircleShare:4','ocinternal:0','ocinternal:04','ocinternal:99999999999999999999999999999'] as $bad)denied(fn()=>Rows::id($bad),'Final sharing provider identity is invalid');
echo json_encode(['ok'=>true,'checks'=>$n],JSON_THROW_ON_ERROR)."\n";
