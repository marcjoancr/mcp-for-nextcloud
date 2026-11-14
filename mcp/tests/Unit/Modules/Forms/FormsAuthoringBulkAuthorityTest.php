<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsBulkAuthority;
use OCA\Mcp\Modules\Forms\Execution\FormsBeforeEffect;
use PHPUnit\Framework\TestCase;
final class FormsAuthoringBulkAuthorityTest extends TestCase {
    public function testNewPrivateAuthoringAuthoritiesRetainEveryOriginalGrant():void {
        foreach([['forms_update',['edit','public_link','configure_notifications']],['forms_delete_question',['edit_questions']],
            ['forms_update_share',['share']],['forms_update_share',['public_link']],['forms_unshare',['share']],['forms_unshare',['public_link']]] as [$tool,$ops]) {
            $authority=['tool'=>$tool,'required_operations'=>$ops,'files_operations'=>['share']];
            self::assertSame($authority,FormsBulkAuthority::checked($authority));
        }
    }
    public function testEveryAuthoringAuthorityMustKeepItsMinimumOriginalOperation():void {
        foreach(['forms_update','forms_delete_question','forms_update_share','forms_unshare'] as $tool) {
            try{FormsBulkAuthority::checked(['tool'=>$tool,'required_operations'=>['read'],'files_operations'=>[]]);self::fail('Missing original authoring operation admitted.');}
            catch(FormsBeforeEffect){self::assertTrue(true);}
        }
    }
    public function testUnknownActionsAndUntrustedOperationNamesRemainClosed():void {
        foreach([['tool'=>'forms_read_settings','required_operations'=>['read_settings'],'files_operations'=>[]],
            ['tool'=>'forms_update','required_operations'=>['edit','unknown'],'files_operations'=>[]],
            ['tool'=>'forms_update','required_operations'=>['edit'],'files_operations'=>['unknown']]] as $authority) {
            try{FormsBulkAuthority::checked($authority);self::fail('Invalid original authority admitted.');}
            catch(FormsBeforeEffect){self::assertTrue(true);}
        }
    }
}
