<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Modules\Forms\Integration\FormsPublication;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidationException;
use PHPUnit\Framework\TestCase;
final class FormsRecoveryReadsContractTest extends TestCase {
    public function testEffectInspectionUsesOnlyASignedCursorAndReadOperationsGrant():void {
        $d=FormsCommands::definitions()['forms_read_operation_impact']??null;
        self::assertIsArray($d);self::assertSame('read_operations',$d['operation']);
        self::assertSame(['cursor'=>'opaque','limit'=>50],ArgumentValidator::validate($d['inputSchema'],['cursor'=>'opaque']));
        self::assertTrue(FormsCommands::readOnly($d['name']));
        self::assertSame('forms_read_operation_impact',\OCA\Mcp\Modules\Forms\Tools\ReadOperationImpact::definition()->name);
    }
    public function testRecoveredCommentsRequireIndependentCommentReadAndAnOriginalOperation():void {
        $d=FormsCommands::definitions()['forms_read_recovered_comments']??null;
        self::assertIsArray($d);self::assertSame('read_comments',$d['operation']);
        self::assertSame(['target_operation_id'=>'original','limit'=>50],ArgumentValidator::validate($d['inputSchema'],['target_operation_id'=>'original']));
        self::assertTrue(FormsCommands::readOnly($d['name']));
        self::assertSame('forms_read_recovered_comments',\OCA\Mcp\Modules\Forms\Tools\ReadRecoveredComments::definition()->name);
    }
    public function testPrivateNativeArchiveOrCursorInputsAreNeverAcceptedAsToolArguments():void {
        foreach([
            ['forms_read_operation_impact',['cursor'=>'opaque','native_cursor'=>100]],
            ['forms_read_recovered_comments',['target_operation_id'=>'original','archive_id'=>str_repeat('a',64)]],
            ['forms_read_recovered_comments',['target_operation_id'=>'original','after'=>100]],
            ['forms_read_recovered_comments',['target_operation_id'=>'original','confirm'=>true]],
        ] as [$tool,$args]) {
            $d=FormsCommands::definitions()[$tool]??null;self::assertIsArray($d);$denied=false;
            try {ArgumentValidator::validate($d['inputSchema'],$args);}catch(ArgumentValidationException){$denied=true;}
            self::assertTrue($denied,'A private native locator or mutation parameter reached a readonly tool.');
        }
    }
    public function testNativeActionsCannotPublishUnqualifiedNewMcpReaders():void {
        $tools=['forms_read_operation_impact','forms_read_recovered_comments'];
        $native=['qualified'=>true,'protocol'=>1,'bulk_lifecycle'=>true,'comments'=>true,'actions'=>$tools];
        foreach($tools as $tool)self::assertFalse(FormsPublication::supported($tool,$native));
    }
}
