<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use OCA\Forms\Mcp\Service\CircleDetached\CircleAudienceProjection;
use PHPUnit\Framework\TestCase;
$circleProjectionSource=dirname(__DIR__).'/src/Service/CircleDetached/CircleAudienceProjection.php';
if(is_file($circleProjectionSource)){require_once $circleProjectionSource;}
final class CircleAudienceProjectionTest extends TestCase {
    public function testAudienceProjectionHelperIsAvailable():void {self::assertFileExists(dirname(__DIR__).'/src/Service/CircleDetached/CircleAudienceProjection.php');}
    private function requireProjection():void {if(!class_exists(CircleAudienceProjection::class)){self::markTestSkipped('projection is not implemented yet');}}
    private function data(int $count=2): array {
        $circleId=str_repeat('A',31);$ownerId=str_repeat('B',31);$members=[];
        for($i=1;$i<=$count;$i++) {
            $uid='member-'.$i;$single='c'.str_pad((string)$i,30,'0',STR_PAD_LEFT);
            $members[]=['uid'=>$uid,'member_id'=>'member-row-'.$i,'single_id'=>$single,'user_type'=>1,
                'level'=>8,'status'=>'Member','instance'=>'','identity'=>['uid'=>$uid,'circle_id'=>$single,'member_id'=>$single,
                    'creation'=>'2026-01-01 00:00:00','source'=>1],
                'inheritance_path'=>[$circleId,$single],
                'share'=>['id'=>456,'file_source'=>77,'share_type'=>7,'share_with'=>$circleId,'parent'=>0,'permissions'=>1,
                    'file_target'=>'','child_id'=>1000+$i,'child_permissions'=>$i===1?0:1,'child_file_target'=>$i===1?'':'moved.csv']];
        }
        return [
            ['id'=>$circleId,'creation'=>'2026-01-01 00:00:00','source'=>1,'owner'=>['uid'=>'owner','member_id'=>'owner-row','single_id'=>$ownerId,'level'=>9,'status'=>'Member','instance'=>'']],
            ['id'=>456,'file_source'=>77,'share_type'=>7,'share_with'=>$circleId,'share_owner'=>'owner','permissions'=>1,'file_target'=>''],
            $members,
        ];
    }
    public function testOpaqueDigestBindsMembershipRoleAndEffectiveChildProjection(): void {
        [$circle,$parent,$members]=$this->data();
        $evidence=CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
        self::assertSame(1,$evidence['recipient_count']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$evidence['circle_incarnation_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$evidence['native_membership_digest']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$evidence['native_recipient_projection_digest']);
        self::assertStringNotContainsString('member-1',json_encode($evidence));
        self::assertStringNotContainsString('member-row-1',json_encode($evidence));
        self::assertStringNotContainsString('moved.csv',json_encode($evidence));
        $members[1]['share']['child_file_target']='elsewhere.csv';
        $changed=CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
        self::assertNotSame($evidence['native_recipient_projection_digest'],$changed['native_recipient_projection_digest']);
        $members[1]['level']=7;
        $roleChanged=CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
        self::assertNotSame($evidence['native_membership_digest'],$roleChanged['native_membership_digest']);
    }
    public function testNonCanonicalCircleIdIsRejected(): void {
        [$circle,$parent,$members]=$this->data();
        $circle['id']='bad';$parent['share_with']='bad';foreach($members as &$member){$member['inheritance_path'][0]='bad';$member['share']['share_with']='bad';}unset($member);
        $this->expectException(RuntimeException::class);
        CircleAudienceProjection::build('bad',456,77,$circle,$parent,$members);
    }
    public function testCircleReplacementAndParentIdentityChangesAreBound(): void {
        [$circle,$parent,$members]=$this->data();$initial=CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
        $circle['creation']='2026-01-02 00:00:00';
        self::assertNotSame($initial['circle_incarnation_digest'],CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members)['circle_incarnation_digest']);
        $parent['id']=457;
        $this->expectException(RuntimeException::class);
        CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
    }
    public function testRemoteMemberAndMalformedIdentityRefuseWholeProjection(): void {
        [$circle,$parent,$members]=$this->data();$members[1]['instance']='remote.invalid';
        try {CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);self::fail('Remote member was accepted');}
        catch(RuntimeException $error){self::assertSame('Circle audience contains a non-local member',$error->getMessage());}
        [$circle,$parent,$members]=$this->data();$members[0]['identity']['uid']='other';
        $this->expectException(RuntimeException::class);
        CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
    }
    public function testRemoteOwnerInstanceIsRejected(): void {
        [$circle,$parent,$members]=$this->data();$circle['owner']['instance']='remote.example';
        $this->expectException(RuntimeException::class);
        CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
    }
    public function testAudienceOverflowIsNotTruncated(): void {
        [$circle,$parent,$members]=$this->data(1001);
        $this->expectException(RuntimeException::class);
        CircleAudienceProjection::build($circle['id'],456,77,$circle,$parent,$members);
    }
}
