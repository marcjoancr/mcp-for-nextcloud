<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Cache;
/** A cache-enabled native read has no authority to delete persistent message rows. */
trait OwnedSyncCache {
    private function deleteOwnedSyncMessages(object $mailbox,$uids):void {
        $lease=$this->mailboxMapper->currentSyncLease($mailbox->getId());if($lease===null)return;
        $lease->assertOwned();$this->dbMessageMapper->deleteByUid($mailbox,...$uids);
    }
}
