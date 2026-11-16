<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use OCA\Forms\Mcp\Service\CircleDetached\ShareWriteBoundary;
use PHPUnit\Framework\TestCase;
require_once dirname(__DIR__).'/src/Service/CircleDetached/ShareWriteBoundary.php';
final class CircleShareWriteBoundaryTest extends TestCase {
    public function testQualifiedMutationRechecksAuthorityImmediatelyBeforeStatement():void {
        $current=true;$trace=[];$share=new stdClass();$result=ShareWriteBoundary::execute(
            static function()use(&$current,&$trace):void{$trace[]='fresh';if(!$current)throw new RuntimeException('authority changed');},
            static function($actual,bool $create)use($share,&$trace):void{if($actual!==$share||!$create)throw new RuntimeException('wrong intent');$trace[]='boundary';},
            $share,true,static function()use(&$trace):int{$trace[]='statement';return 1;});
        self::assertSame(1,$result);self::assertSame(['fresh','boundary','fresh','statement'],$trace);
    }
    public function testChangedAuthorityAfterProviderBoundaryPreventsStatement():void {
        $current=true;$statement=false;$share=new stdClass();
        try {ShareWriteBoundary::execute(
            static function()use(&$current):void{if(!$current)throw new RuntimeException('authority changed');},
            static function($actual,bool $create)use(&$current,$share):void{if($actual!==$share||!$create)throw new RuntimeException('wrong intent');$current=false;},
            $share,true,static function()use(&$statement):int{$statement=true;return 1;});self::fail('stale authority was accepted');}
        catch(RuntimeException $error){self::assertSame('authority changed',$error->getMessage());}
        self::assertFalse($statement);
    }
}
