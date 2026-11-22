<?php
declare(strict_types=1);
require_once __DIR__.'/../src/Service/FinalReadSet.php';
use OCA\Forms\Mcp\Service\{FinalReadSet,FinalReadMismatch};
final class FinalCircleInvitationsReadTest extends \PHPUnit\Framework\TestCase {
 public function testNativeInvitationsReadIsPartOfFinalSnapshot():void{$s=new FinalReadSet(static fn()=>null);$s->add('SELECT id,level FROM *PREFIX*circles_invitations WHERE circle_id=?',['synthetic-circle'],[],[['id'=>1,'level'=>4]]);self::assertSame(['circles_invitations'],$s->tables());$s->seal();$s->verify([['q'=>0,'row'=>'{"id":1,"level":4}']]);self::assertTrue(true);}
 public function testInvitationChangedBeforeCommitIsDenied():void{$s=new FinalReadSet(static fn()=>null);$s->add('SELECT id,level FROM *PREFIX*circles_invitations',[],[],[['id'=>1,'level'=>4]]);$s->seal();$this->expectException(FinalReadMismatch::class);$s->verify([['q'=>0,'row'=>'{"id":1,"level":0}']]);}
 public function testOtherCircleTablesAndWritesRemainClosed():void{$s=new FinalReadSet(static fn()=>null);foreach(['SELECT id FROM *PREFIX*circles_invitations_extra','DELETE FROM *PREFIX*circles_invitations','SELECT id FROM *PREFIX*circles_invitations FOR UPDATE']as$sql){$denied=false;try{$s->validate($sql,[],[]);}catch(RuntimeException){$denied=true;}self::assertTrue($denied);}}
}
