<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Forms\Mcp\Service\BulkStepEvidence;
use PHPUnit\Framework\TestCase;
final class NativeBulkStepEvidenceTest extends TestCase {
    public static function setUpBeforeClass():void {
        $root=dirname(__DIR__,5).'/native/forms-guard/src/Service/';
        require_once $root.'AuthoringScale.php';
        if(is_file($root.'BulkStepEvidence.php'))require_once $root.'BulkStepEvidence.php';
    }
    private function pair():array {
        $id=str_repeat('a',64);$key=str_repeat('b',64);
        $receipt=['bulk_id'=>$id,'operation_key'=>$key,'state'=>'partial','phase'=>'complete','version'=>8,
            'next'=>false,'references'=>['form_id'=>4,'backup_id'=>null,'backup_format'=>'authoring_effects_v1','evidence_id'=>$id,'recovery_scope'=>'none'],
            'progress'=>['done'=>1,'total'=>1],'phases'=>['database'=>'committed','files'=>'pending','events'=>[['kind'=>'files_share','phase'=>'pending']]]];
        $job=['id'=>$id,'key'=>$key,'action'=>'forms_update_share','input'=>['id'=>4,'share_id'=>9],
            'form'=>['id'=>4],'state'=>'partial','phase'=>'complete','version'=>8,'share_events'=>true,'source_effects'=>true,'done'=>1];
        return [$receipt,$job];
    }
    public function testCommittedExactStepResolvesOnlyItsDurableSharePhase():void {
        [$receipt,$job]=$this->pair();
        $actual=BulkStepEvidence::resolve($receipt,$job,static function(string $key):string {
            self::assertSame(str_repeat('b',64),$key);return 'native_files_shares_verified';
        });
        $expected=$receipt;$expected['phases']['files']='verified';$expected['phases']['events'][0]['phase']='attempted_unverified';
        self::assertSame($expected,$actual);
    }
    /** @dataProvider phases */
    public function testPendingAndUnknownEffectsNeverBecomeVerified(?string $phase,string $files,string $event):void {
        [$receipt,$job]=$this->pair();$actual=BulkStepEvidence::resolve($receipt,$job,static fn()=>$phase);
        self::assertSame($files,$actual['phases']['files']);self::assertSame($event,$actual['phases']['events'][0]['phase']);
        self::assertSame('partial',$actual['state']);
    }
    public static function phases():iterable {
        yield 'still pending'=>['native_files_shares_pending','pending','pending'];
        yield 'uncertain'=>['native_files_shares_uncertain','unknown','uncertain'];
        yield 'unrecognized'=>['future','unknown','uncertain'];
        yield 'missing'=>[null,'pending','pending'];
    }
    /** @dataProvider unrelated */
    public function testAnotherJobOrStepAndNonAuthoringReceiptsRetainOriginalEvidence(array $change):void {
        [$receipt,$job]=$this->pair();$job=array_replace_recursive($job,$change);
        self::assertSame($receipt,BulkStepEvidence::resolve($receipt,$job,static function():never {self::fail('Unrelated receipt requested an effect witness.');}));
    }
    public static function unrelated():iterable {
        yield 'other bulk'=>[['id'=>str_repeat('c',64)]];
        yield 'other operation'=>[['key'=>str_repeat('c',64)]];
        yield 'later step'=>[['version'=>9]];
        yield 'other form'=>[['form'=>['id'=>5]]];
        yield 'restore'=>[['action'=>'forms_restore']];
        yield 'incomplete'=>[['phase'=>'apply']];
        yield 'uncertain'=>[['state'=>'uncertain']];
        yield 'not shared'=>[['share_events'=>false]];
        yield 'no source effect'=>[['source_effects'=>false]];
        yield 'cancelled'=>[['state'=>'cancelled']];
        yield 'claimed'=>[['claim'=>['step_key'=>'private']]];
    }
    public function testEarlierOrMalformedReceiptDoesNotBorrowTerminalJobWitness():void {
        [$receipt,$job]=$this->pair();
        foreach([
            ['version'=>7],['next'=>true],['phase'=>'apply'],['state'=>'pending'],
            ['references'=>['backup_format'=>'bulk_v1']],['references'=>['evidence_id'=>str_repeat('d',64)]],
            ['references'=>['form_id'=>5]],['phases'=>['database'=>'partially_committed']],
            ['phases'=>['events'=>[['kind'=>'other','phase'=>'pending']]]],
        ]as$change){$old=array_replace_recursive($receipt,$change);
            self::assertSame($old,BulkStepEvidence::resolve($old,$job,static function():never {self::fail('Malformed receipt requested an effect witness.');}));}
    }
}
