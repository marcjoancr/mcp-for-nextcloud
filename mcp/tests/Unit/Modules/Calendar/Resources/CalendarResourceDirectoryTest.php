<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar\Resources;

use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Resources\CalendarResourceDirectory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;

final class CalendarResourceDirectoryTest extends TestCase
{
    public function testSearchUsesActorFilteredDavSearchAndReturnsOnlyAllowlistedMetadata(): void
    {
        $path='principals/calendar-rooms/backend-roomA';
        $backend=$this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('searchPrincipals')->with('principals/calendar-rooms',['{DAV:}displayname'=>'Conference'],'allof')->willReturn([$path]);
        $backend->expects(self::once())->method('getPrincipalByPath')->with($path)->willReturn([
            'uri'=>$path,'{DAV:}displayname'=>'Conference room','{http://sabredav.org/ns}email-address'=>'room@example.invalid',
            '{http://nextcloud.com/ns}room-type'=>'meeting-room','{urn:private}secret'=>'private',
        ]);
        $directory=new CalendarResourceDirectory(fn()=>$backend,static fn()=>null,$this->session(),$this->users());
        self::assertSame([[
            'id'=>$path,'type'=>'ROOM','name'=>'Conference room','email'=>'room@example.invalid',
            'metadata'=>['{http://nextcloud.com/ns}room-type'=>'meeting-room'],
        ]],$directory->search('alice','ROOM','Conference'));
    }

    public function testExplicitLookupChecksAclSearchBeforeUnfilteredPrincipalLookup(): void
    {
        $path='principals/calendar-rooms/backend-roomA';
        $backend=$this->createMock(BackendInterface::class);
        $backend->expects(self::once())->method('searchPrincipals')->willReturn([]);
        $backend->expects(self::never())->method('findByUri');
        $directory=new CalendarResourceDirectory(fn()=>$backend,static fn()=>null,$this->session(),$this->users());
        try { $directory->resolve('alice','ROOM',$path); self::fail('hidden resource must be indistinguishable from missing'); }
        catch (CalendarException) { self::assertTrue(true); }
    }

    public function testExplicitVisibleLookupResolvesPrincipalUriThenReadsExactPath(): void
    {
        $path='principals/calendar-resources/proxy-projector';
        $backend=$this->createMock(BackendInterface::class);
        $backend->expects(self::exactly(2))->method('searchPrincipals')->willReturnCallback(static function(string $prefix,array $props,string $test) use($path): array {
            if (isset($props['{DAV:}displayname'])) return [$path];
            if (isset($props['{http://sabredav.org/ns}email-address'])) return [$path];
            return [];
        });
        $backend->expects(self::once())->method('findByUri')->with('principal:'.$path,'principals/calendar-resources')->willReturn($path);
        $backend->method('getPrincipalByPath')->with($path)->willReturn([
            'uri'=>$path,'{DAV:}displayname'=>'Projector','{http://sabredav.org/ns}email-address'=>'projector@example.invalid',
        ]);
        $directory=new CalendarResourceDirectory(static fn()=>null,fn()=>$backend,$this->session(),$this->users());
        self::assertSame('RESOURCE',$directory->resolve('alice','RESOURCE',$path)['type']);
    }

    public function testAddressSearchSubstringCannotChooseBetweenTwoVisibleResources(): void
    {
        $room='principals/calendar-rooms/backend-roomA';
        $resource='principals/calendar-resources/backend-roomA';
        $rooms=$this->createMock(BackendInterface::class);
        $resources=$this->createMock(BackendInterface::class);
        $rooms->method('searchPrincipals')->willReturnCallback(static fn(string $prefix,array $props): array => isset($props['{DAV:}displayname'])?[$room]:[$room]);
        $resources->method('searchPrincipals')->willReturnCallback(static fn(string $prefix,array $props): array => [$resource]);
        $rooms->method('findByUri')->willReturn($room);
        $rooms->method('getPrincipalByPath')->willReturn(['uri'=>$room,'{DAV:}displayname'=>'Room','{http://sabredav.org/ns}email-address'=>'shared@example.invalid']);
        $resources->method('getPrincipalByPath')->willReturn(['uri'=>$resource,'{DAV:}displayname'=>'Resource','{http://sabredav.org/ns}email-address'=>'shared@example.invalid']);
        $directory=new CalendarResourceDirectory(fn()=>$rooms,fn()=>$resources,$this->session(),$this->users());
        try { $directory->resolve('alice','ROOM',$room); self::fail('ambiguous email address must not be reserved'); }
        catch (CalendarException) { self::assertTrue(true); }
    }

    public function testExternalEmailAliasRejectsOnlyAnExactVisibleResourceAddress(): void
    {
        $path='principals/calendar-rooms/backend-roomA';
        $backend=$this->createMock(BackendInterface::class);
        $backend->method('searchPrincipals')->willReturnCallback(static fn(string $prefix,array $props): array => isset($props['{http://sabredav.org/ns}email-address']) && $props['{http://sabredav.org/ns}email-address']==='room@example.invalid' ? [$path] : []);
        $backend->method('getPrincipalByPath')->willReturn(['uri'=>$path,'{http://sabredav.org/ns}email-address'=>'room@example.invalid']);
        $directory=new CalendarResourceDirectory(fn()=>$backend,static fn()=>null,$this->session(),$this->users());
        try { $directory->assertNotResourceAddress('alice','room@example.invalid'); self::fail('resource email cannot go through external attendee alias'); }
        catch (CalendarArgumentException) { self::assertTrue(true); }
        $directory->assertNotResourceAddress('alice','guest@example.invalid');
    }

    private function session(): IUserSession
    {
        $user=$this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $session=$this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);
        return $session;
    }

    private function users(): \OCP\IUserManager
    {
        $users=$this->createMock(\OCP\IUserManager::class);
        $users->method('getByEmail')->willReturn([]);
        return $users;
    }
}
