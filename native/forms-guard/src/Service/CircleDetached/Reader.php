<?php
/* UNAPPLIED R2 proposal. SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
/** Each invocation owns and closes its graph. The factory captures live, non-persistable authority. */
final class Reader {
    public function __construct(private readonly \Closure $open) {}
    private function read(\Closure $work): mixed {
        $lease=($this->open)();
        if(!$lease instanceof Lease){throw new \RuntimeException('Circle read lease is unavailable');}
        try {
            return $lease->readData($work);
        } finally {$lease->close();}
    }
    public function profile(string $id): array {return $this->read(static fn(Lease $lease): array=>$lease->profile($id));}
    public function users(string $id): array {return $this->read(static fn(Lease $lease): array=>$lease->users($id));}
    public function detail(string $id): array {return $this->read(static fn(Lease $lease): array=>$lease->detail($id));}
    public function audience(string $circleId,int $parentId,int $fileId): array {return $this->read(static fn(Lease $lease): array=>$lease->audience($circleId,$parentId,$fileId));}
    /** Resolve only the canonical local UID; no leased federated model leaves this read. */
    public function recipientUid(string $singleId):string {
        return $this->read(static function(Lease $lease)use($singleId):string {
            $user=$lease->circles()->getFederatedUserBySingleId($singleId);$uid=$user->getUserId();
            if($uid===''||strlen($uid)>255||$user->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||!$lease->config()->isLocalInstance($user->getInstance()))throw new \RuntimeException('Native Circle recipient is not a local user');
            return $uid;
        });
    }
    /** Canonical identity and native membership use one unpublished lease, with no reused grant. */
    public function recipientMemberUid(string $circleId,string $singleId):string {
        return $this->read(static function(Lease $lease)use($circleId,$singleId):string {
            $user=$lease->circles()->getFederatedUserBySingleId($singleId);$uid=$user->getUserId();
            if($uid===''||strlen($uid)>255||$user->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||!$lease->config()->isLocalInstance($user->getInstance()))throw new \RuntimeException('Native Circle recipient is not a local user');
            if(\OCA\Forms\Mcp\Service\CircleExistingIdentity::read($lease->db(),$uid)['circle_id']!==$singleId)throw new \RuntimeException('Native Circle recipient identity changed');
            if($lease->memberLevel($circleId,$uid)<\OCA\Circles\Model\Member::LEVEL_MEMBER)throw new \RuntimeException('Native Circle recipient membership is unavailable');
            return$uid;
        });
    }
    public function memberLevel(string $id,string $uid): int {return $this->read(static fn(Lease $lease): int=>$lease->memberLevel($id,$uid));}
    public function teamIds(string $uid): array {
        return $this->read(static fn(Lease $lease): array => $lease->query()->readUserTeamIds($uid));
    }
public function circleView(string $id): \OCA\Circles\Model\Circle {return new CircleView($this->profile($id));}
}
