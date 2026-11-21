<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use OCA\Forms\Mcp\Service\OriginReadAuthority;
require_once getenv('FORMS_ORIGIN_CONTEXT_SOURCE') ?: __DIR__.'/../src/Service/OriginContext.php';

final class OriginReadAuthorityTest extends TestCase {
    public function testPrivateReadChecksFrameWithoutRebuildingAuthorityForEveryRow():void {
        $freshCalls=0;$live=true;
        $frame=static function()use(&$live):void{if(!$live)throw new RuntimeException('Expired business frame');};
        $fresh=static function()use(&$freshCalls):void{$freshCalls++;};
        $value=OriginReadAuthority::within($frame,$fresh,static function():int{
            for($i=0;$i<100;$i++)self::assertTrue(OriginReadAuthority::check());
            return 42;
        });
        self::assertSame(42,$value);self::assertSame(2,$freshCalls);self::assertFalse(OriginReadAuthority::check());
    }
    public function testRevocationDuringReadPreventsPublicationAndClearsCachedAuthority():void {
        $authorized=true;$freshCalls=0;$published=false;
        $fresh=static function()use(&$authorized,&$freshCalls):void{$freshCalls++;if(!$authorized)throw new RuntimeException('Revoked authority');};
        try {
            OriginReadAuthority::within(static fn()=>null,$fresh,static function()use(&$authorized):void{
                self::assertTrue(OriginReadAuthority::check());$authorized=false;
                self::assertTrue(OriginReadAuthority::check());
            });
            $published=true;
        }catch(RuntimeException){}
        self::assertFalse($published);self::assertSame(2,$freshCalls);self::assertFalse(OriginReadAuthority::check());
    }
    public function testChangedBusinessFrameFailsInsideRead():void {
        $live=true;$denied=false;
        $frame=static function()use(&$live):void{if(!$live)throw new RuntimeException('Expired business frame');};
        try{OriginReadAuthority::within($frame,static fn()=>null,static function()use(&$live):void{$live=false;OriginReadAuthority::check();});}
        catch(RuntimeException){$denied=true;}
        self::assertTrue($denied);self::assertFalse(OriginReadAuthority::check());
    }
    public function testNestedReadCannotReplaceTheOriginalFrame():void {
        $freshCalls=0;$nestedDenied=false;
        $fresh=static function()use(&$freshCalls):void{$freshCalls++;};
        OriginReadAuthority::within(static fn()=>null,$fresh,static function()use(&$nestedDenied):void{
            try{OriginReadAuthority::within(static fn()=>null,static fn()=>null,static fn()=>null);}
            catch(RuntimeException){$nestedDenied=true;}
            self::assertTrue(OriginReadAuthority::check());
        });
        self::assertTrue($nestedDenied);self::assertSame(2,$freshCalls);self::assertFalse(OriginReadAuthority::check());
    }
    public function testReadFailureStillRechecksAuthorityAndClosesTheLifetime():void {
        $freshCalls=0;$denied=false;
        try{OriginReadAuthority::within(static fn()=>null,static function()use(&$freshCalls):void{$freshCalls++;},static function():void{throw new RuntimeException('Source changed');});}
        catch(RuntimeException){$denied=true;}
        self::assertTrue($denied);self::assertSame(2,$freshCalls);self::assertFalse(OriginReadAuthority::check());
    }
}
