<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Comments\FormsRecoveredCommentsCursor;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class FormsRecoveredCommentsCursorTest extends TestCase {
    private OperationActors $actors;
    private ConnectionContext $connection;
    private FormsRecoveredCommentsCursor $cursors;
    protected function setUp():void {
        $this->actors=new OperationActors();$this->connection=new ConnectionContext();$this->connection->set(12);
        $this->cursors=new FormsRecoveredCommentsCursor(new PlanState((new InMemoryConfig())->mock($this)),$this->actors,$this->connection);
    }
    public function testAnArchiveContinuationRetainsItsExactLastCommentPosition():void {
        $token=$this->cursors->create('alice','restore-operation',str_repeat('a',64),103);
        self::assertSame(103,$this->cursors->verify('alice',$token,'restore-operation',str_repeat('a',64)));
    }
    public function testCursorCannotBeMovedToAnotherOperationArchiveUserOrConnection():void {
        $token=$this->cursors->create('alice','restore-operation',str_repeat('a',64),103);
        foreach([['alice','other-operation',str_repeat('a',64)],['alice','restore-operation',str_repeat('b',64)],['bob','restore-operation',str_repeat('a',64)]] as [$uid,$operation,$archive]) {
            $denied=false;
            try {$this->cursors->verify($uid,$token,$operation,$archive);}catch(ToolFailure){$denied=true;}
            self::assertTrue($denied,'A cursor moved to a different archive or principal.');
        }
        $this->connection->set(99);
        $this->expectException(ToolFailure::class);$this->cursors->verify('alice',$token,'restore-operation',str_repeat('a',64));
    }
    public function testRecreatedActorCannotReadItsPredecessorsArchivePage():void {
        $token=$this->cursors->create('alice','restore-operation',str_repeat('a',64),103);$this->actors->generation='replacement';
        $this->expectException(\OCA\Mcp\Mcp\Operations\ActorRetired::class);
        $this->cursors->verify('alice',$token,'restore-operation',str_repeat('a',64));
    }
    public function testChangingTheLastPositionWithoutANewProofIsRejected():void {
        $token=$this->cursors->create('alice','restore-operation',str_repeat('a',64),103);[$body,$signature]=explode('.',$token);
        $data=json_decode(base64_decode(strtr($body,'-_','+/')),true,16,JSON_THROW_ON_ERROR);$data['after']=104;
        $changed=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=').'.'.$signature;
        $this->expectException(ToolFailure::class);$this->cursors->verify('alice',$changed,'restore-operation',str_repeat('a',64));
    }
    public function testUnboundedInputCannotReachTheArchiveReader():void {
        $this->expectException(ToolFailure::class);
        $this->cursors->verify('alice',str_repeat('x',1025),'restore-operation',str_repeat('a',64));
    }
}
