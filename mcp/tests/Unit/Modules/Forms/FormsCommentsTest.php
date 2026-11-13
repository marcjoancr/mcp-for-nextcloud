<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Comments\FormsComments;
use OCP\Comments\IComment;
use PHPUnit\Framework\TestCase;
final class FormsCommentsTest extends TestCase {
    public function testCoreAuthorRuleDoesNotGrantFormOwnerOrAdministratorEditing():void {
        $c=$this->createMock(IComment::class);$c->method('getActorType')->willReturn('users');$c->method('getActorId')->willReturn('alice');
        self::assertTrue(FormsComments::isAuthor($c,'alice'));self::assertFalse(FormsComments::isAuthor($c,'form-owner'));self::assertFalse(FormsComments::isAuthor($c,'admin'));
    }
    public function testDeletedUserActorCannotBeImpersonatedByRecreatedUid():void {
        $c=$this->createMock(IComment::class);$c->method('getActorType')->willReturn('deleted_users');$c->method('getActorId')->willReturn('alice');
        self::assertFalse(FormsComments::isAuthor($c,'alice'));
    }
    public function testCommentSurfaceMatchesNativeMaximumLength():void {
        $d=\OCA\Mcp\Modules\Forms\Execution\FormsCommands::definitions();
        foreach(['forms_add_comment','forms_update_comment'] as $tool)self::assertSame(IComment::MAX_MESSAGE_LENGTH,$d[$tool]['inputSchema']['properties']['message']['maxLength']);
    }
}
