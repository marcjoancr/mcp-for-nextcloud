<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Native duplicate/path checks on closed input DTOs; no create/update/dispatcher/hook method is invoked. */
final class FinalShareNativeChecks {
    public static function collect(\OC\Share20\Manager $manager,\OCP\IUserManager $users,\OC\Files\Node\Root $root,\OCP\IUser $subject,\OCP\Files\Node $node,
        array $actual,FinalShareBudget $budget,FinalReadConnection $readDb,\Closure $fresh,\Closure $frame):void {
        $fresh();$type=(int)$actual['share_type'];
        if (!in_array($type,[0,1,7],true) || (int)$actual['file_source']!==$node->getId()) {throw new \RuntimeException('Final sharing native check identity changed');}
        if($type===7){
            if(!is_string($actual['share_with']??null)||$actual['share_with']===''||!is_string($actual['uid_owner']??null)||$actual['uid_owner']==='')throw new \RuntimeException('Final Circle share identity is malformed');
            CircleRuntime::assertCircleShareIdentity($subject->getUID(),$actual['share_with'],(int)$actual['id'],$node->getId(),$readDb);
            $mounts=FinalSharePathMounts::read($root,$subject,$node,$budget,$frame);
            $copy=clone $manager;(new \ReflectionProperty($copy,'mountManager'))->setValue($copy,$mounts);
            try{$fresh();(new \ReflectionMethod(\OC\Share20\Manager::class,'pathCreateChecks'))->invoke($copy,$node);$fresh();}
            finally{$mounts->close();}
            return;
        }
        $db=$readDb;$query=$db->getQueryBuilder();
        // Exact DefaultShareProvider::getSharesByPath predicates. Project only getters read by native duplicate checks.
        $query->select('id','share_type','share_with','uid_owner','file_source','item_type')->from('share')
            ->andWhere($query->expr()->eq('file_source',$query->createNamedParameter($node->getId())))
            ->andWhere($query->expr()->in('share_type',$query->createNamedParameter([0,1,3],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($query->expr()->in('item_type',$query->createNamedParameter(['file','folder'],\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1001);
        $result=$query->executeQuery();$rows=$budget->rows($result->fetchAssociative(...),$result->closeCursor(...),1000);$fresh();
        $project=static function(array $row)use($root,$users,$node):\OC\Share20\Share {
            if ((int)$row['id']<1 || !in_array((int)$row['share_type'],[0,1,3],true) || (int)$row['file_source']!==$node->getId() || !is_string($row['uid_owner']) || (!is_string($row['share_with']) && $row['share_with']!==null)) {throw new \RuntimeException('Final sharing duplicate row is malformed');}
            $share=new \OC\Share20\Share($root,$users);
            $share->setId((int)$row['id']);$share->setProviderId('ocinternal');$share->setShareType((int)$row['share_type']);
            if ((int)$row['share_type']!==3) {$share->setSharedWith($row['share_with']);}
            $share->setShareOwner($row['uid_owner']);$share->setNode($node);
            return $share;
        };
        $shares=[];$ids=[];
        foreach ($rows as $row) {if (isset($ids[(int)$row['id']])) {throw new \RuntimeException('Final sharing duplicate row identity');}$ids[(int)$row['id']]=true;$shares[]=$project($row);}
        $share=$project($actual);$provider=new FinalShareReadProvider($node,$shares,$frame);
        $mounts=FinalSharePathMounts::read($root,$subject,$node,$budget,$frame);
        $copy=clone $manager;
        (new \ReflectionProperty($copy,'factory'))->setValue($copy,new FinalShareReadFactory($provider,$type));
        (new \ReflectionProperty($copy,'mountManager'))->setValue($copy,$mounts);
        try {
            $fresh();
            (new \ReflectionMethod(\OC\Share20\Manager::class,$type===0?'userCreateChecks':'groupCreateChecks'))->invoke($copy,$share);
            $fresh();
            (new \ReflectionMethod(\OC\Share20\Manager::class,'pathCreateChecks'))->invoke($copy,$node);
            $fresh();
        } finally {$provider->close();$mounts->close();}
    }
}
