<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Exports\{FormsDownloadStore,FormsDownloadAdmission};
use PHPUnit\Framework\TestCase;
final class FormsDownloadAdmissionTest extends TestCase {
    private array $tokens=[];private bool $allowed=true;private int $now=1000;private ?string $session=null;private FormsDownloadAdmission $links;
    protected function setUp():void {
        $store=$this->createMock(FormsDownloadStore::class);
        $store->method('create')->willReturnCallback(function(array $row):bool{$this->tokens[$row['token_hash']]=$row;return true;});
        $store->method('get')->willReturnCallback(fn(string $hash):?array=>$this->tokens[$hash]??null);
        $store->method('consume')->willReturnCallback(function(array $old,int $now):bool{if(($this->tokens[$old['token_hash']]??null)!==$old||$old['used']||$old['expires_at']<=$now)return false;$this->tokens[$old['token_hash']]['used']=true;return true;});
        $job=['actor_uid'=>'alice','state'=>'ready','payload'=>['arguments'=>['format'=>'csv'],'origin'=>[]]];
        $this->links=new FormsDownloadAdmission($store,static fn(string $uid,string $id):array=>$job,static fn():string=>'safe-content',
            function():void{if(!$this->allowed)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('revoked');},fn():int=>$this->now,fn():?string=>$this->session,static fn(string $token):string=>'https://cloud.test/forms/'.$token);
    }
    public function testExpiredOrRevokedDownloadCannotBeRedeemed():void {
        $link=$this->links->issue('alice','job');self::assertSame(1300,$link['expires_at']);$token=basename($link['download_url']);$this->allowed=false;
        try{$this->links->redeem($token);self::fail('Revoked export returned bytes');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){}
        self::assertFalse(array_values($this->tokens)[0]['used']);$this->allowed=true;$this->now=1300;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->links->redeem($token);
    }
    public function testDownloadCanBeConsumedOnlyOnceAndNeverByAnotherLoggedInUser():void {
        $link=$this->links->issue('alice','job');$token=basename($link['download_url']);$this->session='bob';
        try{$this->links->redeem($token);self::fail('Foreign session admitted');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){}
        self::assertFalse(array_values($this->tokens)[0]['used']);$this->session=null;
        self::assertSame('safe-content',$this->links->redeem($token)['content']);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);$this->links->redeem($token);
    }
}
