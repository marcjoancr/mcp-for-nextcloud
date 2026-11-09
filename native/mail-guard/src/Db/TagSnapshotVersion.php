<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
/** One pre-fetch revision, advanced only by this snapshot's acknowledged commits. */
final class TagSnapshotVersion {
    private ?int $pending=null;
    public function __construct(public readonly string $owner,public readonly int $mailbox,public readonly string $generation,private int $revision,public readonly string $profile,public readonly ?\OCA\Mail\Sync\MailboxLease $syncLease=null){}
    public function expected():int {return $this->revision;}
    public function stage():int {if($this->pending!==null||$this->revision>=PHP_INT_MAX-1)throw new \RuntimeException('Tag snapshot revision unavailable.');return $this->pending=$this->revision+1;}
    public function pending():?int {return $this->pending;}
    public function committed():void {if($this->pending===null)throw new \RuntimeException('Tag snapshot commit was not staged.');$this->revision=$this->pending;$this->pending=null;}
    public function aborted():void {$this->pending=null;}
}
