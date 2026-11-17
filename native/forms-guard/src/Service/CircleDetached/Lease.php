<?php
/* UNAPPLIED R2 proposal. SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service\CircleDetached;
use OCA\Forms\Mcp\Service\{CircleExistingIdentity,CircleReadConfig,CircleReadData,FreshCore,FinalReadConnection,FinalReadCore};
/** Owned by one stack frame; no static current scope, service clone, enrollment or ambient session. */
final class Lease {
    private bool $active=true;
    private array $identity;
    private array $configData;
    private Config $config;
    private ModelManager $manager;
    private CircleRequest $circles;
    private MemberRequest $members;
    private MembershipRequest $memberships;
    private RemoteRequest $remotes;
    private ?ShareWrapperRequest $shareWrappers=null;
    private string $keyhole;
    private int $objects=0;
    private int $queries=0;
    private int $started;
    private const MODELS=['Circle','Member','FederatedUser','Membership','CircleInvitation','ShareWrapper','FileCacheWrapper','ShareToken','Mount','Mountpoint'];
    public function __construct(
        private readonly string $uid,
        private readonly \OCP\IDBConnection $connection,
        private readonly \OC\SystemConfig $systemConfig,
        private readonly \Psr\Log\LoggerInterface $log,
        private readonly \OCP\IURLGenerator $urlGenerator,
        private readonly \Closure $assertAuthority,
        private readonly \Closure $readSystem,
        private readonly SourceGate $sources,
    ) {
        $this->started=hrtime(true);
        ($this->assertAuthority)();$this->sources->assert($this->uid);
        $core=$connection instanceof FinalReadConnection?FinalReadCore::fresh($uid,$connection):new FreshCore($uid);
        if(!$core->apps()->isEnabledForUser('forms',$core->user())||!$core->apps()->isEnabledForUser('circles',$core->user())){throw new \RuntimeException('Circle actor application access is unavailable');}
        $this->identity=CircleExistingIdentity::read($connection,$uid);
        [$this->configData,$this->keyhole]=$this->readConfig();
        $this->config=new Config($this,$this->configData,$this->keyhole);
        $this->manager=$this->construct(ModelManager::class,[]);
        $this->circles=new CircleRequest($this);$this->members=new MemberRequest($this);
        $this->memberships=new MembershipRequest($this);$this->remotes=new RemoteRequest($this);
    }
    public function actorUid(): string {$this->assertActive();return $this->uid;}
    public function assertActive(): void {if(!$this->active||hrtime(true)-$this->started>18000000000){throw new \RuntimeException('Circle request lease ended');}}
    public function checkpoint(): void {
        $this->assertActive();
        if(++$this->queries>128){throw new \RuntimeException('Circle query budget exceeded');}
        ($this->assertAuthority)();$this->sources->assert($this->uid);
        $core=$this->connection instanceof FinalReadConnection?FinalReadCore::fresh($this->uid,$this->connection):new FreshCore($this->uid);
        if(!$core->apps()->isEnabledForUser('forms',$core->user())||!$core->apps()->isEnabledForUser('circles',$core->user())){throw new \RuntimeException('Circle actor application access changed');}
        if(CircleExistingIdentity::read($this->connection,$this->uid)!==$this->identity||$this->readConfig()!==[$this->configData,$this->keyhole]){throw new \RuntimeException('Circle actor identity or configuration changed');}
    }
    private function readConfig(): array {
        $system=($this->readSystem)();
        if(!is_array($system)){throw new \RuntimeException('Circle system configuration is malformed');}
        $keyhole=$system['circles.keyhole_cfg_request']??'';unset($system['circles.keyhole_cfg_request']);
        $data=CircleReadConfig::read($this->connection,$system);
        $r=$this->connection->executeQuery('SELECT SUBSTRING(configvalue,1,3) AS value FROM *PREFIX*appconfig WHERE appid = ? AND configkey = ? LIMIT 2',['circles','keyhole_cfg_request']);
        try{$rows=$r->fetchAll();}finally{$r->closeCursor();}
        if(count($rows)>1){throw new \RuntimeException('Circle keyhole configuration is ambiguous');}
        if(($rows[0]['value']??'')!==''){$keyhole=$rows[0]['value'];}
        if($keyhole===''){$keyhole='0';}
        if(!in_array($keyhole,['0','1'],true)){throw new \RuntimeException('Circle keyhole configuration is malformed');}
        return [$data,$keyhole];
    }
    private function construct(string $class,array $arguments): object {
        $this->assertActive();if(++$this->objects>1024){throw new \RuntimeException('Circle model budget exceeded');}
        $reflection=new \ReflectionClass($class);
        $object=$reflection->newInstanceWithoutConstructor();
        $object->bindCircleLease($this);
        if(($ctor=$reflection->getConstructor())!==null){$ctor->invokeArgs($object,$arguments);}
        return $object;
    }
    public function model(string $class,array $arguments=[]): object {
        $prefix='OCA\\Circles\\Model\\';
        if(!str_starts_with($class,$prefix)||!in_array(substr($class,strlen($prefix)),self::MODELS,true)){throw new \RuntimeException('Circle model class is not qualified');}
        return $this->construct(__NAMESPACE__.'\\'.substr($class,strlen($prefix)),$arguments);
    }
    public function query(): CoreQueryBuilder {return $this->construct(CoreQueryBuilder::class,[]);}
    public function manager(): ModelManager {$this->assertActive();return $this->manager;}
    public function config(): Config {$this->assertActive();return $this->config;}
    public function circles(): CircleRequest {$this->assertActive();return $this->circles;}
    public function members(): MemberRequest {$this->assertActive();return $this->members;}
    public function memberships(): MembershipRequest {$this->assertActive();return $this->memberships;}
    public function remotes(): RemoteRequest {$this->assertActive();return $this->remotes;}
    public function shareWrappers(): ShareWrapperRequest {$this->assertActive();return $this->shareWrappers??=new ShareWrapperRequest($this);}
    public function db(): \OCP\IDBConnection {$this->assertActive();return $this->connection;}
    public function system(): \OC\SystemConfig {$this->assertActive();return $this->systemConfig;}
    public function logger(): \Psr\Log\LoggerInterface {$this->assertActive();return $this->log;}
    public function url(): \OCP\IURLGenerator {$this->assertActive();return $this->urlGenerator;}
    /** Exact read-only localization used by Circles' native provider/event listeners. */
    public function circleLocalization(): \OCP\IL10N {
        $this->assertActive();
        $factory = \OCP\Server::get(\OCP\L10N\IFactory::class);
        if ($factory::class !== \OC\L10N\Factory::class) {
            throw new \RuntimeException('Native Circle localization factory is unqualified');
        }
        $localization = $factory->get('circles');
        if (!$localization instanceof \OCP\IL10N) {
            throw new \RuntimeException('Native Circle localization service is unavailable');
        }
        $this->assertActive();
        return $localization;
    }
    public function service(string $class): object {
        $this->assertActive();
        return match($class){\OCA\Circles\Model\ModelManager::class=>$this->manager,\OCA\Circles\Service\ConfigService::class=>$this->config,default=>throw new \RuntimeException('Circle global dependency is not qualified')};
    }
    public function copyMember(\OCA\Circles\IFederatedUser $user): \OCA\Circles\Model\Member {
        $m=$this->model(\OCA\Circles\Model\Member::class);$m->importFromIFederatedUser($user);
        if($user instanceof \OCA\Circles\Model\Member){$m->setLevel($user->getLevel());}
        return $m;
    }
    public function identity(string $uid): \OCA\Circles\Model\FederatedUser {
        $this->checkpoint();
        $r=$this->connection->executeQuery('SELECT uid FROM *PREFIX*users WHERE uid = ? LIMIT 2',[$uid]);try{$existing=$r->fetchAll();}finally{$r->closeCursor();}
        if(count($existing)!==1||$existing[0]['uid']!==$uid){throw new \RuntimeException('Native local Circle recipient account is unavailable');}
        $identity=CircleExistingIdentity::read($this->connection,$uid);
        $user=$this->circles->getFederatedUserBySingleId($identity['circle_id']);
        if($user->getUserId()!==$uid||$user->getUserType()!==\OCA\Circles\Model\Member::TYPE_USER||!$this->config->isLocalInstance($user->getInstance())){throw new \RuntimeException('Circle canonical identity binding changed');}
        return $user;
    }
    public function actorCircle(string $id): \OCA\Circles\Model\Circle {return $this->circles->getCircle($id,$this->identity($this->uid));}
    public function membership(string $circleId,string $singleId,bool $detailed=false): \OCA\Circles\Model\Membership {
        $membership=$this->memberships->getMembership($circleId,$singleId);
        if($detailed){$membership->setInheritanceDetails($this->circles->getCirclesByIds($membership->getInheritancePath()));}
        return $membership;
    }
    /** Native Forms validation/labels need only these fields; membership is a separate bounded read. */
    public function profile(string $id): array {
        $this->checkpoint();$circle=$this->circles->getCircle($id,null);
        $data=['id'=>$circle->getSingleId(),'display_name'=>$circle->getDisplayName(),'url'=>$circle->getUrl()];
        \OCA\Forms\Mcp\Service\CircleReadData::page([$data]);return $data;
    }
    /** Separate complete scalar user projection; profile-only Circle views never imply an empty membership. */
    public function users(string $id): array {
        $profile=$this->profile($id);
        return $this->query()->readInheritedUserIds($profile['id']);
    }
    /** Data only. This mirrors Forms' detail lookup; it is never target membership authority. */
    public function detail(string $id): array {
        $this->checkpoint();$circle=$this->circles->getCircle($id,null);
        $members=$circle->getInheritedMembers();$users=[];$memberData=[];
        foreach($members as $member){if($member->getUserType()===\OCA\Circles\Model\Member::TYPE_USER){$users[]=$member->getUserId();$memberData[]=['uid'=>$member->getUserId(),'type'=>$member->getUserType(),'member_id'=>$member->getId(),'single_id'=>$member->getSingleId(),'level'=>$member->getLevel(),'instance'=>$member->getInstance()];}}
        $data=['id'=>$circle->getSingleId(),'display_name'=>$circle->getDisplayName(),'url'=>$circle->getUrl(),'users'=>$users,'members'=>$memberData];
        CircleReadData::page([$data]);return $data;
    }
    /** Native query computes the initiator. Caller applies Forms' existing LEVEL_MEMBER comparison. */
    public function memberLevel(string $id,string $uid): int {
        $circle=$this->circles->getCircle($id,$this->identity($uid));
        return $circle->getInitiator()->getLevel();
    }

    /** Native Circle provider read model for one exact type-7 parent and all effective local recipients. */
    public function audience(string $circleId,int $parentId,int $fileId):array {
        $this->checkpoint();
        if($parentId<1||$fileId<1||$circleId===''||strlen($circleId)>31){throw new \RuntimeException('Circle audience parent binding is malformed');}
        $circle=$this->circles->getCircle($circleId,null);
        $owner=$circle->getOwner();
        if($circle->getSingleId()!==$circleId){throw new \RuntimeException('Circle audience identity changed');}
        $parent=$this->shareWrappers()->getShareById($parentId);
        $parentRow=['id'=>(int)$parent->getId(),'file_source'=>$parent->getFileSource(),'share_type'=>$parent->getShareType(),
            'share_with'=>$parent->getSharedWith(),'share_owner'=>$parent->getShareOwner(),'permissions'=>$parent->getPermissions(),'file_target'=>$parent->getFileTarget()];
        if($parentRow['id']!==$parentId||$parentRow['file_source']!==$fileId||$parentRow['share_type']!==7||$parentRow['share_with']!==$circleId||$parent->getParent()!==0){
            throw new \RuntimeException('Circle parent share binding changed');
        }
        $circleData=['id'=>$circleId,'creation'=>$circle->getCreation(),'source'=>$circle->getSource(),
            'owner'=>['uid'=>$owner->getUserId(),'member_id'=>$owner->getId(),'single_id'=>$owner->getSingleId(),
                'level'=>$owner->getLevel(),'status'=>$owner->getStatus(),'instance'=>$owner->getInstance()]];
        $nativeMembers=$circle->getInheritedMembers();
        if(count($nativeMembers)>CircleAudienceProjection::MAX_RECIPIENTS){throw new \RuntimeException('Circle audience recipient budget exceeded');}
        $members=[];
        foreach($nativeMembers as $member){
            $this->checkpoint();
            if(!$member instanceof \OCA\Circles\Model\Member){throw new \RuntimeException('Circle audience native member model changed');}
            $uid=$member->getUserId();$identityData=CircleExistingIdentity::read($this->connection,$uid);
            $this->identity($uid);
            $membership=$this->membership($circleId,$member->getSingleId(),true);
            $view=$this->shareWrappers()->getShareById($parentId,$this->identity($uid));
            $share=['id'=>(int)$view->getId(),'file_source'=>$view->getFileSource(),'share_type'=>$view->getShareType(),
                'share_with'=>$view->getSharedWith(),'parent'=>$view->getParent(),'permissions'=>$view->getPermissions(),
                'file_target'=>$view->getFileTarget(),'child_id'=>$view->getChildId(),'child_permissions'=>$view->getChildPermissions(),
                'child_file_target'=>$view->getChildFileTarget()];
            $members[]=['uid'=>$uid,'member_id'=>$member->getId(),'single_id'=>$member->getSingleId(),
                'user_type'=>$member->getUserType(),'level'=>$member->getLevel(),'status'=>$member->getStatus(),
                'instance'=>$member->getInstance(),'identity'=>$identityData,'inheritance_path'=>$membership->getInheritancePath(),'share'=>$share];
        }
        $this->checkpoint();
        return CircleAudienceProjection::build($circleId,$parentId,$fileId,$circleData,$parentRow,$members);
    }
    public function close(): void {$this->active=false;}
    public function __destruct() {$this->close();}
    private function __clone() {}
    public function __serialize(): array {throw new \LogicException('Circle leases cannot be persisted');}
}
