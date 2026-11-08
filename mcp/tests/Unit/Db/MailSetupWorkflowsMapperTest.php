<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;
use OCA\Mcp\Db\MailSetupWorkflowsMapper;
use OCA\Mcp\Migration\Version002700Date20261108000000;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;
final class MailSetupWorkflowsMapperTest extends TestCase {
    public function testResultBindingIsScopedExclusiveAndBoundToItsCompletionClaim(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261108000000()]);$s=new MailSetupWorkflowsMapper($db->connection());
        $s->create('scope','setup','create_account',null,[4],'digest','',1000,87400);
        self::assertNull($s->get('other','setup'));
        self::assertSame([4],$s->get('scope','setup')['before_ids']);
        self::assertTrue($s->claim('scope','setup','confirm-one',1100));
        self::assertFalse($s->claim('scope','setup','confirm-two',1100));
        self::assertFalse($s->complete('scope','setup','confirm-two',5));
        self::assertTrue($s->complete('scope','setup','confirm-one',5));
        self::assertSame(5,$s->get('scope','setup')['observed_account_id']);
        self::assertSame('completed',$s->get('scope','setup')['phase']);
    }
    public function testExpiryNeverMakesAPendingWorkflowEligibleForNewBinding(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261108000000()]);$s=new MailSetupWorkflowsMapper($db->connection());
        $s->create('scope','setup','configure_connection',4,[],'digest','before',1000,87400);
        self::assertFalse($s->claim('scope','setup','late',87401));
        self::assertSame('pending',$s->get('scope','setup')['phase']);
    }
    public function testMaintenanceRetainsUncertainBindingsAndOperationKeys(): void {
        $db=new SqliteDatabase($this,[new Version002700Date20261108000000(), new \OCA\Mcp\Migration\Version002700Date20261107000000()]);
        $flows=new MailSetupWorkflowsMapper($db->connection());
        $flows->create('scope','pending','configure_connection',4,[],'digest','before',1,100);
        $flows->create('scope','binding','configure_connection',5,[],'digest','before',1,100);
        $flows->claim('scope','binding','completion',10);
        $store=new \OCA\Mcp\Db\OperationsMapper($db->connection());
        $row=['scope_key'=>'scope','operation_id'=>'pending','actor_uid'=>'alice','tool_name'=>'mail_configure_connection',
            'effect_digest'=>'digest','target_key'=>null,'state'=>'awaiting_user','resource_refs'=>['account_id'=>4,'user_expires_at'=>100,'native_state'=>'unverified','source'=>'native_ui'], 'created_at'=>1,'updated_at'=>1];
        $store->reserve($row);
        $flows->compact(1000);$store->compact(1000);
        self::assertNull($flows->get('scope','pending'));
        self::assertSame('binding',$flows->get('scope','binding')['phase']);
        $receipt=$store->get('scope','pending');
        self::assertSame('expired',$receipt['state']);
        self::assertSame('unverified',$receipt['resource_refs']['native_state']);
        self::assertSame(4,$receipt['resource_refs']['account_id']);
        self::assertFalse($store->reserve($row));
    }

}
