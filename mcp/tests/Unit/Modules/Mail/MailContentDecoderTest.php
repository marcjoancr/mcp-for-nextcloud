<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use PHPUnit\Framework\TestCase;
final class MailContentDecoderTest extends TestCase {
    protected function setUp():void {
        parent::setUp();
        $root=dirname(__DIR__,5).'/native/mail-guard/src/';self::assertFileExists($root.'Content/TransferDecoder.php');
        require_once $root.'Sync/RefreshBudget.php';require_once $root.'Content/ContentBudget.php';require_once $root.'Content/TransferDecoder.php';
    }
    private function decode(string $bytes,string $encoding):array {
        $stream=fopen('php://memory','w+');fwrite($stream,$bytes);rewind($stream);
        try{return \OCA\Mail\Content\TransferDecoder::decode($stream,$encoding,new \OCA\Mail\Content\ContentBudget(42,7));}finally{fclose($stream);}
    }
    public function testBase64AcrossChunkBoundaryPreservesHash():void {$raw=str_repeat('abc',5000).'z';$r=$this->decode(chunk_split(base64_encode($raw),76,"\r\n"),'base64');self::assertSame($raw,$r['bytes']);self::assertSame(hash('sha256',$raw),$r['sha256']);}
    public function testQuotedPrintableAcrossChunkBoundary():void {$raw=str_repeat('x',8191).'=0D=0Aend=\r\n';$r=$this->decode(str_repeat('x',8191)."=0D=0Aend=\r\n",'quoted-printable');self::assertSame(str_repeat('x',8191)."\r\nend",$r['bytes']);}
    public function testMalformedEncodingsRefused():void {foreach([['YQ','base64'],['YR==','base64'],['YQ==YQ==','base64'],['ab=QZ','quoted-printable'],['ab=','quoted-printable'],["\xff",'7bit']] as [$input,$encoding]){$refused=false;try{$this->decode($input,$encoding);}catch(\RuntimeException){$refused=true;}self::assertTrue($refused,$encoding);}}
    public function testExactly25MiBDecodedBase64Succeeds():void {$raw=str_repeat('a',26214400);$r=$this->decode(base64_encode($raw),'base64');self::assertSame(26214400,$r['byte_count']);self::assertSame(hash('sha256',$raw),$r['sha256']);}
    public function testDecodedCapDoesNotTrustAdvertisedSize():void {$this->expectException(\RuntimeException::class);$this->decode(base64_encode(str_repeat('a',26214401)),'base64');}
    public function testEncodedSectionHashPreservesExactSourceRepresentation():void {$wire="YQ==\r\n";$r=$this->decode($wire,'base64');self::assertSame(hash('sha256',$wire),$r['wire_sha256']);self::assertSame(strlen($wire),$r['wire_byte_count']);}
}
