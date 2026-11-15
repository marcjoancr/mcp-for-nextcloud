<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
final class FenceOriginStub {public static function active():bool{return true;}public static function assertFresh():void{}}
final class FenceDeadlineStub {public static function check():void{}}
final class FenceServerStub {public static function get(string$class):object{return new class{public function drain():void{}};}}
final class FenceDatabaseStub {
 public bool $transaction=false;public string $owner='alice';private string $before='alice';public array $events=[];
 public function getDatabaseProvider(bool$fresh=true):string{return 'postgres';}public function inTransaction():bool{return $this->transaction;}
 public function beginTransaction():void{$this->transaction=true;$this->before=$this->owner;$this->events[]='begin';}
 public function executeStatement(string$sql):int{$this->events[]='sql';return 1;}
 public function commit():void{$this->events[]='commit';$this->transaction=false;}
 public function rollBack():void{$this->owner=$this->before;$this->events[]='rollback';$this->transaction=false;}
}
final class FormsPreCommitFenceTest extends TestCase {
 private function fixture():object {
  $p=__DIR__.'/../src/Service/FormsRevisionFence.php';
  $tree=(new \PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse(file_get_contents($p));$wanted=['active','afterTransaction','beforeCommit','transact'];$nodes=[];
  foreach((new \PhpParser\NodeFinder())->findInstanceOf($tree,\PhpParser\Node\Stmt\ClassMethod::class)as$m)if(in_array($m->name->toString(),$wanted,true)){$nodes[$m->name->toString()]=$m;}
  foreach($wanted as$n)self::assertArrayHasKey($n,$nodes,'The actual fence has no final validation hook.');
  $nodes['transact']->flags=\PhpParser\Modifiers::PUBLIC;
  $source=(new \PhpParser\PrettyPrinter\Standard())->prettyPrint(array_values($nodes));$source=str_replace(['OriginContext::','BulkDeadline::','Server::'],['FenceOriginStub::','FenceDeadlineStub::','FenceServerStub::'],$source);
  static$serial=0;$name='FormsFenceHarness'.(++$serial);
  eval('class '.$name.' {public function __construct(public object $db){}private int $depth=0;private array $transactionCallbacks=[];private array $resultSourcesTouched=[];private array $linkedSourcesTouched=[];private ?Closure $beforeCommitCheck=null;private bool $checkingBeforeCommit=false;public function assertSession():void{}'.$source.'}');
  return new $name(new FenceDatabaseStub());
 }
 public function testLatePolicyWithdrawalRollsBackOwnerAndReleasesLocks():void {
  $f=$this->fixture();$revoked=false;$released=[];$denied=false;
  try{$f->transact(function()use($f,&$revoked,&$released):void{
   $f->beforeCommit(static function()use(&$revoked):void{if($revoked)throw new RuntimeException('Late scope withdrawal');});
   $f->afterTransaction(static function(bool$committed)use(&$released):void{$released[]=$committed;});
   $f->db->owner='bob';$f->db->events[]='owner';$revoked=true;$f->db->events[]='late receipt wait';
  });}catch(RuntimeException$e){$denied=$e->getMessage()==='Late scope withdrawal';}
  self::assertTrue($denied);self::assertSame('alice',$f->db->owner);self::assertSame([false],$released);self::assertNotContains('commit',$f->db->events);
 }
 public function testValidationSeesAllNestedWorkAndPrecedesCommit():void {
  $f=$this->fixture();$seen=[];
  $f->transact(function()use($f,&$seen):void{$f->beforeCommit(static function()use($f,&$seen):void{$seen[]=$f->db->owner;$f->db->events[]='final validation';});$f->transact(function()use($f):void{$f->db->owner='bob';});$f->db->owner='carol';$f->db->events[]='final bookkeeping';});
  self::assertSame(['carol'],$seen);self::assertSame(['final bookkeeping','final validation','commit'],array_slice($f->db->events,-3));
 }
 public function testFailedWorkDiscardsUnrunGuardBeforeNextTransaction():void {
  $f=$this->fixture();$calls=0;
  try{$f->transact(function()use($f,&$calls):void{$f->beforeCommit(static function()use(&$calls):void{$calls++;throw new RuntimeException('Stale guard');});throw new LogicException('Work failed');});}catch(LogicException){}
  $f->transact(function()use($f):void{$f->db->owner='bob';});self::assertSame(0,$calls);self::assertSame('bob',$f->db->owner);
 }
 public function testGuardRejectsOutsideDuplicateAndRecursiveRegistration():void {
  $f=$this->fixture();$outside=false;try{$f->beforeCommit(static function():void{});}catch(LogicException){$outside=true;}self::assertTrue($outside);
  $f->transact(function()use($f):void{$f->beforeCommit(function()use($f):void{$denied=false;try{$f->beforeCommit(static function():void{});}catch(LogicException){$denied=true;}self::assertTrue($denied);});$denied=false;try{$f->beforeCommit(static function():void{});}catch(LogicException){$denied=true;}self::assertTrue($denied);});
 }
}
