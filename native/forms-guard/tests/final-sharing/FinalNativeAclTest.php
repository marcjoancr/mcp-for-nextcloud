<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
spl_autoload_register(static function(string $class):void {
 $base=dirname(__DIR__).'/references/';
 $file=str_starts_with($class,'OCP\\')?$base.'lib__public__'.str_replace('\\','__',substr($class,4)).'.php':(str_starts_with($class,'OC\\')?$base.'lib__private__'.str_replace('\\','__',substr($class,3)).'.php':null);
 if($file!==null&&is_file($file))require_once $file;
});
require dirname(__DIR__).'/src/FinalNativeAcl.php';
use OCA\Forms\Mcp\Service\FinalNativeAcl as Acl;
final class FinalNativeAclTest extends TestCase {
 private function node(array|\OCP\Files\Cache\ICacheEntry $data):\OC\Files\Node\Folder {
  $info=(new ReflectionClass(\OC\Files\FileInfo::class))->newInstanceWithoutConstructor();(new ReflectionProperty($info,'data'))->setValue($info,$data);
  $node=(new ReflectionClass(\OC\Files\Node\Folder::class))->newInstanceWithoutConstructor();(new ReflectionProperty(\OC\Files\Node\Node::class,'fileInfo'))->setValue($node,$info);return $node;
 }
 public function testInitializedNativeMetadataRetainsReadAndCreateSemantics():void {
  Acl::readable($this->node(['permissions'=>5]),true);Acl::readable($this->node(new \OC\Files\Cache\CacheEntry(['permissions'=>5])),true);self::assertTrue(true);
 }
 public function testCanonicalDatabaseNumericStringsRetainNativeCast():void {Acl::readable($this->node(['permissions'=>'5']),true);self::assertTrue(true);}
 public function testNativeMissingCreatePermissionRefuses():void {
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Transfer final native access changed');Acl::readable($this->node(['permissions'=>1]),true);
 }
 public function testNativeMissingReadPermissionRefuses():void {
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Transfer final native access changed');Acl::readable($this->node(['permissions'=>4]),true);
 }
 public function testUnknownCacheEntryCannotExecuteArrayAccess():void {
  $data=$this->createMock(\OCP\Files\Cache\ICacheEntry::class);$data->expects(self::never())->method('offsetGet');
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final native ACL cache is unqualified');Acl::initialized($this->node($data));
 }
 public function testMissingMetadataNeverHydratesNode():void {
  $node=(new ReflectionClass(\OC\Files\Node\Folder::class))->newInstanceWithoutConstructor();
  $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final native ACL metadata is not initialized');Acl::initialized($node);
 }
}
