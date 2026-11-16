<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;

/** Opaque, complete projection of native Circle membership and recipient-specific share state. */
final class CircleAudienceProjection {
    public const MAX_RECIPIENTS=1000;
    public const MAX_BYTES=1048576;

    public static function build(string $expectedCircleId,int $expectedParentId,int $expectedFileId,array $circle,array $parent,array $members):array {
        if(!self::circleId($expectedCircleId)||$expectedParentId<1||$expectedFileId<1
            ||array_keys($circle)!==['id','creation','source','owner']
            ||$circle['id']!==$expectedCircleId||!self::timestamp($circle['creation'])||!self::integer($circle['source'])
            ||!is_array($circle['owner'])) {
            throw new \RuntimeException('Circle audience incarnation is malformed');
        }
        $owner=self::owner($circle['owner']);
        if(array_keys($parent)!==['id','file_source','share_type','share_with','share_owner','permissions','file_target']
            ||$parent['id']!==$expectedParentId||$parent['file_source']!==$expectedFileId||$parent['share_type']!==7||$parent['share_with']!==$expectedCircleId
            ||!is_string($parent['share_owner'])||$parent['share_owner']===''||!self::integer($parent['permissions'])
            ||$parent['permissions']<0||!self::boundedString($parent['file_target'],4096)) {
            throw new \RuntimeException('Circle parent share identity is malformed');
        }
        if(!array_is_list($members)||count($members)>self::MAX_RECIPIENTS) {
            throw new \RuntimeException('Circle audience recipient budget exceeded');
        }
        $incarnation=['id'=>$expectedCircleId,'creation'=>$circle['creation'],'source'=>$circle['source'],'owner'=>$owner];
        $membership=[];$recipients=[];$seenMembers=[];$seenUsers=[];$bytes=strlen(json_encode($incarnation,JSON_THROW_ON_ERROR));
        foreach($members as $member) {
            if(!is_array($member)||array_keys($member)!==['uid','member_id','single_id','user_type','level','status','instance','identity','inheritance_path','share']) {
                throw new \RuntimeException('Circle audience member shape is malformed');
            }
            if(!is_string($member['instance'])||$member['instance']!=='') {
                throw new \RuntimeException('Circle audience contains a non-local member');
            }
            $uid=self::idString($member['uid'],255);$memberId=self::idString($member['member_id'],255);$singleId=self::canonicalId($member['single_id']);
            if($member['user_type']!==1||!self::integer($member['level'])||$member['level']<0||$member['status']!=='Member'
                ||isset($seenMembers[$memberId])||isset($seenUsers[$uid])) {
                throw new \RuntimeException('Circle audience member identity is malformed');
            }
            $seenMembers[$memberId]=true;$seenUsers[$uid]=true;
            $identity=$member['identity'];
            if(!is_array($identity)||array_keys($identity)!==['uid','circle_id','member_id','creation','source']
                ||$identity['uid']!==$uid||!self::circleId($identity['circle_id'])
                ||!preg_match('/^[A-Za-z0-9]{31}$/D',$identity['circle_id'])||$identity['member_id']!==$identity['circle_id']
                ||!self::date($identity['creation'])||$identity['source']!==1) {
                throw new \RuntimeException('Circle audience recipient identity is unqualified');
            }
            $path=$member['inheritance_path'];
            if(!is_array($path)||!array_is_list($path)||count($path)>128) {
                throw new \RuntimeException('Circle audience inheritance path is malformed');
            }
            foreach($path as $pathId){self::canonicalId($pathId);}
            $share=$member['share'];
            if(!is_array($share)||array_keys($share)!==['id','file_source','share_type','share_with','parent','permissions','file_target','child_id','child_permissions','child_file_target']
                ||$share['id']!==$expectedParentId||$share['file_source']!==$expectedFileId||$share['share_type']!==7||$share['share_with']!==$expectedCircleId||$share['parent']!==0
                ||!self::integer($share['permissions'])||$share['permissions']!==$parent['permissions']||$share['file_target']!==$parent['file_target']
                ||!self::integer($share['child_id'])||$share['child_id']<0||!self::integer($share['child_permissions'])||$share['child_permissions']<0
                ||!self::boundedString($share['child_file_target'],4096)||($share['child_id']===0&&($share['child_permissions']!==0||$share['child_file_target']!==''))) {
                throw new \RuntimeException('Circle recipient share projection is malformed');
            }
            $membership[]=['uid'=>$uid,'member_id'=>$memberId,'single_id'=>$singleId,'level'=>$member['level'],'status'=>$member['status'],
                'identity'=>$identity,'inheritance_path'=>$path];
            $effectivePermissions=$share['child_id']>0?$share['child_permissions']:$share['permissions'];
            $effectiveTarget=$share['child_id']>0&&$share['child_file_target']!==''?$share['child_file_target']:$share['file_target'];
            $recipients[]=['uid'=>$uid,'identity'=>$identity,'share'=>$share,'effective_permissions'=>$effectivePermissions,'effective_target'=>$effectiveTarget];
        }
        usort($membership,static fn(array $a,array $b):int=>strcmp($a['single_id'],$b['single_id'])?:strcmp($a['member_id'],$b['member_id']));
        usort($recipients,static fn(array $a,array $b):int=>strcmp($a['identity']['circle_id'],$b['identity']['circle_id'])?:strcmp($a['uid'],$b['uid']));
        $bytes+=strlen(json_encode([$parent,$membership,$recipients],JSON_THROW_ON_ERROR));
        if($bytes>self::MAX_BYTES){throw new \RuntimeException('Circle audience byte budget exceeded');}
        $visible=0;foreach($recipients as $recipient){if($recipient['effective_permissions']>0){$visible++;}}
        $json=json_encode([$parent,$membership],JSON_THROW_ON_ERROR);$memberDigest=hash('sha256','native-circle-membership-v1:'.$json);
        $json=json_encode([$parent,$recipients],JSON_THROW_ON_ERROR);$recipientDigest=hash('sha256','native-circle-recipient-projection-v1:'.$json);
        return ['circle_id'=>$expectedCircleId,'parent_share_id'=>$expectedParentId,'file_id'=>$expectedFileId,'files_share_provider'=>'ocCircleShare','share_type'=>7,
            'circle_incarnation_digest'=>hash('sha256','native-circle-incarnation-v1:'.json_encode($incarnation,JSON_THROW_ON_ERROR)),
            'native_membership_digest'=>$memberDigest,'native_recipient_projection_digest'=>$recipientDigest,'recipient_count'=>$visible];
    }
    private static function owner(array $owner):array {
        if(array_keys($owner)!==['uid','member_id','single_id','level','status','instance']
            ||$owner['instance']!==''||$owner['level']<1||!is_int($owner['level'])||$owner['status']!=='Member') {
            throw new \RuntimeException('Circle owner identity is unqualified');
        }
        return ['uid'=>self::idString($owner['uid'],255),'member_id'=>self::idString($owner['member_id'],255),
            'single_id'=>self::canonicalId($owner['single_id']),'level'=>$owner['level'],'status'=>$owner['status'],'instance'=>''];
    }
    private static function timestamp(mixed $value):bool {
        if(is_int($value)&&$value>=0)return true;
        return self::date($value);
    }
    private static function date(mixed $value):bool {
        if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D',$value))return false;
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));
        return $date!==false&&$date->format('Y-m-d H:i:s')===$value;
    }
    private static function circleId(mixed $value):bool {return is_string($value)&&preg_match('/^[A-Za-z0-9]{31}$/D',$value)===1;}
    private static function canonicalId(mixed $value):string {if(!self::circleId($value))throw new \RuntimeException('Circle audience canonical identity is malformed');return$value;}
    private static function integer(mixed $value):bool {return is_int($value)&&$value>=0;}
    private static function boundedString(mixed $value,int $max):bool {return is_string($value)&&strlen($value)<=$max&&!str_contains($value,"\0");}
    private static function idString(mixed $value,int $max):string {
        if(!self::boundedString($value,$max)||$value==='')throw new \RuntimeException('Circle audience identity is malformed');
        return $value;
    }
}
