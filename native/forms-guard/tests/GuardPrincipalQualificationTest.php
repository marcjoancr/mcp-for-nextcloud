<?php
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service {
 class QualificationState {public static bool $identity=true;public static bool $sharing=true;public static bool $schema=true;public static bool $user=true;public static string $provider='postgres';public static string $dbVersion='PostgreSQL 16.15 qualified';public static array $coreVersion=[35,0,2];public static array $config=[];public static int $sourceReads=0;public static int $optionalReads=0;public static array $principals=[];public static function reset():void{self::$identity=self::$sharing=self::$schema=self::$user=true;self::$provider='postgres';self::$dbVersion='PostgreSQL 16.15 qualified';self::$coreVersion=[35,0,2];self::$config=[];self::$sourceReads=self::$optionalReads=0;self::$principals=[];}}
 class SharingIdentity {public static function verify():bool{return QualificationState::$sharing;}}
 class OriginContext {public static function active():bool{return false;}}
 class CircleRuntime {public static function available(string $uid):bool{QualificationState::$optionalReads++;return false;}}
 class BulkStore {public static function ready():bool{QualificationState::$optionalReads++;return false;}}
 class HistoricalFiles {public const FORMAT=2;public static function ready():bool{QualificationState::$optionalReads++;return false;}}
 class AuthoringScale {public const ACTIONS=[];}class NativeActions {public const WRITES=[];}
 class FreshCore {public function __construct(private string $uid){}public function assertPrincipal(bool $admin=false):void{QualificationState::$principals[]=[$this->uid,$admin];if(!QualificationState::$user)throw new \RuntimeException('revoked');}}
}
namespace OCA\Forms\Mcp {class SourceIdentity {public static function verify():bool{Service\QualificationState::$sourceReads++;return Service\QualificationState::$identity;}}}
namespace OCA\Forms\Mcp\Comments {class CommentsRuntime {public static function approved():bool{\OCA\Forms\Mcp\Service\QualificationState::$optionalReads++;return false;}}}
namespace OCP {class Server {public static function get(string $class):object{return match($class){'OCP\\IDBConnection'=>new class {public function getDatabaseProvider(bool $primary):string{return \OCA\Forms\Mcp\Service\QualificationState::$provider;}public function executeQuery(string $sql,array $params=[]):object{return new class($sql){public function __construct(private string $sql){}public function fetchOne():mixed{return str_contains($this->sql,'VERSION')?\OCA\Forms\Mcp\Service\QualificationState::$dbVersion:(\OCA\Forms\Mcp\Service\QualificationState::$schema?'installed':false);}};}},'OCP\\ServerVersion'=>new class {public function getVersion():array{return \OCA\Forms\Mcp\Service\QualificationState::$coreVersion;}},'OCP\\IConfig'=>new class {public function getSystemValue(string $key,mixed $default=null):mixed{return \OCA\Forms\Mcp\Service\QualificationState::$config[$key]??$default;}},'OCP\\IUserSession'=>new class {public function getUser():object{return new class {public function getUID():string{return'actor';}};}},default=>throw new \RuntimeException('Unexpected service')};}}}
namespace {
require_once __DIR__.'/../src/Guard.php';
use OCA\Forms\Mcp\{Guard,Service\QualificationState as State};
final class GuardPrincipalQualificationTest extends \PHPUnit\Framework\TestCase {
 private Guard $guard;
 protected function setUp():void{State::reset();$this->guard=(new ReflectionClass(Guard::class))->newInstanceWithoutConstructor();}
 public function testPrincipalDoesNotCalculateUnusedOptionalCapabilities():void{$this->guard->assertPrincipal('actor',true);self::assertSame([['actor',true]],State::$principals);self::assertSame(0,State::$optionalReads);self::assertSame(1,State::$sourceReads);}
 public function testRequestedCapabilitiesStillReportOptionalAvailability():void{$c=$this->guard->capabilities();self::assertTrue($c['qualified']);self::assertFalse($c['comments']);self::assertSame([0,1],$c['files_share_types']);self::assertGreaterThan(0,State::$optionalReads);}
 public function testEveryPrincipalCheckStillReadsSourcesAndCurrentUser():void{$this->guard->assertPrincipal('actor');$this->guard->assertPrincipal('actor');self::assertSame(2,State::$sourceReads);self::assertCount(2,State::$principals);self::assertSame(0,State::$optionalReads);}
 public function testAllBaselineQualificationsStillDeny():void{$cases=[static fn()=>State::$identity=false,static fn()=>State::$sharing=false,static fn()=>State::$schema=false,static fn()=>State::$coreVersion=[35,0,3],static fn()=>State::$dbVersion='PostgreSQL 16.14 invalid',static fn()=>State::$provider='sqlite',static fn()=>State::$config=['filelocking.enabled'=>false],static fn()=>State::$config=['memcache.locking'=>'unsupported'],static fn()=>State::$config=['filelocking.excluded'=>['excluded']],static fn()=>State::$config=['filelocking.debug'=>true],static fn()=>State::$config=['dbreplica'=>['configured']]];foreach($cases as$mutate){State::reset();$mutate();$denied=false;try{$this->guard->assertPrincipal('actor');}catch(RuntimeException){$denied=true;}self::assertTrue($denied);self::assertSame([],State::$principals);}}
 public function testUserRevocationStillPreventsPrincipalAdmission():void{State::$user=false;$this->expectException(RuntimeException::class);$this->guard->assertPrincipal('actor');}
 public function testMariaDbQualificationStillAdmitsCurrentPrincipal():void{State::$provider='mariadb';State::$dbVersion='10.11.19-MariaDB qualified';$this->guard->assertPrincipal('actor');self::assertSame([['actor',false]],State::$principals);self::assertSame(0,State::$optionalReads);}
}
}
