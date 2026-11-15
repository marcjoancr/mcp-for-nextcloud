<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\CircleDetached\SourceTree;
$file=dirname(__DIR__).'/src/Service/CircleDetached/SourceTree.php';if(is_file($file)){require_once$file;}
final class CircleSourceTreeTest extends TestCase {
    private string $root;
    protected function setUp():void {$this->root=sys_get_temp_dir().'/circle-tree-'.bin2hex(random_bytes(8));mkdir($this->root,0700);mkdir($this->root.'/lib');mkdir($this->root.'/vendor');mkdir($this->root.'/appinfo');file_put_contents($this->root.'/lib/Model.php',"<?php\n");file_put_contents($this->root.'/vendor/autoload.php',"<?php // native loader\n");file_put_contents($this->root.'/appinfo/info.xml','<info/>');}
    protected function tearDown():void {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$file){$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());}rmdir($this->root);}
    private function pins():array {$out=[];foreach(['lib/Model.php','vendor/autoload.php','appinfo/info.xml']as$path){$out[$path]=hash_file('sha256',$this->root.'/'.$path);}return$out;}
    public function testCompleteExecutableTreeIncludesPinnedNativeLoader():void {SourceTree::assertExact($this->root,$this->pins());self::assertTrue(true);}
    public function testLibraryOnlyMapRefusesRealAdditionalExecutable():void {$pins=$this->pins();unset($pins['vendor/autoload.php']);$this->expectException(RuntimeException::class);SourceTree::assertExact($this->root,$pins);}
    public function testUnknownExecutableRefuses():void {$pins=$this->pins();file_put_contents($this->root.'/extra.php','<?php');$this->expectException(RuntimeException::class);SourceTree::assertExact($this->root,$pins);}
    public function testMissingExecutableRefuses():void {$pins=$this->pins();unlink($this->root.'/vendor/autoload.php');$this->expectException(RuntimeException::class);SourceTree::assertExact($this->root,$pins);}
    public function testModifiedLoaderRefuses():void {$pins=$this->pins();file_put_contents($this->root.'/vendor/autoload.php','<?php // changed');$this->expectException(RuntimeException::class);SourceTree::assertExact($this->root,$pins);}
    public function testSymlinkRefuses():void {$pins=$this->pins();symlink($this->root.'/lib/Model.php',$this->root.'/alias.php');$this->expectException(RuntimeException::class);SourceTree::assertExact($this->root,$pins);}
}
