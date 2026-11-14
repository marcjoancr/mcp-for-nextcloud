<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Integration\FormsBulkControl;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class FormsBulkControlTest extends TestCase {
    private function refs():array {
        return ['form_id'=>4,'bulk_id'=>str_repeat('a',64),'bulk_action'=>'forms_delete_form','bulk_version'=>7,
            'bulk_authority'=>['tool'=>'forms_delete_form','required_operations'=>['delete_form'],'files_operations'=>['edit','share']]];
    }
    private function dto(string $state='pending',int $version=7):array {
        return ['bulk_id'=>str_repeat('a',64),'state'=>$state,'phase'=>'backup_files','version'=>$version,
            'progress'=>['done'=>0,'total'=>103,'captured'=>205,'capture_total'=>205],
            'references'=>['form_id'=>4,'backup_id'=>str_repeat('b',64),'backup_format'=>'bulk_v1'],
            'retained_files'=>['recovery'=>3,'copies'=>0,'backup_sealed'=>true,'private_path'=>'secret'],
            'next'=>$state==='pending','operation_key'=>'private','phases'=>['database'=>'not_started','files'=>$state==='uncertain'?'unknown':'pending','events'=>[]]];
    }
    public function testControlApprovalBindsExactNativeVersionAndRetainedRecoveryWithoutPrivateData():void {
        $dto=$this->dto();$guard=new class($dto) {
            public function __construct(public array $dto) {}
            public function bulkStatus(string $uid,string $id,array $origin):array {return $this->dto;}
        };
        $control=new FormsBulkControl();$snapshot=$control->snapshot('alice','forms_continue_operation',$this->refs(),['proof'=>'private'],$guard);
        self::assertSame(7,$snapshot['bulk_version']);self::assertSame($this->refs()['bulk_authority'],$snapshot['bulk_authority']);
        self::assertSame(3,$snapshot['impact']['bulk']['retained_files']['recovery']);
        self::assertStringNotContainsString('private',json_encode($snapshot['impact']));
        $guard->dto['version']=8;$new=$control->snapshot('alice','forms_continue_operation',$this->refs(),['proof'=>'private'],$guard);
        self::assertNotSame($snapshot['revision'],$new['revision']);
    }
    public function testAnUncertainNativeClaimCanBeInspectedButCannotBeApprovedForAnotherStep():void {
        $guard=new class($this->dto('uncertain',8)) {
            public function __construct(private array $dto) {}
            public function bulkStatus(string $uid,string $id,array $origin):array {return $this->dto;}
        };
        $control=new FormsBulkControl();
        $snapshot=$control->snapshot('alice','forms_read_result',$this->refs(),['proof'=>'private'],$guard);
        self::assertSame('uncertain',$snapshot['bulk_status']['state']);
        $this->expectException(ToolFailure::class);
        $control->snapshot('alice','forms_continue_operation',$this->refs(),['proof'=>'private'],$guard);
    }
    public function testStepEvidenceAbsenceRequiresTheQualifiedFiveArgumentLookupAndExactVersion():void {
        $guard=new class {
            public array $seen=[];
            public function bulkLookup(string $uid,string $key,array $origin,?string $id=null,?int $version=null):?array {
                $this->seen=[$uid,$key,$origin,$id,$version];return null;
            }
        };
        $origin=['proof'=>'signed-current'];$control=new FormsBulkControl();
        $result=$control->lookup('alice',str_repeat('c',64),'forms_continue_operation',$this->refs(),$origin,$guard);
        self::assertSame('failed_before_effect',$result['state']);
        self::assertSame(['alice',str_repeat('c',64),$origin,str_repeat('a',64),7],$guard->seen);
        self::assertSame(str_repeat('a',64),$result['references']['bulk_id']);
    }
    public function testLegacyInitialOnlyLookupCannotProveAnUntouchedStep():void {
        $guard=new class {public function bulkLookup(string $uid,string $key,array $origin):?array {return null;}};
        $this->expectException(ToolFailure::class);
        (new FormsBulkControl())->lookup('alice',str_repeat('c',64),'forms_continue_operation',$this->refs(),[],$guard);
    }
    public function testMissingInitialReceiptNeverProvesThatNoNativeEffectBegan():void {
        $guard=new class {
            public function bulkLookup(string $uid,string $key,array $origin,?string $id=null,?int $version=null):?array {return null;}
        };
        $refs=$this->refs();unset($refs['bulk_id'],$refs['bulk_version']);
        self::assertNull((new FormsBulkControl())->lookup('alice',str_repeat('c',64),'forms_delete_form',$refs,[],$guard));
    }
    public function testUnknownStepKeepsItsSealedBackupAndDoesNotCallAnyMutation():void {
        $guard=new class($this->dto('uncertain',8)) {
            public function __construct(private array $dto) {}
            public function bulkLookup(string $uid,string $key,array $origin,?string $id=null,?int $version=null):?array {return $this->dto;}
        };
        $result=(new FormsBulkControl())->lookup('alice',str_repeat('c',64),'forms_continue_operation',$this->refs(),[],$guard);
        self::assertSame('uncertain',$result['state']);self::assertSame('bulk_v1:'.str_repeat('b',64),$result['references']['backup_id']);
        self::assertArrayNotHasKey('operation_key',$result['data']);
    }
    public function testEvidenceForAnotherBulkOrStepVersionIsNeverAdopted():void {
        foreach([['bulk_id'=>str_repeat('d',64)],['version'=>9]] as $change) {
            $guard=new class(array_replace($this->dto('uncertain',8),$change)) {
                public function __construct(private array $dto) {}
                public function bulkLookup(string $uid,string $key,array $origin,?string $id=null,?int $version=null):?array {return $this->dto;}
            };
            $denied=false;
            try {(new FormsBulkControl())->lookup('alice',str_repeat('c',64),'forms_continue_operation',$this->refs(),[],$guard);}
            catch(ToolFailure) {$denied=true;}
            self::assertTrue($denied,'Foreign step proof adopted');
        }
    }
}
