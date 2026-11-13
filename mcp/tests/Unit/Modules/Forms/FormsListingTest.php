<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Reading\FormsListing;
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\TestCase;
final class FormsListingTest extends TestCase {
    private function service():FormsListing {
        $connection=new ConnectionContext();$connection->set(12);
        return new FormsListing(new PlanState((new InMemoryConfig())->mock($this)),new OperationActors(),$connection);
    }
    private function rows():array {return [
        ['id'=>1,'title'=>'Zebra','ownerId'=>'alice','state'=>0,'permissions'=>['submit','edit'],'hash'=>'secret'],
        ['id'=>2,'title'=>'Árbol','ownerId'=>'bob','state'=>1,'permissions'=>['submit']],
        ['id'=>3,'title'=>'Invisible','ownerId'=>'bob','state'=>0,'permissions'=>[]]];}
    public function testFilteredListCannotRevealInvisibleCountsOrSharingCapabilities():void {
        $s=$this->service();$rows=$this->rows();$page=$s->page('alice',['kind'=>'shared','limit'=>1],$rows);
        self::assertSame(2,$page['forms'][0]['id']);self::assertFalse($page['hasMore']);self::assertArrayNotHasKey('total',$page);
        self::assertStringNotContainsString('secret',json_encode($page));self::assertStringNotContainsString('Invisible',json_encode($page));
    }
    public function testAllAuthorizedMetadataCanBeRecoveredAcrossStableContinuations():void {
        $s=$this->service();$first=$s->page('alice',['limit'=>1,'sort'=>'id','direction'=>'asc'],$this->rows());
        $next=$s->page('alice',['limit'=>1,'sort'=>'id','direction'=>'asc','cursor'=>$first['nextCursor']],$this->rows());
        self::assertSame(1,$first['forms'][0]['id']);self::assertSame(2,$next['forms'][0]['id']);self::assertFalse($next['hasMore']);
        $rows=$this->rows();$rows[0]['title']='Changed';
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $s->page('alice',['limit'=>1,'sort'=>'id','direction'=>'asc','cursor'=>$first['nextCursor']],$rows);
    }
    public function testCursorCannotChangeFilterOrActor():void {
        $s=$this->service();$first=$s->page('alice',['limit'=>1],$this->rows());
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $s->page('alice',['limit'=>1,'state'=>1,'cursor'=>$first['nextCursor']],$this->rows());
    }
}
