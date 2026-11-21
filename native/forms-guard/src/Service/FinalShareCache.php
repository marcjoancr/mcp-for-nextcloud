<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Bounded projection of the native cache query; no lazy Storage enrollment or unrelated metadata. */
final class FinalShareCache {
    private function __construct(private readonly \OCP\Files\Storage\IStorage $storage,private readonly \OCP\Files\Storage\IStorage $cacheOwner,
        private readonly \OC\Files\Cache\Cache $base,private readonly array $masks,
        private readonly \OC\Files\Cache\Storage $storageCache,private readonly int $numericId,
        private readonly \OCP\IDBConnection $db,private readonly \OC\Files\Type\Loader $loader,
        private readonly \OCP\FilesMetadata\IFilesMetadataManager $metadata,private readonly array $chain) {}
    private static function raw(object $object,string $property):mixed {
        $r=new \ReflectionProperty($object::class===\OC\Files\Cache\HomeCache::class?\OC\Files\Cache\Cache::class:$object,$property);
        if(!$r->isInitialized($object))throw new \RuntimeException('Final sharing cache initialization is required');
        return $r->getValue($object);
    }
    public static function capture(\OCP\Files\Storage\IStorage $storage,int $numericId):self {
        // Prove getWrapperStorage cannot construct FailedStorage/log before admission.
        $node=$storage;$seen=[];$chain=[];
        while($node instanceof \OC\Files\Storage\Wrapper\Wrapper){
            if(isset($seen[spl_object_id($node)])||count($seen)>=256)throw new \RuntimeException('Final sharing cache wrapper is unqualified');
            if(!in_array((new \ReflectionMethod($node,'getCache'))->getDeclaringClass()->getName(),[\OC\Files\Storage\Wrapper\Wrapper::class,\OC\Files\Storage\Wrapper\PermissionsMask::class],true))throw new \RuntimeException('Final sharing cache getter is unqualified');
            $seen[spl_object_id($node)]=true;$wrapped=self::raw($node,'storage');$chain[]=[$node,$wrapped];$node=$wrapped;
            if(!$node instanceof \OCP\Files\Storage\IStorage)throw new \RuntimeException('Final sharing cache initialization is required');
        }
        $getter=(new \ReflectionMethod($node,'getCache'))->getDeclaringClass()->getName();$home=$getter===\OC\Files\Storage\Home::class&&$node::class===\OC\Files\Storage\Home::class;
        if(!$home&&$getter!==\OC\Files\Storage\Common::class)throw new \RuntimeException('Final sharing cache getter is unqualified');
        StorageAdmission::storage($storage);$cacheOwner=$home?$node:$storage;$expected=$home?\OC\Files\Cache\HomeCache::class:\OC\Files\Cache\Cache::class;
        $base=self::raw($cacheOwner,'cache');
        if(!is_object($base)||$base::class!==$expected||self::raw($base,'storage')!==$storage)throw new \RuntimeException('Final sharing cache is unqualified');
        // Positive integer-ID reads use the exact inherited Cache::get path in HomeCache.
        if($home&&(realpath((new \ReflectionClass(\OC\Files\Cache\HomeCache::class))->getFileName())!==realpath(\OC::$SERVERROOT.'/lib/private/Files/Cache/HomeCache.php')))throw new \RuntimeException('Final native Home cache source is unqualified');
        $storageCache=self::raw($base,'storageCache');$db=self::raw($base,'connection');$loader=self::raw($base,'mimetypeLoader');$metadata=self::raw($base,'metadataManager');
        if(!is_object($storageCache)||$storageCache::class!==\OC\Files\Cache\Storage::class||self::raw($storageCache,'numericId')!==$numericId||$numericId<1
            ||!is_object($db)||$db::class!==\OC\DB\ConnectionAdapter::class||$db!==\OCP\Server::get(\OCP\IDBConnection::class)
            ||!is_object($loader)||$loader::class!==\OC\Files\Type\Loader::class||!is_array(self::raw($loader,'mimetypes'))||self::raw($loader,'mimetypes')===[]
            ||!is_object($metadata)||$metadata::class!==\OC\FilesMetadata\FilesMetadataManager::class)throw new \RuntimeException('Final sharing cache initialization is required');
        // With the base cache already present, native getCache only returns it or
        // constructs its exact pure permission-mask wrapper; no cache dependencies resolve.
        $cache=$storage->getCache();$masks=[];
        while($cache!==$base){
            if($cache::class!==\OC\Files\Cache\Wrapper\CachePermissionsMask::class||count($masks)>=1)throw new \RuntimeException('Final sharing cache wrapper is unqualified');
            $mask=self::raw($cache,'mask');if(!is_int($mask)||$mask<0||$mask>31)throw new \RuntimeException('Final sharing cache wrapper is unqualified');
            $masks[]=$cache;$cache=self::raw($cache,'cache');
        }
        return new self($storage,$cacheOwner,$base,$masks,$storageCache,$numericId,$db,$loader,$metadata,$chain);
    }
    public function assertStorage(\OCP\Files\Storage\IStorage $storage):void {
        foreach($this->chain as[$wrapper,$inner])if(self::raw($wrapper,'storage')!==$inner)throw new \RuntimeException('Final sharing cache binding changed');
        if($storage!==$this->storage||self::raw($this->cacheOwner,'cache')!==$this->base||self::raw($this->base,'storage')!==$storage
            ||self::raw($this->base,'storageCache')!==$this->storageCache||self::raw($this->storageCache,'numericId')!==$this->numericId
            ||self::raw($this->base,'connection')!==$this->db||self::raw($this->base,'mimetypeLoader')!==$this->loader||self::raw($this->base,'metadataManager')!==$this->metadata)throw new \RuntimeException('Final sharing cache binding changed');
    }
    public static function canonicalRow(array|false $row,array|false $extra,int $id,int $storage):array|false {
        if($extra!==false)throw new \RuntimeException('Final sharing cache row is ambiguous');
        if($row===false)return false;
        $int=static fn(mixed $value):bool=>is_int($value)||(is_string($value)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$value)===1&&(string)(int)$value===$value);
        foreach(['fileid','storage','permissions','mimetype','mimepart']as$key)if(!$int($row[$key]??null))throw new \RuntimeException('Final sharing cache row is malformed');
        if((int)$row['fileid']!==$id||(int)$row['storage']!==$storage||(int)$row['permissions']<0||(int)$row['permissions']>31||(int)$row['mimetype']<1||(int)$row['mimepart']<1)throw new \RuntimeException('Final sharing cache row is malformed');
        return $row;
    }
    public function read(int $id,FinalShareBudget $budget,?FinalReadConnection $readDb=null):\OC\Files\Cache\CacheEntry|false {
        $this->assertStorage($this->storage);
        // Native selectFileCache columns/predicates. Extended/metadata columns do
        // not participate in any NodeResource/path/ACL getter in this call graph.
        $query=new \OC\Files\Cache\CacheQueryBuilder(($readDb??$this->db)->getQueryBuilder(),$this->metadata);
        $query->selectFileCache(null,false);$query->whereFileId($id);$query->whereStorageId($this->numericId);$query->setMaxResults(2);
        $result=$query->executeQuery();try{$row=$result->fetchAssociative();$extra=$result->fetchAssociative();}finally{$result->closeCursor();}
        $row=self::canonicalRow($row,$extra,$id,$this->numericId);if($row===false)return false;$budget->record($row);
        $types=self::raw($this->loader,'mimetypes');
        foreach(['mimetype','mimepart']as$key)if(!is_array($types)||!is_string($types[(int)$row[$key]]??null))throw new \RuntimeException('Final sharing mimetype initialization is required');
        $entry=\OC\Files\Cache\Cache::cacheEntryFromData($row,$this->loader);
        foreach(array_reverse($this->masks)as$mask)$entry=(new \ReflectionMethod(\OC\Files\Cache\Wrapper\CachePermissionsMask::class,'formatCacheEntry'))->invoke($mask,$entry);
        $this->assertStorage($this->storage);return $entry;
    }
}
