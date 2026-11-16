<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Integration\FormsPublication;
use PHPUnit\Framework\TestCase;
final class FormsPublicationTest extends TestCase {
    public function testSourceLifecycleNeverFallsBackWhenQualifiedWriterOrBulkProtocolIsMissing():void {
        $source=['forms_delete_form','forms_delete_responses','forms_transfer'];
        $native=['qualified'=>true,'protocol'=>1,'bulk_lifecycle'=>true,'bulk_source_writer_ready'=>false,'actions'=>[...$source,'forms_restore']];
        foreach($source as $tool) {
            self::assertFalse(FormsPublication::supported($tool,$native));
            self::assertTrue(FormsPublication::supported($tool,array_replace($native,['bulk_source_writer_ready'=>true])));
            self::assertFalse(FormsPublication::supported($tool,array_replace($native,['bulk_lifecycle'=>false,'bulk_source_writer_ready'=>true])));
        }
        self::assertTrue(FormsPublication::supported('forms_restore',$native));
        self::assertTrue(FormsPublication::supported('forms_continue_operation',$native));
        self::assertTrue(FormsPublication::supported('forms_cancel_operation',$native));
    }

    public function testWiredCommentActionsRequireQualifiedNativeComments():void {
        $actions=['forms_list_comments','forms_add_comment','forms_update_comment','forms_delete_comment'];
        $native=['qualified'=>true,'protocol'=>1,'comments'=>true,'actions'=>$actions];
        foreach($actions as $action) {
            self::assertTrue(FormsPublication::supported($action,$native));
            foreach(['qualified','comments'] as $flag)self::assertFalse(FormsPublication::supported($action,array_replace($native,[$flag=>false])));
            self::assertFalse(FormsPublication::supported($action,array_replace($native,['protocol'=>2])));
        }
    }
    public function testAnswerWritersRequireReadOnlyNativeValidationBeforeApproval():void {
        $tools=['forms_submit_response','forms_update_response','forms_update_own_response'];
        $native=['qualified'=>true,'protocol'=>1,'actions'=>$tools];
        foreach($tools as $tool) {
            self::assertFalse(FormsPublication::supported($tool,$native));
            self::assertTrue(FormsPublication::supported($tool,$native+['answers_preflight'=>true]));
        }
    }
    public function testFilesWritersRequireQualifiedPostLockSinks():void {
        $native=['qualified'=>true,'protocol'=>1,'actions'=>['forms_save_response_file']];
        foreach(['forms_export_results','forms_save_response_file'] as $tool) {
            self::assertFalse(FormsPublication::supported($tool,$native));
            self::assertTrue(FormsPublication::supported($tool,$native+['file_sinks'=>true]));
        }
    }
    public function testNativeLinkedHandlersCannotPublishAnUnwiredMcpAutomationPath():void {
        $native=['qualified'=>true,'protocol'=>1,'file_sinks'=>true,'linked_exports'=>true,'actions'=>['forms_link_results_file','forms_unlink_results_file']];
        self::assertFalse(FormsPublication::supported('forms_link_results_file',$native));
        self::assertFalse(FormsPublication::supported('forms_unlink_results_file',$native));
    }
    public function testSourceQualificationDoesNotPublishAnUnimplementedEffectAdapter():void {
        $native=['qualified'=>true,'protocol'=>1,'actions'=>['forms_create','forms_update']];
        self::assertTrue(FormsPublication::supported('forms_create',$native));
        self::assertTrue(FormsPublication::supported('forms_download_results',$native));
        self::assertFalse(FormsPublication::supported('forms_link_results_file',$native));
        self::assertFalse(FormsPublication::supported('forms_unlink_results_file',$native));
        self::assertFalse(FormsPublication::supported('forms_add_comment',$native));
        self::assertFalse(FormsPublication::supported('forms_list_comments',$native));
        self::assertFalse(FormsPublication::supported('forms_create',['qualified'=>false]));
    }
}
