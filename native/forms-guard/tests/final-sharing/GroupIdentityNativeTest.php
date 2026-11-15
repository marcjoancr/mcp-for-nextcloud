<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
spl_autoload_register(static function(string $class):void {
 $prefix=str_starts_with($class,'OCP\\')?'lib__public__':(str_starts_with($class,'OC\\')?'lib__private__':null);
 if($prefix!==null){$file=dirname(__DIR__).'/references/'.$prefix.str_replace('\\','__',substr($class,$prefix==='lib__public__'?4:3)).'.php';if(is_file($file))require_once$file;}
},true,true);
require dirname(__DIR__).'/standalone/GroupIdentity.php';
final class GroupIdentityNativeTest extends TestCase {
 public function testExactNativeNormalizerPreservesShortNamesAndHashesLongUnicode():void {
  $backend=(new ReflectionClass(\OC\Group\Database::class))->newInstanceWithoutConstructor();
  foreach(['synthetic','é','漢',str_repeat('é',64),str_repeat('漢',64)]as$name)self::assertSame($name,\McpFormsServerExtension\GroupIdentity::creation($backend,$name));
  foreach([str_repeat('a',65),str_repeat('é',65),str_repeat('漢',255)]as$name)self::assertSame(hash('sha256',$name),\McpFormsServerExtension\GroupIdentity::creation($backend,$name));
  self::assertFalse((new ReflectionProperty($backend,'dbConn'))->isInitialized($backend));
 }
 public function testUnknownBackendRefusesBeforeMethodLookupOrInvocation():void {
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Group creation backend is unqualified');\McpFormsServerExtension\GroupIdentity::creation(new stdClass(),'synthetic');
 }
 public function testAliasLookupBindsReturnedCanonicalRowAndRejectsDifferentLifetime():void {
  $canonical=\McpFormsServerExtension\GroupIdentity::canonicalRow(['gid'=>'Canonical'],false);
  $capture=['gid'=>$canonical,'epoch'=>str_repeat('e',64),'generation'=>str_repeat('a',64)];
  self::assertSame(['epoch'=>str_repeat('e',64),'generation'=>str_repeat('a',64)],\McpFormsServerExtension\GroupIdentity::captured($canonical,$capture));
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Group canonical identity changed');\McpFormsServerExtension\GroupIdentity::captured('canonical',$capture);
 }
}
