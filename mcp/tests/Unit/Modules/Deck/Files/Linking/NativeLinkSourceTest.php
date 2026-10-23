<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Deck\Files\Linking;
use OCA\Deck\Db\{BoardMapper,CardMapper,StackMapper};
use OCA\Deck\Service\{BoardService,PermissionService};
use OCA\Mcp\Modules\Deck\Access\DeckCaller;
use OCA\Mcp\Modules\Deck\Files\Linking\NativeLinkSource;
use OCP\{ISession,IUserManager};
use PHPUnit\Framework\TestCase;
require_once __DIR__.'/../../../../../Support/Deck/Stubs/deck_stubs.php';
final class NativeLinkSourceTest extends TestCase {
    use \OCA\Mcp\Tests\Support\Deck\DeckTestHelpers;
    private array $state;
    private NativeLinkSource $source;
    protected function setUp():void {
        $this->state=['warm'=>true,'violations'=>[],'denied'=>null,'deletedCard'=>false,'deletedBoard'=>false,'archived'=>false,'missingStack'=>false];
        $session=$this->createMock(ISession::class);$session->method('get')->willReturn('alice');
        $boardsService=$this->createMock(BoardService::class);
        $boardsService->expects(self::never())->method('findAll');
        $cards=$this->createMock(CardMapper::class);$cards->method('findBoardId')->with(7)->willReturn(20);
        $cards->method('find')->with(7,false)->willReturnCallback(fn()=> $this->card(['id'=>7,'stackId'=>2,'deletedAt'=>$this->state['deletedCard']?1:0,'archived'=>$this->state['archived']]));
        $boards=$this->createMock(BoardMapper::class);$boards->method('flushCache')->with(20,'alice');
        $boards->method('find')->with(20,false,false,false)->willReturnCallback(fn()=> new \OCA\Deck\Db\Board(['id'=>20,'owner'=>'alice','title'=>'Plain','deletedAt'=>$this->state['deletedBoard']?1:0]));
        $stacks=$this->createMock(StackMapper::class);$stacks->method('findAll')->with(20)->willReturnCallback(fn()=> $this->state['missingStack']?[]:[new \OCA\Deck\Db\Stack(['id'=>2,'boardId'=>20])]);
        $permissions=$this->createMock(PermissionService::class);
        $permissions->method('checkPermission')->willReturnCallback(function($mapper,$id,$permission):bool {
            if($this->state['warm']){$this->state['violations'][]=$permission;}
            $this->state['warm']=true; // Even a native authorization side effect cannot cross to the next check.
            if($this->state['denied']===$permission){throw new \OCA\Deck\NoPermissionException('private source detail');}
            return true;
        });
        $this->source=new NativeLinkSource(new DeckCaller(static fn()=> $boardsService,$session),static fn()=> $cards,static fn()=> $boards,static fn()=> $stacks,static fn()=> $permissions,$this->createMock(IUserManager::class),function():void{$this->state['warm']=false;});
    }
    public function testPlainTargetNeverEnumeratesOtherCircleBoardsOrCarriesPrivilegeBetweenPermissions():void {
        $result=$this->source->context('alice',7,true);
        self::assertSame(20,$result['boardId']);self::assertSame('Plain',$result['board']['name']);
        self::assertSame([],$this->state['violations']);self::assertFalse($this->state['warm']);
    }
    public function testNativeDenialsDeletedParentsAndArchivedSourcesRefuseAndClearPrivilege():void {
        foreach([['denied',0],['denied',1],['deletedCard',true],['deletedBoard',true],['missingStack',true],['archived',true]] as [$field,$value]){
            $old=$this->state[$field];$this->state[$field]=$value;
            try{$this->source->context('alice',7,true);self::fail('Invalid source admitted');}
            catch(\OCA\Deck\NoPermissionException|\OCA\Mcp\Modules\Deck\Execution\DeckRefusalException){self::assertFalse($this->state['warm']);}
            $this->state[$field]=$old;
        }
        self::assertSame([],$this->state['violations']);
    }
}
