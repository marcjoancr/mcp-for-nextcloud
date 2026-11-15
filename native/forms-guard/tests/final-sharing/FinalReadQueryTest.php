<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
spl_autoload_register(static function(string$class):void{
 $base=dirname(__DIR__);$file=null;
 if(str_starts_with($class,'OCP\\'))$file=$base.'/references/lib__public__'.str_replace('\\','__',substr($class,4)).'.php';
 elseif(str_starts_with($class,'OC\\'))$file=$base.'/references/lib__private__'.str_replace('\\','__',substr($class,3)).'.php';
 elseif(str_starts_with($class,'OCA\\Forms\\Mcp\\Service\\'))$file=$base.'/src/'.substr($class,strrpos($class,'\\')+1).'.php';
 if($file!==null&&is_file($file))require_once$file;
});
final class FinalReadQueryTest extends TestCase {
 public function testAlternateConnectionRefusesBeforeAnyNativeQuery():void {
  $q=(new ReflectionClass(\OCA\Forms\Mcp\Service\FinalReadQuery::class))->newInstanceWithoutConstructor();
  $alternate=$this->createMock(\OCP\IDBConnection::class);$alternate->expects(self::never())->method('executeQuery');
  $this->expectException(LogicException::class);$this->expectExceptionMessage('Final read alternate connection is closed');$q->executeQuery($alternate);
 }
 public function testQueryStatementWriteIsClosedWithoutDependencies():void {
  $q=(new ReflectionClass(\OCA\Forms\Mcp\Service\FinalReadQuery::class))->newInstanceWithoutConstructor();
  $this->expectException(LogicException::class);$this->expectExceptionMessage('Final read query writes are closed');$q->executeStatement();
 }
 public function testInheritedConnectionMutationAndEscapeMethodsAreClosedWithoutDependencies():void {
  $c=(new ReflectionClass(\OCA\Forms\Mcp\Service\FinalReadConnection::class))->newInstanceWithoutConstructor();
  foreach([['executeStatement',['DELETE FROM ignored']],['executeUpdate',['DELETE FROM ignored']],['getInner',[]],['prepare',['SELECT 1']],['beginTransaction',[]],['commit',[]],['rollBack',[]],['createSchema',[]],['getTypedQueryBuilder',[]],['getCrossShardMoveHelper',[]],['getShardDefinition',['s']],['connect',[]],['close',[]]]as[$method,$args]){
   $error=null;try{$c->$method(...$args);}catch(LogicException$e){$error=$e->getMessage();}self::assertSame('Final read connection method is closed',$error,$method);
  }
 }
 public function testNativeResultPreservesOrderAndScalarTypesThenCloses():void {
  $r=new \OCA\Forms\Mcp\Service\FinalReadResult([['uid'=>'0'],['uid'=>'a']],static function():void{});
  self::assertSame('0',$r->fetchOne());self::assertSame([['uid'=>'a']],$r->fetchAllAssociative());self::assertTrue($r->closeCursor());
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final native result is closed');$r->fetchAssociative();
 }
}
