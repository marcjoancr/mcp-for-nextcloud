<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
// Pure one-shot state/exception fixture: all graph lookups are traps and no constructor runs.
eval('namespace OCP; final class Server {public static int $calls=0;public static function get(string $id):never{++self::$calls;throw new \\RuntimeException("root trap");}}');
require dirname(__DIR__).'/src/FinalTransferShareCheck.php';
require dirname(__DIR__).'/src/FinalShareBudget.php';
use OCA\Forms\Mcp\Service\{FinalTransferShareCheck as Check,FinalShareBudget as Budget};
$n=0;
function check(bool $ok):void {global $n;if(!$ok)throw new RuntimeException('test failed');++$n;}
function denied(Closure $f,string $expected):void {$m=null;try{$f();}catch(RuntimeException $e){$m=$e->getMessage();}check($m===$expected);}
function state():Check{return(new ReflectionClass(Check::class))->newInstanceWithoutConstructor();}
function set(Check $c,string $field,mixed $value):void{(new ReflectionProperty(Check::class,$field))->setValue($c,$value);}
$c=state();denied(fn()=>$c->collect(new Budget()),'Final sharing collector escaped or repeated');denied(fn()=>$c->resolve(),'Final sharing collector escaped or repeated');check(\OCP\Server::$calls===0);
$c=state();set($c,'sealed',true);$calls=0;set($c,'fresh',static function()use(&$calls):void{++$calls;throw new RuntimeException('revoked');});
denied(fn()=>$c->collect(new Budget()),'revoked');check($calls===1);denied(fn()=>$c->collect(new Budget()),'Final sharing collector escaped or repeated');check($calls===1);denied(fn()=>$c->resolve(),'Final sharing collector escaped or repeated');check(\OCP\Server::$calls===0);
$c=state();set($c,'collected',true);denied(fn()=>$c->renewViews(new Budget()),'root trap');check(\OCP\Server::$calls===1);denied(fn()=>$c->renewViews(new Budget()),'Final sharing policy projection is unavailable');check(\OCP\Server::$calls===1);denied(fn()=>$c->resolve(),'Final sharing collector escaped or repeated');
echo json_encode(['ok'=>true,'checks'=>$n,'actual_graph_calls'=>0,'synthetic_lookup_traps'=>\OCP\Server::$calls],JSON_THROW_ON_ERROR)."\n";
