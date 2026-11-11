<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Access\ResourceScope;
use OCA\Mcp\Tests\Support\Access\ResourceScope\ScopeFixture;
use OCA\Mcp\Access\ResourceScope\ScopeActivation;
use OCA\Mcp\Tests\Unit\Modules\Mail\FakeMailApp;
use PHPUnit\Framework\TestCase;
final class MailScopeWriterTest extends TestCase {
    public function testOwnedTypedChoicesSaveAndUndoThroughCanonicalReceipt():void {
        $fx=new ScopeFixture($this);$native=(new FakeMailApp())->withAccount(9,'alice','Main','a@example.test')->withMailbox(9,['id'=>9,'name'=>'INBOX']);
        $resources=$fx->enumerator($native);$active=new ScopeActivation(['mail'=>true]);$writer=$fx->commands(resources:$resources,activation:$active);
        $r=$writer->setResourceScope($fx->revision(),'root','alice','mail',['mailbox:9','account:9']);
        self::assertTrue($r['changed']);self::assertSame(['account:9','mailbox:9'],$fx->scopes->forModule('alice','mail')['items']);
        self::assertSame(['mailbox:9','account:9'],array_column($resources->candidates('alice','mail','',1)['items'],'id'));
        self::assertSame(['mailbox:8'],$resources->inaccessible('alice','mail',['account:9','mailbox:8']));
        $undo=$fx->services(activation:$active)->undo->restoreReceipt($r['receiptId'],$r['revision'],'root');
        self::assertSame(1,$undo['changed']);self::assertNull($fx->scopes->forModule('alice','mail'));
        self::assertFalse((new ScopeActivation())->isActive('mail'),'Do not activate until every consumer is verified');
    }
}
