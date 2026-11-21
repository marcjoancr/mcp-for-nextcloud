<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Prove the exact native ACL getters will consume initialized scalar metadata only. */
final class FinalNativeAcl {
    /** Unwrap only an already initialized, pinned native lazy root; never invoke its closure. */
    public static function root(): \OC\Files\Node\Root {
        FinalShareProfile::assertCurrent();$root=\OCP\Server::get(\OCP\Files\IRootFolder::class);
        if($root::class===\OC\Files\Node\LazyRoot::class){
            foreach([\OC\Files\Node\LazyRoot::class=>'lib/private/Files/Node/LazyRoot.php',\OC\Files\Node\LazyFolder::class=>'lib/private/Files/Node/LazyFolder.php']as$class=>$path)if(realpath((new \ReflectionClass($class))->getFileName())!==realpath(\OC::$SERVERROOT.'/'.$path))throw new \RuntimeException('Final native root wrapper is unqualified');
            $property=new \ReflectionProperty(\OC\Files\Node\LazyFolder::class,'folder');$root=$property->isInitialized($root)?$property->getValue($root):null;
        }
        if(!is_object($root)||$root::class!==\OC\Files\Node\Root::class)throw new \RuntimeException('Final native root must already be initialized');
        return$root;
    }
    /** Native mount preparation precedes activation and all private final read lifetimes. */
    public static function prepareSubjects(array $subjects,\Closure $fresh):void {
        $fresh();$root=self::root();$manager=(new \ReflectionProperty($root,'mountManager'))->getValue($root);
        if($manager::class!==\OC\Files\Mount\Manager::class||$manager!==\OCP\Server::get(\OCP\Files\Mount\IMountManager::class))throw new \RuntimeException('Final native mount manager is unqualified');
        $setup=$manager->getSetupManager();if($setup::class!==\OC\Files\SetupManager::class)throw new \RuntimeException('Final native setup manager is unqualified');
        foreach(array_unique($subjects)as$uid){$fresh();StorageAdmission::assertExistingHome($uid);$user=(new FreshCore($uid))->user();$setup->setupForUser($user);if(!$setup->isSetupComplete($user))throw new \RuntimeException('Final native mount setup is incomplete');$fresh();}
    }
    public static function initialized(\OCP\Files\Node $node):void {
        if (!in_array($node::class,[\OC\Files\Node\File::class,\OC\Files\Node\Folder::class],true)) {throw new \RuntimeException('Final native ACL node is unqualified');}
        $property=new \ReflectionProperty(\OC\Files\Node\Node::class,'fileInfo');
        $info=$property->isInitialized($node)?$property->getValue($node):null;
        if (!is_object($info) || $info::class!==\OC\Files\FileInfo::class) {throw new \RuntimeException('Final native ACL metadata is not initialized');}
        $data=(new \ReflectionProperty(\OC\Files\FileInfo::class,'data'))->getValue($info);
        if (is_object($data)) {
            if ($data::class!==\OC\Files\Cache\CacheEntry::class) {throw new \RuntimeException('Final native ACL cache is unqualified');}
            $data=(new \ReflectionProperty(\OC\Files\Cache\CacheEntry::class,'data'))->getValue($data);
        }
        $permission=$data['permissions']??null;
        $canonical=is_int($permission)||(is_string($permission)&&preg_match('/^(?:0|[1-9][0-9]*)$/D',$permission)===1&&(string)(int)$permission===$permission);
        if (!is_array($data) || !$canonical || (int)$permission<0 || (int)$permission>31) {throw new \RuntimeException('Final native ACL permission metadata is malformed');}
    }
    public static function readable(\OCP\Files\Node $node,bool $create=false):void {
        self::initialized($node);
        if (!$node->isReadable() || ($create && (!$node instanceof \OCP\Files\Folder || !$node->isCreatable()))) {throw new \RuntimeException('Transfer final native access changed');}
    }
}
