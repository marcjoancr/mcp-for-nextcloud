<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Circles\Model {
    class FederatedUser {public function __construct(private string $uid){}public function getUserId():string{return$this->uid;}}
    class ShareWrapper {public function __construct(private string $id,private int $parent,private int $type,private int $file,private string $owner,private string $circle){}public function getId():string{return$this->id;}public function getParent():int{return$this->parent;}public function getShareType():int{return$this->type;}public function getFileSource():int{return$this->file;}public function getShareOwner():string{return$this->owner;}public function getSharedWith():string{return$this->circle;}public function getPermissions():int{return 1;}public function getFileTarget():?string{return null;}}
}
namespace OCA\Circles\Db {
    class CoreQueryBuilder {}
    class ShareWrapperRequest {public static array $rows=[];public static array $call=[];public function getSharesBy(\OCA\Circles\Model\FederatedUser $user,int $nodeId,bool $reshares,int $limit,int $offset,bool $getData=false,bool $completeDetails=false):array {self::$call=[$user->getUserId(),$nodeId,$reshares,$limit,$offset,$getData,$completeDetails];return self::$rows;}}
}
namespace OCA\Forms\Mcp\Service\CircleDetached {
    final class Lease {public function actorUid():string{return'owner';}public function identity(string $uid):\OCA\Circles\Model\FederatedUser{return new \OCA\Circles\Model\FederatedUser($uid);}public function checkpoint():void{}}
}
namespace {
    require_once dirname(__DIR__).'/src/Service/CircleDetached/ShareWrapperRequest.php';
    final class CircleManagedParentsPageTest extends \PHPUnit\Framework\TestCase {
        private function request():\OCA\Forms\Mcp\Service\CircleDetached\ShareWrapperRequest{return new \OCA\Forms\Mcp\Service\CircleDetached\ShareWrapperRequest(new \OCA\Forms\Mcp\Service\CircleDetached\Lease());}
        public function testUsesNativeOwnerFileTypePredicateWithLimitPlusOneBeforeReturningProjection():void {
            \OCA\Circles\Db\ShareWrapperRequest::$rows=[new \OCA\Circles\Model\ShareWrapper('8',0,7,101,'owner','circle-a'),new \OCA\Circles\Model\ShareWrapper('9',0,7,101,'owner','circle-b')];
            $rows=$this->request()->managedParents('owner',101,2);self::assertSame(['owner',101,false,3,0,false,false],\OCA\Circles\Db\ShareWrapperRequest::$call);self::assertSame(['8','9'],array_column($rows,'id'));self::assertSame(7,$rows[0]['share_type']);
        }
        public function testSentinelOverflowFailsClosed():void {
            \OCA\Circles\Db\ShareWrapperRequest::$rows=array_fill(0,3,new \OCA\Circles\Model\ShareWrapper('8',0,7,101,'owner','circle-a'));
            try{$this->request()->managedParents('owner',101,2);self::fail('Sentinel row must reject the whole page');}catch(\RuntimeException $error){self::assertSame('Managed Circle parent page overflowed',$error->getMessage());}
        }
        public function testRejectsRowsOutsideExactOwnerFileParentType():void {
            \OCA\Circles\Db\ShareWrapperRequest::$rows=[new \OCA\Circles\Model\ShareWrapper('8',0,7,202,'owner','circle-a')];
            $this->expectException(\RuntimeException::class);$this->request()->managedParents('owner',101,2);
        }
    }
}
