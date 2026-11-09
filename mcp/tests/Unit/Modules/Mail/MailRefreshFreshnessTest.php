<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Integration\{MailGateway,MailNativeBoundary};
use PHPUnit\Framework\TestCase;
final class MailRefreshFreshnessTest extends TestCase {
    public function testOrdinaryMailboxReadReportsUnknownWithoutResolvingAnyRefreshService():void {
        $app=(new FakeMailApp())->withAccount(3,'alice','Mail','alice@example.test')->withMailbox(3,['id'=>7,'name'=>'INBOX']);$g=new MailGateway($app);$r=$g->mailboxes('alice',3);self::assertSame(['status'=>'unknown'],$r['mailboxes'][0]['freshness']);self::assertSame(['AccountService::find','MailboxMapper::findAll'],$app->serviceCalls());
    }
}
