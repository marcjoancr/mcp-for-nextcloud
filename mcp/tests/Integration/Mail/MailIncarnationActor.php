<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCP\{Server,IUserManager};
use OCA\Mcp\Mcp\Operations\ActorIdentity;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Db\ToolGrantsMapper;
use OCA\Mcp\Tests\Integration\Support\ToolPermissionFixture;
final class MailIncarnationActor {
    public static function run(array $in):array {
        $uid=$in['uid']??'';if(!is_string($uid)||preg_match('/^mcp-it-scope-owner-[a-f0-9]{16}$/D',$uid)!==1)throw new \RuntimeException('Unexpected synthetic incarnation actor.');
        if(($in['action']??'')==='recreate') {
            $users=Server::get(IUserManager::class);$deleted=$users->get($uid)?->delete();$created=$users->createUser($uid,bin2hex(random_bytes(24)))!==false;
            $policy=Server::get(GrantPolicy::class);$policy->setEligible($uid,true);$policy->setConnected($uid,true);
            ToolPermissionFixture::assign($uid,[ToolGrantsMapper::SERVICE=>'allow','mail_create_tag'=>'allow','mail_get_operation'=>'allow']);
            return ['deleted'=>$deleted,'created'=>$created];
        }
        if(($in['action']??'')==='capture')return ['fingerprint'=>hash('sha256',Server::get(ActorIdentity::class)->capture($uid))];
        throw new \RuntimeException('Unknown incarnation test action.');
    }
}
