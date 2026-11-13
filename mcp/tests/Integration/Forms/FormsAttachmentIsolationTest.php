<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
final class FormsAttachmentIsolationTest extends TestCase {
    private function call(string $action,array $args=[],?string $actor=null): array {
        $probe=getenv('MCP_FORMS_ATTACHMENT_PROBE')?:'/tmp/attachment-probe.php';
        $p=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fwrite($pipes[0],json_encode(compact('action','args','actor')));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$err);
        self::assertNotSame('',trim($out),$err);return json_decode($out,true,64,JSON_THROW_ON_ERROR);
    }
    private function ok(string $action,array $args=[],?string $actor=null): mixed {$r=$this->call($action,$args,$actor);self::assertTrue($r['ok'],json_encode($r));return $r['result'];}
    protected function setUp(): void {if (!getenv('MCP_FORMS_NATIVE_BOUNDARY')) {$this->markTestSkipped('Requires disposable native database/storage cell');}}
    private function write(string $action,array $input): array {
        $s=$this->ok('snapshot',compact('action','input'));return $this->ok('apply',['action'=>$action,'input'=>$input,'revision'=>$s['revision'],'manifest'=>$s['file_manifest'],'key'=>bin2hex(random_bytes(16))]);
    }
    private function attached(): array {
        $f=$this->ok('fixture');$upload=$this->ok('stage',$f);$handle=$upload['data']['files'][0]['handle'];
        $receipt=$this->write('forms_submit_response',['id'=>$f['id'],'answers'=>[$f['question_id']=>[['mcpHandle'=>$handle]]]]);
        $f['submission_id']=$receipt['references']['submission_id'];$f['file_id']=$this->ok('references',$f)['files'][0]['file_id'];$f['handle']=$handle;return $f;
    }
    public function testFileCapabilitiesCannotBeUsedTwice(): void {
        $f=$this->attached();$action='forms_submit_response';$input=['id'=>$f['id'],'answers'=>[$f['question_id']=>[['mcpHandle'=>$f['handle']]]]];
        $r=$this->call('snapshot',compact('action','input'));self::assertFalse($r['ok']);self::assertSame('File handle is unavailable',$r['error']);
    }
    public function testCrossFormQuestionActorAndExpiredUploadHandlesAreRefused(): void {
        $f=$this->ok('fixture');$other=$this->ok('fixture');$handle=$this->ok('stage',$f)['data']['files'][0]['handle'];
        $action='forms_submit_response';$input=['id'=>$other['id'],'answers'=>[$other['question_id']=>[['mcpHandle'=>$handle]]]];
        self::assertFalse($this->call('snapshot',compact('action','input'))['ok']);
        $this->ok('expire',['handle'=>$handle]);$input=['id'=>$f['id'],'answers'=>[$f['question_id']=>[['mcpHandle'=>$handle]]]];
        self::assertFalse($this->call('snapshot',compact('action','input'))['ok']);
    }
    public function testVerifiedRecoveryRestoresBytesToNewPrivateForm(): void {
        $f=$this->attached();$hash=$this->ok('hash',['file_id'=>$f['file_id']]);$deleted=$this->write('forms_delete_form',['id'=>$f['id']]);
        self::assertNull($this->ok('hash',['file_id'=>$f['file_id']]));self::assertArrayHasKey('backup_id',$deleted['references']);
        $restored=$this->write('forms_restore',['backup_id'=>$deleted['references']['backup_id']]);self::assertNotSame($f['id'],$restored['references']['form_id']);
        self::assertTrue($restored['data']['private']);self::assertSame('closed',$restored['data']['state']);self::assertSame($hash,$this->ok('hash',['file_id'=>$restored['data']['recovered_file_ids'][0]]));
        $again=$this->write('forms_restore',['backup_id'=>$deleted['references']['backup_id']]);self::assertNotSame($restored['references']['form_id'],$again['references']['form_id']);
    }
    public function testStrayFilesRefuseDestructionAndRename(): void {
        $f=$this->attached();$stray=$this->ok('stray',$f);
        foreach (['forms_delete_form'=>['id'=>$f['id']],'forms_update'=>['id'=>$f['id'],'fields'=>['title'=>'new title']]] as $action=>$input) {
            $r=$this->call('snapshot',compact('action','input'));self::assertFalse($r['ok']);self::assertSame('Unmanaged file in native Forms effect path',$r['error']);
        }
        self::assertSame(hash('sha256','unmanaged canary'),$this->ok('hash',['file_id'=>$stray]));self::assertNotNull($this->ok('hash',['file_id'=>$f['file_id']]));
    }
    public function testConcurrentFileChangeInvalidatesRecoveryPreview(): void {
        $f=$this->attached();$action='forms_delete_form';$input=['id'=>$f['id']];$s=$this->ok('snapshot',compact('action','input'));
        $this->ok('tamper',['file_id'=>$f['file_id']]);$r=$this->call('apply',['action'=>$action,'input'=>$input,'revision'=>$s['revision'],'manifest'=>$s['file_manifest'],'key'=>bin2hex(random_bytes(16))]);
        self::assertFalse($r['ok']);self::assertSame('Forms file preview changed',$r['error']);self::assertSame(hash('sha256','changed'),$this->ok('hash',['file_id'=>$f['file_id']]));
    }
    public function testDeferredQuestionCleanupCannotDeleteNewUnmanagedFile(): void {
        $f=$this->attached();$this->write('forms_delete_question',['id'=>$f['id'],'question_id'=>$f['question_id']]);$stray=$this->ok('stray',$f);
        $this->ok('deferred',$f);self::assertSame(hash('sha256','unmanaged canary'),$this->ok('hash',['file_id'=>$stray]));
    }
    public function testGenericUpdateCannotBypassDedicatedNativeAuthority(): void {
        $f=$this->ok('fixture');foreach (['ownerId'=>'guard-owner','lockedUntil'=>0,'fileId'=>1,'state'=>2] as $key=>$value) {
            $r=$this->call('snapshot',['action'=>'forms_update','input'=>['id'=>$f['id'],'fields'=>[$key=>$value]]]);self::assertFalse($r['ok']);self::assertSame('Unsupported generic Forms fields',$r['error']);
        }
    }
    public function testOtherQuestionAndActorCannotConsumeHandle(): void {
        $f=$this->ok('fixture');$handle=$this->ok('stage',$f)['data']['files'][0]['handle'];
        $q2=$this->ok('api',['method'=>'newQuestion','input'=>[$f['id'],'file',null,'Other attachment']])['id'];
        $action='forms_submit_response';$input=['id'=>$f['id'],'answers'=>[$q2=>[['mcpHandle'=>$handle]]]];self::assertFalse($this->call('snapshot',compact('action','input'))['ok']);
        $this->ok('create_user',['uid'=>'guard-other']);$this->ok('api',['method'=>'updateForm','input'=>[$f['id'],['access'=>['permitAllUsers'=>true,'showToAllUsers'=>false]]]]);
        $input=['id'=>$f['id'],'answers'=>[$f['question_id']=>[['mcpHandle'=>$handle]]]];$denied=$this->call('snapshot',compact('action','input'),'guard-other');self::assertFalse($denied['ok']);self::assertSame('File handle is unavailable',$denied['error']);
        $receipt=$this->write($action,$input);self::assertArrayHasKey('submission_id',$receipt['references']);
    }
    public function testNativeSizeLimitRejectsBeforeStaging(): void {
        $f=$this->ok('fixture');$this->ok('api',['method'=>'updateQuestion','input'=>[$f['id'],$f['question_id'],['extraSettings'=>['maxFileSize'=>3]]]]);
        $r=$this->call('stage',$f+['content'=>'more than three']);self::assertFalse($r['ok']);self::assertSame('File content or size changed',$r['error']);
    }
    public function testResultsShareCreatesAndRemovesExactManagedFilesShare(): void {
        $f=$this->ok('fixture');$this->ok('create_user',['uid'=>'guard-other']);$share=$this->ok('share_api',['method'=>'newShare','input'=>[$f['id'],0,'guard-other',['submit']]]);
        $input=['id'=>$f['id'],'share_id'=>$share['id'],'permissions'=>['submit','results']];
        if (!$this->ok('capabilities')['files_shares']) {
            $denied=$this->call('snapshot',['action'=>'forms_update_share','input'=>$input]);self::assertFalse($denied['ok']);self::assertSame('Native Files sharing is not qualified on PostgreSQL',$denied['error']);self::assertSame([],$this->ok('file_shares',$f));return;
        }
        $snap=$this->ok('snapshot',['action'=>'forms_update_share','input'=>$input]);self::assertTrue($snap['file_manifest']['files_share']);
        $this->write('forms_update_share',$input);self::assertCount(1,$this->ok('file_shares',$f));
        $this->write('forms_unshare',['id'=>$f['id'],'share_id'=>$share['id']]);self::assertCount(0,$this->ok('file_shares',$f));
    }
    public function testRecoveryCannotBeUsedByAnotherActor(): void {
        $f=$this->attached();$deleted=$this->write('forms_delete_form',['id'=>$f['id']]);$this->ok('create_user',['uid'=>'guard-other']);
        $denied=$this->call('snapshot',['action'=>'forms_restore','input'=>['backup_id'=>$deleted['references']['backup_id']]],'guard-other');self::assertFalse($denied['ok']);self::assertSame('Recovery is unavailable',$denied['error']);
    }
    public function testOwnershipTransferCopiesVerifiedFilesAndPreservesOriginals(): void {
        $f=$this->attached();$uid='guard-transfer-'.bin2hex(random_bytes(4));$this->ok('create_user',['uid'=>$uid]);$hash=$this->ok('hash',['file_id'=>$f['file_id']]);
        $receipt=$this->write('forms_transfer',['id'=>$f['id'],'owner_id'=>$uid]);self::assertArrayHasKey('backup_id',$receipt['references']);
        $refs=$this->ok('references',$f,$uid);$newId=$refs['files'][0]['file_id'];self::assertNotSame($f['file_id'],$newId);
        self::assertSame($hash,$this->ok('hash',['file_id'=>$newId],$uid));self::assertSame($hash,$this->ok('hash',['file_id'=>$f['file_id']]));
    }
    public function testQuotaFailurePreservesExistingManagedFiles(): void {
        $f=$this->attached();$hash=$this->ok('hash',['file_id'=>$f['file_id']]);$this->ok('quota',['value'=>'1 B']);
        try {$result=$this->call('stage',$f);self::assertFalse($result['ok']);self::assertSame($hash,$this->ok('hash',['file_id'=>$f['file_id']]));}
        finally {$this->ok('quota',['value'=>'default']);}
    }
    public function testQuestionDeletionLeavesSubsequentFormRecoveryUsable(): void {
        $f=$this->attached();$this->write('forms_delete_question',['id'=>$f['id'],'question_id'=>$f['question_id']]);
        $deleted=$this->write('forms_delete_form',['id'=>$f['id']]);self::assertArrayHasKey('backup_id',$deleted['references']);
    }
    public function testNativeCopyCannotOverwriteUserDestination(): void {
        $r=$this->ok('new_file_collision');self::assertTrue($r['refused']);self::assertSame(hash('sha256','keep original'),$r['sha256']);
    }
    public function testNativeExpiryCleanupCannotConsumeRetainedMcpStaging(): void {
        $f=$this->ok('fixture');$handle=$this->ok('stage',$f)['data']['files'][0]['handle'];$id=$this->ok('age_staging',['handle'=>$handle]);$hash=$this->ok('hash',['file_id'=>$id]);
        $this->ok('cleanup');self::assertSame($hash,$this->ok('hash',['file_id'=>$id]));
        $receipt=$this->write('forms_submit_response',['id'=>$f['id'],'answers'=>[$f['question_id']=>[['mcpHandle'=>$handle]]]]);self::assertArrayHasKey('submission_id',$receipt['references']);
    }
}
