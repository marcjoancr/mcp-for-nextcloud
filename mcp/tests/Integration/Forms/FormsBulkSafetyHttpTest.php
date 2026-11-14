<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
/** Explicit disposable Maria cell; exact-job SQL faults occur only after a real managed file deletion. */
final class FormsBulkSafetyHttpTest extends TestCase {
    public static function positions():array {return ['first'=>[1],'second'=>[2]];}
    #[DataProvider('positions')]
    public function testFileReceiptFailureRetainsDiscoverableRecoveryWithoutRepeatingDeletion(int $position):void {
        $previous=getenv('MCP_FORMS_FILE_FAULT_POSITION');putenv('MCP_FORMS_FILE_FAULT_POSITION='.$position);
        try {
            $result=$this->probe('bulk-file-fault-http','MCP_FORMS_FILE_FAULT_HTTP_PROBE',[
                'actual_file_deletion_then_receipt_persistence_failure','fresh_http_backup_discovery','uncertain_never_replayed',
                'all_recovery_bytes_and_answer_mappings','private_closed_restore_preserves_original_claim','restoration_oracle_rejects_wrong_bindings']);
            self::assertSame($position,$result['position']);
        } finally {putenv($previous===false?'MCP_FORMS_FILE_FAULT_POSITION':'MCP_FORMS_FILE_FAULT_POSITION='.$previous);}
    }
    public function testIndependentRecoveryOracleRejectsDuplicatedFilesAndForeignBindings():void {
        if(getenv('MCP_FORMS_FILE_FAULT_EXPECTED')===false)$this->markTestSkipped('Provide explicit fixed observed first/second fault attempts.');
        $this->probe('bulk-file-recovery-oracle-probe','MCP_FORMS_FILE_RECOVERY_ORACLE_PROBE',[
            'fresh_null_session','1_baseline','1_duplicate_first_rejected','1_foreign_question_rejected','1_wrong_submission_rejected','1_restored_baseline',
            '2_baseline','2_duplicate_first_rejected','2_foreign_question_rejected','2_wrong_submission_rejected','2_restored_baseline']);
    }
    public function testSourceWriterReadinessDoesNotSuppressIndependentRecoveryOrControls():void {
        $this->probe('bulk-readiness-http','MCP_FORMS_BULK_READINESS_HTTP_PROBE',[
            'qualified_source_available','protocol_independent_source_diagnostics','source_three_refused_without_fallback',
            'recovery_control_publication_independent','fresh_http_writer_restoration']);
    }
    private function probe(string $name,string $environment,array $checks):array {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires an explicitly selected disposable Forms Maria HTTP cell.');
        $file=getenv($environment)?:__DIR__.'/fixtures/'.$name.'.php';self::assertFileExists($file);
        $p=proc_open([PHP_BINARY,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);
        foreach($checks as $key)self::assertTrue($result['checks'][$key],$key);return $result;
    }
}
