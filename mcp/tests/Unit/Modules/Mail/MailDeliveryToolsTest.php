<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\MailToolCatalog;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Confirmation\WriteGate;
use PHPUnit\Framework\TestCase;
final class MailDeliveryToolsTest extends TestCase {
    public function testOriginalDefinitionsIncludeReviewedCompositionPreferences():void {
        $catalog=MailToolCatalog::definitions();
        self::assertSame(['user_preferences','complete_body'],$catalog['mail_write_draft']['inputSchema']['properties']['composition']['enum']);
        self::assertContains('richtext',$catalog['mail_write_account_settings']['inputSchema']['properties']['changes']['properties']['editor_mode']['enum']);
        $first=array_slice(array_values(MailToolCatalog::definitions()),0,43);
        $first[6]['description']=str_replace('into Files, up to 25 MiB on the qualified bounded reader.','into Files.',$first[6]['description']);
        self::assertSame('6c7c281ab013a782c657728086a005cf0a836912d1e6224f5a02c22302230179',hash('sha256',json_encode($first,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)));
    }
    public function testSendingAnnotationsExposeTheExternalIrreversibleEffect():void {
        foreach(['mail_send'=>'send','mail_schedule'=>'schedule']as$name=>$operation){
            $a=\OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation::annotations($name,$operation);
            self::assertTrue($a['openWorldHint']);self::assertTrue($a['destructiveHint']);self::assertFalse($a['idempotentHint']);
        }
    }
    public function testFiveSeparateDefaultDeniedToolsAppendAfterTheOriginalCatalog():void {
        $expected=['mail_send'=>'send','mail_schedule'=>'schedule','mail_cancel_delivery'=>'cancel_delivery','mail_read_delivery'=>'read_delivery','mail_sent_repair'=>'repair_sent'];
        $definitions=MailToolCatalog::definitions();
        self::assertSame(array_keys($expected),array_slice(array_keys($definitions),43,5));
        self::assertSame('mail_export_message',array_keys($definitions)[48]);self::assertFalse(GrantPolicy::defaultGranted('mail','export_message'));
        foreach($expected as $tool=>$op){
            self::assertSame($op,$definitions[$tool]['operation']);
            self::assertContains($op,GrantPolicy::CATALOG['mail']);
            self::assertFalse(GrantPolicy::defaultGranted('mail',$op));
            self::assertSame($op!=='read_delivery',WriteGate::isWrite($definitions[$tool]));
        }
    }
}
