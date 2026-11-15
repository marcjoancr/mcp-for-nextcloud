<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Prove the exact native ACL getters will consume initialized scalar metadata only. */
final class FinalNativeAcl {
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
