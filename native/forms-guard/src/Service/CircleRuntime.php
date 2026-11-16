<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** Optional provider qualification is independent of the Forms/user/group baseline. */
final class CircleRuntime {
    public static function available(string $uid): bool {
        try {
            $core = new FreshCore($uid);
            if (!$core->apps()->isEnabledForUser('circles', $core->user()) || $core->appConfig()->getValueString('circles', 'installed_version') !== '35.0.0') {return false;}
            $root = $core->apps()->getAppPath('circles');
            $sources = json_decode(file_get_contents(dirname(__DIR__) . '/CircleSources.json'), true, 32, JSON_THROW_ON_ERROR);
            foreach ($sources as $path => $hash) {
                $file = $root . '/' . $path;
                if (!is_file($file) || is_link($file) || !hash_equals($hash, hash_file('sha256', $file))) {return false;}
            }
            $provider=new \ReflectionClass(\OCA\Circles\ShareByCircleProvider::class);
            return $provider->getFileName()!==false&&realpath($provider->getFileName())===realpath($root.'/lib/ShareByCircleProvider.php');
        } catch (\Throwable) {return false;}
    }
    public static function assertAvailable(string $uid): void {
        if (!self::available($uid)) {throw new \RuntimeException('Native Circle provider source or account access is unqualified');}
    }

    /** Request-local provider-7 routing over the pinned native provider and managed write boundary. */
    public static function provider(string $uid,\Closure $beforeWrite,?string $intentKey,?\Closure $oneShotProof=null,?\OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary $transferCapability=null): CircleBoundaryProvider {
        if($intentKey!==null&&$oneShotProof!==null){throw new \RuntimeException('Native Circle provider event authority is unavailable');}
        self::assertAvailable($uid);
        $assert=static function()use($uid):void {
            if(OriginContext::active()){OriginContext::circleReadAuthority($uid)();}
            elseif(ManagedSharePhase::circleReadActor()!==null){ManagedSharePhase::circleReadAuthority($uid)();}
            else{throw new \RuntimeException('Native Circle provider frame is unavailable');}
            CircleRuntime::assertAvailable($uid);
            CircleExistingIdentity::read(Server::get(\OCP\IDBConnection::class),$uid);
        };
        $run=static function(?\OCP\Share\IShare $share,?string $recipient,bool $event,\Closure $work)use($uid,$beforeWrite,$assert,$intentKey,$oneShotProof,$transferCapability):mixed {
            return CircleReadRuntime::withProviderLease($uid,static function(\OCA\Forms\Mcp\Service\CircleDetached\Lease $lease,\Closure $guard,\Closure $open)use($uid,$beforeWrite,$assert,$intentKey,$oneShotProof,$transferCapability,$share,$recipient,$event,$work):mixed {
                $reader=$lease->shareWrappers();
                $writer=new \OCA\Forms\Mcp\Service\CircleDetached\WriteShareWrapperRequest($lease,$reader,$assert,$beforeWrite,$transferCapability);
                $cacheOwner=Server::get(\OCA\Circles\Service\ShareWrapperService::class);
                if($cacheOwner::class!==\OCA\Circles\Service\ShareWrapperService::class){throw new \RuntimeException('Native Circle cache service is unqualified');}
                $actor=new \OCA\Forms\Mcp\Service\CircleDetached\ScopedFederatedUserService($lease);
                $wrapper=new CircleNativeShareWrapperService($reader,$writer,$actor,$transferCapability,$cacheOwner,$beforeWrite,$assert,$uid);
                $circle=new \OCA\Forms\Mcp\Service\CircleDetached\ScopedCircleService($lease,$actor);
                $transport=null;
                if($event){if($intentKey===null&&$oneShotProof===null){throw new \RuntimeException('Native Circle event is outside an approved one-shot operation');}$transport=new \OCA\Forms\Mcp\Service\CircleDetached\LocalTransport($lease,$open,static fn():null=>$assert(),$intentKey,Server::get(\OCP\EventDispatcher\IEventDispatcher::class),$oneShotProof);$federatedTransport=\OCA\Forms\Mcp\Service\CircleDetached\CircleProviderTransport::wrap($transport);}
                else{$federatedTransport=new \ReflectionClass(\OCA\Circles\Service\FederatedEventService::class);$federatedTransport=$federatedTransport->newInstanceWithoutConstructor();}
                $eventService=(new \ReflectionClass(\OCA\Circles\Service\EventService::class))->newInstanceWithoutConstructor();
                $native=new \OCA\Circles\ShareByCircleProvider(Server::get(\OCP\IUserManager::class),Server::get(\OCP\Files\IRootFolder::class),Server::get(\OCP\IL10N::class),Server::get(\Psr\Log\LoggerInterface::class),Server::get(\OCP\IURLGenerator::class),$wrapper,Server::get(\OCA\Circles\Service\ShareTokenService::class),$actor,$federatedTransport,$circle,$eventService);
                if($share!==null){$wrapper->current($share,$recipient);}
                try{return$work($native,$wrapper);}finally{$transport?->close();}
            });
        };
        return new CircleBoundaryProvider($uid,$run,$beforeWrite,$assert,$intentKey,$oneShotProof,$transferCapability);
    }

    public static function assertRecipientMember(string $actorUid,string $circleId,string $recipientUid,string $singleId,?FinalReadConnection $readConnection=null):void {
        if(!preg_match('/^[A-Za-z0-9]{31}$/D',$circleId)||!preg_match('/^[A-Za-z0-9]{31}$/D',$singleId)||$recipientUid===''||strlen($recipientUid)>255)throw new \RuntimeException('Native Circle recipient tuple is malformed');
        $connection=Server::get(\OCP\IDBConnection::class);if($readConnection!==null){$readConnection->assertPrimary($connection);$connection=$readConnection;}
        $identity=CircleExistingIdentity::read($connection,$recipientUid);
        if($identity['circle_id']!==$singleId){throw new \RuntimeException('Native Circle recipient identity changed');}
        $level=CircleReadRuntime::withProviderLease($actorUid,static fn(\OCA\Forms\Mcp\Service\CircleDetached\Lease $lease):int=>$lease->memberLevel($circleId,$recipientUid),$readConnection);
        if($level<\OCA\Circles\Model\Member::LEVEL_MEMBER){throw new \RuntimeException('Native Circle recipient membership is unavailable');}
    }
    /** Resolve a persisted provider canonical ID through the native local-user request; it is never a UID alias. */
    public static function recipientUid(string $actorUid,string $circleId,string $singleId,?FinalReadConnection $readConnection=null):string {
        if(!preg_match('/^[A-Za-z0-9]{31}$/D',$circleId)||!preg_match('/^[A-Za-z0-9]{31}$/D',$singleId))throw new \RuntimeException('Native Circle recipient identity is malformed');
        return CircleReadRuntime::withProviderLease($actorUid,static function(\OCA\Forms\Mcp\Service\CircleDetached\Lease $lease)use($singleId):string {
            $user=$lease->circles()->getFederatedUserBySingleId($singleId);
            $uid=$user->getUserId();
            if($uid===''||strlen($uid)>255||$user->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||!$lease->config()->isLocalInstance($user->getInstance()))throw new \RuntimeException('Native Circle recipient is not a local user');
            return$uid;
        },$readConnection);
    }

    /** Full opaque audience evidence through the original request-local Circle read lease. */
    public static function linkedAudienceEvidence(string $uid,string $circleId,int $shareId,int $fileId,?FinalReadConnection $readConnection=null):array {
        if($shareId<1||$fileId<1){throw new \RuntimeException('Native Circle parent is unavailable');}
        self::assertAvailable($uid);
        $deferred=OriginContext::active()||ManagedSharePhase::circleReadActor()!==null;
        $read=self::readCircleAudience($uid,$circleId,$shareId,$fileId,$deferred,$readConnection);
        return $read;
    }
    public static function assertCircleShareIdentity(string $uid,string $circleId,int $shareId,int $fileId,?FinalReadConnection $readConnection=null):void {
        self::assertAvailable($uid);
        if($shareId<1||$fileId<1||$circleId===''){throw new \RuntimeException('Native Circle parent is unavailable');}
        CircleReadRuntime::withProviderLease($uid,static function(\OCA\Forms\Mcp\Service\CircleDetached\Lease $lease)use($circleId,$shareId,$fileId):void {
            try{$row=$lease->shareWrappers()->searchShare($circleId,$fileId);}
            catch(\OCA\Circles\Exceptions\ShareWrapperNotFoundException){return;}
            if((int)$row->getId()!==$shareId||$row->getParent()!==0||$row->getShareType()!==7||$row->getSharedWith()!==$circleId||$row->getFileSource()!==$fileId){throw new \RuntimeException('Native Circle duplicate share identity changed');}
        },$readConnection);
    }
    private static function readCircleAudience(string $uid,string $circleId,int $shareId,int $fileId,bool $deferred,?FinalReadConnection $readConnection=null):array {
        $call=CircleReadRuntime::scoped($uid,$deferred,$readConnection);
        return $call('linkedAudience',['circleId'=>$circleId,'shareId'=>(string)$shareId,'fileId'=>(string)$fileId]);
    }
}
