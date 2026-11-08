<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Operations;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailSetupOperationTest extends TestCase {
    public function testAwaitingUserIsNotAStaleNativeWriterAndRepeatsNeverReopenSetup(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$now=1000;
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(static function()use(&$now):int{return $now;});
        $journal=new OperationJournal(new OperationsMapper($db->connection()),new PlanState((new InMemoryConfig())->mock($this)),new ConnectionContext(),$clock);
        $args=['intent'=>['email'=>'alice@example.test']];$p=$journal->plan('mail_add_account','alice',$args,['plan_state'=>'proof']);
        $args+=['operation_id'=>$p['operation_id'],'plan_state'=>'proof'];$calls=0;
        $effect=function()use($journal,&$calls):array{$calls++;$journal->awaitingUser(87400);return ['url'=>'https://cloud.example/apps/mail/setup'];};
        $r=$journal->execute('mail_add_account','alice',$args,$effect);self::assertSame('awaiting_user',$r['operation']['state']);
        $now=3000;self::assertSame('awaiting_user',$journal->get('alice',$p['operation_id'])['state']);
        $journal->execute('mail_add_account','alice',$args,$effect);self::assertSame(1,$calls);
        $now=87401;self::assertSame('expired',$journal->get('alice',$p['operation_id'])['state']);
        $journal->execute('mail_add_account','alice',$args,$effect);self::assertSame(1,$calls);
    }
}
