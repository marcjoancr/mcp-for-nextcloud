<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use PHPUnit\Framework\TestCase;
final class MailAccessGuardTest extends TestCase {
    public function testFolderScopeNeverAuthorizesAccountAndAccountIncludesDescendants(): void {
        $scope=(new ScopeStub())->restrict('mail',['mailbox:7','account:12']);
        $guard=new MailAccessGuard($scope);
        self::assertTrue($guard->mailboxAllowed('alice',3,7));
        self::assertFalse($guard->accountAllowed('alice',3));
        self::assertFalse($guard->mailboxAllowed('alice',3,8));
        self::assertTrue($guard->mailboxAllowed('alice',12,8));
        self::assertTrue($guard->accountAllowed('alice',12));
        self::assertFalse($guard->unrestricted('alice'));
    }
    public function testEmptyUnrestrictedAndUnreadableAreDifferent(): void {
        $scope=new ScopeStub(); $guard=new MailAccessGuard($scope);
        self::assertTrue($guard->unrestricted('alice'));
        self::assertTrue($guard->accountAllowed('alice',1));
        $scope->restrict('mail',[]);
        self::assertFalse($guard->mailboxAllowed('alice',1,1));
        self::assertFalse($guard->accountAllowed('alice',1));
        $scope->unavailable=true;
        $this->expectException(PolicyUnavailable::class);
        $guard->mailboxAllowed('alice',1,1);
    }
}
