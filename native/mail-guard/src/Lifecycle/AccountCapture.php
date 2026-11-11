<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Lifecycle;
use OCA\Mail\Drafts\DraftOwnerLease;
use OCP\{IDBConnection,IConfig};
/** Separate from draft-owner exclusion: capture can precede a busy delivery worker. */
final class AccountCapture {
    public static function acquire(IDBConnection $db,string $uid):DraftOwnerLease {
        $config=\OCP\Server::get(IConfig::class);
        // NUL cannot occur in a native UID, so this namespace cannot collide with a delivery owner.
        return DraftOwnerLease::acquire($db,$config->getSystemValueString('dbtableprefix','oc_'),"\0mail-erasure-capture\0".$uid,$config);
    }
    public static function insert(IDBConnection $db,string $uid,\Closure $work):mixed {$lease=self::acquire($db,$uid);try{$lease->assertOwned();$out=$work();$lease->assertOwned();return $out;}finally{$lease->release();}}
}
