<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\People;
use OCA\Mcp\Modules\People\Circles\CirclesDirectory;
use OCP\App\IAppManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class CirclesDirectoryTest extends TestCase {
    private function user(string $uid): IUser { $user=$this->createMock(IUser::class);$user->method('getUID')->willReturn($uid);$user->method('isEnabled')->willReturn(true);return $user; }
    private function member(int $type,string $id,string $name,int $level,bool $local=true): object {
        return new class($type,$id,$name,$level,$local) {
            public function __construct(private int $type,private string $id,private string $name,private int $level,private bool $local) {}
            public function getLevel():int{return $this->level;} public function getUserType():int{return $this->type;}
            public function getUserId():string{return $this->id;} public function getDisplayName():string{return $this->name;} public function isLocal():bool{return $this->local;}
        };
    }
    private function directory(object $native, array $users, ?object &$probe=null, ?object &$dataProbe=null, ?IAppManager $apps=null): CirclesDirectory {
        $actor=$this->user('actor');$session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn($actor);
        $app=$apps??$this->createMock(IAppManager::class);$app->method('isEnabledForUser')->with('circles',$actor)->willReturn(true);
        $manager=static fn():object=>$native;$probeFactory=function() use (&$probe):object { $probe=new class {public bool $hiddenFiltered=false;public bool $singleFiltered=false;public bool $memberRequired=false;public function filterHiddenCircles():self{$this->hiddenFiltered=true;return $this;}public function filterSingleCircles():self{$this->singleFiltered=true;return $this;}public function mustBeMember():self{$this->memberRequired=true;return $this;}};return $probe;};
        $userManager=$this->createMock(IUserManager::class);$userManager->method('get')->willReturnCallback(fn(string $uid):?IUser=>$users[$uid]??null);
        $dataProbeFactory=function() use (&$dataProbe):object { return $dataProbe=new \stdClass(); };
        return new CirclesDirectory($app,$userManager,$session,$manager,$probeFactory,$dataProbeFactory,$this->createMock(LoggerInterface::class));
    }
    public function testLoadsEnabledLazyCirclesAppBeforeResolvingNativeManager():void {
        $actor=$this->user('actor');$loaded=false;
        $apps=$this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->with('circles',$actor)->willReturn(true);
        $apps->expects(self::once())->method('loadApp')->with('circles')->willReturnCallback(static function () use (&$loaded): void { $loaded=true; });
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn($actor);
        $manager=static function () use (&$loaded): object {
            self::assertTrue($loaded, 'Circles must load before resolving its lazy manager service.');
            return new class {
                public function getLocalFederatedUser(string $uid):object{return (object)['uid'=>$uid];}
                public function startSession(object $user):void{}
                public function probeCircles(object $probe):array{return [];}
                public function stopSession():void{}
            };
        };
        $directory=new CirclesDirectory($apps,$this->createMock(IUserManager::class),$session,$manager,
            static fn():object=>new class {public function filterHiddenCircles():self{return $this;}public function filterSingleCircles():self{return $this;}public function mustBeMember():self{return $this;}},
            static fn():object=>new \stdClass(),$this->createMock(LoggerInterface::class));
        self::assertSame(['teams'=>[],'truncated'=>false],$directory->listTeams('actor','',10));
    }
    public function testListsOnlyNativeVisibleTeamsAndClosesActorSession():void {
        $circle=static fn(string $id,string $name):object=>new class($id,$name) {public function __construct(private string $id,private string $name){}public function getSingleId():string{return $this->id;}public function getName():string{return $this->name;}public function getDisplayName():string{return $this->name;}};
        $native=new class($this->user('actor'),[$circle('visible','Project Alpha'),$circle('hidden','Secret Team')]) {public bool $stopped=false;public function __construct(private IUser $actor,private array $circles){}public function getLocalFederatedUser(string $uid):object{return (object)['uid'=>$uid];}public function startSession(object $user):void{}public function probeCircles(object $probe):array{return array_slice($this->circles,0,1);}public function stopSession():void{$this->stopped=true;}};
        $probe=null;$directory=$this->directory($native,[],$probe);
        self::assertSame(['teams'=>[['id'=>'visible','name'=>'Project Alpha','displayName'=>'Project Alpha']],'truncated'=>false],$directory->listTeams('actor','Project',10));
        self::assertTrue($probe->hiddenFiltered);self::assertTrue($probe->singleFiltered);self::assertTrue($probe->memberRequired);self::assertTrue($native->stopped);
    }
    public function testListsDirectMembersWithRolesAndNeverTreatsGroupsOrRemoteIdsAsLocalUsers():void {
        $members=[$this->member(1,'moderator','Morgan',4),$this->member(1,'member','Alex',1),$this->member(1,'gone','Removed',1),$this->member(2,'group-uid','Sales',1),$this->member(16,'nested-circle-id','Nested',1),$this->member(1,'remote@example.test','Remote',1,false)];
        $circle=new class($members) {public function __construct(private array $members){}public function getSingleId():string{return 'team-1';}public function getName():string{return 'Team';}public function getDisplayName():string{return 'Team';}public function isLocal():bool{return true;}public function getMembers():array{return $this->members;}};
        $native=new class($circle) {public bool $stopped=false;public ?object $receivedDataProbe=null;public function __construct(private object $circle){}public function getLocalFederatedUser(string $uid):object{return (object)['uid'=>$uid];}public function startSession(object $user):void{}public function probeCircle(string $id,object $probe,object $dataProbe):object{$this->receivedDataProbe=$dataProbe;return $id==='team-1'?$this->circle:throw new \RuntimeException('missing');}public function stopSession():void{$this->stopped=true;}};
        $probe=null;$dataProbe=null;$directory=$this->directory($native,['moderator'=>$this->user('moderator'),'member'=>$this->user('member')],$probe,$dataProbe);
        self::assertSame(['team'=>['id'=>'team-1','name'=>'Team','displayName'=>'Team'],'members'=>[
            ['type'=>'user','uid'=>'moderator','displayName'=>'Morgan','role'=>'moderator','membership'=>'direct'],
            ['type'=>'user','uid'=>'member','displayName'=>'Alex','role'=>'member','membership'=>'direct'],
            ['type'=>'group','displayName'=>'Sales','role'=>'member','membership'=>'direct'],
            ['type'=>'circle','displayName'=>'Nested','role'=>'member','membership'=>'direct'],
            ['type'=>'remote_user','displayName'=>'Remote','role'=>'member','membership'=>'direct'],
        ],'truncated'=>false],$directory->listMembers('actor','team-1',50));
        self::assertSame($dataProbe,$native->receivedDataProbe);self::assertTrue($probe->hiddenFiltered);self::assertTrue($probe->memberRequired);self::assertTrue($native->stopped);
    }
    public function testDisabledCirclesFailsBeforeResolvingNativeManager():void {
        $apps=$this->createMock(IAppManager::class);$apps->method('isEnabledForUser')->willReturn(false);$actor=$this->user('actor');$session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn($actor);
        $directory=new CirclesDirectory($apps,$this->createMock(IUserManager::class),$session,static fn():object=>throw new \RuntimeException('must not resolve'),static fn():object=>new \stdClass(),static fn():object=>new \stdClass(),$this->createMock(LoggerInterface::class));
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$directory->listTeams('actor','',10);
    }
}
