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
    public function testDeletedUserProjectionDoesNotBorrowTheRecreatedUsersDisplayName():void {
        $comment=$this->createMock(IComment::class);
        $comment->method('getId')->willReturn('9');$comment->method('getActorType')->willReturn('deleted_users');
        $comment->method('getActorId')->willReturn('alice');$comment->method('getMessage')->willReturn('Historical comment');
        $manager=$this->createMock(\OCP\Comments\ICommentsManager::class);$manager->method('getForObject')->willReturn([$comment]);
        $users=$this->createMock(\OCP\IUserManager::class);$users->expects(self::never())->method('getDisplayName');
        $adapter=new FormsComments($manager,$users,$this->createMock(\OCP\IUserSession::class),
            $this->createMock(\OCP\IDBConnection::class),$this->createMock(\Psr\Log\LoggerInterface::class));
        $page=\OCA\Mcp\Modules\Forms\Presentation\FormsProjection::read('forms_list_comments',$adapter->read('alice',['id'=>3]),[]);
        self::assertSame('deleted_users',$page['comments'][0]['actorType']);self::assertSame('',$page['comments'][0]['actorDisplayName']);
        self::assertFalse($page['comments'][0]['canEdit']);self::assertFalse($page['comments'][0]['canDelete']);
    }
    public function testCommentSurfaceMatchesNativeMaximumLength():void {
        $d=\OCA\Mcp\Modules\Forms\Execution\FormsCommands::definitions();
        foreach(['forms_add_comment','forms_update_comment'] as $tool)self::assertSame(IComment::MAX_MESSAGE_LENGTH,$d[$tool]['inputSchema']['properties']['message']['maxLength']);
    }
}
