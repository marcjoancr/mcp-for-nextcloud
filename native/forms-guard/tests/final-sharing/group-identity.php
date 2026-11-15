<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/standalone/GroupIdentity.php';
$n=0;
function check(bool $value):void {global $n;if(!$value)throw new RuntimeException('assertion failed');++$n;}
function denied(Closure $work,string $expected):void {$message=null;try{$work();}catch(RuntimeException $e){$message=$e->getMessage();}check($message===$expected);}
use McpFormsServerExtension\GroupIdentity as Identity;
check(Identity::canonicalRow(['gid'=>'Canonical'],false)==='Canonical');
check(Identity::canonicalRow(['gid'=>'á'],false)==='á');
denied(fn()=>Identity::canonicalRow(false,false),'Group canonical identity is unavailable');
denied(fn()=>Identity::canonicalRow(['gid'=>'x'],['gid'=>'y']),'Group canonical identity is unavailable');
foreach ([['gid'=>''],['gid'=>3],['gid'=>'x','displayname'=>'x'],['gid'=>"bad\0id"],['gid'=>str_repeat('x',256)],['gid'=>"\xff"]] as $row) {denied(fn()=>Identity::canonicalRow($row,false),'Group canonical identity is malformed');}
check(Identity::captured('Canonical',['gid'=>'Canonical','epoch'=>str_repeat('e',64),'generation'=>str_repeat('a',64)])===['epoch'=>str_repeat('e',64),'generation'=>str_repeat('a',64)]);
denied(fn()=>Identity::captured('canonical',['gid'=>'Canonical','epoch'=>str_repeat('e',64),'generation'=>str_repeat('a',64)]),'Group canonical identity changed');
denied(fn()=>Identity::captured('Canonical',['epoch'=>str_repeat('e',64),'generation'=>str_repeat('a',64)]),'Group canonical identity changed');
echo json_encode(['ok'=>true,'checks'=>$n],JSON_THROW_ON_ERROR)."\n";
