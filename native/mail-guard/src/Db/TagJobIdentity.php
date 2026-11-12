<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Db;
/** A queued classification belongs to one cached incarnation, never a header lookup. */
final class TagJobIdentity {
    public static function valid(mixed $a):bool {return is_array($a)&&is_int($a['cachedMessageId']??null)&&$a['cachedMessageId']>0&&is_int($a['mailboxId']??null)&&$a['mailboxId']>0&&is_int($a['uid']??null)&&$a['uid']>0&&is_string($a['generation']??null)&&preg_match('/^[1-9][0-9]{0,9}$/D',$a['generation'])===1&&(float)$a['generation']<=4294967295&&is_string($a['userId']??null)&&$a['userId']!=='';}
    public static function matches(array $a,object $m,object $b,object $account):bool {
        return self::valid($a)&&$m->getId()===$a['cachedMessageId']&&$m->getMailboxId()===$a['mailboxId']&&$b->getId()===$a['mailboxId']&&$m->getUid()===$a['uid']&&$m->getTagCacheGeneration()===$a['generation']&&$account->getUserId()===$a['userId']&&$b->getAccountId()===$account->getId()&&$m->getTagCacheProfile()===TagProfile::account($account->getMailAccount())&&ExactTagAssociations::generation(['sync_new_token'=>$b->getSyncNewToken(),'sync_changed_token'=>$b->getSyncChangedToken(),'sync_vanished_token'=>$b->getSyncVanishedToken()])===$a['generation'];
    }
}
