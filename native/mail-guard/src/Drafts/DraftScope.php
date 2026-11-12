<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Drafts;
use OCA\Mail\Exception\DraftAdmissionFailure;
final class DraftScope {
    private array $rows=[];
    private bool $written=false;
    public function __construct(public readonly string $uid,private DraftOwnerLease $lease){if($lease->uid!==$uid)throw new \LogicException('Draft lease owner mismatch.');}
    public function markWrite():void {$this->assertOwned();$this->written=true;}
    public function hasWritten():bool {return $this->written;}
    public function assertOwned():void {$this->lease->assertOwned();}
    public function admit(int $id,string $purpose):void {
        $this->assertOwned();
        if(isset($this->rows[$id])&&$this->rows[$id]!==$purpose)throw new DraftAdmissionFailure('Another lifecycle action already owns this draft.');
        $this->rows[$id]=$purpose;
    }
    public function permits(int $id,array $purposes):bool {$this->assertOwned();return in_array($this->rows[$id]??null,$purposes,true);}
}
