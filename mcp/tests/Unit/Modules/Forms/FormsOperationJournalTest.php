<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;

use OCA\Mcp\Mcp\Confirmation\PlanState;
use OCA\Mcp\Mcp\Operations\OperationStore;
use OCA\Mcp\Modules\Forms\Execution\FormsOperationJournal;
use OCA\Mcp\OAuth\ConnectionContext;
use OCA\Mcp\Tests\Support\OperationActors;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;

final class FormsOperationJournalTest extends TestCase {
    private array $rows = [];
    private ConnectionContext $connection;
    private OperationActors $actors;
    private FormsOperationJournal $journal;
    private int $now = 1000;

    protected function setUp(): void {
        $this->connection = new ConnectionContext(); $this->connection->set(12);
        $this->actors = new OperationActors();
        $store = $this->createMock(OperationStore::class);
        $store->method('get')->willReturnCallback(fn(string $scope,string $id):?array=>$this->rows[$scope.$id]??null);
        $store->method('reserve')->willReturnCallback(function(array $row):bool {
            $key=$row['scope_key'].$row['operation_id'];
            if(isset($this->rows[$key]))return false;
            foreach($this->rows as $r) if($r['target_key']!==null&&$r['target_key']===$row['target_key'])return false;
            $this->rows[$key]=$row;return true;
        });
        $store->method('checkpoint')->willReturnCallback(function(string $scope,string $id,array $refs):void {$this->rows[$scope.$id]['resource_refs']=$refs;});
        $outcomes=$this->createMock(\OCA\Mcp\Modules\Forms\Execution\FormsOutcomeStore::class);
        $outcomes->method('reconcile')->willReturnCallback(function(array $old,string $state,array $refs,int $time):bool {
            $key=$old['scope_key'].$old['operation_id'];
            if($this->rows[$key]!==$old)return false;
            $this->rows[$key]=array_replace($old,['state'=>$state,'resource_refs'=>$refs,'updated_at'=>$time,'target_key'=>null]);return true;
        });
        $store->method('finish')->willReturnCallback(function(string $scope,string $id,string $state,array $refs,int $time):void {
            $key=$scope.$id;$this->rows[$key]['state']=$state;$this->rows[$key]['resource_refs']=$refs;$this->rows[$key]['updated_at']=$time;
            if($state!=='uncertain')$this->rows[$key]['target_key']=null;
        });
        $store->method('list')->willReturnCallback(fn(string $scope,int $limit,int $offset):array=>array_slice(array_values(array_filter($this->rows,fn(array $r):bool=>$r['scope_key']===$scope)),$offset,$limit));
        $clock=$this->createMock(ITimeFactory::class);$clock->method('getTime')->willReturnCallback(fn():int=>$this->now);
        $this->journal=new FormsOperationJournal($store,new PlanState((new InMemoryConfig())->mock($this)),$this->connection,$clock,$this->actors,$outcomes);
    }

    public function testDuplicateConfirmationReturnsSameEvidenceWithoutAnswerContent(): void {
        $args=$this->approved(['id'=>4,'answers'=>['1'=>['private answer']]]);
        $calls=0;$run=function()use(&$calls):array{$calls++;return ['state'=>'succeeded','data'=>['submission_id'=>8,'secret'=>'not retained'],'references'=>['form_id'=>4,'submission_id'=>8]];};
        $first=$this->journal->execute('forms_submit_response','alice',$args,$run);
        $again=$this->journal->execute('forms_submit_response','alice',$args,$run);
        self::assertSame(1,$calls);
        self::assertSame('succeeded',$first['operation']['state']);
        self::assertTrue($again['operation']['replayed']);
        self::assertSame(8,$again['operation']['references']['submission_id']);
        self::assertStringNotContainsString('private answer',json_encode($this->rows));
        self::assertStringNotContainsString('not retained',json_encode($this->rows));
    }

    public function testNonRestorableAuthoringEvidenceSurvivesFreshHistoryWithoutPrivateInput():void {
        $args=$this->approved(['id'=>4,'fields'=>['title'=>'private proposed label']],'forms_update');$bulk=str_repeat('b',64);
        $this->journal->execute('forms_update','alice',$args,static function(string $key,\Closure $checkpoint)use($bulk):array {
            $checkpoint(['bulk_authority'=>['tool'=>'forms_update','required_operations'=>['edit'],'files_operations'=>[]]]);
            return ['state'=>'succeeded','references'=>['form_id'=>4,'bulk_id'=>$bulk,'bulk_action'=>'forms_update','backup_format'=>'authoring_effects_v1',
                'evidence_id'=>$bulk,'recovery_scope'=>'none','private'=>'secret']];
        });
        $status=$this->journal->get('alice',$args['operation_id'],static function():void{});
        self::assertSame($bulk,$status['references']['evidence_id']);self::assertSame('none',$status['references']['recovery_scope']);
        self::assertArrayNotHasKey('backup_id',$status['references']);self::assertStringNotContainsString('private proposed label',json_encode($status));
        self::assertStringNotContainsString('secret',json_encode($status));
    }
    public function testExistingCommentTargetIsRetainedBeforeTheNativeReplyCanBeLost():void {
        $args=$this->approved(['id'=>4,'comment_id'=>'9','message'=>'approved edit'],'forms_update_comment');
        $this->journal->execute('forms_update_comment','alice',$args,static fn()=>throw new \RuntimeException('lost reply'));
        $status=$this->journal->get('alice',$args['operation_id'],static function():void{},static function(string $key,string $tool,array $references):array {
            self::assertSame('9',$references['comment_id']??null);
            return ['state'=>'succeeded','references'=>['form_id'=>4,'comment_id'=>'9']];
        });
        self::assertSame('9',$status['references']['comment_id']);
    }
    public function testLostNativeResultIsUncertainAndNeverRepeated(): void {
        $args=$this->approved(['id'=>4]);$calls=0;
        $run=function()use(&$calls):array{$calls++;throw new \RuntimeException('native private response');};
        $a=$this->journal->execute('forms_submit_response','alice',$args,$run);
        $b=$this->journal->execute('forms_submit_response','alice',$args,$run);
        self::assertSame(1,$calls);self::assertSame('uncertain',$a['operation']['state']);
        self::assertFalse($b['operation']['safe_to_retry']);
        self::assertStringNotContainsString('private response',json_encode($a));
    }

    public function testChangedConnectionCannotConfirmOriginalPlan(): void {
        $args=$this->approved(['id'=>4]);$this->connection->set(99);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('forms_submit_response','alice',$args,fn():array=>self::fail('Wrong connection executed'));
    }

    public function testChangedArgumentsCannotReuseOperation(): void {
        $args=$this->approved(['id'=>4]);$args['id']=5;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('forms_submit_response','alice',$args,fn():array=>self::fail('Changed request executed'));
    }

    public function testExpiredNewOperationCannotStart(): void {
        $args=$this->approved(['id'=>4]);$this->now+=901;
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('forms_submit_response','alice',$args,fn():array=>self::fail('Expired request executed'));
    }

    public function testPublicStatusRequiresOriginalAuthorization(): void {
        $args=$this->approved(['id'=>4]);
        $this->journal->execute('forms_submit_response','alice',$args,fn():array=>['state'=>'succeeded','references'=>['form_id'=>4]]);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->get('alice',$args['operation_id'],static function(string $tool,array $references):void{throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Revoked');});
    }

    public function testLostReplyReconcilesOnlyCommittedNativeReceiptWithoutReplaying():void {
        $args=$this->approved(['id'=>4]);$calls=0;
        $this->journal->execute('forms_submit_response','alice',$args,function(string $key,\Closure $checkpoint)use(&$calls):array {
            $calls++;$checkpoint(['required_operations'=>['submit']]);throw new \RuntimeException('lost reply');
        });
        $lookup=static fn(string $key):array=>['state'=>'succeeded','references'=>['form_id'=>4,'submission_id'=>8,'secret'=>'forbidden']];
        $status=$this->journal->get('alice',$args['operation_id'],static function(string $tool,array $refs):void {
            self::assertSame(['submit'],$refs['required_operations']);
        },$lookup);
        self::assertSame('succeeded',$status['state']);self::assertSame(8,$status['references']['submission_id']);
        self::assertSame(1,$calls);self::assertArrayNotHasKey('secret',$status['references']);
    }
    public function testPartialReceiptCanConvergeFromNewNativeEvidenceWithoutRepeatingTheEffect():void {
        $args=$this->approved(['id'=>4]);$calls=0;
        $this->journal->execute('forms_submit_response','alice',$args,function()use(&$calls):array {
            $calls++;return ['state'=>'partial','references'=>['form_id'=>4,'database'=>'committed']];
        });
        $status=$this->journal->get('alice',$args['operation_id'],static function():void{},static fn():array=>['state'=>'succeeded','references'=>['form_id'=>4,'database'=>'committed']]);
        self::assertSame('succeeded',$status['state']);self::assertSame(1,$calls);
    }
    public function testFilteredReceiptPageStillHasContinuation():void {
        for($i=0;$i<3;$i++){$a=$this->approved(['id'=>10+$i]);$this->journal->execute('forms_submit_response','alice',$a,fn():array=>['state'=>'succeeded','references'=>[]]);}
        $page=$this->journal->list('alice',null,1,static function():void{throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('revoked');});
        self::assertTrue($page['hasMore']);self::assertNotNull($page['nextCursor']);self::assertSame([],$page['operations']);
    }
    public function testLostContinuationProtectsItsJobAndDoesNotBlockNewForms():void {
        $start=$this->approved(['id'=>4],'forms_download_results');
        $this->journal->execute('forms_download_results','alice',$start,static fn():array=>['state'=>'pending','references'=>['job_id'=>'job-a','form_id'=>4]]);
        $args=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
        $this->journal->execute('forms_continue_operation','alice',$args,static function(string $key,\Closure $checkpoint):array {
            $checkpoint(['job_version'=>3,'required_operations'=>['download_results']]);throw new \RuntimeException('Lost step receipt');
        },null,static fn():array=>['job_version'=>3]);
        $create=$this->approved([],'forms_create');
        $created=$this->journal->execute('forms_create','alice',$create,static fn():array=>['state'=>'succeeded','references'=>['form_id'=>7]]);
        self::assertSame('succeeded',$created['operation']['state']);
        $recovered=$this->journal->get('alice',$args['operation_id'],static function():void{},static function(string $key,string $tool,array $refs):array {
            self::assertSame('job-a',$refs['job_id']);self::assertSame(3,$refs['job_version']);
            return ['state'=>'pending','references'=>['job_id'=>'job-a']];
        });
        self::assertSame('pending',$recovered['state']);
    }
    public function testJobVersionIsReservedBeforePreflightCanCrash():void {
        $start=$this->approved(['id'=>4],'forms_download_results');
        $this->journal->execute('forms_download_results','alice',$start,static fn():array=>['state'=>'pending','references'=>['job_id'=>'job-a','form_id'=>4]]);
        $args=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
        $this->journal->execute('forms_continue_operation','alice',$args,static function():array {throw new \RuntimeException('Preflight process loss');},null,static fn():array=>['job_version'=>1]);
        $status=$this->journal->get('alice',$args['operation_id'],static function():void{},static function(string $key,string $tool,array $refs):?array {
            return isset($refs['job_version'])?['state'=>'failed_before_effect','references'=>['job_id'=>'job-a']]:null;
        });
        self::assertSame('failed_before_effect',$status['state']);
        $next=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
        $result=$this->journal->execute('forms_continue_operation','alice',$next,static fn():array=>['state'=>'pending','references'=>['job_id'=>'job-a']],null,static fn():array=>['job_version'=>2]);
        self::assertSame('pending',$result['operation']['state']);
    }

    public function testBulkStepPinsOriginalAuthorityAndVersionBeforeTheReplyCanBeLost():void {
        $authority=['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit','share']];
        $start=$this->approved(['id'=>4],'forms_delete_form');
        $initial=$this->journal->execute('forms_delete_form','alice',$start,static function(string $key,\Closure $checkpoint)use($authority):array {
            $checkpoint(['bulk_authority'=>$authority]);
            return ['state'=>'pending','references'=>['form_id'=>4,'bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_delete_form']];
        });
        self::assertSame(str_repeat('a',64),$initial['operation']['references']['bulk_id']??null);
        $args=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
        $step=$this->journal->execute('forms_continue_operation','alice',$args,static function():array {throw new \RuntimeException('Lost reply before checkpoint');},
            null,static fn():array=>['bulk_id'=>str_repeat('a',64),'bulk_version'=>7]);
        self::assertSame('uncertain',$step['operation']['state']);
        $context=$this->journal->context('alice',$args['operation_id'],static function():void {});
        self::assertSame(7,$context['references']['bulk_version']);
        self::assertSame($authority,$context['references']['bulk_authority']);
        self::assertSame('forms_delete_form',$context['references']['bulk_action']);
        self::assertArrayHasKey('native_key',$context['references']);
        self::assertArrayNotHasKey('bulk_authority',$step['operation']['references']);
        self::assertArrayNotHasKey('native_key',$step['operation']['references']);
        $this->journal->execute('forms_create','alice',$this->approved([],'forms_create'),static fn():array=>['state'=>'succeeded','references'=>['form_id'=>8]]);
        $blocked=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_cancel_operation');
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('forms_cancel_operation','alice',$blocked,static fn():array=>self::fail('Unknown bulk step replayed'),
            null,static fn():array=>['bulk_id'=>str_repeat('a',64),'bulk_version'=>7]);
    }
    public function testBulkStepCannotSubstituteTheReservedVersionOrOriginalAuthority():void {
        $authority=['tool'=>'forms_delete_responses','required_operations'=>['delete_all_responses'],'files_operations'=>['edit']];
        $start=$this->approved(['id'=>4],'forms_delete_responses');
        $this->journal->execute('forms_delete_responses','alice',$start,static function(string $key,\Closure $checkpoint)use($authority):array {
            $checkpoint(['bulk_authority'=>$authority]);
            return ['state'=>'pending','references'=>['bulk_id'=>str_repeat('b',64),'bulk_action'=>'forms_delete_responses','form_id'=>4]];
        });
        foreach([['bulk_version'=>8],['bulk_version'=>7,'bulk_authority'=>array_replace($authority,['files_operations'=>[]])]] as $change) {
            $args=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
            try {
                $this->journal->execute('forms_continue_operation','alice',$args,static function(string $key,\Closure $checkpoint)use($change):array {
                    $checkpoint($change);self::fail('Substituted bulk authority reached native effect');
                },null,static fn():array=>['bulk_id'=>str_repeat('b',64),'bulk_version'=>7]);
                self::fail('Changed admission was accepted');
            } catch(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect) {}
            self::assertSame('failed_before_effect',$this->journal->get('alice',$args['operation_id'],static function():void {})['state']);
        }
    }
    public function testBulkControlCannotAdoptAnotherNativeTarget():void {
        $start=$this->approved(['id'=>4],'forms_transfer');
        $this->journal->execute('forms_transfer','alice',$start,static function(string $key,\Closure $checkpoint):array {
            $checkpoint(['bulk_authority'=>['tool'=>'forms_transfer','required_operations'=>['transfer'],'files_operations'=>['create']]]);
            return ['state'=>'pending','references'=>['bulk_id'=>str_repeat('c',64),'bulk_action'=>'forms_transfer']];
        });
        $args=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('forms_continue_operation','alice',$args,static fn():array=>self::fail('Wrong native bulk admitted'),
            null,static fn():array=>['bulk_id'=>str_repeat('d',64),'bulk_version'=>1]);
    }
    public function testPrivateContextRequiresTheCurrentConnectionAndFreshOriginalAuthorization():void {
        $args=$this->approved(['id'=>4]);
        $this->journal->execute('forms_submit_response','alice',$args,static fn():array=>['state'=>'succeeded','references'=>['form_id'=>4]]);
        try {
            $this->journal->context('alice',$args['operation_id'],static fn()=>throw new \OCA\Mcp\Mcp\Tools\Results\ToolFailure('Revoked'));
            self::fail('Revoked private context was returned');
        } catch(\OCA\Mcp\Mcp\Tools\Results\ToolFailure) {}
        $this->connection->set(99);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->context('alice',$args['operation_id'],static function():void {});
    }


    public function testBulkControlRejectsMixedJobAndBulkReservations():void {
        $start=$this->approved(['id'=>4],'forms_transfer');
        $this->journal->execute('forms_transfer','alice',$start,static function(string $key,\Closure $checkpoint):array {
            $checkpoint(['bulk_authority'=>['tool'=>'forms_transfer','required_operations'=>['transfer'],'files_operations'=>['create']]]);
            return ['state'=>'pending','references'=>['bulk_id'=>str_repeat('c',64),'bulk_action'=>'forms_transfer']];
        });
        $args=$this->approved(['target_operation_id'=>$start['operation_id']],'forms_continue_operation');
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $this->journal->execute('forms_continue_operation','alice',$args,static fn():array=>self::fail('Mixed native protocols admitted'),
            null,static fn():array=>['bulk_id'=>str_repeat('c',64),'bulk_version'=>1,'job_version'=>1]);
    }
    public function testMalformedOriginalBulkAuthorityNeverReachesANativeEffect():void {
        foreach([
            ['tool'=>'forms_continue_operation','required_operations'=>['continue_operations'],'files_operations'=>[]],
            ['tool'=>'forms_delete_form','required_operations'=>[],'files_operations'=>[]],
            ['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['delete']],
            ['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>[],'origin'=>'private'],
        ] as $authority) {
            $args=$this->approved(['id'=>4],'forms_delete_form');
            try {
                $this->journal->execute('forms_delete_form','alice',$args,static function(string $key,\Closure $checkpoint)use($authority):array {
                    $checkpoint(['bulk_authority'=>$authority]);self::fail('Invalid original authority reached native effect');
                });
                self::fail('Malformed admission accepted');
            } catch(\OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect) {}
            self::assertSame('failed_before_effect',$this->journal->get('alice',$args['operation_id'],static function():void {})['state']);
        }
    }
    public function testPrivateContextCannotSurviveActorRecreationDuringAuthorization():void {
        $args=$this->approved(['id'=>4]);
        $this->journal->execute('forms_submit_response','alice',$args,static fn():array=>['state'=>'succeeded','references'=>['form_id'=>4]]);
        $this->expectException(\OCA\Mcp\Mcp\Operations\ActorRetired::class);
        $this->journal->context('alice',$args['operation_id'],function():void {$this->actors->generation='two';});
    }

    private function approved(array $args,string $tool='forms_submit_response'):array {
        $plan=$this->journal->plan($tool,'alice',$args,['plan_state'=>'proof']);
        return $args+['operation_id'=>$plan['operation_id'],'plan_state'=>'proof','confirm'=>true];
    }
}
