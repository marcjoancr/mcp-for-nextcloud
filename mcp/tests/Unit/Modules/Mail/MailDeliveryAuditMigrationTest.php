<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;
final class MailDeliveryAuditMigrationTest extends TestCase {
    public function testLaterMigrationIndexesAnExistingQueueAndIsIdempotent():void {
        $root=dirname(__DIR__,5).'/native/mail-guard/src/Migration/';$path=$root.'Version5125Date20261111010000.php';
        self::assertFileExists($path,'Already installed v11 candidates need a later indexed audit migration.');
        require_once $root.'Version5125Date20261111000000.php';require_once $path;
        $migration=new \OCA\Mail\Migration\Version5125Date20261111010000();
        $db=new SqliteDatabase($this,[new \OCA\Mail\Migration\Version5125Date20261111000000(),$migration,$migration]);
        $indexes=$db->dbal->createSchemaManager()->listTableIndexes('mail_mcp_deliveries');
        self::assertSame(['state','id'],$indexes['mail_mcp_delivery_audit']->getColumns());
        self::assertSame(['state','send_at'],$indexes['mail_mcp_delivery_due']->getColumns());
    }
}
