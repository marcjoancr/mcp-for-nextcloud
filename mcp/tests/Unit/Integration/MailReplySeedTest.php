<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Integration;
use OCA\Mcp\Tests\Integration\Mail\MailLifecycleActor;
use PHPUnit\Framework\TestCase;
final class MailReplySeedTest extends TestCase {
    public function testStockFieldsRequireNeitherNativeAccessorsNorCleanupDatabase():void {
        $message=new class {public function getFieldTypes():array{return ['uid'=>'integer'];}public function __call(string $name,array $arguments):never{throw new \LogicException('Stock provenance accessor must never run');}};
        self::assertSame(['supported'=>false],MailLifecycleActor::replyProvenance($message));
        MailLifecycleActor::restoreReply(1,['supported'=>false]);
        self::assertTrue(true,'Unsupported cleanup must not resolve any Nextcloud database service');
    }
    public function testQualifiedFieldsRetainExactNullOrKnownValues():void {
        foreach([[null,null],['42','profile-canary']]as[$generation,$profile]){
            $message=new class($generation,$profile){public function __construct(private ?string $generation,private ?string $profile){}public function getFieldTypes():array{return ['tagCacheGeneration'=>'string','tagCacheProfile'=>'string'];}public function getTagCacheGeneration():?string{return $this->generation;}public function getTagCacheProfile():?string{return $this->profile;}};
            self::assertSame(['supported'=>true,'generation'=>$generation,'profile'=>$profile],MailLifecycleActor::replyProvenance($message));
        }
    }
    public function testPartialProvenanceContractIsNotTreatedAsStock():void {
        $message=new class {public function getFieldTypes():array{return ['tagCacheGeneration'=>'string'];}};
        $this->expectException(\RuntimeException::class);MailLifecycleActor::replyProvenance($message);
    }
}
