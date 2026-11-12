<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use PHPUnit\Framework\TestCase;
final class MailBodyPreviewTest extends TestCase {
    public function testBinaryProjectionCannotHideOversizedVisibleText():void {
        $this->expectException(ToolFailure::class);
        MailBodyPreview::fields(['body_html'=>str_repeat('Visible text',2000).'<img src="data:image/png;base64,'.base64_encode(str_repeat('x',80000)).'">']);
    }
    public function testMalformedImageEncodingCannotBecomeAReviewableProjection():void {
        $this->expectException(ToolFailure::class);
        MailBodyPreview::fields(['body_html'=>'<img src="data:image/png;base64,'.str_repeat('x',5000).'===">']);
    }
    public function testDeliveryProjectionRetainsExactNativeRevisionAndImageChangesChangeThePlan():void {
        $body='<p>Signature</p><img src="data:image/png;base64,'.base64_encode(str_repeat('x',80000)).'">';
        $snapshot=['revision'=>str_repeat('a',64),'bodyPlain'=>'Fallback','bodyHtml'=>$body];
        $p=MailBodyPreview::delivery($snapshot);
        self::assertSame($snapshot['revision'],$p['revision']);self::assertSame('Fallback',$p['bodyPlain']);
        self::assertArrayNotHasKey('bodyHtml',$p);self::assertLessThan(20000,strlen(json_encode($p)));
        self::assertSame(hash('sha256',$body),$p['body_html_sha256']);self::assertTrue($p['inline_images_omitted']);
        $snapshot['bodyHtml']=str_replace(base64_encode(str_repeat('x',80000)),base64_encode(str_repeat('y',80000)),$body);
        self::assertNotSame($p,MailBodyPreview::delivery($snapshot));
    }    public function testNativeDeliveryKeepsItsExistingEightyKilobyteReviewBudget():void {
        $body=str_repeat('x',60000);$r=MailBodyPreview::delivery(['bodyPlain'=>$body,'bodyHtml'=>null,'revision'=>'original']);
        self::assertSame($body,$r['bodyPlain']);self::assertSame('original',$r['revision']);
    }

}
