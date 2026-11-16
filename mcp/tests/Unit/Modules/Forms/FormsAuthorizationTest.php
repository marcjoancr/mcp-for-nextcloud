<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Modules\Forms;
use OCA\Mcp\Modules\Forms\Access\FormsAuthorization;
use PHPUnit\Framework\TestCase;
final class FormsAuthorizationTest extends TestCase {
    public function testFileReferenceVariantsRequireIndependentFilesReadAndRetainOriginalAuthority():void {
        self::assertSame([],FormsAuthorization::fileOperations('forms_download_results',[],[]));
        self::assertSame(['read'],FormsAuthorization::fileOperations('forms_download_results',['include_files'=>true],[]));
        self::assertSame(['create','read','edit'],FormsAuthorization::fileOperations('forms_export_results',['include_files'=>true,'overwrite'=>true],[]));
        self::assertSame(['read'],FormsAuthorization::fileOperations('forms_download_results',[],['historical'=>['files_operations'=>['read']]]));
    }
    public function testFreshHomeTransferAndRestoreRequireOriginalFilesReadAndCreateAuthority():void {
        self::assertSame(['read','create'],FormsAuthorization::fileOperations('forms_transfer',[],['counts'=>['files'=>1,'managed_shares'=>0],'historical_unavailable_files'=>0]));
        self::assertSame(['read','create'],FormsAuthorization::fileOperations('forms_restore',[],['counts'=>['files'=>1],'historical_unavailable_files'=>0]));
        self::assertSame([],FormsAuthorization::fileOperations('forms_transfer',[],['counts'=>['files'=>0,'managed_shares'=>0]]));
        self::assertSame([],FormsAuthorization::fileOperations('forms_transfer',[],['counts'=>['files'=>3,'managed_shares'=>0],'historical_unavailable_files'=>3]));
        self::assertSame([],FormsAuthorization::fileOperations('forms_restore',[],['counts'=>['files'=>3],'historical_unavailable_files'=>3]));
        self::assertSame(['read'],FormsAuthorization::fileOperations('forms_transfer',[],['historical'=>['files_operations'=>['read']]]));
    }
    public function testPublicLinkAuthorityCannotComeFromOrdinaryShare():void {
        self::assertSame(['public_link'],FormsAuthorization::operations('forms_share',['kind'=>'link'],[]));
        self::assertSame(['share'],FormsAuthorization::operations('forms_share',['kind'=>'group'],[]));
    }
    public function testChangingAudienceOrNotificationRequiresAdditionalAuthority():void {
        self::assertSame(['edit','public_link','configure_notifications'],FormsAuthorization::operations('forms_update',
            ['fields'=>['title'=>'A','access'=>['permitAllUsers'=>true],'confirmationEmailEnabled'=>true]],[]));
    }
    public function testExistingLinkTypeDeterminesUnshareGrant():void {
        self::assertSame(['public_link'],FormsAuthorization::operations('forms_unshare',['share_id'=>7],['shares'=>[['id'=>7,'shareType'=>3]]]));
        self::assertSame(['share'],FormsAuthorization::operations('forms_unshare',['share_id'=>8],['shares'=>[['id'=>8,'shareType'=>0]]]));
    }
    public function testMissingShareTypeNeverAssumesWeakerGrant():void {
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        FormsAuthorization::operations('forms_unshare',['share_id'=>7],['form'=>['id'=>4]]);
    }
    public function testIdentityAndFileProjectionAreIndependentPermissions():void {
        self::assertSame(['export_results','read_respondents','read_response_files'],FormsAuthorization::operations('forms_export_results',['include_respondents'=>true,'include_files'=>true],[]));
    }
}
