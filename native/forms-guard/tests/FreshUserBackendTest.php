<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCP\Cache {class CappedMemoryCache {private array $values=[];public function get(string $key):mixed{return $this->values[$key]??null;}public function set(string $key,mixed $value):void{$this->values[$key]=$value;}}}
namespace OCP {class Server {public static object $db;public static function get(string $class):object{return self::$db;}}}
namespace OC\User {class Database {private string $table='users';private \OCP\Cache\CappedMemoryCache $cache;public function __construct(){ $this->cache=new \OCP\Cache\CappedMemoryCache();}public function userExists(string $uid):bool{return true;}}}
namespace OCA\UserOIDC\Db {
 class UserMapper {private string $tableName='user_oidc';private \OCP\Cache\CappedMemoryCache $userCache;public static array $rows=[];public function __construct(private object $db){$this->userCache=new \OCP\Cache\CappedMemoryCache();}public function userExists(string $uid):bool{if($this->userCache->get($uid))return true;if(!isset(self::$rows[$uid]))return false;$this->userCache->set($uid,true);return true;}}
}
namespace OCA\UserOIDC\User {class Backend {public function __construct(private \OCA\UserOIDC\Db\UserMapper $userMapper){}public function userExists(string $uid):bool{return $this->userMapper->userExists($uid);}}}
namespace {
require_once __DIR__.'/../src/Service/FreshUserBackend.php';
use OCA\Forms\Mcp\Service\FreshUserBackend;
use OCA\UserOIDC\{User\Backend,Db\UserMapper};
final class FreshUserBackendTest extends \PHPUnit\Framework\TestCase {
 private function boundary():FreshUserBackend {return new FreshUserBackend([Backend::class=>hash_file('sha256',__FILE__),UserMapper::class=>hash_file('sha256',__FILE__)]);}
 protected function setUp():void{\OCP\Server::$db=new \stdClass();UserMapper::$rows=['actor'=>true];}
 public function testProvisionedOidcAccountHasFreshIndependentBackend():void{$b=new Backend(new UserMapper(\OCP\Server::$db));$fresh=$this->boundary()->forUser($b,'actor');self::assertNotSame($b,$fresh);self::assertTrue($fresh->userExists('actor'));}
 public function testMappingRemovalIsObservedWithoutChangingWarmedOriginal():void{$b=new Backend(new UserMapper(\OCP\Server::$db));self::assertTrue($b->userExists('actor'));$this->boundary()->forUser($b,'actor');UserMapper::$rows=[];try{$this->boundary()->forUser($b,'actor');self::fail('Removed OIDC account admitted');}catch(\RuntimeException $e){self::assertSame('Forms principal is unavailable',$e->getMessage());}self::assertTrue($b->userExists('actor'));}
 public function testUnknownBackendRefuses():void{$this->expectException(\RuntimeException::class);$this->boundary()->forUser(new \stdClass(),'actor');}
 public function testAlteredOidcSourceRefuses():void{$p=new FreshUserBackend([Backend::class=>str_repeat('0',64),UserMapper::class=>hash_file('sha256',__FILE__)]);$this->expectException(\RuntimeException::class);$p->forUser(new Backend(new UserMapper(\OCP\Server::$db)),'actor');}
 public function testNonPrimaryMapperRefuses():void{$this->expectException(\RuntimeException::class);$this->boundary()->forUser(new Backend(new UserMapper(new \stdClass())),'actor');}
 public function testLocalDatabaseBackendRemainsSupported():void{self::assertInstanceOf(\OC\User\Database::class,$this->boundary()->forUser(new \OC\User\Database(),'actor'));}
}
}
