<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** One complete, bounded native findIn projection. No setup or storage factory is reachable. */
final class FinalSharePathMounts extends \OC\Files\Mount\Manager {
    private bool $used=false;
    private function __construct(private readonly string $path,private array $projection,private readonly \Closure $frame) {}
    public static function read(\OC\Files\Node\Root $root,\OCP\IUser $subject,\OCP\Files\Node $node,FinalShareBudget $budget,\Closure $frame):self {
        $frame();$path=$node->getPath();
        if (!str_starts_with($path,'/'.$subject->getUID().'/files/')) {throw new \RuntimeException('Final sharing path is outside its subject home');}
        $native=(new \ReflectionProperty($root,'mountManager'))->getValue($root);
        if ($native::class!==\OC\Files\Mount\Manager::class) {throw new \RuntimeException('Final sharing mount manager is unqualified');}
        $setup=$native->getSetupManager();
        if ($setup::class!==\OC\Files\SetupManager::class || !$setup->isSetupComplete($subject)) {throw new \RuntimeException('Final sharing complete mount setup is required');}
        $mounts=(new \ReflectionProperty($native,'mounts'))->getValue($native);
        if (!is_array($mounts) || count($mounts)>1000) {throw new \RuntimeException('Final sharing path mount budget exceeded');}
        foreach ($mounts as $key=>$mount) {
            if (!is_string($key) || !is_object($mount) || $key!==FinalShareMount::point($mount)) {throw new \RuntimeException('Final sharing mount key changed');}
            $budget->record(['mount'=>$key,'class'=>$mount::class]);
        }
        ksort($mounts,SORT_STRING);
        $prefix=(new \ReflectionMethod($native,'formatPath'))->invoke($native,$path);
        $selected=(new \ReflectionMethod($native,'binarySearch'))->invoke($native,$mounts,array_keys($mounts),$prefix);
        foreach ($selected as $mount) {FinalShareMount::inspect($mount);StorageAdmission::storage($mount->getStorage());}
        $frame();return new self($path,$selected,$frame);
    }
    public function findIn(string $path):array {
        if ($this->used || $path!==$this->path) {throw new \RuntimeException('Final sharing path projection escaped');}
        $this->used=true;($this->frame)();return $this->projection;
    }
    public function close():void {$this->used=true;$this->projection=[];}
}
