<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use PHPUnit\Framework\TestCase;
final class MailFreshPolicyTest extends TestCase {
    public function testInlineFenceIgnoresEnclosingSnapshotAfterRealScopeWrite():void {
        $fx=new ScopeFixture($this);$activation=new ScopeActivation(['mail'=>true]);
        $native=(new FakeMailApp())->withAccount(3,'alice','A','a@example.test')->withMailbox(3,['id'=>7,'name'=>'INBOX']);
        $writer=$fx->commands(resources:$fx->enumerator($native),activation:$activation);$writer->setResourceScope($fx->revision(),'root','alice','mail',['mailbox:7']);
        $policy=$fx->policy($fx->services(activation:$activation));
        $policy->within('alice',function()use($policy,$writer,$fx):void {
            self::assertTrue((new MailAccessGuard($policy))->mailboxAllowed('alice',3,7));
            $writer->setResourceScope($fx->revision(),'root','alice','mail',[]);
            self::assertTrue((new MailAccessGuard($policy))->mailboxAllowed('alice',3,7),'The enclosing request intentionally retains its snapshot');
            self::assertFalse($policy->freshWithin('alice',static fn($fresh)=>(new MailAccessGuard($fresh))->mailboxAllowed('alice',3,7)),'A native fence must see the intervening revoke');
        });
    }
}
