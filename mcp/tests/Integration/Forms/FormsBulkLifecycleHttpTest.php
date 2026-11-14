<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Disposable native HTTP cell only; keep long-running harness output in an external evidence log. */
final class FormsBulkLifecycleHttpTest extends TestCase {
    public function testScaledResponsesCanBeDeletedAndRestoredCompletelyThroughHttp():void {
        $this->probe('bulk-public-http','MCP_FORMS_BULK_HTTP_PROBE',[
            'complete_scaled_preview','start_and_no_replay','original_grant_denied',
            'all_rows_deleted_and_recoverable','complete_private_closed_restore']);
    }
    public function testCompletedHttpRecoveryHasIndependentFreshDatabaseEvidence():void {
        $this->probe('bulk-http-final-proof','MCP_FORMS_BULK_HTTP_FINAL_PROBE',[
            'fresh_null_session','sealed_exact_source','exact_expected_attempt','matching_final_mcp_http_receipts','exact_question_and_submission_bindings',
            'source_responses_zero_schema_retained','restored_103_responses_11_questions_1133_exact_values','private_closed_unlinked_no_shares']);
    }
    public function testIndependentOracleRejectsAnotherAttemptAndWrongAnswerAssociations():void {
        $this->probe('bulk-http-oracle-mutations','MCP_FORMS_BULK_HTTP_ORACLE_MUTATION_PROBE',[
            'baseline','wrong_attempt_denied','wrong_question_denied','mixed_submissions_denied','restored_baseline']);
    }
    public function testEvidenceFailureStopsBeforeCreatingNativeData():void {
        $this->probe('bulk-http-evidence-probe','MCP_FORMS_BULK_HTTP_EVIDENCE_PROBE',[
            'old_evidence_cannot_be_reused','failed_create_stops_before_effects','fresh_evidence_writes','exact_current_update','no_native_effects']);
    }
    private function probe(string $fixture,string $environment,array $checks):void {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires an explicitly selected disposable native Forms HTTP cell.');
        self::assertNotFalse(getenv('MCP_FORMS_BULK_HTTP_EXPECTED'),'Provide a unique explicit evidence path for this attempt.');
        $file=getenv($environment)?:__DIR__.'/fixtures/'.$fixture.'.php';self::assertFileExists($file);
        $p=proc_open([PHP_BINARY,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        self::assertIsResource($p);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
        fclose($pipes[1]);fclose($pipes[2]);self::assertSame(0,proc_close($p),$out."\n".$err);
        $r=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($r['ok']);
        foreach($checks as $check)self::assertTrue($r['checks'][$check],$check);
    }
}
