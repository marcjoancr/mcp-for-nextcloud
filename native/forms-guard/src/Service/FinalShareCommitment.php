<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Exact already-validated activation data, never a stored authorization result. */
final class FinalShareCommitment {
    private \HashContext $hash;
    private ?string $sealed=null;
    private int $count=0;
    private int $bytes=0;
    private array $last=[];
    public function __construct() {$this->hash=hash_init('sha256');hash_update($this->hash,"forms-final-share-activation-v1\0");}
    public function append(string $domain,int $id,array $row):void {
        if ($this->sealed!==null) {throw new \RuntimeException('Final sharing commitment is sealed');}
        if (!in_array($domain,['parent','child','mapping','folder','absent','self'],true) || $id<1 || $id<=($this->last[$domain]??0)) {throw new \RuntimeException('Final sharing activation order changed');}
        $this->last[$domain]=$id;
        $json=FinalShareBudget::encode([$domain,$id,$row]);
        if (++$this->count>100000 || ($this->bytes+=strlen($json))>1048576) {throw new \RuntimeException('Final sharing activation budget exceeded');}
        hash_update($this->hash,strlen($json).':'.$json);
    }
    public function seal():void {
        if ($this->sealed!==null) {throw new \RuntimeException('Final sharing commitment is sealed');}
        $this->sealed=hash_final($this->hash);
    }
    public function assertMatches(iterable $rows):void {
        if ($this->sealed===null) {throw new \RuntimeException('Final sharing commitment is unsealed');}
        $actual=new self();
        foreach ($rows as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row)!==3 || !is_string($row[0]) || !is_int($row[1]) || !is_array($row[2])) {throw new \RuntimeException('Final sharing activation changed');}
            $actual->append(...$row);
        }
        $actual->seal();
        if ($actual->count!==$this->count || !hash_equals($this->sealed,$actual->sealed)) {throw new \RuntimeException('Final sharing activation changed');}
    }
}
