<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkImpactCursor;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsBulkImpactCursorTest extends TestCase {
    private FormsBulkImpactCursor $cursor;
    private ConnectionContext $connection;
    private OperationActors $actors;
    protected function setUp():void {
        $this->connection=new ConnectionContext();$this->connection->set(12);
        $this->actors=new OperationActors();
        $this->cursor=new FormsBulkImpactCursor(new PlanState((new InMemoryConfig())->mock($this)),$this->actors,$this->connection);
    }
    private function issue():string {return $this->cursor->create('alice','forms_transfer',['id'=>4,'owner_id'=>'bob'],'b1:42:'.str_repeat('a',64),'files',100);}
    public function testOpaqueCursorPreservesTheExactIntentAndNativePosition():void {
        $target=$this->cursor->verify('alice',$this->issue());
        self::assertSame('forms_transfer',$target['tool']);
        self::assertSame(['id'=>4,'owner_id'=>'bob'],$target['input']);
        self::assertSame('b1:42:'.str_repeat('a',64),$target['revision']);
        self::assertSame('files',$target['kind']);self::assertSame(100,$target['cursor']);
    }
    public function testModifiedNativePositionCannotBeRead():void {
        [$body,$signature]=explode('.',$this->issue());
        $data=json_decode(base64_decode(strtr($body,'-_','+/')),true,16,JSON_THROW_ON_ERROR);$data['cursor']=101;
        $body=rtrim(strtr(base64_encode(json_encode($data,JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->cursor->verify('alice',$body.'.'.$signature);
    }
    public function testAnotherUserCannotUseTheCursor():void {
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->cursor->verify('bob',$this->issue());
    }
    public function testAnotherConnectionCannotUseTheCursor():void {
        $token=$this->issue();$this->connection->set(13);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->cursor->verify('alice',$token);
    }
    public function testRecreatedActorCannotUseAnOldCursor():void {
        $token=$this->issue();$this->actors->generation='two';
        $this->expectException(\OCA\Mcp\Mcp\Operations\ActorRetired::class);$this->cursor->verify('alice',$token);
    }
    public function testCursorCannotCarryUnrelatedPrivateCapabilities():void {
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->cursor->create('alice','forms_delete_form',['id'=>4,'token'=>'unrelated capability'],'b1:42:'.str_repeat('a',64),'files',0);
    }
    public function testOversizedUntrustedCursorIsRefused():void {
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->cursor->verify('alice',str_repeat('a',4097));
    }
}
