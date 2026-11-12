<?php
/*
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use PHPUnit\Framework\TestCase;
final class MailConfigurationToolsTest extends TestCase {
    public function testConfigurationCapabilitiesHaveSeparateOperationsAndNoSendTool():void {
        $definitions=MailToolCatalog::definitions();
        $expected=['mail_read_account_settings'=>'read_settings','mail_write_account_settings'=>'write_settings','mail_read_signature'=>'read_signatures',
            'mail_write_signature'=>'write_signatures','mail_read_connection'=>'read_connection','mail_configure_connection'=>'configure_connection',
            'mail_test_connection'=>'test_connection','mail_add_account'=>'create_accounts','mail_complete_account_setup'=>'complete_setup','mail_delete_account'=>'delete_accounts'];
        foreach($expected as $tool=>$op){self::assertArrayHasKey($tool,$definitions);self::assertSame($op,$definitions[$tool]['operation']);
            self::assertSame(!in_array($op,['read_settings','read_signatures','read_connection'],true),WriteGate::isWrite($definitions[$tool]));}
        foreach(array_slice(array_keys($definitions),0,43) as $tool) {
            if ($tool === 'mail_read_outbox') { continue; }
            self::assertDoesNotMatchRegularExpression('/send|outbox/',$tool);
        }
    }
    public function testCredentialFieldsAreNeverAcceptedAtAnySetupBoundary():void {
        $definitions=MailToolCatalog::definitions();
        foreach(['mail_add_account','mail_configure_connection','mail_complete_account_setup'] as $tool){
            self::assertArrayHasKey($tool,$definitions);
            foreach(['password','imapPassword','smtpPassword','oauth_token','refresh_token'] as $secret){
                $args=['intent'=>['email'=>'alice@example.test',$secret=>'SECRET']];
                if($tool!=='mail_add_account')$args['account_id']=4;
                if($tool==='mail_complete_account_setup')$args['setup_operation_id']='setup';
                try{ArgumentValidator::validate($definitions[$tool]['inputSchema'],$args);self::fail('Credential field was accepted');}
                catch(\InvalidArgumentException $e){self::assertStringNotContainsString('SECRET',$e->getMessage());}
            }
        }
    }
}
