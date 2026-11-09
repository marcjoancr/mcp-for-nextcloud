<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Mcp\Operations;
use OCA\Mcp\Db\OperationsMapper;
use OCA\Mcp\Mcp\Operations\{OperationJournal,BeforeEffectFailure};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Migration\Version002700Date20261107000000;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCP\{IDBConnection};
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class LocalOperationTransactionTest extends TestCase {
    private function fixture(string $fault=''):array {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$sql->dbal->executeStatement('CREATE TABLE fixture_tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE)');$base=$sql->connection();$db=$this->createMock(IDBConnection::class);
        $db->method('inTransaction')->willReturnCallback(fn():bool=>$sql->dbal->isTransactionActive());$db->method('beginTransaction')->willReturnCallback(fn()=>$sql->dbal->beginTransaction());$db->method('rollBack')->willReturnCallback(fn()=>$sql->dbal->rollBack());
        $db->method('commit')->willReturnCallback(function()use($sql,$fault):void{if(in_array($fault,['before_commit','close_failure'],true))throw new \RuntimeException('Commit reply unavailable');$sql->dbal->commit();if($fault==='after_commit')throw new \RuntimeException('Commit reply lost');});
        $db->method('close')->willReturnCallback(function()use($sql,$fault):void{if($fault==='close_failure')throw new \RuntimeException('Connection could not be discarded');if($sql->dbal->isTransactionActive())$sql->dbal->rollBack();});
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);$connection=new ConnectionContext();$journal=new OperationJournal(new OperationsMapper($base),new PlanState((new InMemoryConfig())->mock($this)),$connection,$clock);
        $args=['name'=>'Reviewed','color'=>'#123456'];$p=$journal->plan('mail_create_tag','alice',$args,['plan_state'=>'proof']);$args+=['operation_id'=>$p['operation_id'],'plan_state'=>'proof'];return [$sql,$db,$journal,$args,$connection];
    }
    public function testNativeRowAndOpaqueCheckpointCommitTogetherAndNeverReplay():void {
        [$sql,$db,$j,$args]=$this->fixture();$calls=0;$r=$j->execute('mail_create_tag','alice',$args,function()use($sql,$db,$j,&$calls):array{return $j->atomicLocal($db,function()use($sql,$j,&$calls):array{$calls++;$sql->dbal->insert('fixture_tags',['name'=>'Reviewed']);$id=(int)$sql->dbal->lastInsertId();$j->checkpoint(['tag_id'=>$id]);return ['tag'=>['tag_id'=>$id]];});});
        self::assertSame('succeeded',$r['operation']['state']);self::assertSame(1,$r['operation']['resource_refs']['tag_id']);self::assertSame(1,(int)$sql->dbal->fetchOne('SELECT COUNT(*) FROM fixture_tags'));$replay=$j->execute('mail_create_tag','alice',$args,static fn():array=>throw new \LogicException('No second insert'));self::assertTrue($replay['operation']['replayed']);self::assertSame(1,$calls);
        (new OperationsMapper($sql->connection()))->compact(1001);$retained=$j->get('alice',$args['operation_id']);self::assertSame(1,$retained['resource_refs']['tag_id']);self::assertTrue($j->execute('mail_create_tag','alice',$args,static fn():array=>throw new \LogicException('Compaction cannot permit insertion'))['operation']['replayed']);
    }
    public function testKnownRollbackRestoresJournalMemoryWithoutPhantomIdentity():void {
        [$sql,$db,$j,$args]=$this->fixture();try{$j->execute('mail_create_tag','alice',$args,fn():array=>$j->atomicLocal($db,function()use($sql,$j):array{$sql->dbal->insert('fixture_tags',['name'=>'Reviewed']);$j->checkpoint(['tag_id'=>(int)$sql->dbal->lastInsertId()]);throw new \RuntimeException('Readback failed');}));self::fail('Rollback must refuse');}catch(BeforeEffectFailure){}
        self::assertSame(0,(int)$sql->dbal->fetchOne('SELECT COUNT(*) FROM fixture_tags'));$r=$j->get('alice',$args['operation_id']);self::assertSame('failed_before_effect',$r['state']);self::assertArrayNotHasKey('tag_id',$r['resource_refs']);
    }
    public function testCommitAcknowledgementLossUsesPersistedEvidenceAndRetainsUserWideClaim():void {
        foreach(['before_commit','after_commit']as$fault){[$sql,$db,$j,$args,$connection]=$this->fixture($fault);$r=$j->execute('mail_create_tag','alice',$args,fn():array=>$j->atomicLocal($db,function()use($sql,$j):array{$sql->dbal->insert('fixture_tags',['name'=>'Reviewed']);$j->checkpoint(['tag_id'=>(int)$sql->dbal->lastInsertId()]);return [];}));self::assertSame('uncertain',$r['operation']['state']);self::assertSame($fault==='after_commit'?1:0,(int)$sql->dbal->fetchOne('SELECT COUNT(*) FROM fixture_tags'));self::assertSame($fault==='after_commit',isset($r['operation']['resource_refs']['tag_id']));self::assertTrue($j->execute('mail_create_tag','alice',$args,static fn():array=>throw new \LogicException('No retry'))['operation']['replayed']);
            $connection->set(7);$p=$j->plan('mail_create_tag','alice',['name'=>'Other','color'=>'#ffffff'],['plan_state'=>'new']);try{$j->execute('mail_create_tag','alice',['name'=>'Other','color'=>'#ffffff','operation_id'=>$p['operation_id'],'plan_state'=>'new'],static fn():array=>throw new \LogicException('No concurrent namespace effect'));self::fail('Uncertain tag namespace must remain claimed across connections');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){}
        }
    }
    public function testUncertainTransitionPreservesNewerPersistedEvidenceWithoutRewritingReferences():void {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000()]);$store=new OperationsMapper($sql->connection());$store->reserve(['scope_key'=>'s','operation_id'=>'o','tool_name'=>'mail_create_tag','effect_digest'=>'effect','target_key'=>'held','actor_uid'=>'alice','state'=>'running','resource_refs'=>['tag_id'=>7],'created_at'=>1,'updated_at'=>1]);
        $sql->beforeStatement=static function()use($sql):void{$sql->dbal->update('mcp_operations',['resource_refs'=>json_encode(['tag_id'=>8])],['scope_key'=>'s','operation_id'=>'o']);};
        self::assertTrue($store->uncertainLocal('s','o','mail_create_tag',2));$row=$store->get('s','o');self::assertSame(['tag_id'=>8],$row['resource_refs']);self::assertSame('held',$row['target_key']);self::assertSame('uncertain',$row['state']);self::assertFalse($store->uncertainLocal('s','o','mail_create_tag',3));
    }

    public function testFailedConnectionDiscardDoesNotWriteProvisionalEvidenceOrReleaseTheClaim():void {
        [$sql,$db,$j,$args]=$this->fixture('close_failure');$refused=false;
        try{$j->execute('mail_create_tag','alice',$args,fn():array=>$j->atomicLocal($db,function()use($sql,$j):array{$sql->dbal->insert('fixture_tags',['name'=>'Reviewed']);$j->checkpoint(['tag_id'=>(int)$sql->dbal->lastInsertId()]);return [];}));}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){$refused=true;}
        self::assertTrue($refused,'An undiscarded connection cannot supply authoritative outcome evidence.');
        if($sql->dbal->isTransactionActive())$sql->dbal->rollBack();$row=$j->get('alice',$args['operation_id']);self::assertSame('running',$row['state']);self::assertArrayNotHasKey('tag_id',$row['resource_refs']);self::assertTrue($j->execute('mail_create_tag','alice',$args,static fn():array=>throw new \LogicException('No retry'))['operation']['replayed']);
    }

}
