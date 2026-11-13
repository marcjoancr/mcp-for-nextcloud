<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsCommands;
use OCA\Mcp\Modules\Forms\FormsToolCatalog;
use OCA\Mcp\Modules\Forms\Presentation\FormsProjection;
use PHPUnit\Framework\TestCase;
final class FormsRecoverySurfaceTest extends TestCase {
    public function testRecoveryHasSeparateDeniedByDefaultGrantAndNoUserControlledTarget():void {
        $d=FormsCommands::definitions()['forms_restore'];
        self::assertSame('restore',$d['operation']);
        self::assertArrayHasKey('forms_restore',FormsToolCatalog::definitions());
        self::assertSame(['backup_id'],$d['inputSchema']['required']);
        self::assertArrayNotHasKey('id',$d['inputSchema']['properties']);
    }
    public function testTypedUploadOutputKeepsOnlyOpaqueHandleAndSafeMetadata():void {
        $r=FormsProjection::mutation(['files'=>[['handle'=>'opaque','name'=>'a.txt','size'=>2,'expires_at'=>90,'uploadToken'=>'secret','path'=>'private']]],'forms_upload_response_files');
        self::assertSame(['files'=>[['handle'=>'opaque','name'=>'a.txt','size'=>2,'expires_at'=>90]]],$r);
    }
    public function testIndependentFileReadUsesActualByteCountAndNativeReferences():void {
        $r=FormsProjection::read('forms_list_response_files',['files'=>[['file_id'=>9,'size'=>3,'form_id'=>4,'submission_id'=>5,'name'=>'a','path'=>'private']]],[]);
        self::assertSame(3,$r['files'][0]['size']);self::assertSame(5,$r['files'][0]['submission_id']);
        self::assertArrayNotHasKey('path',$r['files'][0]);
    }
}
