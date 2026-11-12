<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Modules\Mail\Integration\MailGateway;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use PHPUnit\Framework\TestCase;
final class MailSavedScopeGatewayTest extends TestCase {
    public function testFolderSelectionFiltersAccountsAndFoldersWithoutGrantingAccountAccess():void {
        $app=(new FakeMailApp())->withAccount(3,'alice','First','a@example.test')->withAccount(4,'alice','Hidden','b@example.test')
            ->withMailbox(3,['id'=>7,'name'=>'Allowed'])->withMailbox(3,['id'=>8,'name'=>'Hidden'])->withMailbox(4,['id'=>9,'name'=>'Hidden']);
        $access=new MailAccessGuard((new ScopeStub())->restrict('mail',['mailbox:7']));
        $gateway=new MailGateway($app,access:$access);
        self::assertSame([3],array_column($gateway->accounts('alice'),'id'));
        self::assertSame([7],array_column($gateway->mailboxes('alice',3)['mailboxes'],'id'));
        self::assertNotNull($gateway->ownedAccount('alice',3));
        self::assertFalse($access->accountAllowed('alice',3));
        self::assertNull($gateway->mailboxes('alice',4));
    }
    public function testUnreadableScopeRefusesBeforeNativeLookup():void {
        $app=new FakeMailApp();$scope=new ScopeStub();$scope->unavailable=true;
        $gateway=new MailGateway($app,access:new MailAccessGuard($scope));
        try{$gateway->accounts('alice');self::fail('Unreadable scope admitted');}catch(\OCA\Mcp\Access\Policy\PolicyUnavailable){self::assertSame([],$app->serviceCalls());}
    }
}
