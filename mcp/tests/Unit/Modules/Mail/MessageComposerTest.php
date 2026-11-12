<?php
/* SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Modules\Mail;
use OCA\Mcp\Modules\Mail\Composition\MessageComposer;
use OCA\Mcp\Modules\Mail\Integration\MailServices;
use PHPUnit\Framework\TestCase;

final class MessageComposerTest extends TestCase {
    #[\PHPUnit\Framework\Attributes\DataProvider('positions')]
    public function testNativeReplyAndSignaturePositions(bool $top,bool $above,array $expected):void {
        $composer=new MessageComposer($this->createMock(MailServices::class));
        $fields=$composer->compose($this->fields(),['body_plain'=>'Answer'],['signature'=>'Alice','editor_mode'=>'plaintext','reply_on_top'=>$top,'signature_above_quote'=>$above],['text'=>'Question','attribution'=>'Sender']);
        $last=-1;foreach($expected as $part){$position=strpos($fields['body_plain'],$part);self::assertNotFalse($position);self::assertGreaterThan($last,$position);$last=$position;}
        self::assertSame('',$fields['body_html']);
    }
    public static function positions():array {return [
        [true,true,['Answer','-- ','> Question']], [true,false,['Answer','> Question','-- ']],
        [false,true,['-- ','> Question','Answer']], [false,false,['> Question','Answer','-- ']],
    ];}
    public function testNativeRichtextModeAndImageSignatureArePreserved():void {
        $composer=new MessageComposer($this->createMock(MailServices::class));
        foreach(['plaintext','richtext'] as $mode){
            $f=$this->fields();$f['body_plain']='Answer <safe>';
            $fields=$composer->compose($f,['body_plain'=>'Answer <safe>'],['signature'=>'<p>Alice</p><img src="data:image/png;base64,YQ==">','editor_mode'=>$mode,'reply_on_top'=>true,'signature_above_quote'=>true],['text'=>'Question <script>alert(1)</script>']);
            self::assertTrue($fields['is_html']);self::assertSame('',$fields['body_plain']);
            self::assertStringContainsString('Answer &lt;safe&gt;',$fields['body_html']);
            self::assertStringContainsString('<div class="signature"><p>Alice</p><img',$fields['body_html']);
            self::assertStringContainsString('&lt;script&gt;',$fields['body_html']);
            self::assertStringNotContainsString('-- ',$fields['body_html']);
        }
    }
    public function testImagePromotionKeepsTheExplicitlyActivePlainBody():void {
        $composer=new MessageComposer($this->createMock(MailServices::class));
        $f=['body_plain'=>'Active plain body','body_html'=>'<p>Inactive stale HTML</p>','is_html'=>false];
        $r=$composer->compose($f,$f,['signature'=>'<img src="data:image/png;base64,YQ==">','editor_mode'=>'richtext','reply_on_top'=>true,'signature_above_quote'=>false],null);
        self::assertTrue($r['is_html']);self::assertStringContainsString('Active plain body',$r['body_html']);
        self::assertStringNotContainsString('Inactive stale HTML',$r['body_html']);
    }
    public function testExistingNativeSignatureIsNotInsertedTwice():void {
        $composer=new MessageComposer($this->createMock(MailServices::class));
        $f=$this->fields();$f['body_html']='<p>Answer</p><div class="signature">Alice</div>';
        $r=$composer->compose($f,['body_html'=>$f['body_html'],'is_html'=>true],['signature'=>'Alice','editor_mode'=>'richtext','reply_on_top'=>true,'signature_above_quote'=>false],null);
        self::assertSame(1,substr_count($r['body_html'],'class="signature"'));
        $f['body_plain']="Answer\n\n-- \nAlice";
        $r=$composer->compose($f,['body_plain'=>$f['body_plain']],['signature'=>'Alice','editor_mode'=>'plaintext','reply_on_top'=>true,'signature_above_quote'=>false],null);
        self::assertSame(1,substr_count($r['body_plain'],'-- '));
    }
    public function testCombinedBodyLimitRejectsRatherThanTruncatesSignatureOrQuote():void {
        $composer=new MessageComposer($this->createMock(MailServices::class));$f=$this->fields();$f['body_plain']=str_repeat('x',20000);
        $this->expectException(\OCA\Mcp\Mcp\Tools\Results\ToolFailure::class);
        $composer->compose($f,['body_plain'=>$f['body_plain']],['signature'=>'Alice','editor_mode'=>'plaintext','reply_on_top'=>true,'signature_above_quote'=>false],null);
    }
    public function testAliasSignatureComesFromTheSelectedOwnedIdentity():void {
        $native=$this->createMock(MailServices::class);
        $native->method('accountService')->willReturn(new class {
            public function find(string $uid,int $id):object{return new class {
                public function getMailAccount():object{return new class {
                    public function getSignature():string{return 'Account';}
                    public function getEditorMode():string{return 'richtext';}
                    public function isSignatureAboveQuote():bool{return true;}
                };}
            };}
        });
        $native->method('aliasesService')->willReturn(new class {
            public function find(int $id,string $uid):object{return new class {
                public function getAccountId():int{return 4;}
                public function getSignature():string{return 'Alias';}
            };}
        });
        $native->method('userPreferences')->willReturn(new class {public function getPreference(string $uid,string $key,string $default):string{return 'bottom';}});
        $p=(new MessageComposer($native))->preferences('alice',4,3);
        self::assertSame('Alias',$p['signature']);self::assertFalse($p['reply_on_top']);self::assertTrue($p['signature_above_quote']);
    }
    public function testLargeNativeImageSignatureKeepsEveryByteWithACompactReview():void {
        $image=base64_encode(str_repeat('image-bytes',6000));
        $signature='<p>Alice</p><img src="data:image/png;base64,'.$image.'">';
        $composer=new MessageComposer($this->createMock(MailServices::class));
        $f=$composer->compose($this->fields(),['body_plain'=>'Answer'],['signature'=>$signature,'editor_mode'=>'richtext','reply_on_top'=>true,'signature_above_quote'=>true],['text'=>'Question']);
        self::assertStringContainsString($signature,$f['body_html']);
        $p=\OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields($f);
        self::assertArrayNotHasKey('body_html',$p);
        self::assertStringContainsString('Question',$p['body_html_preview']);
        self::assertStringNotContainsString($image,json_encode($p));
        self::assertSame(hash('sha256',$f['body_html']),$p['body_html_sha256']);
        self::assertSame(66000,$p['inline_images'][0]['bytes']);
        $g=$f;$g['body_html']=str_replace($image,base64_encode(str_repeat('other-bytes',6000)),$g['body_html']);
        self::assertNotSame($p['body_html_sha256'],\OCA\Mcp\Modules\Mail\Presentation\MailBodyPreview::fields($g)['body_html_sha256']);
    }
    private function fields():array {return ['body_plain'=>'Answer','body_html'=>'','is_html'=>false];}
}
