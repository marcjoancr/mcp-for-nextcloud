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
    private function audience(\Closure $resolve,?\Closure $deferred=null):FormsLinkedAudience {
        $root=$this->createMock(IRootFolder::class);$home=$this->createMock(Folder::class);$parent=$this->createMock(Folder::class);$file=$this->createMock(File::class);$user=$this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');$root->method('getUserFolder')->with('alice')->willReturn($home);$home->method('get')->willReturnCallback(static fn(string $path)=>$path==='exports'?$parent:$file);$home->method('nodeExists')->willReturn(true);
        foreach([[$home,1],[$parent,2],[$file,3]] as [$node,$id]){$node->method('getId')->willReturn($id);$node->method('getOwner')->willReturn($user);}
        $home->method('getPath')->willReturn('/alice/files');$parent->method('getPath')->willReturn('/alice/files/exports');$parent->method('getParent')->willReturn($home);
        return new FormsLinkedAudience($root,$this->sql->connection(),static fn():int=>1000,$resolve,$deferred);
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
    public function testDeferredCircleAudienceForwardsExactOriginalLinkProof():void {
        $origin=['uid'=>'alice','tool'=>'forms_link_results_file','connection'=>8,'references'=>['form_id'=>4,'owner_id'=>'alice']];
        $calls=0;$evidence=['circle_id'=>'circle-a','parent_share_id'=>11,'file_id'=>2,'files_share_provider'=>'ocCircleShare','share_type'=>7,
            'circle_incarnation_digest'=>str_repeat('a',64),'native_membership_digest'=>str_repeat('b',64),
            'native_recipient_projection_digest'=>str_repeat('c',64),'recipient_count'=>2];
        $legacy=static fn(string $uid,string $circleId,int $shareId,int $fileId):array=>$evidence;
        $deferred=static function(array $proof,string $uid,string $circleId,int $shareId,int $fileId)use(&$calls,$origin,$evidence):array {
            $calls++;self::assertSame($origin,$proof);self::assertSame('alice',$uid);self::assertSame('circle-a',$circleId);self::assertSame(11,$shareId);self::assertSame(2,$fileId);return $evidence;
        };
        $audience=$this->audience($legacy,$deferred);$this->sql->dbal->executeStatement("INSERT INTO share VALUES (11,2,7,'circle-a',1,NULL,NULL)");
        $result=$audience->snapshot('alice','/exports/results.csv',$origin);
        self::assertSame(1,$calls);self::assertSame([['type'=>7,'recipient'=>'circle-a','permissions'=>1,'expires'=>null]],$result['recipients']);
    }
    public function testDeferredCircleAudienceRejectsMismatchedPrincipalProof():void {
        $calls=0;$legacy=static fn(string $uid,string $circleId,int $shareId,int $fileId):array=>throw new \RuntimeException('Legacy resolver must not authorize a deferred read');
        $deferred=static function()use(&$calls):array{$calls++;throw new \RuntimeException('Mismatched proof reached resolver');};
        $audience=$this->audience($legacy,$deferred);$this->sql->dbal->executeStatement("INSERT INTO share VALUES (11,2,7,'circle-a',1,NULL,NULL)");
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Original linked Forms authority does not match');
        $audience->snapshot('alice','/exports/results.csv',['uid'=>'bob','tool'=>'forms_link_results_file','references'=>['form_id'=>4,'owner_id'=>'alice']]);
        self::assertSame(0,$calls);
    }
    private function personalShareColumns():void {
        $this->sql->dbal->executeStatement('ALTER TABLE share ADD COLUMN parent INTEGER');
        $this->sql->dbal->executeStatement('ALTER TABLE share ADD COLUMN uid_owner TEXT');
        $this->sql->dbal->executeStatement('ALTER TABLE share ADD COLUMN uid_initiator TEXT');
    }
    public function testPersonalCircleRowsUseOnlyParentEvidenceButRemainInApprovalFingerprint():void {
        $this->personalShareColumns();$calls=0;
        $resolver=static function(string $uid,string $circleId,int $shareId,int $fileId)use(&$calls):array {
            $calls++;self::assertSame('circle-a',$circleId);self::assertSame(11,$shareId);
            return ['circle_id'=>'circle-a','parent_share_id'=>11,'file_id'=>2,'files_share_provider'=>'ocCircleShare','share_type'=>7,'circle_incarnation_digest'=>str_repeat('a',64),'native_membership_digest'=>str_repeat('b',64),'native_recipient_projection_digest'=>str_repeat('c',64),'recipient_count'=>1];
        };
        $audience=$this->audience($resolver);
        $this->sql->dbal->executeStatement("INSERT INTO share (id,file_source,share_type,share_with,permissions,parent,uid_owner,uid_initiator) VALUES (11,2,7,'circle-a',1,NULL,'alice','alice'),(12,2,7,'personal-bob',1,11,'alice','alice'),(13,2,7,'personal-declined',0,11,'alice','alice')");
        $before=$audience->snapshot('alice','/exports/results.csv');self::assertSame(1,$calls);self::assertCount(1,$before['recipients']);
        $this->sql->dbal->executeStatement('UPDATE share SET permissions=1 WHERE id=13');$after=$audience->snapshot('alice','/exports/results.csv');
        self::assertSame(2,$calls);self::assertNotSame($before['digest'],$after['digest']);self::assertStringNotContainsString('personal-',json_encode($after));
    }
    public function testDerivedCircleRowsRefuseMissingOrMismatchedNativeParents():void {
        $this->personalShareColumns();$audience=$this->audience(static fn():array=>throw new \RuntimeException('parent resolver must not be reached'));
        $this->sql->dbal->executeStatement("INSERT INTO share (id,file_source,share_type,share_with,permissions,parent,uid_owner,uid_initiator) VALUES (12,2,7,'personal-bob',1,99,'alice','alice')");
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('Native personal share parent is unavailable');$audience->snapshot('alice','/exports/results.csv');
    }
    public function testNativeGroupPersonalOverridesRemainBoundToTheGroupApproval():void {
        $this->personalShareColumns();$audience=$this->audience(static fn():array=>throw new \RuntimeException('group override is not a Circle'));
        $this->sql->dbal->executeStatement("INSERT INTO share (id,file_source,share_type,share_with,permissions,parent,uid_owner,uid_initiator) VALUES (11,2,1,'group-a',1,NULL,'alice','alice'),(12,2,2,'bob',0,11,'alice','alice')");
        $this->sql->dbal->executeStatement("INSERT INTO group_user VALUES ('group-a','bob')");$before=$audience->snapshot('alice','/exports/results.csv');
        $this->sql->dbal->executeStatement('UPDATE share SET permissions=1 WHERE id=12');self::assertNotSame($before['digest'],$audience->snapshot('alice','/exports/results.csv')['digest']);
    }

}
