<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Execution\FormsPlan;
use PHPUnit\Framework\TestCase;
final class FormsEffectPlanTest extends TestCase {
    public function testSharedFileDestinationRequiresAdditionalConfirmationAndVisibleImpact():void {
        $p=FormsPlan::build('forms_save_response_file',['id'=>4,'path'=>'/team/a.txt'],['form'=>['ownerId'=>'alice'],'file_destination'=>['plan'=>['requiresSharedConfirmation'=>true,'path'=>'/team/a.txt']],'file_manifest'=>['delete_file_ids'=>[9],'files'=>[['file_id'=>9,'name'=>'a','size'=>5,'sha256'=>'digest','path'=>'/alice/files/secret']],'recovery'=>true]],'alice');
        self::assertTrue($p['requiresSharedConfirmation']);self::assertSame([9],$p['impact']['files']['delete_file_ids']);
        self::assertSame('digest',$p['impact']['files']['files'][0]['sha256']);
        self::assertStringNotContainsString('/alice/files',json_encode($p));
    }
    public function testQuestionUpdateShowsCurrentTargetRatherThanUnrelatedFormOnly():void {
        $p=FormsPlan::build('forms_update_question',['id'=>4,'question_id'=>7,'fields'=>['text'=>'New']],['form'=>['ownerId'=>'alice','questions'=>[['id'=>7,'text'=>'Old','type'=>'short']]]],'alice');
        self::assertSame('Old',$p['before']['question']['text']);
    }
}
