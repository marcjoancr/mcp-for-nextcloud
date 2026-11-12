<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailSignatureModeTest extends IntegrationTestCase {
    public function testNativeSignatureModeBoundaryExists():void {
        $r=MailActor::run(MailActor::ALICE,'signature-mode-native');self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));self::assertTrue($r['implementation_available'],'Native validated mode boundary is required.');
        foreach(['account_preserved','alias_preserved','serialized_mode','stale_refused','wrong_owner_refused','wrong_account_refused','invalid_refused','withdrawal_rollback','provisioned_refused','alias_provisioned_refused','alias_account_refused','alias_owner_refused','independent_owner_refused','owner_released','operator_off']as$key)self::assertTrue($r[$key],$key);
    }

    public function testActualToolsPreserveBothIdentitiesAndOriginalHistory():void {
        $r=MailActor::run(MailActor::ALICE,'signature-mode-tool');self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['fpm_shipping_available','preview_modes','alias_preserved','completed','status','replayed','private_fields_hidden','account_preserved','stale_refused','other_connection_denied','scope_status_denied','scope_replay_denied','source_grant_denied']as$key)self::assertTrue($r[$key],$key);
    }
    public function testLostLocalReceiptRecoversOnlyDurableCompletionWithoutRepeatingModeWrite():void {
        $r=MailActor::run(MailActor::ALICE,'signature-mode-tool',['scenario'=>'lost_finish']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['lost_ack_retained','lost_replay_no_effect','completion_proven','reconciled','recovery_preserved']as$key)self::assertTrue($r[$key],$key);
    }
    public static function withdrawals():array {return [['checkpoint:scope'],['checkpoint:grant'],['checkpoint:actor'],['precommit:scope'],['precommit:grant'],['precommit:actor']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('withdrawals')]
    public function testActualOriginalAuthorityWithdrawalRollsBackNativeModeAndCheckpoint(string $scenario):void {
        $r=MailActor::run(MailActor::ALICE,'signature-mode-tool',['scenario'=>$scenario]);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));foreach(['boundary_reached','effect_seen','refused','rolled_back']as$key)self::assertTrue($r[$key],$scenario.':'.$key);
    }
}
