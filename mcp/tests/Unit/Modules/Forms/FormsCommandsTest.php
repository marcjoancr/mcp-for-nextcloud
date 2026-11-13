<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use PHPUnit\Framework\TestCase;

final class FormsCommandsTest extends TestCase {
    public function testEveryNativeWorkflowHasAnExplicitTypedTool():void {
        $definitions=FormsCommands::definitions();
        foreach(['forms_create','forms_clone','forms_set_state','forms_transfer','forms_create_question','forms_reorder_options',
            'forms_share','forms_read_links','forms_submit_response','forms_read_own_responses','forms_delete_responses',
            'forms_upload_response_files','forms_summarize_results','forms_download_results','forms_link_results_file',
            'forms_list_comments','forms_update_admin_settings','forms_get_operation'] as $name) {
            self::assertArrayHasKey($name,$definitions);
            self::assertFalse($definitions[$name]['inputSchema']['additionalProperties']);
        }
        self::assertNotSame($definitions['forms_read_own_responses']['operation'],$definitions['forms_read_response']['operation']);
        self::assertNotSame($definitions['forms_delete_response']['operation'],$definitions['forms_delete_responses']['operation']);
    }
    public function testQuestionTypeCannotBePatched():void {
        $schema=FormsCommands::definitions()['forms_update_question']['inputSchema'];
        $this->expectException(\InvalidArgumentException::class);
        ArgumentValidator::validate($schema,['id'=>1,'question_id'=>2,'fields'=>['type'=>'short']]);
    }
    public function testUnknownFormFieldsCannotReachNativeSetters():void {
        $schema=FormsCommands::definitions()['forms_update']['inputSchema'];
        $this->expectException(\InvalidArgumentException::class);
        ArgumentValidator::validate($schema,['id'=>1,'fields'=>['ownerId'=>'outsider']]);
    }
    public function testLegacyDatetimeCannotBeCreated():void {
        $schema=FormsCommands::definitions()['forms_create_question']['inputSchema'];
        $this->expectException(\InvalidArgumentException::class);
        ArgumentValidator::validate($schema,['id'=>1,'type'=>'datetime','text'=>'Legacy']);
    }
    public function testStructuredAnswersHaveNoArbitraryObjectOrTokenInput():void {
        $schema=FormsCommands::definitions()['forms_submit_response']['inputSchema'];
        $accepted=ArgumentValidator::validate($schema,['id'=>1,'answers'=>[['question_id'=>5,'values'=>['answer']]]]);
        self::assertSame('answer',$accepted['answers'][0]['values'][0]);
        $this->expectException(\InvalidArgumentException::class);
        ArgumentValidator::validate($schema,['id'=>1,'answers'=>[['question_id'=>5,'uploadToken'=>'stolen']]]);
    }
}
