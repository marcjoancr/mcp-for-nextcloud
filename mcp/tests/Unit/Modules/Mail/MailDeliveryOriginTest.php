<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Unit\{SqliteDatabase,InMemoryConfig};
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Mcp\Operations\{OperationJournal,ActorRetired};
use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Db\{OperationsMapper,OperationOriginsMapper};
use OCA\Mcp\Modules\Mail\Access\MailDeliveryOrigin;
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261111100000};
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailDeliveryOriginTest extends TestCase {
    public static function sources():array {$out=[];foreach(['mail_schedule','mail_snooze','mail_write_filters','mail_transmit_filters','mail_write_absence','mail_enable_absence','mail_disable_absence']as$s)foreach([true,false]as$lost)$out[]=[$s,$lost];return $out;}
    #[\PHPUnit\Framework\Attributes\DataProvider('sources')]
    public function testOriginalActorAndNativeKeySurviveLostResponseAndActualCompaction(string $source,bool $lost):void {
        $sql=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261111100000()]);$store=new OperationsMapper($sql->connection());$mapper=new OperationOriginsMapper($sql->connection());$actors=new OperationActors();
        $connection=new ConnectionContext();$connection->set(12);$clock=$this->createStub(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $journal=new OperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$connection,$clock,$actors);$origin=new MailDeliveryOrigin($mapper,$journal,$store,$actors);
        $p=$journal->plan($source,'alice',['account_id'=>3],['plan_state'=>'p']);$id=$p['operation_id'];$scope=hash('sha256',"alice\0".'12');$key=hash('sha256',$scope."\0".$id);
        $result=$journal->execute($source,'alice',['account_id'=>3,'operation_id'=>$id,'plan_state'=>'p'],function()use($journal,$origin,$key,$source,$lost):array {
            $journal->recordAuthorization('alice',['v'=>1,'mail'=>$source==='mail_snooze'?[['account'=>3,'mailbox'=>7],['account'=>3,'mailbox'=>8]]:[['account'=>3,'mailbox'=>0]],'global'=>false,'files'=>[['91']],'tools'=>['files_read']]);
            $origin->bind('alice',$key,12,$source);if($lost)throw new \RuntimeException('Synthetic queue acknowledgement loss');return [];
        });
        self::assertSame($lost?'uncertain':'succeeded',$result['operation']['state']);$native=['operationKeyHash'=>hash('sha256',$key),'connectionId'=>12,'sourceTool'=>$source,'accountId'=>3];
        if($source==='mail_snooze')$native+=['sourceMailboxId'=>7,'snoozeMailboxId'=>8];
        self::assertSame($id,$origin->assertCurrent('alice',$native));$store->compact(2000);self::assertSame($id,$origin->assertCurrent('alice',$native));
        self::assertSame($lost?'alice':'',$store->get($scope,$id)['actor_uid']);
        foreach([['connectionId'=>13],['accountId'=>4],['sourceTool'=>'mail_send'],['operationKeyHash'=>str_repeat('f',64)]]as$change){try{$origin->assertCurrent('alice',array_replace($native,$change));self::fail('Foreign native context authorized');}catch(ActorRetired){self::assertTrue(true);}}
        if($source==='mail_snooze')foreach(['sourceMailboxId','snoozeMailboxId']as$field){try{$origin->assertCurrent('alice',array_replace($native,[$field=>9]));self::fail('Foreign historical folder authorized');}catch(ActorRetired){self::assertTrue(true);}}
        $actors->generation='replacement';$this->expectException(ActorRetired::class);$origin->assertCurrent('alice',$native);
    }
}
