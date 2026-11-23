<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Calendar\Resources;

use DateTimeImmutable;
use OCA\Mcp\Modules\Calendar\Events\Classification;
use OCA\Mcp\Modules\Calendar\Events\EventBuilder;
use OCA\Mcp\Modules\Calendar\Events\EventRepository;
use OCA\Mcp\Modules\Calendar\Execution\CalendarArgumentException;
use OCA\Mcp\Modules\Calendar\Execution\CalendarException;
use OCA\Mcp\Modules\Calendar\Resources\CalendarResourceDirectory;
use OCA\Mcp\Modules\Calendar\Resources\ResourceReserver;
use OCA\Mcp\Modules\Calendar\Scheduling\Scheduling;
use OCA\Mcp\Modules\Calendar\Scheduling\AttendeeResolver;
use OCA\Mcp\Platform\Dav\CalDav\ResourceAwareCalDavWriter;
use OCA\Mcp\Platform\Dav\Transport\DavResult;
use OCA\Mcp\Tests\Support\Calendar\CalendarTestCase;
use OCP\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserSession;
use Sabre\DAVACL\PrincipalBackend\BackendInterface;
use Sabre\VObject\Reader;
use Sabre\VObject\ITip\Broker;

final class ResourceReserverTest extends CalendarTestCase
{
    public function testReservationUsesCutypeRoomAndReportsResourceStateSeparately(): void
    {
        $this->store->addObject(1,'meeting.ics',self::ics("UID:meeting\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid"));
        $this->store->addObject(1,'meeting-second.ics',self::ics("UID:meeting-second\nSUMMARY:Planning second\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid"));
        $path='principals/calendar-rooms/backend-roomA';
        $principal=['uri'=>$path,'{DAV:}displayname'=>'Conference room','{http://sabredav.org/ns}email-address'=>'room@example.invalid'];
        $backend=$this->createMock(BackendInterface::class);
        $backend->method('searchPrincipals')->willReturn([$path]);
        $backend->method('findByUri')->willReturn($path);
        $backend->method('getPrincipalByPath')->willReturn($principal);
        $user=$this->createMock(IUser::class); $user->method('getUID')->willReturn('alice');
        $session=$this->createMock(IUserSession::class); $session->method('getUser')->willReturn($user);
        $userManager=$this->createMock(\OCP\IUserManager::class);
        $userManager->method('get')->with('alice')->willReturn($user);
        $userManager->method('getByEmail')->willReturn([]);
        $directory=new CalendarResourceDirectory(fn()=>$backend,static fn()=>null,$session,$userManager);
        $user->method('isEnabled')->willReturn(true);
        $user->method('getEMailAddress')->willReturn('alice@example.invalid');
        $user->method('getDisplayName')->willReturn('Alice');
        $writer=$this->createMock(ResourceAwareCalDavWriter::class);
        $attempt=0;
        $writer->expects(self::exactly(2))->method('updateWithResourcePrincipals')->willReturnCallback(function(string $actor,string $calendar,string $uri,string $etag,string $ics,bool $scheduling) use (&$attempt): DavResult {
            self::assertSame('alice',$actor);
            self::assertSame('personal',$calendar);
            self::assertTrue($scheduling,'resource reservation always invokes the native scheduler');
            self::assertSame($this->store->objects[1][$uri]['etag'],$etag);
            self::assertStringContainsString('CUTYPE=ROOM',$ics);
            self::assertStringContainsString('mailto:room@example.invalid',$ics);
            ++$attempt;
            $calendarData=Reader::read($ics);
            foreach ($calendarData->VEVENT->select('ATTENDEE') as $attendee) {
                if ((string)$attendee==='mailto:room@example.invalid') {
                    $attendee['PARTSTAT']=$attempt===1?'ACCEPTED':'DECLINED';
                    $attendee['SCHEDULE-STATUS']=$attempt===1?'2.0;Accepted':'3.7;Declined';
                }
            }
            return new DavResult(204,$this->store->applyUpdate(1,$uri,$calendarData->serialize()));
        });
        $config=$this->createMock(IAppConfig::class);
        $config->method('getValueString')->willReturn('yes');
        $time=$this->createMock(ITimeFactory::class); $time->method('now')->willReturn(new DateTimeImmutable('2026-03-10T12:00:00Z'));
        $operation=new ResourceReserver(
            \OCA\Mcp\Tests\Support\Dav\ScopeStub::access($this->store,$this->scope),
            new \OCA\Mcp\Mcp\Confirmation\SharedCollectionNotice($this->createMock(\OCP\IUserManager::class)),
            $writer,$this->store,new EventRepository($this->store,new Classification()),$directory,new EventBuilder(),new Scheduling($config),$time,new AttendeeResolver($userManager),
        );
        $result=self::json($operation->execute(['calendar'=>self::PERSONAL,'uid'=>'meeting','type'=>'ROOM','resource'=>$path],'alice'));
        $competing=self::json($operation->execute(['calendar'=>self::PERSONAL,'uid'=>'meeting-second','type'=>'ROOM','resource'=>$path],'alice'));
        self::assertSame('ACCEPTED',$result['resourceBooking']['status']);
        self::assertSame('2.0;Accepted',$result['resourceBooking']['scheduleStatus']);
        self::assertSame('DECLINED',$competing['resourceBooking']['status']);
        self::assertSame('3.7;Declined',$competing['resourceBooking']['scheduleStatus']);
        self::assertSame('ACCEPTED',(string)Reader::read($this->store->objects[1]['meeting.ics']['data'])->VEVENT->select('ATTENDEE')[1]['PARTSTAT']);
        self::assertSame('DECLINED',(string)Reader::read($this->store->objects[1]['meeting-second.ics']['data'])->VEVENT->select('ATTENDEE')[1]['PARTSTAT']);
        self::assertSame('mailto:alice@example.invalid',(string)Reader::read($this->store->objects[1]['meeting-second.ics']['data'])->VEVENT->ORGANIZER);

        $this->store->addObject(1,'not-organizer.ics',self::ics("UID:not-organizer\nSUMMARY:Other owner\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:bob@example.invalid"));
        try { $operation->prepare(['calendar'=>self::PERSONAL,'uid'=>'not-organizer','type'=>'ROOM','resource'=>$path],'alice'); self::fail('only the event organizer may book a resource'); }
        catch (CalendarException) { self::assertTrue(true); }
        try { $operation->prepare(['calendar'=>self::PERSONAL,'uid'=>'meeting','type'=>'ROOM','resource'=>$path,'confirm'=>true],'alice'); self::fail('confirmed resource booking requires plan etag'); }
        catch (CalendarArgumentException) { self::assertTrue(true); }
    }

    public function testSabreBuildsAResourceSpecificRequestFromTheNativeAttendee(): void
    {
        $before=Reader::read(self::ics("UID:meeting\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid"));
        $after=Reader::read(self::ics("UID:meeting\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.invalid\nATTENDEE;CUTYPE=ROOM;PARTSTAT=NEEDS-ACTION:mailto:room@example.invalid"));
        $messages=(new Broker())->parseEvent($after,['mailto:alice@example.invalid'],$before);
        $roomMessages=array_values(array_filter($messages,static fn($message): bool => strtolower((string)$message->recipient)==='mailto:room@example.invalid'));
        self::assertCount(1,$roomMessages);
        self::assertSame('REQUEST',$roomMessages[0]->method);
        $roomAttendee=null;
        foreach($roomMessages[0]->message->VEVENT->select('ATTENDEE') as $attendee) if((string)$attendee==='mailto:room@example.invalid') $roomAttendee=$attendee;
        self::assertNotNull($roomAttendee);
        self::assertSame('ROOM',(string)$roomAttendee['CUTYPE']);
    }

    public function testExistingResourceAttendeesUseResourceAwareEditAndDeleteRoutes(): void
    {
        $this->store->addObject(1,'edit-room.ics',self::ics("UID:edit-room\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;CUTYPE=ROOM;PARTSTAT=ACCEPTED:mailto:room@example.invalid"));
        $this->store->addObject(1,'delete-room.ics',self::ics("UID:delete-room\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;CUTYPE=RESOURCE;PARTSTAT=ACCEPTED:mailto:projector@example.invalid"));

        self::json($this->call('calendar_update_event',['calendar'=>self::PERSONAL,'uid'=>'edit-room','summary'=>'Moved','send_invitations'=>true]));
        self::json($this->call('calendar_delete_event',['calendar'=>self::PERSONAL,'uid'=>'delete-room','send_invitations'=>true]));

        self::assertSame([
            ['operation'=>'update','calendarUri'=>'personal','objectUri'=>'edit-room.ics','scheduling'=>true],
            ['operation'=>'delete','calendarUri'=>'personal','objectUri'=>'delete-room.ics','scheduling'=>true],
        ],$this->dav->resourcePrincipalCalls);
    }

    public function testExternalEmailAliasCannotBeMixedWithResourceRoutingWithoutNativeCheck(): void
    {
        $this->store->addObject(1,'mixed.ics',self::ics("UID:mixed\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;CUTYPE=ROOM:mailto:room@example.invalid"));
        $result=$this->call('calendar_update_event',['calendar'=>self::PERSONAL,'uid'=>'mixed','add_attendee_emails'=>['guest@example.invalid'],'send_invitations'=>true]);
        self::assertTrue($result['isError']??false);
        self::assertSame([], $this->dav->resourcePrincipalCalls);
        self::assertCount(1,Reader::read($this->store->objects[1]['mixed.ics']['data'])->VEVENT->select('ATTENDEE'));
    }

    public function testAcceptedPartstatIsCopiedToChangedRequestAndIsNotEvidenceForNewSlot(): void
    {
        $before=Reader::read(self::ics("UID:meeting\nSUMMARY:Planning\nDTSTART:20260311T090000Z\nDTEND:20260311T100000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;CUTYPE=ROOM;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:mailto:room@example.invalid"));
        $after=Reader::read(self::ics("UID:meeting\nSUMMARY:Planning\nDTSTART:20260311T110000Z\nDTEND:20260311T120000Z\nORGANIZER:mailto:alice@example.invalid\nATTENDEE;CUTYPE=ROOM;PARTSTAT=ACCEPTED;SCHEDULE-STATUS=2.0:mailto:room@example.invalid"));
        $messages=(new Broker())->parseEvent($after,['mailto:alice@example.invalid'],$before);
        $room=array_values(array_filter($messages,static fn($message): bool => strtolower((string)$message->recipient)==='mailto:room@example.invalid'))[0]??null;
        self::assertNotNull($room);
        self::assertSame('REQUEST',$room->method);
        $attendee=$room->message->VEVENT->ATTENDEE;
        self::assertSame('ACCEPTED',(string)$attendee['PARTSTAT'],'Sabre retains the previous acceptance while scheduling a changed slot');
        self::assertSame('2.0',(string)$after->VEVENT->ATTENDEE['SCHEDULE-STATUS'],'the organizer copy still carries the prior delivery status');
        self::assertFalse(isset($attendee['SCHEDULE-STATUS']),'Sabre omits SCHEDULE-STATUS from the outgoing request');
    }
}
