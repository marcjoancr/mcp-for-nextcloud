<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCP\Cache {class CappedMemoryCache {public array $values=[];}}
namespace OCP {class Server {public static object $db;public static function get(string $class):object{return self::$db;}}}
namespace OCA\Guests {
 class UserBackend {private \OCP\Cache\CappedMemoryCache $cache;public static array $rows=[];public function __construct(private object $dbConn){$this->cache=new \OCP\Cache\CappedMemoryCache();}public function userExists(string $uid):bool{return $this->cache->values[$uid]??=isset(self::$rows[$uid]);}}
 class GuestManager {public function __construct(private UserBackend $userBackend){}}
 class GroupBackend {public function __construct(private GuestManager $guestManager,private string $groupName='guest_app'){}}
}
namespace {
require_once __DIR__.'/../src/Service/FreshGuestGroup.php';
use OCA\Forms\Mcp\Service\FreshGuestGroup;
use OCA\Guests\{GroupBackend,GuestManager,UserBackend};
final class FreshGuestGroupTest extends \PHPUnit\Framework\TestCase {
 private function boundary():FreshGuestGroup{return new FreshGuestGroup(array_fill_keys([GroupBackend::class,GuestManager::class,UserBackend::class],hash_file('sha256',__FILE__)));}
 protected function setUp():void{\OCP\Server::$db=new \stdClass();UserBackend::$rows=[];}
 public function testVirtualGuestGroupDoesNotBlockNonGuestAuthority():void{$b=new GroupBackend(new GuestManager(new UserBackend(\OCP\Server::$db)));$this->boundary()->assertAbsent($b,'actor');self::assertTrue(true);}
 public function testGuestMappingAppearingAfterCachedAbsenceStillRefuses():void{$u=new UserBackend(\OCP\Server::$db);self::assertFalse($u->userExists('actor'));$b=new GroupBackend(new GuestManager($u));$this->boundary()->assertAbsent($b,'actor');UserBackend::$rows=['actor'=>true];try{$this->boundary()->assertAbsent($b,'actor');self::fail('Guest authority admitted');}catch(\RuntimeException $e){self::assertSame('Forms guest authority is unqualified',$e->getMessage());}self::assertFalse($u->userExists('actor'));}
 public function testConfiguredAdministrativeGroupNameRefuses():void{$this->expectException(\RuntimeException::class);$this->boundary()->assertAbsent(new GroupBackend(new GuestManager(new UserBackend(\OCP\Server::$db)),'admin'),'actor');}
 public function testAlteredGuestSourceRefuses():void{$this->expectException(\RuntimeException::class);(new FreshGuestGroup([GroupBackend::class=>str_repeat('0',64)]))->assertAbsent(new GroupBackend(new GuestManager(new UserBackend(\OCP\Server::$db))),'actor');}
 public function testDifferentGuestDatabaseRefuses():void{$this->expectException(\RuntimeException::class);$this->boundary()->assertAbsent(new GroupBackend(new GuestManager(new UserBackend(new \stdClass()))),'actor');}
}
}
