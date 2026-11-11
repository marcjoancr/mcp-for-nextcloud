<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Tests\Support\Mail\MailSettingsFixture;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCA\Mcp\Db\{OperationsMapper,MailSetupWorkflowsMapper};
use OCA\Mcp\Migration\{Version002700Date20261107000000,Version002700Date20261108000000};
use OCA\Mcp\Modules\Mail\Accounts\MailAccountRemoval;
use OCA\Mcp\Mcp\Operations\OperationJournal;
use OCA\Mcp\OAuth\ConnectionContext;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class MailAccountRemovalTest extends TestCase {
    use MailSettingsFixture;
    private bool $busy=false;
    private bool $deliveryBusy=false;
    private function removal():array {
        [$gateway,$entity,$native,, $states,$services]=$this->fixture();
        $services->method('localDraftType')->willReturn(1);$services->method('localOutgoingType')->willReturn(0);
        $services->method('scopedMetadataAvailable')->willReturn(true);$services->method('boundedMetadata')->willReturn(new class($this->busy){public function __construct(private bool &$busy){}public function hasLocalMessages(string $uid,int $account,int $type):bool{return $this->busy;}});
        $services->method('mailboxMapper')->willReturn(new class{public function findAll(object $a):array{return [];}});
        $db=new SqliteDatabase($this,[new Version002700Date20261107000000(),new Version002700Date20261108000000()]);$store=new OperationsMapper($db->connection());
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $delivery=$this->createMock(\OCA\Mcp\Modules\Mail\Integration\MailDeliveryGateway::class);$delivery->method('hasUnresolved')->willReturnCallback(fn():bool=>$this->deliveryBusy);
        $j=new OperationJournal($store,$states,new ConnectionContext(),$clock);
        return [new MailAccountRemoval($gateway,$services,$states,$store,new MailSetupWorkflowsMapper($db->connection()),$clock,$delivery),$j,$native,$store];
    }
    public function testEmptyNativeAccountRemovalIsConfirmedAndNeverRepeatedOnLostResponse():void {
        [$s,$j,$native]=$this->removal();$args=['account_id'=>4];$p=$j->plan('mail_delete_account','alice',$args,$s->preview($args,'alice'));
        self::assertSame(0,$native->writes);self::assertSame(0,$p['impact']['local_drafts']);self::assertFalse($p['impact']['remote_messages_deleted']);
        $a=$args+['plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];$r=$j->execute('mail_delete_account','alice',$a,fn():array=>$s->write($a,'alice'));
        self::assertSame('succeeded',$r['operation']['state']);self::assertSame(1,$native->writes);self::assertSame([],$native->accounts);
        self::assertTrue($j->execute('mail_delete_account','alice',$a,fn():array=>throw new \LogicException('Deletion replayed'))['operation']['replayed']);
    }
    public function testNativeDeliveryBlocksDeletionEvenWhenAdmissionJournalHasSucceeded():void {
        [$service,, $native]=$this->removal();$this->deliveryBusy=true;
        try{$service->preview(['account_id'=>4],'alice');self::fail('Accepted queue admission lost its unresolved native delivery hold.');}
        catch(\OCA\Mcp\Mcp\Operations\BeforeEffectFailure){self::assertSame(0,$native->writes);}
    }
    public function testLocalMessagesOrUncertainHistoryBlockDeletionWithoutErasingHistory():void {
        [$s,$j,$native,$store]=$this->removal();$this->busy=true;
        try{$s->preview(['account_id'=>4],'alice');self::fail('Local messages lost');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){}
        $this->busy=false;
        $store->reserve(['scope_key'=>'other-connection','actor_uid'=>'alice','operation_id'=>'uncertain','tool_name'=>'mail_write_signature','effect_digest'=>'digest','target_key'=>null,
            'state'=>'running','resource_refs'=>['account_id'=>4],'created_at'=>1000,'updated_at'=>1000]);$store->finish('other-connection','uncertain','uncertain',['account_id'=>4],1000);
        try{$s->preview(['account_id'=>4],'alice');self::fail('Uncertain account reference ignored');}catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){}
        self::assertSame('uncertain',$store->get('other-connection','uncertain')['state']);self::assertSame(0,$native->writes);
    }
}
