<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Mount\IMovableMount;
/** Bounded, complete native Root::getByIdInPath projection. Never invokes mount setup/fallback. */
final class FinalShareViews {
    private ?int $preparedPermissions=null;
    private function __construct(private readonly array $nodes,private readonly array $identities,private readonly \OC\Files\Node\Root $root,private readonly \OCP\IUser $subject,private readonly int $id,private readonly array $admissions,private readonly FinalReadConnection $readDb) {}
    private static function property(object $object,string $name):mixed {return (new \ReflectionProperty($object,$name))->getValue($object);}
    public static function read(\OC\Files\Node\Root $root,\OCP\IUser $subject,int $id,FinalShareBudget $budget,\Closure $authority,FinalReadConnection $readDb):self {
        $authority();FinalShareProfile::assertCurrent();
        return self::project($root,$subject,$id,$budget,$authority,$readDb);
    }
    /** Resolver-only native metadata renewal. Policy/profile inputs were admitted in collect. */
    public function renew(FinalShareBudget $budget,\Closure $frame):self {
        $fresh=self::project($this->root,$this->subject,$this->id,$budget,$frame,$this->readDb,$this->admissions);
        if($fresh->identities!==$this->identities)throw new \RuntimeException('Final sharing complete view set changed');
        return $fresh;
    }
    private static function project(\OC\Files\Node\Root $root,\OCP\IUser $subject,int $id,FinalShareBudget $budget,\Closure $authority,FinalReadConnection $readDb,?array $admitted=null):self {
        $authority();
        if ($root::class!==\OC\Files\Node\Root::class || $id<1 || $subject->getUID()==='') {throw new \RuntimeException('Final sharing view subject is invalid');}
        $uid=$subject->getUID();$path='/'.$uid.'/files';
        $mountManager=self::property($root,'mountManager');
        if ($mountManager::class!==\OC\Files\Mount\Manager::class) {throw new \RuntimeException('Final sharing mount manager is unqualified');}
        $setup=$mountManager->getSetupManager();
        // Native setupForProvider returns immediately for this exact condition. Missing setup is not repaired.
        if ($setup::class!==\OC\Files\SetupManager::class || !$setup->isSetupComplete($subject)) {throw new \RuntimeException('Final sharing complete mount setup is required');}
        $db=$readDb;
        $builder=$db->getQueryBuilder();$builder->select('storage','path','mimetype')->from('filecache')->where($builder->expr()->eq('fileid',$builder->createNamedParameter($id,IQueryBuilder::PARAM_INT)))->setMaxResults(2);
        $result=$builder->executeQuery();$cache=$budget->rows($result->fetchAssociative(...),$result->closeCursor(...),1);
        if (count($cache)!==1 || !is_string($cache[0]['path']??null)) {throw new \RuntimeException('Final sharing file cache disappeared');}
        $storageId=(int)$cache[0]['storage'];$internalPath=$cache[0]['path'];
        // Verbatim native getMountsForFileId predicates, with explicit non-null subject and overflow sentinel.
        $builder=$db->getQueryBuilder();
        $query=$builder->select('storage_id','root_id','user_id','mount_point','mount_id','f.path','mount_provider_class')
            ->from('mounts','m')
            ->innerJoin('m','filecache','f',$builder->expr()->eq('m.root_id','f.fileid'))
            ->where($builder->expr()->eq('m.storage_id',$builder->createNamedParameter($storageId,IQueryBuilder::PARAM_INT)))
            ->andWhere($builder->expr()->eq('f.storage',$builder->createNamedParameter($storageId,IQueryBuilder::PARAM_INT)))
            ->andWhere($builder->expr()->orX(
                $builder->expr()->eq('f.fileid',$builder->createNamedParameter($id)),
                $builder->expr()->emptyString('f.path'),
                $builder->expr()->eq($builder->func()->concat('f.path',$builder->createNamedParameter('/')),
                    $builder->func()->substring($builder->createNamedParameter($internalPath),$builder->createNamedParameter(1,IQueryBuilder::PARAM_INT),
                        $builder->func()->add($builder->func()->charLength('f.path'),$builder->createNamedParameter(1,IQueryBuilder::PARAM_INT))))))
            ->andWhere($builder->expr()->eq('user_id',$builder->createNamedParameter($uid)))->setMaxResults(1001);
        $result=$query->executeQuery();$infos=$budget->rows($result->fetchAssociative(...),$result->closeCursor(...),1000);
        if ($infos===[]) {throw new \RuntimeException('Final sharing mount cache setup is required');}
        $roots=[];$providers=[];$points=[];
        foreach ($infos as $info) {
            if (($info['user_id']??null)!==$uid || !is_string($info['mount_point']??null) || (int)($info['root_id']??0)<1 || (int)($info['storage_id']??0)!==$storageId) {throw new \RuntimeException('Final sharing mount row is malformed');}
            // array_combine(rootIDs,rootPaths) in native Root retains the LAST duplicate root mapping.
            $roots[(int)$info['root_id']]=$info['path']??'';
            $providers[]=$info['mount_provider_class']??'';$points[]=$info['mount_point'];
        }
        $providers=array_values(array_unique($providers));
        $groups=[];
        if (in_array('',$providers,true)) {$groups[] = self::property($mountManager,'mounts');}
        else {$byProvider=self::property($mountManager,'mountsByProvider');foreach ($providers as $provider) {$groups[]=$byProvider[$provider]??[];}}
        $mounts=[];
        foreach ($groups as $group) {
            if (!is_array($group) || count($group)>10000) {throw new \RuntimeException('Final sharing cumulative row budget exceeded');}
            foreach ($group as $mount) {
                if (!$mount instanceof \OCP\Files\Mount\IMountPoint) {throw new \RuntimeException('Final sharing mount is malformed');}
                $point=FinalShareMount::point($mount);
                $budget->record(['mount'=>$point,'provider'=>$mount::class]);
                if (in_array($point,$points,true)) {
                    if (count($mounts)>=1000) {throw new \RuntimeException('Final sharing row budget exceeded');}
                    $mounts[]=$mount;
                }
            }
        }
        if ($mounts===[] || array_diff($points,array_map(FinalShareMount::point(...),$mounts))!==[]) {throw new \RuntimeException('Final sharing mount setup fallback is forbidden');}
        $nodes=[];$identities=[];$admissions=[];
        foreach ($mounts as $mount) {
            $authority();
            [$storage,$rootId,$point]=FinalShareMount::inspect($mount);
            if (!array_key_exists($rootId,$roots)) {throw new \RuntimeException('Final sharing mount root changed');}
            $key=spl_object_id($mount);
            if($admitted===null){$cache=FinalShareCache::capture($storage,$storageId);}
            else{$prior=$admitted[$key]??null;if(!is_array($prior)||$prior['mount']!==$mount||$prior['storage']!==$storage||$prior['root']!==$rootId||$prior['point']!==$point)throw new \RuntimeException('Final sharing mount binding changed');$cache=$prior['cache'];$cache->assertStorage($storage);}
            $admissions[$key]=['mount'=>$mount,'storage'=>$storage,'root'=>$rootId,'point'=>$point,'cache'=>$cache];
            $entry=$cache->read($id,$budget,$readDb);
            if (!$entry) {continue;} // Exact native absent-cache view omission; its mount candidate was already counted.
            $rootPath=$roots[$rootId];$internal=ltrim($rootPath.'/'.$entry->getPath(),'/');
            $relative=ltrim(substr($internal,strlen($rootPath)),'/');$absolute=rtrim($point.$relative,'/');
            $ownerId=$storage->getOwner($relative);
            if($admitted===null){
                $users=\OCP\Server::get(\OCP\IUserManager::class);
                if ($users::class!==\OC\User\Manager::class) {throw new \RuntimeException('Final sharing owner manager is unqualified');}
                $owner=$ownerId!==false?$users->get($ownerId):null;
            }else{if(($prior['owner_id']??null)!==$ownerId)throw new \RuntimeException('Final sharing view owner changed');$owner=$prior['owner'];}
            $admissions[$key]['owner_id']=$ownerId;$admissions[$key]['owner']=$owner;
            $info=new \OC\Files\FileInfo($absolute,$storage,$entry->getPath(),$entry,$mount,$owner);
            // Pinned native Root inherits this exact metadata-only node factory from Folder.
            $node=(new \ReflectionMethod(\OC\Files\Node\Folder::class,'createNode'))->invoke($root,$absolute,$info);
            if (\OC\Files\Utils\PathHelper::getRelativePath($path,$node->getPath())!==null) {
                $identity=['id'=>$id,'path'=>$node->getPath(),'mount'=>$point,'root'=>$rootId,'storage'=>$storage->getId(),'internal'=>$node->getInternalPath()];
                $budget->record($identity);$nodes[]=$node;$identities[]=$identity;
            }
        }
        if ($nodes===[]) {throw new \RuntimeException('Final sharing native views disappeared');}
        usort($nodes,static fn($a,$b):int=>$b->getPath()<=>$a->getPath());
        usort($identities,static fn($a,$b):int=>$b['path']<=>$a['path']);
        $authority();return new self($nodes,$identities,$root,$subject,$id,$admissions,$readDb);
    }
    public function identities():array {return $this->identities;}
    /** Materialize native's complete union before any final ACL decision, including root-storage permission reads. */
    public function preparePermissions():void {
        if($this->preparedPermissions!==null)throw new \RuntimeException('Final sharing permission projection repeated');
        $permissions=0;
        foreach($this->nodes as $node) {
            FinalNativeAcl::initialized($node);
            if($node->getInternalPath()==='' && !$node->getMountPoint() instanceof IMovableMount)throw new \RuntimeException('Final sharing root-storage permission profile is unqualified');
            $permissions|=$node->getPermissions();
        }
        if($permissions<0||$permissions>31)throw new \RuntimeException('Final sharing permission projection malformed');
        $this->preparedPermissions=$permissions;
    }
    public function permissions():int {return $this->preparedPermissions??throw new \RuntimeException('Final sharing permission projection is unavailable');}
    public function node(?string $path=null):\OCP\Files\Node {
        if($path===null)return $this->nodes[0];
        foreach($this->nodes as $node)if($node->getPath()===$path)return $node;
        throw new \RuntimeException('Final sharing bound path disappeared');
    }
}
