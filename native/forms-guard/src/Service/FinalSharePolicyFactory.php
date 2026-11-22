<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\Server;
/** All dependencies are local to the query; Core DI and the authenticated session remain unchanged. */
final class FinalSharePolicyFactory {
    private static function set(object $object,string $property,mixed $value):void {(new \ReflectionProperty($object,$property))->setValue($object,$value);}
    private static function circleReceiver(string $circleId):\OCP\Interaction\Receivers\CircleReceiver {
        if(!preg_match('/^[A-Za-z0-9]{31}$/D',$circleId)||!class_exists(\OCP\Interaction\Receivers\CircleReceiver::class))throw new \RuntimeException('Final Circle receiver is unavailable');
        $class=new \ReflectionClass(\OCP\Interaction\Receivers\CircleReceiver::class);$constructor=$class->getConstructor();
        if($class->isAbstract()||$constructor===null||!$constructor->isPublic()||$constructor->getNumberOfParameters()!==1||$constructor->getNumberOfRequiredParameters()!==1||$constructor->getParameters()[0]->getType()?->__toString()!=='string')throw new \RuntimeException('Final Circle receiver source is unqualified');
        $receiver=new \OCP\Interaction\Receivers\CircleReceiver($circleId);
        if($receiver->getID()!==$circleId)throw new \RuntimeException('Final Circle receiver identity changed');
        return$receiver;
    }
    /** Native policy subjects never replace the verified MCP actor used for Circle metadata reads. */
    private static function circleReadActor(string $subject):string {
        $context=OriginContext::current();$actor=$context['verified']['uid']??null;$target=$context['input']['owner_id']??null;
        if(($context['action']??null)!=='forms_transfer'||($context['verified']['tool']??null)!=='forms_transfer'
            ||!is_string($actor)||$actor===''||!is_string($target)||$target===''||!in_array($subject,[$actor,$target],true))throw new \RuntimeException('Final Circle read actor is unavailable');
        return$actor;
    }
    /** Full audience replay is mandatory in FinalTransferShareCheck; this native receiver needs fresh existence only. */
    private static function circleReceiverEvidence(string $subject,string $recipient,FinalReadConnection $readDb):string {
        $actor=self::circleReadActor($subject);CircleRuntime::assertAvailable($actor);
        $read=CircleReadRuntime::scoped($actor,true,$readDb);$circle=$read('getCircle',['circleId'=>$recipient]);
        if(!$circle instanceof CircleDetached\CircleView||$circle->getSingleId()!==$recipient)throw new \RuntimeException('Final Circle receiver disappeared');
        return$actor;
    }
    public static function collect(string $subject,int $type,string $recipient,int $nodeId,\OC\Files\Node\Folder $home,\OC\Files\Node\Root $root,\OCP\Files\Node $node,array $actual,
        FinalShareBudget $budget,FinalReadConnection $readDb,\Closure $liveAuthority,\Closure $frame):FinalSharePolicy {
        $liveAuthority();
        FinalShareProfile::assertCurrent();
        if (!in_array($type,[0,1,7],true) || $recipient==='' || ($type===0 && $recipient===$subject)) {throw new \RuntimeException('Final sharing recipient is invalid');}
        $core=FinalReadCore::fresh($subject,$readDb);
        // Native receiver existence does not imply/enforce recipient Forms or MCP enrollment.
        $users=Server::get(\OCP\IUserManager::class);
        if ($users::class!==\OC\User\Manager::class) {throw new \RuntimeException('Final sharing user manager is unqualified');}
        $users=clone $users;$backends=[];
        foreach ($users->getBackends() as $key=>$backend) {
            if ($backend::class!==\OC\User\Database::class || (new \ReflectionProperty($backend,'table'))->getValue($backend)!=='users') {throw new \RuntimeException('Final sharing user backend is unqualified');}
            $backend=clone $backend;FinalReadCore::backend($backend,$readDb);$backends[$key]=$backend;
        }
        self::set($users,'backends',$backends);self::set($users,'cachedUsers',[$subject=>$core->user()]);
        self::set($users,'config',new SharingConfig($core));
        $groups=$core->groups();self::set($groups,'userManager',$users);$circleReadActor=null;
        if($type===7){
            if((int)($actual['share_type']??-1)!==7||($actual['share_with']??null)!==$recipient||($actual['uid_owner']??null)!==$subject||(int)($actual['file_source']??0)!==$nodeId||(int)($actual['parent']??0)!==0||!isset($actual['id']))throw new \RuntimeException('Final Circle share tuple changed');
            $circleReadActor=self::circleReceiverEvidence($subject,$recipient,$readDb);
            $receiver=self::circleReceiver($recipient);
        }else{
            $receiver=$type===0?$users->get($recipient):$groups->get($recipient);
            if ($receiver===null || ($type===0 && !$users->userExists($recipient)) || ($type===1 && !$groups->groupExists($recipient))) {throw new \RuntimeException('Final sharing recipient disappeared');}
            $receiver=$type===0?new \OCP\Interaction\Receivers\UserReceiver($recipient,$receiver):new \OCP\Interaction\Receivers\GroupReceiver($recipient,$receiver);
        }
        $db=$readDb;
        $query=$db->getQueryBuilder();$query->select('configkey','configvalue')->from('appconfig')->where($query->expr()->eq('appid',$query->createNamedParameter('core')))->andWhere($query->expr()->eq('lazy',$query->createNamedParameter(0,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))->andWhere($query->expr()->in('configkey',$query->createNamedParameter(FinalShareConfig::KEYS,\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(count(FinalShareConfig::KEYS)+1);
        $result=$query->executeQuery();$rows=$budget->rows($result->fetchAssociative(...),$result->closeCursor(...),count(FinalShareConfig::KEYS));
        $config=new FinalShareConfig($rows);
        $manager=clone SharingIdentity::manager();
        foreach (['config'=>$config,'groupManager'=>$groups,'userManager'=>$users,'shareDisableChecker'=>new \OC\Share20\ShareDisableChecker($config,$users,$groups)] as $key=>$value) {self::set($manager,$key,$value);}
        $l10n=Server::get(\OCP\L10N\IFactory::class);
        if ($l10n::class!==\OC\L10N\Factory::class) {throw new \RuntimeException('Final sharing localization is unqualified');}
        FinalShareNativeChecks::collect($manager,$users,$root,$core->user(),$node,$actual,$budget,$readDb,$liveAuthority,$frame,$circleReadActor);
        $coreHandler=new \OC\Core\Listener\RestrictInteractionListener($manager,$groups,$l10n);
        $handlers=[];
        foreach (FinalShareProfile::resourceOrder() as $class) {
            $handlers[]=$class===\OCA\Files\Listener\RestrictInteractionListener::class
                ?new \OCA\Files\Listener\RestrictInteractionListener($l10n)
                :new \OCA\Files_Sharing\Listener\RestrictInteractionListener(new FinalShareHomeRoot($subject,$home),$manager,$l10n);
        }
        $liveAuthority();
        $policy=FinalSharePolicy::collect($core->user(),$receiver,$nodeId,$coreHandler,$handlers,$frame);
        $liveAuthority();
        return $policy;
    }
}
