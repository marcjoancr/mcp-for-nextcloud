<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Comments\FormsComments;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class FormsCommentPersistenceTest extends TestCase {
    private ICommentsManager $manager;
    private FormsComments $adapter;
    private IComment $comment;
    private array|false $row;
    private string $message='Original';
    private bool $save=true;
    protected function setUp():void {
        $this->row=['id'=>9,'object_type'=>'forms','object_id'=>'3','actor_type'=>'users','actor_id'=>'alice','message'=>'Original','verb'=>'comment'];
        $this->manager=$this->createMock(ICommentsManager::class);
        $this->comment=$this->createMock(IComment::class);
        $this->comment->method('getId')->willReturn('9');
        $this->comment->method('getObjectType')->willReturn('forms');
        $this->comment->method('getObjectId')->willReturn('3');
        $this->comment->method('getActorType')->willReturn('users');
        $this->comment->method('getActorId')->willReturn('alice');
        $this->comment->method('getParentId')->willReturn('0');
        $this->comment->method('getMessage')->willReturnCallback(fn():string=>$this->message);
        $this->comment->method('setMessage')->willReturnCallback(function(string $message):IComment {$this->message=trim($message);return $this->comment;});
        $this->manager->method('getCommentsWithVerbForObjectSinceComment')->willReturnCallback(fn():array=>$this->row===false?[]:[$this->comment]);
        $this->manager->method('create')->willReturn($this->comment);
        $this->manager->method('save')->willReturnCallback(function():bool {if($this->save)$this->row['message']=$this->message;return $this->save;});
        $user=$this->createMock(IUser::class);$user->method('getUID')->willReturn('alice');
        $session=$this->createMock(IUserSession::class);$session->method('getUser')->willReturn($user);
        $users=$this->createMock(IUserManager::class);$users->method('getDisplayName')->willReturn('Alice');
        $db=$this->createMock(IDBConnection::class);$result=$this->createMock(\OCP\DB\IResult::class);
        $result->method('fetch')->willReturnCallback(fn():array|false=>$this->row);
        $db->method('executeQuery')->willReturn($result);
        $this->adapter=new FormsComments($this->manager,$users,$session,$db,$this->createMock(\Psr\Log\LoggerInterface::class));
    }
    public function testFailedNativeSaveCannotAdvancePosterReadMarkerOrReportSuccess():void {
        $this->save=false;$this->manager->expects(self::never())->method('setReadMark');
        $this->expectException(ToolFailure::class);
        $this->adapter->apply('alice','forms_add_comment',['id'=>3,'message'=>'New'],[]);
    }
    public function testSuccessfulAddVerifiesNormalizedMessageAndKeepsNativePosterMarker():void {
        $this->manager->expects(self::once())->method('setReadMark');
        self::assertSame(['id'=>'9'],$this->adapter->apply('alice','forms_add_comment',['id'=>3,'message'=>"  New\n"],[]));
        self::assertSame('New',$this->row['message']);
    }
    public function testFailedNativeDeleteCannotBeReportedAsSuccessful():void {
        $approved=$this->adapter->snapshot('alice',3,'9');
        $this->manager->method('delete')->willReturn(false);
        $this->expectException(ToolFailure::class);
        $this->adapter->apply('alice','forms_delete_comment',['id'=>3,'comment_id'=>'9'],$approved);
    }
    public function testSuccessfulDeleteRequiresActualRowAbsence():void {
        $approved=$this->adapter->snapshot('alice',3,'9');
        $this->manager->method('delete')->willReturnCallback(function():bool {$this->row=false;return true;});
        self::assertSame(['id'=>'9'],$this->adapter->apply('alice','forms_delete_comment',['id'=>3,'comment_id'=>'9'],$approved));
        self::assertFalse($this->row);
    }
    public function testAFalseSuccessFromNativeDeleteIsNotAccepted():void {
        $approved=$this->adapter->snapshot('alice',3,'9');
        $this->manager->method('delete')->willReturn(true);
        $this->expectException(ToolFailure::class);
        $this->adapter->apply('alice','forms_delete_comment',['id'=>3,'comment_id'=>'9'],$approved);
    }
    public function testEditUsesNativeNormalizationAndVerifiesPersistedMessage():void {
        $approved=$this->adapter->snapshot('alice',3,'9');
        self::assertSame(['id'=>'9'],$this->adapter->apply('alice','forms_update_comment',['id'=>3,'comment_id'=>'9','message'=>'  Changed  '],$approved));
        self::assertSame('Changed',$this->row['message']);
    }
}
