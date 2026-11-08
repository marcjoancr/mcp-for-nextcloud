<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use OCP\Server;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\Group;
#[Group('mail')]
final class McpMailNativeMutationTest extends IntegrationTestCase {
    public function testServerNoLostAcknowledgementAndUnsolicitedGenerationNeverReplay():void {
        if(getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Guard dependency fixture only');
        $account=MailActor::run(MailActor::ALICE,'accounts')['accounts'][0]['id'];
        foreach(['no','lost_ack','generation'] as $mode){
            $r=MailActor::run(MailActor::ALICE,'c1-protocol-fault',['account_id'=>$account,'mode'=>$mode]);
            self::assertSame($mode==='generation'?'failed_before_effect':'uncertain',$r['operation']['state'],json_encode($r));
            self::assertSame($mode==='generation'?0:1,$r['stores']);self::assertTrue($r['replayed']);self::assertFalse($r['operation']['safe_to_retry']);
            self::assertArrayNotHasKey('remote',$r['operation']['mutation']);self::assertSame($mode==='lost_ack',$r['server_applied']);
        }
    }
    public function testGuardedStoreUsesOneSelectionAndRefusesReconnectAndNewGeneration(): void {
        if(getenv('MCP_IT_WITH_MAIL')!=='1'||getenv('MCP_IT_MAIL_GUARD')!=='1')self::markTestSkipped('Mail fixture only');
        $q=Server::get(IDBConnection::class)->getQueryBuilder();
        $r=$q->select('id')->from('mail_accounts')->where($q->expr()->eq('user_id',$q->createNamedParameter(MailActor::ALICE)))->executeQuery();
        try{$id=(int)$r->fetchOne();}finally{$r->closeCursor();}
        $proof=MailActor::run(MailActor::ALICE,'c1-native-proof',['account_id'=>$id]);
        self::assertTrue($proof['applied']);self::assertTrue($proof['cleared']);self::assertTrue($proof['already_desired']);
        self::assertTrue($proof['old_generation_refused']);self::assertTrue($proof['reconnect_refused']);
        self::assertTrue($proof['wrong_message_untouched']);self::assertTrue($proof['fence_refused']);
        self::assertTrue($proof['no_send']);
    }
}
