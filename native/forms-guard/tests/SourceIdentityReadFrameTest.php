<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use FormsSourceIdentityReadFixture\SourceIdentity as Identity;

final class SourceIdentityReadFrameTest extends TestCase {
    public function testReadFrameRechecksSourcesAndChecksAuthorityForEveryUse(): void {
        $root=sys_get_temp_dir().'/forms-source-frame-'.bin2hex(random_bytes(8));
        mkdir($root.'/lib/Mcp',0700,true);
        try {
            $source=file_get_contents(__DIR__.'/../src/SourceIdentity.php');
            $source=str_replace('namespace OCA\\Forms\\Mcp;','namespace FormsSourceIdentityReadFixture;',$source);
            file_put_contents($root.'/lib/Mcp/SourceIdentity.php',$source);
            $hashes=['lib/Mcp/SourceIdentity.php'=>hash('sha256',$source)];
            for($i=0;$i<55;$i++){$name='file'.$i.'.php';file_put_contents($root.'/'.$name,'<?php return 1;');$hashes[$name]=hash_file('sha256',$root.'/'.$name);}
            file_put_contents($root.'/mcp-guard-installed.json',json_encode(['protocol'=>1,'formsVersion'=>'5.4.2','sha256'=>$hashes],JSON_THROW_ON_ERROR));
            require $root.'/lib/Mcp/SourceIdentity.php';
            self::assertTrue(Identity::verify());
            $calls=0;$frame=static function()use(&$calls):void{$calls++;};
            $result=Identity::withinVerifiedReadFrame($frame,static function():int{for($i=0;$i<5;$i++){if(!Identity::verify())throw new RuntimeException('unexpected source refusal');}return 42;});
            self::assertSame(42,$result);self::assertSame(7,$calls);

            $inside=null;$refused=false;
            try {Identity::withinVerifiedReadFrame($frame,static function()use($root,&$inside):void{file_put_contents($root.'/file0.php','<?php return 2;');$inside=Identity::verify();});}
            catch(RuntimeException){$refused=true;}
            self::assertTrue($inside);self::assertTrue($refused);self::assertFalse(Identity::verify());
            file_put_contents($root.'/file0.php','<?php return 1;');self::assertTrue(Identity::verify());

            $refused=false;
            try {Identity::withinVerifiedReadFrame($frame,static function()use($root):void{file_put_contents($root.'/unlisted.php','<?php return 1;');});}
            catch(RuntimeException){$refused=true;}
            self::assertTrue($refused);unlink($root.'/unlisted.php');

            $live=true;$refused=false;
            $authority=static function()use(&$live):void{if(!$live)throw new RuntimeException('expired read frame');};
            try {Identity::withinVerifiedReadFrame($authority,static function()use(&$live):void{$live=false;Identity::verify();});}
            catch(RuntimeException){$refused=true;}
            self::assertTrue($refused);self::assertTrue(Identity::verify());

            $nested=false;
            Identity::withinVerifiedReadFrame($frame,static function()use($frame,&$nested):void{try{Identity::withinVerifiedReadFrame($frame,static fn()=>null);}catch(RuntimeException){$nested=true;}});
            self::assertTrue($nested);self::assertTrue(Identity::verify());
        } finally {
            $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
            foreach($items as$item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root);
        }
    }
}
