<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
require dirname(__DIR__).'/src/TransferExpiryInputs.php';
use OCA\Forms\Mcp\Service\TransferExpiryInputs as Expiry;
$n=0;
function check(bool $ok):void{global $n;if(!$ok)throw new RuntimeException('assertion failed');++$n;}
function denied(Closure $f,string $expected):void{$m=null;try{$f();}catch(RuntimeException $e){$m=$e->getMessage();}check($m===$expected);}
$policy=['timezone'=>'Europe/Madrid','config'=>['fixture'=>'fixed'],'timezone_inputs'=>['original'=>'fixed']];
$at=(new DateTimeImmutable('2026-11-15T12:00:00+01:00'))->getTimestamp();$capture=['policy'=>$policy,'at'=>$at];
Expiry::assertCurrent($capture,$policy,null,$at);check(true);
Expiry::assertCurrent($capture,$policy,'2026-11-15 00:00:00',$at);check(true);
Expiry::assertCurrent($capture,$policy,'2026-11-16 00:00:00',$at+86400);check(true);
denied(fn()=>Expiry::assertCurrent($capture,$policy,'2026-11-15 00:00:00',$at+86400),'Transfer activated expiration is in the past');
denied(fn()=>Expiry::assertCurrent($capture,$policy,null,$at-1),'Transfer activation expiration inputs changed');
$changed=$policy;$changed['timezone']='Pacific/Honolulu';denied(fn()=>Expiry::assertCurrent($capture,$changed,null,$at),'Transfer activation expiration inputs changed');
$changed=$policy;$changed['config']['fixture']='changed';denied(fn()=>Expiry::assertCurrent($capture,$changed,null,$at),'Transfer activation expiration inputs changed');
echo json_encode(['ok'=>true,'checks'=>$n],JSON_THROW_ON_ERROR)."\n";
