<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsWriteCoordinator;
use OCA\Mcp\Modules\Forms\Integration\FormsGateway;
use OCA\Mcp\Mcp\Confirmation\{PlanState,PlanChanged};
use OCA\Mcp\Mcp\Operations\OperationStore;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
final class FormsWriteCoordinatorTest extends TestCase {
    private int $effects=0;
    private string $revision='1';
    private bool $allowed=true;
    private bool $snapshotFails=false;
    private array $rows=[];
    private array $extraSnapshot=[];
    private array $outcome=[];
    private bool $failApply=false;
    private FormsWriteCoordinator $coordinator;
    protected function setUp():void {
        $gateway=$this->createMock(FormsGateway::class);
        $gateway->method('snapshot')->willReturnCallback(fn():array=>$this->snapshotFails?throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Unavailable'):['revision'=>$this->revision,'form'=>['id'=>4,'title'=>'Before','ownerId'=>'alice','isAnonymous'=>false],'impact'=>['database'=>true]]+$this->extraSnapshot);
        $gateway->method('apply')->willReturnCallback(function():array{$this->effects++;if($this->failApply)throw new \RuntimeException('Lost native reply');if($this->outcome!==[])return $this->outcome;return ['state'=>'succeeded','data'=>['id'=>4,'title'=>'After','shares'=>[['token'=>'private']]],'references'=>['form_id'=>4]];});
        $gateway->method('read')->willReturn(['id'=>4,'title'=>'Before','ownerId'=>'alice','isAnonymous'=>false,'questions'=>[],'shares'=>[['token'=>'private']]]);
        $config=(new InMemoryConfig())->mock($this);$proof=new PlanState($config);
        $store=$this->createMock(OperationStore::class);
        $store->method('get')->willReturnCallback(fn(string $s,string $i):?array=>$this->rows[$s.$i]??null);
        $store->method('reserve')->willReturnCallback(function(array $r):bool{$this->rows[$r['scope_key'].$r['operation_id']]=$r;return true;});
        $store->method('checkpoint')->willReturnCallback(function(string $s,string $i,array $refs):void {$this->rows[$s.$i]['resource_refs']=$refs;});
        $store->method('finish')->willReturnCallback(function(string $s,string $i,string $state,array $refs,int $t):void{$this->rows[$s.$i]['state']=$state;$this->rows[$s.$i]['resource_refs']=$refs;});
        $connection=new ConnectionContext();$connection->set(12);
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturn(1000);
        $journal=new FormsOperationJournal($store,$proof,$connection,$clock,new OperationActors());
        $this->coordinator=new FormsWriteCoordinator($gateway,$proof,$journal,function():void{if(!$this->allowed)throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Revoked');});
    }
    public function testPreviewHasNoNativeEffectAndBindsCompleteChange():void {
        $p=$this->coordinator->preview('forms_update','alice',['id'=>4,'fields'=>['title'=>'After']]);
        self::assertSame(0,$this->effects);self::assertSame('Before',$p['before']['title']);
        self::assertSame('After',$p['changes']['title']);self::assertArrayHasKey('plan_state',$p);
        self::assertArrayHasKey('operation_id',$p);
        self::assertStringNotContainsString('private',json_encode($p));
    }
    public function testSourceChangedAfterPreviewRefusesEffect():void {
        $a=['id'=>4,'fields'=>['title'=>'After']];$p=$this->coordinator->preview('forms_update','alice',$a);$this->revision='2';
        try{$this->coordinator->call('forms_update','alice',$a+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);self::fail('Stale preview ran');}
        catch(PlanChanged $changed){self::assertSame(0,$this->effects);self::assertArrayHasKey('plan_state',$changed->plan);}
    }
    public function testPermissionRevokedAfterPreviewRefusesEffect():void {
        $a=['id'=>4,'fields'=>['title'=>'After']];$p=$this->coordinator->preview('forms_update','alice',$a);$this->allowed=false;
        try{$this->coordinator->call('forms_update','alice',$a+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);self::fail('Revoked permission ran');}
        catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure){self::assertSame(0,$this->effects);}
    }
    public function testConfirmedWriteAndReplayNeverRevealNativeSharingSecrets():void {
        $a=['id'=>4,'fields'=>['title'=>'After']];$p=$this->coordinator->preview('forms_update','alice',$a);
        $a+=['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']];
        $one=$this->coordinator->call('forms_update','alice',$a);$two=$this->coordinator->call('forms_update','alice',$a);
        self::assertSame(1,$this->effects);self::assertTrue($two['operation']['replayed']);
        self::assertStringNotContainsString('private',json_encode($one));
    }
    public function testReadOnlyPreflightFailureIsNeverAnUncertainMutation():void {
        $a=['id'=>4,'fields'=>['title'=>'After']];$p=$this->coordinator->preview('forms_update','alice',$a);$this->snapshotFails=true;
        try{$this->coordinator->call('forms_update','alice',$a+['confirm'=>true,'plan_state'=>$p['plan_state'],'operation_id'=>$p['operation_id']]);self::fail('Unavailable source ran');}
        catch(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect){self::assertSame(0,$this->effects);self::assertSame('failed_before_effect',array_values($this->rows)[0]['state']);}
    }

    public function testInitialBulkAuthorityIsCheckpointedBeforeTheNativeReplyCanBeLost():void {
        $authority=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit','share']];
        $this->extraSnapshot=['bulk_authority'=>$authority];$this->failApply=true;
        $args=['id'=>4];$plan=$this->coordinator->preview('forms_delete_form','alice',$args);
        $result=$this->coordinator->call('forms_delete_form','alice',$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]);
        self::assertSame('uncertain',$result['operation']['state']);
        self::assertSame($authority,array_values($this->rows)[0]['resource_refs']['bulk_authority']??null);
        self::assertArrayNotHasKey('bulk_authority',$result['operation']['references']);
    }
    public function testBulkControlsReserveTheirExactVersionAndKeepOriginalAuthorityOnReplyLoss():void {
        $authority=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit','share']];
        $bulk=str_repeat('a',64);$this->extraSnapshot=['bulk_authority'=>$authority];
        $this->outcome=['state'=>'pending','references'=>['bulk_id'=>$bulk,'bulk_action'=>'forms_delete_form','form_id'=>4]];
        $args=['id'=>4];$plan=$this->coordinator->preview('forms_delete_form','alice',$args);
        $this->coordinator->call('forms_delete_form','alice',$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]);
        $this->extraSnapshot=['bulk_authority'=>$authority,'bulk_id'=>$bulk,'bulk_version'=>7];$this->failApply=true;
        $args=['target_operation_id'=>$plan['operation_id']];$plan=$this->coordinator->preview('forms_continue_operation','alice',$args);
        $result=$this->coordinator->call('forms_continue_operation','alice',$args+['confirm'=>true,'plan_state'=>$plan['plan_state'],'operation_id'=>$plan['operation_id']]);
        self::assertSame('uncertain',$result['operation']['state']);
        $refs=array_values($this->rows)[1]['resource_refs'];
        self::assertSame($authority,$refs['bulk_authority']);self::assertSame(7,$refs['bulk_version']);
        self::assertSame(2,$this->effects,'Initial start and exactly one control reached the native gateway.');
    }

    public function testGridCreationNeedsAnExplicitNativeSubtype():void {
        $this->expectException(\OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException::class);
        $this->coordinator->preview('forms_create_question','alice',['id'=>4,'type'=>'grid','text'=>'Grid']);
    }
    public function testSettingsReadNeverIncludesShareCapabilities():void {
        $r=$this->coordinator->call('forms_read_settings','alice',['id'=>4]);
        self::assertSame(0,$this->effects);self::assertStringNotContainsString('private',json_encode($r));self::assertArrayNotHasKey('shares',$r);
    }
}
