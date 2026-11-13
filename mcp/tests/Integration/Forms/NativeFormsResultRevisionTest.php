<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class NativeFormsResultRevisionTest extends TestCase {
    protected function setUp():void {if(!getenv('MCP_FORMS_NATIVE_BOUNDARY')){$this->markTestSkipped('Requires disposable native cell');}}
    private function check(string $case):void {
        $p=proc_open([PHP_BINARY,'/tmp/result-revision-probe.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fwrite($pipes[0],json_encode(['case'=>$case]));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($p),$err.' '.$out);$r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok'],json_encode($r));
        foreach($r['result'] as $key=>$value){self::assertTrue($value,$key);}
    }
    public function testNativeSnapshotExposesSeparateBoundedSourceIdentity():void {$this->check('token');}
    public function testUnrelatedWritesAndEmptyJobsPreserveSource():void {$this->check('unrelated');}
    public function testSameCountAnswerEditAndSubmissionAnonymizationInvalidateSource():void {$this->check('edit');}
    public function testEpochRollsBackAndTouchesOncePerOuterTransaction():void {$this->check('rollback');}
    public function testResponseCreationAndEveryGenericAndBulkDeletionAffectOnlyTheirForm():void {$this->check('mutations');}
    public function testRenderedQuestionOptionsAndFormAndOriginalActorTimezoneAffectSource():void {$this->check('schema');}
    public function testLinkedFileAndMarkerLikeBookkeepingPreserveSource():void {$this->check('bookkeeping');}
    public function testLazySnapshotUsesScalarCountsWithoutAnswerScansOrWritesOrLocks():void {$this->check('readonly');}
    public function testDeferredAndInteractiveSourceMatchOriginalActorWithoutWorkerSession():void {$this->check('deferred');}
    public function testPinnedNativeImportAndDeletedUserJobsUseTheSameAtomicEpoch():void {$this->check('native_paths');}
    public function testSchemaBulkAndGenericDeletesChangeOnlyRenderedSources():void {$this->check('schema_deletes');}
    public function testMetadataByteLimitIncludesItsExportRevisionEnvelope():void {$this->check('metadata_budget');}
    public function testNativeMapperUnchangedEntitiesRemainSourceNoops():void {$this->check('unchanged');}
    public function testCorruptAndOverflowEpochRefuseAndRollbackData():void {$this->check('overflow');}
}
