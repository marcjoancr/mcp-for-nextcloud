<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Integration\Mail;
use OCA\Mcp\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\{Group,DataProvider};
#[Group('mail')]
final class McpMailSavedScopeTest extends IntegrationTestCase {
    public function testActualHttpFolderScopeAndAllLocalHistoryRevocation():void {
        $r=MailActor::run(MailActor::ALICE,'content-native',['mode'=>'service_http_scopes']);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        foreach(['local_write','remote_folder_read','no_hidden_thread','no_hidden_folders','parameterless_local_filtered','history_filtered','history_allowed','deleted_history','empty_filtered','private_refs'] as $key)self::assertTrue($r[$key]??false,$key.': '.json_encode($r));
        foreach($r['denials'] as $tool=>$denied)self::assertTrue($denied,$tool);self::assertSame(0,$r['writes']);
    }
    public static function revocations():array {return ['copy scope'=>['copy_scope'],'copy tool'=>['copy_tool'],'index scope'=>['index_scope'],'index tool'=>['index_tool']];}
    #[DataProvider('revocations')]
    public function testRealInlineNativeEffectSeesRevocationInsideEnclosingHttpSnapshot(string $case):void {
        $r=MailActor::run(MailActor::ALICE,'content-native',['mode'=>'service_scope_'.$case]);self::assertArrayNotHasKey('fixture_error',$r,json_encode($r));
        foreach(['prepared','revoked','refused','no_local_effect','no_index_effect','no_copy_reservation'] as $key)self::assertTrue($r[$key]??false,$key.': '.json_encode($r));self::assertSame(0,$r['writes']);
    }
}
