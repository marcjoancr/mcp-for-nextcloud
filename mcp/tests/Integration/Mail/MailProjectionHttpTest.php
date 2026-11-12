<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\{Server,IConfig};
final class MailProjectionHttpTest extends IntegrationTestCase {
    public static function scenarios():array {return [['preview'],['effect']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('scenarios')]
    public function testWithdrawalAfterActualNativeWorkDeniesProjectionWithoutRepeatingEffect(string $scenario):void {
        if(getenv('MCP_IT_PROJECTION_HTTP')!=='1')self::markTestSkipped('Explicit coordinated projection fixture window only');
        self::assertFalse(Server::get(IConfig::class)->getSystemValueBool('app.mail.mcp-transmission.enabled',false));
        $r=MailActor::run(MailActor::ALICE,'projection-http',['scenario'=>$scenario]);
        self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        foreach(['unchanged_authority_control','no_smtp','operator_false','restored']as$key)self::assertTrue($r[$key],$key);
        self::assertSame(1,$r['barriers']);self::assertSame(1,$r['released']);self::assertSame(0,$r['timeouts']);
        if($scenario==='effect'){
            self::assertSame(1,$r['store_count']);self::assertTrue($r['durable_same_after_denied_replay']);self::assertTrue($r['no_second_store']);self::assertTrue($r['replay_denied']);self::assertTrue($r['original_status_restored']);self::assertSame('verified',$r['remote_outcome']);
            self::assertTrue($r['receipt_absent'],json_encode($r));
        }else self::assertTrue($r['private_canary_absent'],json_encode($r));
        self::assertTrue($r['public_denied']);
        self::assertFalse(Server::get(IConfig::class)->getSystemValueBool('app.mail.mcp-transmission.enabled',false));
    }
}
