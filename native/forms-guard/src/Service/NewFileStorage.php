<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Refuses an existing destination at the storage call, under the core's native write lock. */
final class NewFileStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    public bool $enabled=true;
    private bool $createdDirectory=false;
    public function __construct(\OCP\Files\Storage\IStorage $storage,private string $target,private ?\Closure $authorize=null) {StorageAdmission::storage($storage);parent::__construct(['storage'=>$storage]);}
    private function free(string $path): void {
        if ($this->enabled) {StorageAdmission::storage($this->getWrapperStorage());}
        if ($this->enabled && OriginContext::active()) {OriginContext::assertFileEffect();}
        if ($this->enabled && $path===$this->target && $this->authorize!==null) {($this->authorize)();}
        if ($this->enabled && $path===$this->target && $this->getWrapperStorage()->file_exists($path)) {throw new \RuntimeException('Forms destination already exists');}
        BulkDeadline::check();
    }
    public function file_put_contents(string $path,mixed $data): int|float|false {$this->free($path);return parent::file_put_contents($path,$data);}
    public function mkdir(string $path): bool {
        $this->free($path);
        $created=parent::mkdir($path);
        if ($this->enabled && $path===$this->target && $created) {$this->createdDirectory=true;}
        return $created;
    }
    public function fopen(string $path,string $mode) {if (strpbrk($mode,'waxc+')!==false) {$this->free($path);}return parent::fopen($path,$mode);}
    public static function create(\OCP\Files\Folder $folder,string $name,mixed $content,?\Closure $destination=null): \OCP\Files\File {
        StorageAdmission::node($folder);
        StorageAdmission::path($folder->getPath().'/'.$name);
        if (OriginContext::active()) {OriginContext::assertFileEffect();}
        if ($folder->nodeExists($name)) {throw new \RuntimeException('Forms destination already exists');}
        $target=rtrim($folder->getInternalPath(),'/').'/'.$name;$wrapper=null;$authorize=$destination===null?null:static fn()=>$destination($folder,true);if($authorize!==null){$authorize();}
        $folder->getMountPoint()->wrapStorage(function($path,$storage)use($target,&$wrapper,$authorize){$wrapper=new self($storage,$target,$authorize);return $wrapper;});
        try {return $folder->newFile($name,$content);}finally{if ($wrapper!==null) {$wrapper->enabled=false;}}
    }
    public static function folder(\OCP\Files\Folder $root,string $path,?\Closure $authorize=null,bool $requireNewFinal=false,?\Closure $destination=null): \OCP\Files\Folder {
        StorageAdmission::path($root->getPath().'/'.trim($path,'/'),true);
        $folder=$root;if($destination!==null){$destination($folder,false);}
        $segments=explode('/',trim($path,'/'));
        foreach ($segments as $index=>$segment) {
            if ($segment==='' || $segment==='.' || $segment==='..') {throw new \RuntimeException('Invalid native artifact folder');}
            if (OriginContext::active()) {OriginContext::assertFileEffect();}
            StorageAdmission::path($folder->getPath().'/'.$segment);
            $exists=$folder->nodeExists($segment);
            if ($requireNewFinal && $index===array_key_last($segments) && $exists) {
                throw new \RuntimeException('Native share target was created by another operation');
            }
            $write=static function()use($authorize,$destination,$folder):void{if($authorize!==null){$authorize();}if($destination!==null){$destination($folder,true);}};
            if(!$exists){$write();}$next=$exists?$folder->get($segment):self::createFolder($folder,$segment,$write);
            if (!$next instanceof \OCP\Files\Folder) {throw new \RuntimeException('Native artifact parent is not a folder');}
            $next->getId();$folder=$next;if($destination!==null){$destination($folder,false);}
        }
        return $folder;
    }
    private static function createFolder(\OCP\Files\Folder $folder, string $name, ?\Closure $authorize=null): \OCP\Files\Folder {
        StorageAdmission::node($folder);
        $target = rtrim($folder->getInternalPath(), '/') . '/' . $name;
        $wrapper = null;
        $folder->getMountPoint()->wrapStorage(function ($path, $storage) use ($target, &$wrapper, $authorize) {
            $wrapper = new self($storage, $target, $authorize);
            return $wrapper;
        });
        try {
            $created=$folder->newFolder($name);
            if ($wrapper===null || !$wrapper->createdDirectory) {
                throw new \RuntimeException('Native folder creation was not verified');
            }
            return $created;
        } finally {
            if ($wrapper !== null) {$wrapper->enabled = false;}
        }
    }
}
