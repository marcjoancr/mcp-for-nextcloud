<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/src/FinalShareCache.php';
use OCA\Forms\Mcp\Service\FinalShareCache as Cache;
$n=0;
function check(bool $v):void{global$n;if(!$v)throw new RuntimeException('assertion failed');++$n;}
function denied(Closure$f,string$expected):void{$m=null;try{$f();}catch(RuntimeException$e){$m=$e->getMessage();}check($m===$expected);}
$row=['fileid'=>5,'storage'=>8,'permissions'=>'17','mimetype'=>1,'mimepart'=>2];
check(Cache::canonicalRow($row,false,5,8)===$row);check(Cache::canonicalRow(false,false,5,8)===false);
denied(fn()=>Cache::canonicalRow($row,$row,5,8),'Final sharing cache row is ambiguous');
foreach([['fileid'=>6],['storage'=>9],['permissions'=>'17x'],['permissions'=>32],['permissions'=>null],['mimetype'=>0],['mimepart'=>-1]]as$change)denied(fn()=>Cache::canonicalRow(array_replace($row,$change),false,5,8),'Final sharing cache row is malformed');
denied(fn()=>Cache::canonicalRow(false,$row,5,8),'Final sharing cache row is ambiguous');
echo json_encode(['ok'=>true,'checks'=>$n],JSON_THROW_ON_ERROR)."\n";
