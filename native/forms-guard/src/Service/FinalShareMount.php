<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Inspect already initialized native mounts without triggering lazy storage construction or setup. */
final class FinalShareMount {
    public static function point(object $mount):string {
        if (!in_array($mount::class,[\OC\Files\Mount\MountPoint::class,\OC\Files\Mount\HomeMountPoint::class,\OCA\Files_External\Config\ExternalMountPoint::class,\OCA\Files_Sharing\SharedMount::class],true)
            || (new \ReflectionMethod($mount,'getMountPoint'))->getDeclaringClass()->getName()!==\OC\Files\Mount\MountPoint::class) {throw new \RuntimeException('Final sharing mount class is unqualified');}
        $point=(new \ReflectionProperty(\OC\Files\Mount\MountPoint::class,'mountPoint'))->getValue($mount);
        if (!is_string($point)) {throw new \RuntimeException('Final sharing mount point is malformed');}return $point;
    }
    public static function inspect(object $mount):array {
        if (!in_array($mount::class,[\OC\Files\Mount\MountPoint::class,\OC\Files\Mount\HomeMountPoint::class],true)) {throw new \RuntimeException('Final sharing mount class is unqualified');}
        $read=static fn(string $property):mixed=>(new \ReflectionProperty(\OC\Files\Mount\MountPoint::class,$property))->getValue($mount);
        $storage=$read('storage');$root=$read('rootId');$point=$read('mountPoint');
        if (!$storage instanceof \OCP\Files\Storage\IStorage || !is_int($root) || $root<1 || !is_string($point)) {throw new \RuntimeException('Final sharing mount initialization is required');}
        return [$storage,$root,$point];
    }
}
