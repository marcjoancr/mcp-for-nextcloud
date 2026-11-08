<?php
/** SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\IMAP;
/** One-shot native selection permit. Contains no credentials and never reconnects a client. */
final class MutationSelection {
    private ?array $selection=null;
    private bool $used=false;
    private bool $invalid=false;
    private bool $wire=false;
    public function arm(object $connection,string $mailbox,string $generation,int $mode,int $uid): void {
        if($this->used||$this->invalid||$uid<1||!preg_match('/^[1-9][0-9]*$/D',$generation))throw new \LogicException('Mutation selection cannot be armed.');
        $this->selection=[$connection,$mailbox,$generation,$mode,$uid];$this->used=true;
    }
    public function invalidate(): void {$this->invalid=true;$this->selection=null;}
    public function check(object $connection,string $mailbox,string $generation,int $mode,int $uid): bool {
        return !$this->invalid&&$this->selection===[$connection,$mailbox,$generation,$mode,$uid];
    }
    public function startWire(object $connection,string $mailbox,string $generation,int $mode,int $uid): bool {
        if($this->wire||!$this->check($connection,$mailbox,$generation,$mode,$uid))return false;
        $this->wire=true;return true;
    }
}
