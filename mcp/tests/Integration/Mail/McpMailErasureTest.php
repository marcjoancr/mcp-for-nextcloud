<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailErasureTest extends IntegrationTestCase {
    private function proof(string $scenario):array {if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Native erasure fixture only.');$r=MailActor::run(MailActor::ALICE,'erasure-native',['scenario'=>$scenario]);if(isset($r['fixture_error']))self::fail(json_encode($r));return $r;}
    public function testOrdinaryRemovalPurgesOnlyTerminalPayloadsAndPreservesUnresolvedAccounts():void {
        foreach($this->proof('ordinary') as $state=>$r){$held=!in_array($state,['cancelled:not_started','blocked:not_started','accepted:verified'],true);self::assertSame($held,$r['refused'],$state);self::assertSame($held?1:0,$r['accounts'],$state);self::assertSame($held?1:0,$r['payloads'],$state);}
    }
    public function testActualCoreUserDeletionErasesEveryDeliveryStateAndOrphanWithoutTouchingOthers():void {$r=$this->proof('user');self::assertTrue($r['deleted']);self::assertSame(0,$r['accounts']);self::assertSame(0,$r['payloads']);self::assertTrue($r['unrelated']);self::assertTrue($r['replay_missing']);}
    public function testDelayedDuplicateEventAndRetryCannotEraseRecreatedUidAccounts():void {$r=$this->proof('recreated');self::assertSame(0,$r['accounts']);self::assertSame(0,$r['payloads']);self::assertSame(1,$r['new_accounts']);self::assertSame(1,$r['new_payloads']);}

    public function testIndependentPhysicalOwnerLeasePreventsPartialErasureAndPersistsExactRetry():void {$r=$this->proof('busy');self::assertTrue($r['independent']);self::assertTrue($r['deleted']);self::assertSame(1,$r['busy_accounts']);self::assertSame(10,$r['busy_payloads']);self::assertSame(10,$r['still_payloads']);self::assertSame('blocked',$r['worker_state']);self::assertTrue($r['admission_refused']);foreach($r['privacy'] as $boundary=>$closed)self::assertTrue($closed,$boundary);self::assertSame(1,$r['new_accounts']);self::assertSame(1,$r['new_payloads']);}
    public function testReentrantLifecycleCannotPurgeAnActiveWorkerOnTheSameSession():void {$r=$this->proof('reentrant');self::assertSame(1,$r['busy_accounts']);self::assertSame(10,$r['busy_payloads']);self::assertSame('blocked',$r['worker_state']);self::assertTrue($r['admission_refused']);foreach($r['privacy'] as $boundary=>$closed)self::assertTrue($closed,$boundary);self::assertSame(1,$r['new_accounts']);self::assertSame(1,$r['new_payloads']);}
    public function testFailedCoreBackendDeletionNeverActivatesErasureOrStrandsNextRequestCreation():void {$r=$this->proof('failed');self::assertFalse($r['deleted']);self::assertSame('',$r['deleted_flag']);self::assertSame(1,$r['accounts']);self::assertSame(1,$r['payloads']);self::assertSame(2,$r['after_accounts']);self::assertSame(1,$r['after_payloads']);}

    public function testCaptureExcludesConcurrentCreationAndUnmatchedPostCannotConfirm():void {$r=$this->proof('capture');self::assertTrue($r['crossing_refused']);self::assertSame(1,$r['unmatched_accounts']);self::assertSame(1,$r['unmatched_payloads']);self::assertSame(0,$r['accounts']);self::assertSame(0,$r['payloads']);self::assertTrue($r['job_registered']);}
}
