<?php
/* UNAPPLIED adapter proposal. SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Server;
use OCA\Forms\Mcp\Service\CircleDetached\{Lease,Reader,ReadFrame,SourceGate};
/** Data reads only. No provider, IShare, event, container/session swap or authority inferred from a recipient. */
final class CircleReadRuntime {
    /** Captures a verified origin/producer frame when present. Native immediate assertions are created per call. */
    public static function scoped(string $uid, bool $deferred): \Closure {
        $bound = OriginContext::active() ? OriginContext::circleReadAuthority($uid)
            : (ManagedSharePhase::circleReadActor() === null ? null : ManagedSharePhase::circleReadAuthority($uid));
        return static function(string $method,array $arguments)use($uid,$deferred,$bound):mixed {
            if($bound===null&&$deferred){throw new \RuntimeException('Deferred Circle origin frame is unavailable');}
            $assert=$bound;
            if($assert===null){
                $proof=NativeShareRequest::capture();
                if($proof['uid']!==$uid){throw new \RuntimeException('Immediate Circle actor differs from the authenticated request');}
                $assert=static function()use($proof):void {
                    if(OriginContext::active()||ManagedSharePhase::circleReadActor()!==null){throw new \RuntimeException('Immediate Circle read cannot enter another producer frame');}
                    NativeShareRequest::assertCurrent($proof);
                };
            }
            return self::read($uid,$assert,$method,$arguments);
        };
    }
    public static function managedProducerActive(): bool {return ManagedSharePhase::circleReadActor()!==null;}
    public static function managedProducer(string $method,array $arguments): mixed {
        $uid=ManagedSharePhase::circleReadActor()??throw new \RuntimeException('Circle managed producer is unavailable');
        return self::read($uid,ManagedSharePhase::circleReadAuthority($uid),$method,$arguments);
    }
    private static function read(string $uid,\Closure $assert,string $method,array $arguments):mixed {
        $shape=match($method){'isCirclesEnabled'=>[],'getCircle','getCircleUsers'=>['circleId'],'isUserInCircle'=>['circleId','userId'],'getUserTeamIds'=>['userId'],default=>throw new \LogicException('Unknown Circle adapter method')};
        if(array_keys($arguments)!==$shape){throw new \LogicException('Malformed Circle adapter arguments');}
        foreach($arguments as$value){if(!is_string($value)){throw new \LogicException('Malformed Circle adapter argument');}}
        $generation=null;
        $qualifiedAssert=static function()use($assert,$uid,&$generation):void {
            $assert();
            if($generation!==null){
                if(!\OCA\Forms\Mcp\SourceIdentity::verify()){throw new \RuntimeException('Forms Circle adapter source changed');}
                NativeHistoryIdentity::assertCurrent($uid,$generation);
            }
        };
        return ReadFrame::within($qualifiedAssert,static function(\Closure $guard)use($uid,$method,$arguments,&$generation):mixed {
            $core=new FreshCore($uid);$core->assertPrincipal(false,false);
            $enabled=$core->apps()->isEnabledForUser('circles',$core->user());
            if($method==='isCirclesEnabled'){return $enabled;}
            if(!$enabled){return self::absent($method);}
            if(!\OCA\Forms\Mcp\SourceIdentity::verify()){throw new \RuntimeException('Forms Circle adapter source is unqualified');}
            $generation=self::existingGeneration($uid);
            $open=static fn():Lease=>new Lease($uid,Server::get(\OCP\IDBConnection::class),Server::get(\OC\SystemConfig::class),Server::get(\Psr\Log\LoggerInterface::class),Server::get(\OCP\IURLGenerator::class),$guard,self::system(...),new SourceGate());
            $reader=new Reader($open);
            try {
                return match($method){
                    'getCircle'=>$reader->circleView($arguments['circleId']),
                    'isUserInCircle'=>$reader->memberLevel($arguments['circleId'],$arguments['userId'])>=\OCA\Circles\Model\Member::LEVEL_MEMBER,
                    'getCircleUsers'=>$reader->users($arguments['circleId']),
                    'getUserTeamIds'=>$reader->teamIds($arguments['userId']),
                };
            } catch(\OCA\Circles\Exceptions\CircleNotFoundException) {
                return self::absent($method);
            }
        });
    }
    private static function absent(string $method):mixed {
        return match($method){'getCircle'=>null,'isUserInCircle'=>false,'getCircleUsers','getUserTeamIds'=>[],default=>throw new \LogicException('Unknown absent Circle result')};
    }
    /** Read an existing Forms lifecycle witness; capture/enrollment is deliberately never called. */
    private static function existingGeneration(string $uid):string {
        $result=Server::get(\OCP\IDBConnection::class)->executeQuery('SELECT uid,generation FROM *PREFIX*forms_mcp_incarnations WHERE uid_hash=? LIMIT 2',[hash('sha256',"forms-user\0".$uid)]);
        try{$rows=$result->fetchAll();}finally{$result->closeCursor();}
        if(count($rows)!==1||$rows[0]['uid']!==$uid||!is_string($rows[0]['generation'])||!preg_match('/^[a-f0-9]{64}$/D',$rows[0]['generation'])){throw new \RuntimeException('Circle native account generation is unavailable');}
        NativeHistoryIdentity::assertCurrent($uid,$rows[0]['generation']);return$rows[0]['generation'];
    }
    private static function system():array {
        $config=Server::get(\OCP\IConfig::class);$out=[];
        foreach([...array_map(static fn(string$key):string=>'circles.'.$key,CircleReadConfig::KEYS),'circles.keyhole_cfg_request','overwrite.cli.url','trusted_domains']as$key){
            $value=$config->getSystemValue($key,$key==='trusted_domains'?[]:'');if($value!==''&&$value!==[]){$out[$key]=$value;}
        }
        return$out;
    }
}
