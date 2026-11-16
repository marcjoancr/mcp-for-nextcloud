<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace {
    require_once dirname(__DIR__).'/src/Service/CircleNativeGraph.php';
    final class CircleNativeGraphStaticFixture {
        public static string $ambient='original';
        public string $actor='source';
    }
    final class CircleNativeGraphStaticSafetyTest extends \PHPUnit\Framework\TestCase {
        public function testCopyLeavesGlobalStaticStateUntouched():void {
            $source=new CircleNativeGraphStaticFixture();$copy=\OCA\Forms\Mcp\Service\CircleNativeGraph::copy($source,['actor'=>'request']);
            self::assertSame('original',CircleNativeGraphStaticFixture::$ambient);self::assertSame('request',$copy->actor);
        }
        public function testStaticReplacementIsRejectedWithoutChangingGlobalState():void {
            try{\OCA\Forms\Mcp\Service\CircleNativeGraph::copy(new CircleNativeGraphStaticFixture(),['ambient'=>'attacker']);self::fail('Static replacement should fail closed');}
            catch(\RuntimeException){self::assertSame('original',CircleNativeGraphStaticFixture::$ambient);}
        }
    }
}
