<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
spl_autoload_register(static function(string $class):void {
    $base=dirname(__DIR__);
    if (str_starts_with($class,'OCP\\')) {$file=$base.'/references/lib__public__'.str_replace('\\','__',substr($class,4)).'.php';}
    elseif (str_starts_with($class,'OC\\')) {$file=$base.'/references/lib__private__'.str_replace('\\','__',substr($class,3)).'.php';}
    elseif (str_starts_with($class,'OCA\\Forms\\Mcp\\Service\\')) {$file=$base.'/src/'.substr($class,strrpos($class,'\\')+1).'.php';}
    else {return;}
    if (is_file($file)) {require_once $file;}
});
final class NativeDuplicateTest extends TestCase {
    private function share(string $id,int $type=0,string $recipient='recipient',string $owner='owner'):\OCP\Share\IShare {
        $s=$this->createMock(\OCP\Share\IShare::class);$s->method('getFullId')->willReturn('ocinternal:'.$id);$s->method('getShareType')->willReturn($type);$s->method('getSharedWith')->willReturn($recipient);$s->method('getShareOwner')->willReturn($owner);$s->method('getSharedWithDisplayName')->willReturn('synthetic');return $s;
    }
    private function runNative(int $type,array $existing,\OCP\Share\IShare $share,bool $member=false):void {
        $node=$this->createMock(\OCP\Files\Folder::class);$node->method('getName')->willReturn('synthetic');$share->method('getNode')->willReturn($node);
        $provider=new \OCA\Forms\Mcp\Service\FinalShareReadProvider($node,$existing,static function():void{});
        $manager=(new ReflectionClass(\OC\Share20\Manager::class))->newInstanceWithoutConstructor();
        $users=$this->createMock(\OCP\IUserManager::class);$users->method('get')->willReturn($this->createMock(\OCP\IUser::class));
        $group=$this->createMock(\OCP\IGroup::class);$group->method('inGroup')->willReturn($member);
        $groups=$this->createMock(\OCP\IGroupManager::class);$groups->method('get')->willReturn($group);
        $l=$this->createMock(\OCP\IL10N::class);$l->method('t')->willReturnArgument(0);
        foreach (['factory'=>new \OCA\Forms\Mcp\Service\FinalShareReadFactory($provider,$type),'userManager'=>$users,'groupManager'=>$groups,'l'=>$l] as $name=>$value) {(new ReflectionProperty($manager,$name))->setValue($manager,$value);}
        try {(new ReflectionMethod($manager,$type===0?'userCreateChecks':'groupCreateChecks'))->invoke($manager,$share);}finally{$provider->close();}
    }
    public function testExactSelfIdentityIsIgnoredByNativeRule():void {$this->runNative(0,[$this->share('1')],$this->share('1'));self::assertTrue(true);}
    public function testDistinctNativeUserDuplicateIsRejected():void {
        $this->expectException(\OCP\Share\Exceptions\AlreadySharedException::class);$this->expectExceptionMessage('Sharing %s failed, because this item is already shared with the account %s');
        $this->runNative(0,[$this->share('2')],$this->share('1'));
    }
    public function testDistinctNativeGroupDuplicateIsRejected():void {
        $this->expectException(\OCP\Share\Exceptions\AlreadySharedException::class);$this->expectExceptionMessage('Path is already shared with this group');
        $this->runNative(1,[$this->share('2',1)],$this->share('1',1));
    }
    public function testNativeGroupMembershipDuplicateAcrossOwnerIsRejected():void {
        $this->expectException(\OCP\Share\Exceptions\AlreadySharedException::class);$this->expectExceptionMessage('Sharing %s failed, because this item is already shared with the account %s');
        $this->runNative(0,[$this->share('2',1,'group','different-owner')],$this->share('1'),true);
    }
    public function testSameOwnerNativeGroupMembershipRemainsAllowed():void {$this->runNative(0,[$this->share('2',1,'group')],$this->share('1'),true);self::assertTrue(true);}
    public function testNonmemberNativeGroupRemainsAllowed():void {$this->runNative(0,[$this->share('2',1,'group','different-owner')],$this->share('1'),false);self::assertTrue(true);}
    public function testReadProjectionCannotBeReplayed():void {
        $node=$this->createMock(\OCP\Files\Folder::class);$p=new \OCA\Forms\Mcp\Service\FinalShareReadProvider($node,[],static function():void{});
        self::assertSame([],$p->getSharesByPath($node));$this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final sharing duplicate projection escaped');$p->getSharesByPath($node);
    }
    public function testClosedProjectionCannotReturnInputs():void {
        $node=$this->createMock(\OCP\Files\Folder::class);$p=new \OCA\Forms\Mcp\Service\FinalShareReadProvider($node,[],static function():void{});$p->close();
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('Final sharing duplicate projection escaped');$p->getSharesByPath($node);
    }
    public function testFailedFramePoisonsTheReadProjectionBeforeCallbacks():void {
        $node=$this->createMock(\OCP\Files\Folder::class);$calls=0;
        $p=new \OCA\Forms\Mcp\Service\FinalShareReadProvider($node,[],static function()use(&$calls):void{++$calls;throw new RuntimeException('revoked');});
        $message=null;try{$p->getSharesByPath($node);}catch(RuntimeException $e){$message=$e->getMessage();}self::assertSame('revoked',$message);
        $message=null;try{$p->getSharesByPath($node);}catch(RuntimeException $e){$message=$e->getMessage();}self::assertSame('Final sharing duplicate projection escaped',$message);self::assertSame(1,$calls);
    }

}
