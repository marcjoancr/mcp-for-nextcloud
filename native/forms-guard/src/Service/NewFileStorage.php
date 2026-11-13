<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Forms\Mcp\Service;
/** Refuses an existing destination at the storage call, under the core's native write lock. */
final class NewFileStorage extends \OC\Files\Storage\Wrapper\Wrapper {
    public bool $enabled=true;
    public function __construct(\OCP\Files\Storage\IStorage $storage,private string $target) {parent::__construct(['storage'=>$storage]);}
    private function free(string $path): void {
        if ($this->enabled && $path===$this->target && $this->getWrapperStorage()->file_exists($path)) {throw new \RuntimeException('Forms destination already exists');}
    }
    public function file_put_contents(string $path,mixed $data): int|float|false {$this->free($path);return parent::file_put_contents($path,$data);}
    public function fopen(string $path,string $mode) {if (strpbrk($mode,'waxc+')!==false) {$this->free($path);}return parent::fopen($path,$mode);}
    public static function create(\OCP\Files\Folder $folder,string $name,mixed $content): \OCP\Files\File {
        if ($folder->nodeExists($name)) {throw new \RuntimeException('Forms destination already exists');}
        $target=rtrim($folder->getInternalPath(),'/').'/'.$name;$wrapper=null;
        $folder->getMountPoint()->wrapStorage(function($path,$storage)use($target,&$wrapper){$wrapper=new self($storage,$target);return $wrapper;});
        try {return $folder->newFile($name,$content);}finally{if ($wrapper!==null) {$wrapper->enabled=false;}}
    }
    public static function folder(\OCP\Files\Folder $root,string $path): \OCP\Files\Folder {
        $folder=$root;
        foreach (explode('/',trim($path,'/')) as $segment) {
            if ($segment==='' || $segment==='.' || $segment==='..') {throw new \RuntimeException('Invalid native artifact folder');}
            $next=$folder->nodeExists($segment)?$folder->get($segment):$folder->newFolder($segment);
            if (!$next instanceof \OCP\Files\Folder) {throw new \RuntimeException('Native artifact parent is not a folder');}
            $next->getId();$folder=$next;
        }
        return $folder;
    }
}
