<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\FormsLinkedAudience;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\Files\{IRootFolder,Folder,File};
use OCP\IUser;
use PHPUnit\Framework\TestCase;
final class FormsLinkedCircleAudienceTest extends TestCase {
    private SqliteDatabase $sql;
    private function audience(\Closure $resolve):FormsLinkedAudience {
        $root=$this->createMock(IRootFolder::class);$home=$this->createMock(Folder::class);$parent=$this->createMock(Folder::class);$file=$this->createMock(File::class);$user=$this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');$root->method('getUserFolder')->with('alice')->willReturn($home);$home->method('get')->willReturnCallback(static fn(string $path)=>$path==='exports'?$parent:$file);$home->method('nodeExists')->willReturn(true);
        foreach([[$home,1],[$parent,2],[$file,3]] as [$node,$id]){$node->method('getId')->willReturn($id);$node->method('getOwner')->willReturn($user);}
        $home->method('getPath')->willReturn('/alice/files');$parent->method('getPath')->willReturn('/alice/files/exports');$parent->method('getParent')->willReturn($home);
        return new FormsLinkedAudience($root,$this->sql->connection(),static fn():int=>1000,$resolve);
    }
    protected function setUp():void {
        $this->sql=new SqliteDatabase($this,[]);
        $this->sql->dbal->executeStatement('CREATE TABLE share (id INTEGER PRIMARY KEY, file_source INTEGER, share_type INTEGER, share_with TEXT, permissions INTEGER, expiration TEXT, token TEXT)');
        $this->sql->dbal->executeStatement('CREATE TABLE group_user (gid TEXT, uid TEXT)');
    }
    public function testCircleMembershipAndRoleDigestsChangeApprovalWithoutExposingRecipients():void {
        $revision=1;$calls=0;$resolve=static function(string $uid,string $circleId,int $shareId,int $fileId)use(&$revision,&$calls):array {$calls++;self::assertSame('alice',$uid);self::assertSame('circle-a',$circleId);self::assertSame(11,$shareId);self::assertSame(2,$fileId);return ['circle_id'=>'circle-a','parent_share_id'=>11,'file_id'=>2,'files_share_provider'=>'ocCircleShare','share_type'=>7,'circle_incarnation_digest'=>str_repeat('a',64),'native_membership_digest'=>hash('sha256','members-'.$revision),'native_recipient_projection_digest'=>hash('sha256','projection-'.$revision),'recipient_count'=>2];};
        $audience=$this->audience($resolve);$this->sql->dbal->executeStatement("INSERT INTO share VALUES (11,2,7,'circle-a',1,NULL,'secret-token')");
        $before=$audience->snapshot('alice','/exports/results.csv');$revision=2;$after=$audience->snapshot('alice','/exports/results.csv');
        self::assertNotSame($before['digest'],$after['digest']);self::assertSame(2,$calls);
        self::assertSame([['type'=>7,'recipient'=>'circle-a','permissions'=>1,'expires'=>null]],$before['recipients']);
        self::assertStringNotContainsString('member-',json_encode($before));self::assertStringNotContainsString('secret-token',json_encode($before));
    }
    public function testAggregateCircleRecipientOverflowRefusesInsteadOfTruncating():void {
        $audience=$this->audience(static fn():array=>['circle_incarnation_digest'=>str_repeat('a',64),'native_membership_digest'=>str_repeat('b',64),'native_recipient_projection_digest'=>str_repeat('c',64),'recipient_count'=>1001]);
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (11,2,7,'circle-a',1,NULL,NULL)");
        $this->expectException(\RuntimeException::class);$audience->snapshot('alice','/exports/results.csv');
    }
    public function testMissingNativeCircleResolverKeepsTypeSevenClosed():void {
        $audience=$this->audience(static fn():array=>throw new \RuntimeException('Native Circle provider is unavailable'));
        $this->sql->dbal->executeStatement("INSERT INTO share VALUES (11,2,7,'circle-a',1,NULL,NULL)");
        $this->expectException(\RuntimeException::class);$audience->snapshot('alice','/exports/results.csv');
    }
}
