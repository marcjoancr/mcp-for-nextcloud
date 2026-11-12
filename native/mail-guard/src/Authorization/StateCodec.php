<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mail\Authorization;
use OCA\Mail\Exception\DraftAdmissionFailure;
use OCP\Security\ICrypto;
/** Native HMAC remains the authority; the nonce prevents same-second context aliases. */
final class StateCodec {
    public const TTL=600;
    public static function create(ICrypto $crypto,string $uid,int $accountId,int $timestamp):string {
        if($uid===''||strlen($uid)>1024||$accountId<1||$timestamp<0)throw new DraftAdmissionFailure('Invalid native OAuth state context.');
        $nonce=bin2hex(random_bytes(16));$hmac=bin2hex($crypto->calculateHMAC(implode('.',['v7',$accountId,$uid,$timestamp,$nonce])));
        return implode('.',['v7',$accountId,$timestamp,$nonce,$hmac]);
    }
    /** Structure only; callers must still use the native HMAC validation before proof admission. */
    public static function isCorrelated(string $state):bool {
        return strlen($state)<=1024&&preg_match('/^v7\.[1-9][0-9]{0,18}\.[0-9]{1,19}\.[a-f0-9]{32}\.[a-f0-9]{16,256}$/D',$state)===1;
    }
    public static function validate(ICrypto $crypto,string $uid,#[\SensitiveParameter] string $state,int $now):int {
        if($uid===''||strlen($uid)>1024||strlen($state)>1024)throw new DraftAdmissionFailure('Invalid native OAuth state.');
        $parts=explode('.',$state,6);
        if(count($parts)===5&&self::isCorrelated($state)){
            [, $rawId,$rawTime,$nonce,$actual]=$parts;$new=true;
        }elseif(count($parts)===3){
            [$rawId,$rawTime,$actual]=$parts;$nonce=null;$new=false;
        }else throw new DraftAdmissionFailure('Invalid native OAuth state.');
        $id=filter_var($rawId,FILTER_VALIDATE_INT);$time=filter_var($rawTime,FILTER_VALIDATE_INT);
        if($id===false||$id<1||$time===false||$time<0||$time>$now||$now-$time>self::TTL)throw new DraftAdmissionFailure('Native OAuth state expired or unavailable.');
        $message=$new?implode('.',['v7',$id,$uid,$time,$nonce]):implode('.',[$id,$uid,$time]);
        $expected=bin2hex($crypto->calculateHMAC($message));
        if(!hash_equals($expected,$actual))throw new DraftAdmissionFailure('Native OAuth state owner or identity changed.');
        return $id;
    }
}
