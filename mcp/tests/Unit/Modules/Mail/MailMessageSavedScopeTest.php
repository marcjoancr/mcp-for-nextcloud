<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Modules\Mail\Integration\{MailMessageGateway,MailServices};
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use PHPUnit\Framework\TestCase;
final class MailMessageSavedScopeTest extends TestCase {
    public function testDeniedExplicitMessageNeverProjectsPrivateMetadata():void {
        $native=$this->createMock(MailServices::class);
        $native->expects(self::never())->method('mailManager');
        $native->method('boundedMetadata')->willReturn(new class{public function identities(string $uid,string $kind,array $ids):array{return [['id'=>5,'account_id'=>3,'mailbox_id'=>8]];}});
        $g=new MailMessageGateway($native,new MailAccessGuard((new ScopeStub())->restrict('mail',['mailbox:7'])));
        self::assertNull($g->message('alice',5));
    }
}
