<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Forms;
use PHPUnit\Framework\TestCase;
/** Real persistent OAuth HTTP approval, initial recovery, cron update and unlink preserving output.
 * Stage the tracked native origin policy fixture in the cell (MCP_FORMS_NATIVE_ORIGIN_FIXTURE). */
final class FormsLinkedPublicHttpTest extends TestCase {
    /** @dataProvider modes */
    public function testPublicActivationRecoveryCronAndUnlink(string $mode):void {
        $result=$this->probe($mode);
        foreach(['persistent_http_initial_link','real_cron_updates','unlink_preserves_file'] as $key)self::assertTrue($result['checks'][$key],$key);
        self::assertSame($mode,$result['checks']['initial_recovery']);
        if(str_starts_with($mode,'reconcile'))self::assertTrue($result['checks']['historical_reconciliation_without_replay']);
    }
    public function testBothActorsKeepTheirSeededVersionsAfterPublicJourneysOnTheSameRelativePath():void {
        $fixture=getenv('MCP_FORMS_LINKED_PAIR_PROBE')?:__DIR__.'/fixtures/linked-two-actors.php';
        $result=$this->probe('suite',$fixture);
        self::assertSame(2,$result['checks']['actual_mcp_journeys']);self::assertSame(2,$result['checks']['verification_children']);
        foreach(['same_relative_path','distinct_preimages','final_versions_and_outputs'] as $key)self::assertTrue($result['checks'][$key],$key);
    }
    public function testCancellationAfterSourceChangeKeepsAuthorityAndReleasesReservation():void {
        $result=$this->probe('cancel_source');
        foreach(['cancel_changed_source','no_output_effect','reservation_released'] as $key)self::assertTrue($result['checks'][$key],$key);
    }
    public function testCurrentGrantsSelectionScopeAndPersistentConnectionAreRequiredBeforeLinking():void {
        $result=$this->probe('preflight_matrix');
        foreach(['forms_link_results_current_denied_then_restored','files_create_current_denied_then_restored',
            'files_edit_current_denied_then_restored','forms_read_respondents_current_denied_then_restored',
            'forms_read_response_files_current_denied_then_restored','files_read_current_denied_then_restored',
            'current_connection_tool_selection_denied_then_restored','empty_current_files_scope_denied_then_restored',
            'basic_authenticated_owner_read','basic_connection_cannot_authorize_future_worker','all_previews_and_denials_no_export_effects']as$key)self::assertTrue($result['checks'][$key]??false,$key);
    }
    public function testAudienceChangesInvalidateApprovalAndPauseCronWithoutOverwritingTheFile():void {
        $result=$this->probe('audience_changes');
        foreach(['audience_change_invalidates_pending_approval','stale_audience_no_export_effects',
            'fresh_shared_audience_approval_activates','real_cron_audience_change_pauses_without_overwrite',
            'restoring_audience_does_not_resume_old_consent','paused_unlink_preserves_exact_file']as$key)
            self::assertTrue($result['checks'][$key]??false,$key);
    }
    public function testDisabledVersionsIsRefusedBeforeEffectsWithEitherCopyPreference():void {
        $result=$this->probe('versions_disabled');
        self::assertSame(2,$result['checks']['rejected_before_effect']);
    }
    private function probe(string $mode,?string $fixture=null):array {
        if(getenv('MCP_FORMS_HTTP_BOUNDARY')!=='1')$this->markTestSkipped('Requires explicitly selected disposable native Forms HTTP cell.');
        $probe=$fixture??(getenv('MCP_FORMS_LINKED_PUBLIC_PROBE')?:__DIR__.'/fixtures/linked-public-http.php');
        self::assertFileExists($probe);
        $process=proc_open([PHP_BINARY,$probe],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);self::assertIsResource($process);
        fwrite($pipes[0],json_encode(['mode'=>$mode],JSON_THROW_ON_ERROR));fclose($pipes[0]);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        self::assertSame(0,proc_close($process),$out."\n".$err);
        $result=json_decode($out,true,64,JSON_THROW_ON_ERROR);self::assertTrue($result['ok']);return $result;
    }
    public static function modes():iterable {yield 'new output'=>['create'];yield 'native Versions overwrite'=>['versions'];yield 'private copy overwrite'=>['copy'];yield 'lost acknowledgement'=>['reconcile'];yield 'lost acknowledgement after expiry'=>['reconcile_expired'];yield 'lost acknowledgement after cleanup'=>['reconcile_cleaned'];}
}
