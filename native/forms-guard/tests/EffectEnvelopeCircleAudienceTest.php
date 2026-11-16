<?php
/* SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use PhpParser\{NodeFinder,ParserFactory};
final class EffectEnvelopeCircleAudienceTest extends TestCase {
    public function testDeferredEffectDigestRecomputesProvider7AudienceUnderOriginalActor():void {
        $tree=(new ParserFactory)->createForNewestSupportedVersion()->parse(file_get_contents(dirname(__DIR__).'/src/Service/EffectEnvelope.php'));
        $source=file_get_contents(dirname(__DIR__).'/src/Service/EffectEnvelope.php');
        self::assertStringNotContainsString('if ($share->getShareType()===7) {throw new \\RuntimeException(\'Deferred Circle event audience is not qualified\');}',$source);
        self::assertStringContainsString('CircleRuntime::assertAvailable($uid)',$source);
        self::assertStringContainsString('CircleRuntime::linkedAudienceEvidence($uid', $source);
        self::assertNotEmpty((new NodeFinder)->findInstanceOf($tree,PhpParser\Node\Stmt\ClassMethod::class));
    }
}
