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
    public function testDeletePreviewNamesExactManagedShareRemovalsWithoutPrivateStoragePaths():void {
        $p=FormsPlan::build('forms_delete_form',['id'=>4],['form'=>['ownerId'=>'alice'],'file_manifest'=>['files_share'=>true,'share_removals'=>[['native_share_id'=>8,'recipient'=>'bob','type'=>0,'folder_ids'=>[77],'parent'=>['file_id'=>70,'path'=>'/alice/files/private'],'managed_shares'=>[['files_share_id'=>'ocinternal:7','origin'=>'private']]]]]],'alice');
        self::assertSame([['native_share_id'=>8,'recipient'=>'bob','type'=>0,'folder_ids'=>[77],'managed_share_ids'=>['ocinternal:7']]],$p['impact']['files']['share_removals']);
        self::assertStringNotContainsString('/alice/files',json_encode($p));self::assertStringNotContainsString('origin',json_encode($p));
        self::assertSame(['share'],\OCA\Mcp\Modules\Forms\Access\FormsAuthorization::fileOperations('forms_delete_form',['id'=>4],['file_manifest'=>['files_share'=>true]]));
    }
    public function testFutureLinkedAudienceNeedsSharedConfirmationAndExposesOnlyConsent():void {
        $consent=['updates'=>'future submissions until unlink, revocation or a source/destination change','recipients'=>[['type'=>0,'recipient'=>'bob','permissions'=>1]],'format'=>'csv'];
        $p=FormsPlan::build('forms_link_results_file',['id'=>4],['form'=>['ownerId'=>'alice'],'file_destination'=>['plan'=>[],'audience'=>['digest'=>'private']],'impact'=>['linked_export'=>$consent]],'alice');
        self::assertTrue($p['requiresSharedConfirmation']);
        self::assertSame($consent,$p['impact']['linked_export']);
        self::assertStringNotContainsString('private',json_encode($p));
    }
    public function testQuestionUpdateShowsCurrentTargetRatherThanUnrelatedFormOnly():void {
        $p=FormsPlan::build('forms_update_question',['id'=>4,'question_id'=>7,'fields'=>['text'=>'New']],['form'=>['ownerId'=>'alice','questions'=>[['id'=>7,'text'=>'Old','type'=>'short']]]],'alice');
        self::assertSame('Old',$p['before']['question']['text']);
    }
}
