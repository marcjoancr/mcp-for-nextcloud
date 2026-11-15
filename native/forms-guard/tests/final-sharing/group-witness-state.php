<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/standalone/GroupWitnessState.php';
use McpFormsServerExtension\GroupWitnessState as State;
$n=0;
function check(bool $value):void {global $n;if(!$value)throw new RuntimeException('assertion failed');++$n;}
function denied(Closure $work,string $expected):void {$message=null;try{$work();}catch(RuntimeException $e){$message=$e->getMessage();}check($message===$expected);}
$e=str_repeat('e',64);$a=str_repeat('a',64);$b=str_repeat('b',64);$gid='synthetic-group';
$live=State::enroll(null,$gid,$e,$a);check($live['state']==='live');check(State::enroll($live,$gid,$e,$b)===$live);
State::assertLive($live,$gid,$e,['epoch'=>$e,'generation'=>$a]);check(true);
denied(fn()=>State::assertLive($live,$gid,$e,['epoch'=>$e,'generation'=>$b]),'Group lifetime changed');
$retired=State::beforeDelete($live,$gid,$e,$b);check($retired['state']==='retired');check($retired['generation']===$b);
denied(fn()=>State::enroll($retired,$gid,$e,$a),'Group lifetime requires reconciliation');
denied(fn()=>State::assertLive($retired,$gid,$e,['epoch'=>$e,'generation'=>$a]),'Group lifetime changed');
$creating=State::beforeCreate($retired,$gid,$e,$a);check($creating['state']==='creating');
denied(fn()=>State::enroll($creating,$gid,$e,$b),'Group lifetime requires reconciliation');
denied(fn()=>State::afterCreate($creating,$gid,$e,$b),'Group creation intent changed');
$new=State::afterCreate($creating,$gid,$e,$a);check($new['state']==='live');
denied(fn()=>State::afterCreate($new,$gid,$e,$a),'Group creation intent changed');
$other=State::beforeCreate($creating,$gid,$e,$b);denied(fn()=>State::afterCreate($other,$gid,$e,$a),'Group creation intent changed');
$retired2=State::beforeDelete($creating,$gid,$e,$b);denied(fn()=>State::afterCreate($retired2,$gid,$e,$a),'Group creation intent changed');
denied(fn()=>State::assertLive($new,$gid,str_repeat('f',64),['epoch'=>$e,'generation'=>$a]),'Group lifetime changed');
denied(fn()=>State::assertLive($new,'different',$e,['epoch'=>$e,'generation'=>$a]),'Group witness row binding changed');
$bad=$new;$bad['gid_key']=str_repeat('0',64);denied(fn()=>State::enroll($bad,$gid,$e,$a),'Group witness row binding changed');
denied(fn()=>State::enroll(null,'',$e,$a),'Group witness identity is invalid');
echo json_encode(['ok'=>true,'checks'=>$n],JSON_THROW_ON_ERROR)."\n";
