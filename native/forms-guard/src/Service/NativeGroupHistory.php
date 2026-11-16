<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use McpFormsServerExtension\{GroupBootstrap,GroupWitnessStore,GroupWitnessState,GroupIdentity};
/** Backup-only enrollment and read-only final validation on the already owned Forms fence. */
final class NativeGroupHistory {
    private static function database():\OCP\IDBConnection {
        GroupBootstrap::assertActive();
        $fence=\OCP\Server::get(FormsRevisionFence::class);$db=\OCP\Server::get(\OCP\IDBConnection::class);
        if (!$fence->active() || !$db->inTransaction() || $db::class!==\OC\DB\ConnectionAdapter::class) {throw new \RuntimeException('Group capture requires the Forms transaction');}
        $fence->assertSession();return $db;
    }
    public static function capture(string $gid,array $job):array {
        $db=self::database();$context=OriginContext::current();
        $input=$job['input']??null;$origin=$context['origin']??null;
        if(!is_array($origin)||($job['origin']??null)!==$origin||!is_array($input)){throw new \RuntimeException('Group capture original authority changed');}
        if(array_key_exists('_mcp_origin',$input)){if($input['_mcp_origin']!==$origin){throw new \RuntimeException('Group capture transport authority changed');}unset($input['_mcp_origin']);}
        if (($context['verified']['tool']??null)!=='forms_transfer' || ($job['action']??null)!=='forms_transfer' || ($job['phase']??null)!=='capture' || ($job['sealed']??null)!==false || ($job['uid']??null)!==($context['verified']['uid']??null) || $input!==($context['input']??null)) {throw new \RuntimeException('Group witness enrollment is not a transfer capture');}
        OriginContext::assertFresh();$core=new FreshCore($context['verified']['uid']);$groups=$core->groups();
        if (!$groups->groupExists($gid) || $groups->get($gid)===null) {throw new \RuntimeException('Group capture recipient disappeared');}
        $gid=GroupIdentity::existing($db,$gid);
        $epoch=GroupWitnessStore::epoch($db);$prior=GroupWitnessStore::read($db,$gid);
        $row=GroupWitnessState::enroll($prior,$gid,$epoch,bin2hex(random_bytes(32)));
        if ($row!==$prior) {GroupWitnessStore::write($db,$prior,$row);}
        OriginContext::assertFresh();self::database();
        return ['gid'=>$gid,'epoch'=>$epoch,'generation'=>$row['generation']];
    }
    public static function assertCurrent(string $gid,array $captured,?FinalReadConnection $readDb=null):void {
        $primary=self::database();$readDb?->assertPrimary($primary);$db=$readDb??$primary;$epoch=GroupWitnessStore::epoch($db);$gid=GroupIdentity::existing($db,$gid);
        GroupWitnessState::assertLive(GroupWitnessStore::read($db,$gid),$gid,$epoch,GroupIdentity::captured($gid,$captured));
        self::database();
    }
}
