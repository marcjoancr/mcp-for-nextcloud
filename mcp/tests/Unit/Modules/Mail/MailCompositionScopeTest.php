<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Composition\{ReplyComposer,ForwardComposer};
use OCA\Mcp\Modules\Mail\Integration\{MailGateway,MailMessageLocator,MailServices};
use OCA\Mcp\Modules\Mail\Access\MailAccessGuard;
use OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess;
use OCA\Mcp\Tests\Support\Dav\ScopeStub;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class MailCompositionScopeTest extends TestCase {
    public function testFolderOnlyCannotReadReplyIdentitiesBeforeNativeAliases():void {
        $scope=new ScopeStub();$scope->restrict('mail',['mailbox:7']);
        $accounts=new MailGateway((new FakeMailApp())->withAccount(3,'alice','A','a@example.test'),new MailAccessGuard($scope));
        $native=$this->createMock(MailServices::class);$native->expects(self::never())->method('aliasesService');
        $composer=new ReplyComposer($this->createMock(MailMessageLocator::class),static fn()=>throw new \LogicException(),$native,static fn()=>true,$accounts);
        $this->expectException(ToolFailure::class);$composer->identities('alice',$accounts->ownedAccount('alice',3),null);
    }
    public function testFolderOnlyCannotPrepareForwardIntoLocalAccount():void {
        $scope=new ScopeStub();$scope->restrict('mail',['mailbox:7']);
        $accounts=new MailGateway((new FakeMailApp())->withAccount(3,'alice','A','a@example.test'),new MailAccessGuard($scope));
        $locator=$this->createMock(MailMessageLocator::class);$locator->expects(self::never())->method('locate');
        $composer=new ForwardComposer($locator,static fn()=>throw new \LogicException(),$this->createMock(MailAttachmentAccess::class),$this->createMock(MailServices::class),static fn()=>true,$accounts);
        $this->expectException(ToolFailure::class);$composer->prepare('alice',3,99,[],true);
    }
}
