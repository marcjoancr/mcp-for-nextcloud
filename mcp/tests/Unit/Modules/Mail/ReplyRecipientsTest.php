<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;

use OCA\Mcp\Modules\Mail\Composition\ReplyRecipients;
use PHPUnit\Framework\TestCase;

final class ReplyRecipientsTest extends TestCase {
    public function testReplyAllUsesReplyToDeduplicatesAliasesAndNeverCopiesBcc(): void {
        $mail = ['from' => [['email'=>'sender@example.test']], 'replyTo'=>[['email'=>'reply@example.test']],
            'to'=>[['email'=>'alice@example.test'], ['email'=>'ALIAS@example.test'], ['email'=>'other@example.test']],
            'cc'=>[['email'=>'OTHER@example.test'], ['email'=>'copy@example.test']],
            'bcc'=>[['email'=>'secret@example.test']]];
        $result = ReplyRecipients::derive($mail, ['alice@example.test','alias@example.test'], true);
        self::assertSame(['reply@example.test','other@example.test'], array_column($result['to'], 'email'));
        self::assertSame(['copy@example.test'], array_column($result['cc'], 'email'));
        self::assertSame([], $result['bcc']);
    }
    public function testSelfSentReplyGoesToOriginalVisibleRecipients(): void {
        $result=ReplyRecipients::derive(['from'=>[['email'=>'alice@example.test']],
            'to'=>[['email'=>'customer@example.test']], 'cc'=>[]], ['alice@example.test'], false);
        self::assertSame(['customer@example.test'], array_column($result['to'],'email'));
    }
    public function testHeaderInjectionIsRejected(): void {
        $this->expectException(\InvalidArgumentException::class);
        ReplyRecipients::derive(['from'=>[['email'=>"bad@example.test\r\nBcc: victim@example.test"]]],[],false);
    }
}
