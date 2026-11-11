<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use OCA\Mcp\Mcp\Tools\Validation\ArgumentValidator;
use PHPUnit\Framework\TestCase;
final class MailDPlusToolsTest extends TestCase {
    public function testNewActionsHaveIndependentWriteGrantsAndNoCredentialOrCryptoInputs():void {
        $defs=MailToolCatalog::definitions();
        foreach(['mail_reconcile_operation'=>'reconcile_operations','mail_add_alias'=>'create_identities','mail_update_alias'=>'edit_identities','mail_delete_alias'=>'delete_identities'] as $name=>$operation){
            self::assertArrayHasKey($name,$defs);self::assertSame($operation,$defs[$name]['operation']);self::assertContains($operation,GrantPolicy::CATALOG['mail']);self::assertTrue(WriteGate::isWrite($defs[$name]));
            foreach(['password','oauth_token','smime_certificate_id'] as $key){
                $args=['account_id'=>4,'alias_id'=>7,'changes'=>[$key=>'NEVER-SECRET']];
                try{ArgumentValidator::validate($defs[$name]['inputSchema'],$args);self::fail('Unknown sensitive input admitted');}catch(\InvalidArgumentException $e){self::assertStringNotContainsString('NEVER-SECRET',$e->getMessage());}
            }
        }
        foreach(array_slice($defs,0,43) as $def) {
            if ($def['name'] === 'mail_read_outbox') { self::assertSame('read_outbox', $def['operation']); self::assertFalse(WriteGate::isWrite($def)); continue; }
            self::assertDoesNotMatchRegularExpression('/send|outbox|enqueue|schedule|mdn/',$def['name']);
        }
    }
}
