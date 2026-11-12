<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Service;
use OCA\Mail\Drafts\DraftCoordinator;
use OCA\Mail\Sieve\SieveFailure;
use OCP\{IDBConnection,Server};
/** Participating native UI/background paths share the owner from BEFORE the current read. */
final class SieveSettingsFence {
    public static function run(string $uid,#[\SensitiveParameter] \Closure $work):mixed {
        try{return DraftCoordinator::forDatabase(Server::get(IDBConnection::class))->runOwner($uid,$work);}catch(\Throwable){throw new SieveFailure();}
    }
    public static function account(#[\SensitiveParameter] object $old,#[\SensitiveParameter] \Closure $work):mixed {
        $uid=$old->getUserId();$id=(int)$old->getId();return self::run($uid,function()use($uid,$id,$work){$fresh=Server::get(AccountService::class)->find($uid,$id)->getMailAccount();return $work($fresh);});
    }
}
