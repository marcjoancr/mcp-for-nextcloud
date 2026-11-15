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
            $data=$work($lease);
            \OCA\Forms\Mcp\Service\CircleReadData::page([['value'=>$data]]);
            return $data;
        } finally {$lease->close();}
    }
    public function profile(string $id): array {return $this->read(static fn(Lease $lease): array=>$lease->profile($id));}
    public function users(string $id): array {return $this->read(static fn(Lease $lease): array=>$lease->users($id));}
    public function detail(string $id): array {return $this->read(static fn(Lease $lease): array=>$lease->detail($id));}
    public function memberLevel(string $id,string $uid): int {return $this->read(static fn(Lease $lease): int=>$lease->memberLevel($id,$uid));}
    public function teamIds(string $uid): array {
        return $this->read(static fn(Lease $lease): array => $lease->query()->readUserTeamIds($uid));
    }
public function circleView(string $id): \OCA\Circles\Model\Circle {return new CircleView($this->profile($id));}
}
