<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../stubs/OCA/Mail/Exception/ClientException.php';
require_once dirname(__DIR__,5).'/native/mail-guard/src/Exception/DraftAdmissionFailure.php';
final class MailSnoozeHoldsQueryTest extends TestCase {
    public function testIncomingAndMembershipAcceptNativeQueryParameters():void {
        require_once dirname(__DIR__,5).'/native/mail-guard/src/Snooze/SnoozeHolds.php';
        $db=new SqliteDatabase($this,[]);
        $db->dbal->executeStatement('CREATE TABLE mail_mcp_snoozes (id TEXT,active_source TEXT,source_mailbox_id INTEGER,source_uid TEXT,snooze_mailbox_id INTEGER,current_mailbox_id INTEGER,current_uid TEXT,state TEXT,placement_phase TEXT,wake_phase TEXT)');
        \OCA\Mail\Snooze\SnoozeHolds::incoming($db->connection(),3);
        \OCA\Mail\Snooze\SnoozeHolds::mailbox($db->connection(),3,7);
        self::assertSame(2,$db->queryCount);
        $db->dbal->insert('mail_mcp_snoozes',['id'=>'held','active_source'=>'active','source_mailbox_id'=>2,'source_uid'=>'1','snooze_mailbox_id'=>3,'current_mailbox_id'=>3,'current_uid'=>'99','state'=>'snoozed','placement_phase'=>'completed','wake_phase'=>'prepared']);
        \OCA\Mail\Snooze\SnoozeHolds::incoming($db->connection(),3);
        \OCA\Mail\Snooze\SnoozeHolds::mailbox($db->connection(),3,7);
        self::assertSame(4,$db->queryCount);
        $db->dbal->executeStatement("UPDATE mail_mcp_snoozes SET placement_phase='wire_may_start'");
        $this->expectException(\OCA\Mail\Exception\DraftAdmissionFailure::class);
        \OCA\Mail\Snooze\SnoozeHolds::incoming($db->connection(),3);
    }
}
